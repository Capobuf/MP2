# Quickstart: Validate Exercises, Expenses and Lines

## Prerequisites

- S2 stack is running and healthy on port 9000.
- Administrator has `visualizza`, `modifica_operativita` and
  `gestisce_anagrafiche` in the validation Company.
- Active and archived Supplier/Cost Center examples exist.
- Automated tests use only `APP_ENV=testing` and `DB_DATABASE=testing`.

## Apply S3 forward changes

```bash
./vendor/bin/sail composer install
./vendor/bin/sail artisan migrate
```

Do not use `migrate:fresh`, truncate, reset persistent data, remove volumes, or edit
already-applied migrations.

## Focused automated validation

```bash
./vendor/bin/sail artisan test tests/Unit/Domain/Expenses tests/Feature/Expenses
```

## Demonstrate the vertical flow

1. Sign in at `http://127.0.0.1:9000/admin` and select the validation Company.
2. Create current and next-year `Esercizi`; verify both remain `Aperto` and duplicate
   same-company year is rejected.
3. Create `Licenze laboratorio` in the current year with active Supplier/Cost Center
   and initial Stima 1,000.00 EUR.
4. Add Stima 500.00 and Effettivi 900.00/100.00; verify Allocato 1,500.00, Effettivo
   1,000.00, Scostamento -500.00.
5. Enter quantity 3, unit 100.00 and authoritative amount 310.00; verify suggested
   300.00 warning and explicit acknowledgement.
6. Verify negative Stima and negative Effettivo without Note fail; save the latter
   with a truthful Note and observe signed totals.
7. Create Actual +100.00/-100.00; verify net zero still blocks Storno.
8. Annul/restore a Line; verify stable identity, exact totals and no delete action.
9. Storna/restore an Estimate-only Expense with reason; verify exact removal/addition.
10. Move an Estimate-only Expense to next open year after preview. Verify an Expense
    with Actuals cannot move to a future year.
11. Reclassify Supplier/Cost Center after preview. Verify archived/cross-company new
    choices fail while existing archived references remain readable.
12. Inspect Timeline: one immutable complete event per real mutation, two-year impact
    for moves, and no duplicate event for retries/no-ops.
13. As a viewer without `modifica_operativita`, verify read-only access. Guess another
    tenant's Exercise/Expense URL and verify no disclosure.
14. Verify absence of all Project, Contract, Budget, Closing, carryover, Forecast and
    later-slice controls.

## Full local quality gate

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail composer format:test
./vendor/bin/sail composer analyse
./vendor/bin/sail composer test
./vendor/bin/sail artisan about --only=environment,cache,drivers
curl -I http://127.0.0.1:9000/admin
curl -I http://10.0.0.30:9000/admin
```

## Persistence check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
```

After restart, Exercises, Expenses, Lines, states, references, OriginKeys and Timeline
events remain. No validation step deletes persistent data.
