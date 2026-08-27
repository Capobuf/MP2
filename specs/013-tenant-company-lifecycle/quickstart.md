# Quickstart: verificare Tenant Azienda e ciclo di vita

Questo documento descrive la verifica da eseguire **dopo** l'implementazione. Tutti i comandi distruttivi sono ammessi esclusivamente sul database MySQL isolato `testing` secondo `docs/testing-policy.md`. Non eseguire `migrate:fresh`, truncate o reset sul database di sviluppo persistente.

## 1. Prepare the isolated environment

Verificare prima i guard del test environment:

```bash
APP_ENV=testing php artisan about
APP_ENV=testing php artisan db:show
```

Il database riportato deve essere `testing`. Se non lo è, fermarsi.

Installare/buildare soltanto se necessario:

```bash
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run build
```

## 2. Focused automated verification

```bash
APP_ENV=testing vendor/bin/pest \
  tests/Feature/Tenancy \
  tests/Feature/CompanyTenancyTest.php \
  tests/Feature/CompanyBoundaryTest.php \
  tests/Feature/CreateCompanyTest.php
```

```bash
APP_ENV=testing vendor/bin/pest \
  tests/Feature/Contracts/ProcessContractRenewalsCommandTest.php \
  tests/Feature/Closing/CloseExerciseTest.php \
  tests/Feature/Closing/ClosingSnapshotTest.php \
  tests/Feature/Closing/ClosingUiTest.php
```

Required result: zero failures, including explicit rejection cases for archived and cross-tenant access.

## 3. Backfill and one-to-one integrity

On a populated copy of the isolated test database, record the Company count, apply migrations, then run:

```sql
SELECT COUNT(*) AS companies FROM companies;
SELECT COUNT(*) AS tenants FROM tenant_companies;

SELECT c.id
FROM companies c
LEFT JOIN tenant_companies tc ON tc.company_id = c.id
WHERE tc.company_id IS NULL;

SELECT company_id, COUNT(*)
FROM tenant_companies
GROUP BY company_id
HAVING COUNT(*) <> 1;

SELECT status, COUNT(*)
FROM tenant_companies
GROUP BY status;
```

Expected:

- Company and Tenant counts match;
- missing/duplicate queries return zero rows;
- all migrated Tenant records are `active`;
- existing Company IDs and domain row counts are unchanged.

### New registration check

As platform admin, open the existing registration flow. `Annulla` before submit must leave Company/Tenant/capability counts unchanged. A valid submit must add exactly one Company, one active Tenant with the same ID, all existing initial capabilities and existing creation audit. An injected validation/transaction failure must add none.

## 4. Archive and restore journey

Prepare a platform admin, an ordinary user with `visualizza`, two active Tenant and representative data/files.

1. Sign in to `/platform` as platform admin.
2. Archive Tenant A and verify status `Archiviato`.
3. Sign in as the ordinary user and verify A is absent from the operational Tenant set.
4. Attempt known URLs for an A Resource, Attachment download, Budget Evidence download and report PDF; all must deny without revealing A data.
5. Invoke one domain Action for A directly in the automated test; it must deny.
6. Verify Tenant B remains usable.
7. Restore A from `/platform`.
8. Compare capabilities, domain rows, snapshots, audit and files against the pre-archive fixture; only Tenant status/timestamps may differ.
9. Verify dates/deadlines were not moved and A is usable again according to preserved capabilities.

## 5. Automatic processing journey

Create overdue renewable Contracts in Tenant A and B, archive A, then run:

```bash
APP_ENV=testing php artisan contracts:process-renewals
```

Expected:

- B receives normal idempotent processing;
- A receives no new fact, condition, classification, audit or revision;
- A does not produce a missing-operator warning;
- after Restore, the next command run processes A according to real dates;
- a repeated run creates no duplicate renewal facts.

## 6. Permanent destruction journey

Use the full-graph fixture in `TenantCompanyDestructionTest`; it must include one row from every direct/indirect owned table, all cross-link families, immutable snapshots/audit, at least one shared User, another Tenant, un path duplicato fra Attachment/Evidence dello stesso Tenant e un path referenziato anche dal secondo Tenant.

1. Complete the first Wizard confirmation but do not complete the second: no deletion.
2. In the server-side forged-payload test, send only the second confirmation: no deletion.
3. Complete both sequential confirmation steps as platform admin.
4. Verify Company/Tenant and every tenant-owned row count for the target are zero.
5. Verify global User and every other-Tenant count/value/file are unchanged.
6. Verify all target file paths are absent or have one pending manifest row.
7. Verify a physical path still referenced by the second Tenant is preserved while every target metadata row is gone.
8. Verify all old operational/download URLs deny.

Repeat for both an active and archived Tenant.

## 7. Database failure and storage recovery

Automated failure injection must prove:

- DB exception before commit leaves Company, Tenant, entire graph, manifest and files unchanged;
- storage false/exception after commit leaves Tenant absent and a pending row with attempt/error metadata;
- running the retry command after restoring storage deletes the file and pending row;
- rerunning cleanup is a no-op success;
- an already absent file completes normally.

Manual command for an operation under test:

```bash
APP_ENV=testing php artisan tenant-files:cleanup --operation=<uuid>
```

Inspect pending work:

```sql
SELECT operation_id, storage_disk, storage_path, attempts, last_attempted_at, last_error
FROM pending_file_deletions
ORDER BY id;
```

## 8. N+1 semantics

Run a Chiusura with N+1 absent for each option:

- `Crea N+1`: N+1 is created open;
- `Non creare N+1`: N+1 is absent, Tenant status is unchanged, and a later authorized manual creation succeeds.

Run a Chiusura with N+1 already existing: no unnecessary choice is shown and disposition is `already_existed`.

Repository scan must return no implementation reference to removed semantics outside the historical canonical paragraphs superseded by §31:

```bash
rg -n -i "gestione[ _-]?(terminata|continuata)|management[ _-]?terminated|not_created_management_terminated" app database/factories resources tests
```

Expected: no matches.

## 9. Browser verification

Start the application against safe test/development fixtures without resetting persistent development data:

```bash
php artisan serve --host=127.0.0.1 --port=9000
npm run dev
```

Verify in a real browser:

- `/platform` denied to ordinary user and usable by platform admin;
- list/status/action visibility for active and archived Tenant;
- Archive confirmation, operational disappearance and denial;
- Restore confirmation and return to operation;
- two distinct destruction confirmations and truthful cleanup result copy;
- responsive/readable modal states, validation errors, keyboard flow;
- no console errors, failed Livewire requests or unexpected 5xx responses.

## 10. Repository-wide quality gate

Run the current CI-equivalent gate once the feature is complete:

```bash
composer validate --strict
npm run build
composer audit --locked --no-interaction
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
APP_ENV=testing vendor/bin/pest
```

Also smoke both login surfaces:

```bash
curl --fail --silent --output /dev/null http://127.0.0.1:9000/admin/login
curl --fail --silent --output /dev/null http://127.0.0.1:9000/platform/login
```

Do not declare completion until the focused checks, complete quality gate, browser journey, migration count comparison, process re-scan and full ownership-graph deletion test all pass.
