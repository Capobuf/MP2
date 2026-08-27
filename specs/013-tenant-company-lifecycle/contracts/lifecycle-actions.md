# Contract: Tenant lifecycle actions

## Shared authorization boundary

All lifecycle Actions accept an authenticated `User $actor` and a concrete `TenantCompany`. They call Gate/policy server-side; only `is_platform_admin` is accepted. `TenantCompanyPolicy::create` uses the same platform-only rule so Filament's registration page is not viewable by ordinary users. No `CompanyCapability` grants lifecycle or registration authority.

All mutations run in a database transaction and use lock order:

1. lock related `companies` row by `company_id`;
2. lock `tenant_companies` row by the same ID;
3. re-authorize and revalidate the requested transition;
4. write the single transition or continue to destruction.

## ArchiveTenantCompany

```php
execute(User $actor, TenantCompany $tenant): TenantCompany
```

Preconditions:

- actor is platform admin;
- Company and Tenant still exist as a pair;
- locked Tenant status is `active`.

Postconditions:

- status is `archived`;
- all Company/domain/capability/audit/file data remains unchanged;
- operational access and new tenant-owned mutations are denied;
- no domain AuditEvent is added; only Tenant status and technical timestamps change.

Failure:

- unauthorized → authorization exception;
- already archived/missing/incoherent pair → explicit validation/not-found failure;
- transaction failure → status remains active.

## RestoreTenantCompany

```php
execute(User $actor, TenantCompany $tenant): TenantCompany
```

Preconditions mirror Archive with required status `archived`.

Postconditions:

- status is `active`;
- no Company/domain/capability/file field changes;
- no date, deadline, exercise, snapshot or economic state is shifted/recalculated;
- users regain operational access only according to preserved capabilities.

Failure is explicit and leaves status/data unchanged.

## Authorization formula after lifecycle changes

For every domain policy:

```text
hasCapability(user, company, capability)
= tenant(company).status is active
  AND matching CompanyCapability exists
```

This formula applies equally to panel interaction, direct Action calls, report/download routes and automatic-process actors. Platform lifecycle authorization is separate and never calls this formula as a substitute for `is_platform_admin`.

## Concurrency contract

- A domain mutation holding the Company lock may finish before Archive obtains the lock.
- Once Archive commits, a later domain mutation reauthorizes under the Company lock and fails.
- Archive and Restore requests on the same Tenant serialize and exactly one valid state transition succeeds.
- Destroy serializes with both state transitions and domain mutations using the same leading Company lock.
- No broad/global lock is taken.

## UI confirmations

- Archive: confirmation modal naming the Company and explaining preserved data plus operational suspension.
- Restore: confirmation modal naming the Company and stating that real dates/states are not shifted.
- These modal confirmations do not replace server authorization or state validation.
