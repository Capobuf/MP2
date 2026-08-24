# Quickstart: Correzioni post-Chiusura

All automated checks use the dedicated `testing` database. Never run destructive reset
commands against the persistent development database.

## Per-slice focused checks

Run only the tests named by the current implementation slice plus Laravel boot:

```bash
./vendor/bin/sail test <focused S10 test files>
./vendor/bin/sail artisan about --only=environment
```

Expected: the selected tests pass and the application remains bootable and
inspectable. Do not run the complete suite after every slice.

## Planned focused evidence

| Behavior | Primary test file |
|---|---|
| Late Actual correction, compatibility, authorization and rollback | `tests/Feature/LateCorrections/RecordLateCorrectionTest.php` |
| Closed-year immutability and current-knowledge calculation | `tests/Feature/LateCorrections/LateCorrectionImmutabilityTest.php` |
| Historical-error annotation and zero economic impact | `tests/Feature/LateCorrections/RecordHistoricalErrorAnnotationTest.php` |
| Filament journeys and unavailable controls | `tests/Feature/LateCorrections/LateCorrectionUiTest.php` |
| Canonical invariants 28.29–28.31 | `tests/Feature/LateCorrections/S10InvariantTest.php` |
| Explicit S11 and historical-rewrite exclusions | `tests/Feature/LateCorrections/S10ExcludedBehaviorTest.php` |

## Canonical invariant evidence

`tests/Feature/LateCorrections/S10InvariantTest.php` is the authoritative executable
map:

| Canonical invariant | Required evidence |
|---|---|
| 28.29 Correzioni tardive | Every amount correction appends a new Actual line and never edits an existing line |
| 28.30 Nessuna riclassificazione storica | Correction/annotation attempts leave Cost Center, Supplier, Project, Contract, container, Exercise and historical state unchanged |
| 28.31 Riporto storico invariato | Late corrections and annotations leave consolidated Carryover and Closing materialization unchanged |

## Authenticated vertical demonstration

Use the deterministic development administrator from local `.env` and select one
Company tenant with a Closed Exercise.

### Late correction slice

1. Open the Closed Exercise and its immutable Closing Snapshot.
2. Choose `Registra correzione tardiva`.
3. Select a compatible historical manual Expense, enter a positive Actual, reason and
   required declaration, then confirm.
4. Verify that one new Actual line and one correction record appear while the original
   line and Closing Snapshot remain unchanged.
5. Add a negative compensating correction referencing the original line and verify
   that both corrections remain independently visible.
6. Select an incompatible historical Expense and confirm creation of a new manual late
   Expense in the same owner context with no Estimate.
7. Verify that an Archived historical Supplier is available only in this correction
   context.

### Historical annotation slice

1. Choose `Annota errore storico` on the same Closed Exercise.
2. Record a Cost Center error with stored/correct facts, affected source and reason.
3. Verify `Nessun impatto economico`, immutable Closing/Budget/Carryover and readable
   evidence.
4. Repeat for Carryover or accidental Closing and verify that no future plan/state
   action is executed automatically.

Also verify:

- `visualizza` and `corregge_esercizio_chiuso` remain distinct;
- another Company cannot read or submit either action;
- Open Exercises expose neither action;
- persisted corrections/annotations expose no edit/delete/reclassify/reopen control;
- no report/export, Previsto/Non previsto, matching or arbitrary as-of control appears;
- browser console errors and failed Livewire requests are absent.

## Final repository quality gate

After all S10 slices and browser demonstrations are complete, run the current CI gate
locally through Sail where applicable:

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit --locked --no-interaction
npm ci --no-audit --no-fund
npm run build
./vendor/bin/sail vendor/bin/pint --test
./vendor/bin/sail vendor/bin/phpstan analyse --no-progress
./vendor/bin/sail vendor/bin/pest
./vendor/bin/sail artisan about --only=environment
```

Expected: dependency validation/audit, locked frontend build, formatting, static
analysis, complete isolated Pest suite and application boot all pass. Record actual
evidence only after commands and authenticated journeys have run.

## Persistence check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
curl -I http://127.0.0.1:9000/admin/login
```

After restart, corrections, annotations, retained evidence and the original immutable
Closing Snapshot remain readable. No verification step removes persistent data.