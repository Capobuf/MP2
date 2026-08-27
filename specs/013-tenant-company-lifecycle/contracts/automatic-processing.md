# Contract: Automatic tenant-owned processing

## General rule

Every automatic process that reads or mutates tenant-owned domain data has two boundaries:

1. **selection boundary**: query only records whose Company has a `TenantCompany` with status `active`;
2. **mutation boundary**: after acquiring the Company lock, re-run the normal policy/Gate so an Archive committed after selection denies the mutation.

Filtering alone is an optimization, not the authorization boundary. A missing TenantCompany is invalid migration state and must not be interpreted as active.

## Existing renewal process

`contracts:process-renewals` remains scheduled daily and keeps existing business behavior and idempotency.

Selection contract:

```text
Contract active
AND next_expiry_date is not null
AND Contract.Company.TenantCompany.status == active
```

Actor selection remains the first User with `gestisce_operatività` for that Company. Archived Tenant records must not generate “no operator” warnings because they are not selected.

Before changes commit, `ProcessContractRenewals` locks Company/Contract and Gate authorizes the chosen actor. Since `hasCapability()` includes active status, a Tenant archived after command selection is skipped through an explicit authorization failure and receives no new lifecycle facts, conditions, classifications, audit or revision changes.

## Restore behavior

Restore does not enqueue synthetic jobs, move `next_expiry_date`, rewrite dates or execute renewals inside the Restore transaction. The next normal command run evaluates real overdue dates. Existing renewal idempotency and anchored schedule rules determine how many facts are produced.

## Technical file cleanup process

`tenant-files:cleanup` is global infrastructure, not tenant-owned domain processing. It operates only on `pending_file_deletions`, which intentionally survive Company deletion; therefore it is not filtered by Tenant status.

## Future-process review gate

Before completion, search `routes/console.php`, Console commands, scheduler definitions, queued jobs, listeners and event subscribers. Any newly discovered tenant-owned mutator must satisfy both boundaries and receive an archived-Tenant regression test. No generic job framework is introduced solely for this review.

## Required tests

- active Tenant renewal behavior remains unchanged;
- archived Tenant is excluded before actor lookup;
- archived Tenant receives zero renewal/audit mutations;
- selected-then-archived race is rejected under lock;
- restored Tenant is processed on the next normal run using real dates;
- repeated run remains idempotent;
- Tenant A archive never suppresses Tenant B processing.
