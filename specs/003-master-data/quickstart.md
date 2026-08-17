# Quickstart: Validate Master Data

## Prerequisites

- S1 stack is running and healthy on port 9000.
- The persistent administrator and operator credentials remain unchanged.
- The administrator holds `visualizza` and `gestisce_anagrafiche` in the Company used
  for validation.
- Commands run through Sail; host PHP or Composer is not required.

## Apply S2 forward migrations

```bash
./vendor/bin/sail artisan migrate
```

Do not use `migrate:fresh`, truncate tables, reset the persistent database, remove
volumes, or edit an already-applied migration.

## Focused automated validation

```bash
./vendor/bin/sail artisan test tests/Feature/MasterData
```

The existing environment guard must prove the suite uses only the `testing` database.

## Demonstrate the vertical flow

1. Open `http://127.0.0.1:9000/admin` and sign in as the administrator.
2. Enter a Company in which the administrator has `gestisce_anagrafiche`.
3. Open `Fornitori` and create two Suppliers with the same Ragione Sociale and the
   same optional Partita IVA; verify both retain different stable identities.
4. Open one Supplier and add one Contact without role tags and a second Contact with
   multiple truthful descriptive tags; update one Contact.
5. Rename the Supplier and verify its identity does not change.
6. Archive the Supplier; verify it leaves the default active list, remains visible
   under `Archiviati`, and has no delete action.
7. Restore it and verify the same identity returns to the active list.
8. Open `Centri di Costo`, create two records with the same denomination, rename one,
   Archive it, inspect it under `Archiviati`, and restore it.
9. Verify no Exercise, annual classification, amount, split, hierarchy, or other S3+
   control appears.
10. Open the Company Timeline and verify distinct immutable S2 events for create,
    update, Contact change, Archive, and restore.
11. Sign in as a viewer without `gestisce_anagrafiche`; verify lists/details remain
    readable while mutation controls are unavailable.
12. Attempt a direct Supplier, Contact, and Cost Center URL from another Company;
    verify no cross-company record is disclosed.

## Full local quality gate

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail composer format:test
./vendor/bin/sail composer analyse
./vendor/bin/sail composer test
```

## Persistence and non-deletion check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
```

After restart, active and archived master data, Contacts, stable identities, and
Timeline events must remain. No normal validation step deletes or resets persistent
development data.
