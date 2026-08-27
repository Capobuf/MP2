# Contract: Permanent Tenant destruction

## Action input

```php
execute(
    User $actor,
    TenantCompany $tenant,
    bool $irreversibilityConfirmed,
    bool $destructionConfirmed,
): TenantDestructionResult
```

The result contains only operation status and file cleanup counts; it must not retain a deleted Company model.

## Two distinct confirmations

Both are required and validated on the server in the locked transaction, within the action already bound to the target Tenant. The UI presents them as two sequential Wizard steps, each with its own unchecked checkbox and explicit advance/confirm action:

1. `irreversibilityConfirmed === true`, from a dedicated checkbox acknowledging deletion of data, history, audit and files;
2. `destructionConfirmed === true`, from a second dedicated checkbox confirming permanent destruction of the named Tenant.

No OTP, password, typed name or random code is required. Repeating a click on the first step cannot submit the second. A missing confirmation or stale Tenant causes zero deletion. The Action generates its internal operation UUID only after both confirmations and locked validation succeed.

## Preconditions

- actor has `is_platform_admin = true`;
- Tenant/Company pair exists;
- status is either `active` or `archived`;
- no economic, Exercise, Contract, Budget or Closing precondition is evaluated.

## Database transaction

Within one MySQL transaction:

1. lock Company then TenantCompany;
2. repeat authorization, state and confirmation checks;
3. query distinct non-null `(storage_disk, storage_path)` from Attachment and Budget Evidence owned by `company_id`;
4. exclude any pair still referenced by Attachment or Budget Evidence metadata of another Company, because that physical file is not exclusively owned by the target;
5. insert/upsert one `pending_file_deletions` row per remaining exclusive pair with the internally generated operation UUID;
6. delete the Company root with a direct query scoped to the locked ID;
7. require exactly one Company deletion and let declared FK actions remove all tenant-owned rows;
8. commit.

The Action must not disable foreign-key checks and must not invoke individual Eloquent delete hooks as a workaround.

## Database postconditions

After a successful commit:

- no TenantCompany or Company exists for the ID;
- no direct, indirect, historical, association, capability, Attachment metadata or AuditEvent row exists for the Company;
- no global User was deleted;
- rows and relationships belonging to every other Company are unchanged;
- one pending row exists for each distinct exclusively owned file until cleanup succeeds; files still referenced by another Tenant have no target manifest row.

On any database exception, all transaction effects roll back and storage is not called.

## File cleanup

After commit, `DeletePendingTenantFiles` processes only manifest rows for the operation:

1. validate that disk/path are non-empty exact file references;
2. if the file does not exist, delete the manifest row as complete;
3. otherwise call exact-file deletion on the recorded disk/path;
4. on success, delete the manifest row;
5. on false/exception, increment attempts, record timestamp/error and keep the row.

No recursive directory delete is allowed. A repeated or concurrent cleanup is safe because the end condition is “file absent and manifest row absent”.

## Retry command

```text
tenant-files:cleanup {--operation=}
```

- without option, processes all pending rows in stable ID order;
- with operation UUID, processes only that operation;
- outputs processed/completed/failed counts;
- returns non-zero if any selected row remains failed;
- is scheduled hourly in `routes/console.php`;
- must not require a Tenant context or Company row.

## Observability language

UI/action output distinguishes:

- `Cancellazione completata`: DB deleted and no file rows remain for operation;
- `Dati eliminati; pulizia file in attesa`: DB deleted and at least one manifest row remains.

It must never claim rollback or atomic completion across DB and storage.

## Required failure tests

- first confirmation missing;
- second confirmation missing;
- non-platform actor;
- active and archived target;
- DB exception before delete and during cascade/commit;
- complete populated ownership graph;
- shared User and other Tenant preservation;
- duplicate path across Attachment/Evidence;
- path also referenced by another Tenant is preserved physically while target metadata is removed;
- file already absent;
- storage delete returns false;
- storage throws;
- retry later succeeds;
- two cleanup workers see the same row;
- guessed old URL/download after commit.
