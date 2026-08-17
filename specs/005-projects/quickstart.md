# Quickstart: Validate Projects

## Prerequisites

- S3 stack is running and healthy on port 9000.
- Administrator has `visualizza`, `modifica_operativita`, and
  `gestisce_anagrafiche` in the validation Company.
- Current, past, and future open Exercise examples plus active/archived Cost Centers
  exist.
- Automated tests use only `APP_ENV=testing` and `DB_DATABASE=testing`.

## Apply S4 forward changes

```bash
./vendor/bin/sail artisan migrate
```

Do not use `migrate:fresh`, truncate, reset persistent data, remove volumes, or edit
already-applied migrations.

## Focused automated validation

```bash
./vendor/bin/sail artisan test tests/Unit/Domain/Projects tests/Feature/Projects tests/Feature/Expenses
```

## Demonstrate the vertical flow

1. Sign in at `http://127.0.0.1:9000/admin` and select the validation Company.
2. Create a future Planned Project and a current Open Project with optional annual
   classifications. Verify neither creates an Expense or amount.
3. Inspect the future Project before its initial date and verify `Assente alla data`.
4. Schedule Planned → Open and Open → Closed transitions; verify current and annual
   state at the displayed reference dates.
5. Annul and replace a future transition. Verify the old identity/history remains,
   duplicate effective date and incompatible future sequence are rejected, and an
   effective transition has no erase action.
6. Create two Project Expenses with different Suppliers and Estimate Lines. Verify
   Project allocation is exact, Supplier remains on each Expense, inherited Cost
   Center is shown, and Exercise totals count the values once.
7. Add ordinary Actual to Open. Verify Planned rejects it unless opening is confirmed
   atomically; Closed/Cancelled accepts only declared late/reimbursement/corrective
   Actual with Note and without state change.
8. Reclassify one Project Exercise after preview. Verify the whole annual allocation
   and Actual move, while a second Exercise remains unchanged.
9. Create a new Exercise. Verify each Project receives the latest known
   classification, including readable archived continuity, without any economic row.
10. Move an Estimate-only Expense autonomous → Project → another Project →
    autonomous. Verify IDs remain stable, direct classification is cleared/explicit,
    and all deltas are atomic.
11. Repeat a move with Actuals. Verify reason and state eligibility, stale preview,
    cross-company, archived target, reversed Expense, and Contract-shaped input are
    rejected without partial effects.
12. Create and increase positive variance. Verify one warning each; equal/decreased
    variance produces none. Enable mandatory note and verify missing note rolls back.
13. Close/cancel then archive a Project; verify Planned/Open archive is unavailable,
    restore preserves all identities/values, and archived Projects remain historical.
14. Inspect filtered Project Timeline and confirm all event details, exact impacts,
    reasons, Project references, and operation identities are immutable.
15. As a viewer, verify read-only access. Guess another tenant's Project URL and
    verify no disclosure.
16. Verify absence of carryover, reprogramming, Contract, Proposal, Budget, Closing,
    attachment, Forecast, and full-reporting controls.

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

After restart, Projects, transitions, annual classifications, Expense ownership,
states, totals, archive visibility, OriginKeys, and Timeline events remain. No
validation step deletes persistent data.
