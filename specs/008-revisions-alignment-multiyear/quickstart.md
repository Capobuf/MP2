# Quickstart: Revisions, Realignment, and Multi-Year Impact

All automated checks use the dedicated `testing` database. Never run destructive
reset commands against the persistent development database.

## Per-phase focused checks

Run only the tests named by the current implementation phase plus Laravel boot:

```bash
./vendor/bin/sail test <focused S7 test files>
./vendor/bin/sail artisan about --only=environment,cache,drivers
```

Expected: the current phase's tests pass and the application remains inspectable.
The complete suite and slower checks stay at the final gate.

## Canonical invariant evidence

`tests/Feature/Proposals/S7InvariantTest.php` is the primary executable map:

| Canonical invariant | Executable evidence |
|---|---|
| 28.18 Revisioni | An authorized user creates and approves vN+1 in every Open Exercise while earlier versions remain immutable |
| 28.22 Riallineamento per sorgente | Every invalidating source change requires one whole-source choice and never performs field-level merge |
| 28.55 Copia fra Esercizi | Copy from an Open or Closed source creates a new identity, preserves lineage and copies zero Actuals |

Focused feature suites additionally cover S7 authorization/tenant isolation,
closed-list inconsistency reasons, new-source acknowledgement, multi-Exercise
rollback, Closed divergence, discard, version races and retry receipts.

### Focused evidence — 2026-08-23

- All 20 focused S7 test files passed: **58 tests, 233 assertions**.
- `./vendor/bin/sail artisan about --only=environment` completed successfully on
  Laravel 13.25.0 and PHP 8.3.33; maintenance mode was off.
- The post-implementation convergence check found no unimplemented S7 requirement
  and appended no corrective task.

## Final local quality gate

Run the heavier checks only after all implementation phases are complete:

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
npm ci --no-audit --no-fund
npm run build
./vendor/bin/sail composer format:test
./vendor/bin/sail composer analyse
./vendor/bin/sail composer test
git diff --check
```

Expected: no validation, audit, frontend-build, formatting, static-analysis, test,
or whitespace failure.

### Final gate evidence — 2026-08-23

- Composer validation and audit passed; no security advisories were reported.
- Locked frontend dependency installation and the Vite production build passed.
- Pint passed and `git diff --check` reported no whitespace error.
- PHPStan passed repository-wide after the five findings in
  `app/Actions/Operations/CreateContract.php` and
  `app/Filament/Resources/Contracts/Schemas/ContractForm.php` were resolved during
  the subsequent CI repair.
- The complete isolated Pest suite passed: **465 tests, 3,454 assertions**.

## Authenticated vertical demonstration

Use the deterministic development administrator from local `.env` and select one
Company tenant.

1. Open an Open Exercise with Budget v1 and create a Revision.
2. Verify that current live reality is the baseline and v1 is comparison-only.
3. Change a live source, review the Draft, and confirm the entire Item becomes `Da
   riallineare`.
4. Exercise `Ricarica realtà`, then create another stale case and exercise `Mantieni
   proposta` with a reason.
5. Create a third stale case and use `Rivedi manualmente` to retain one decision and
   withdraw another.
6. Add a newly qualifying live source, review, and explicitly take notice after
   optionally preparing a supported Estimate change.
7. Prepare a Contract/future-state change spanning two Open Exercises; inspect every
   applied impact, unchanged Budget and stale Draft.
8. Include a Closed historical year in the calculation and verify `Storico invariato`
   plus the divergence explanation.
9. Approve with a reason and open Budget v2. Confirm v1 is unchanged and v2 links to
   v1.
10. Retry the approval operation and confirm no duplicate version, live change or
    event appears.
11. Create another Draft, perform a live operation outside it, then discard the Draft
    and confirm the live operation remains.
12. Copy an autonomous Expense from a Closed source Exercise and verify new identity,
    lineage, Estimate-only copy and unchanged source.

Also verify:

- `visualizza`, `gestisce_proposte`, and `approva_budget` remain distinct;
- another Company cannot read or submit any S7 action;
- terminal Proposals expose no mutations;
- no S8–S11, Forecast, `Sostituisce`, delete or field-merge control appears;
- browser console errors and failed Livewire requests are absent.

### Authenticated browser evidence — 2026-08-23

The incremental migration was applied to the persistent development database and
the deterministic development administrator was used against the non-destructive
tenant `S7 Browser Demo 2026-08-23`.

- Created a Revision from Budget v1 and observed the explicit v2 approval target.
- Triggered three independent live-source changes and completed `Ricarica realtà`,
  `Mantieni proposta` with a reason, and `Rivedi manualmente`.
- Added a newly qualifying Expense, recalculated readiness, and completed `Prendi
  visione` without an economic action.
- Copied `Spesa storica copiabile S7` from a Closed Exercise; the materialized copy
  had a distinct identity, one Estimate, zero Actuals, while the source retained its
  Actual.
- Inspected the multi-year Contract Revision and observed `Storico invariato` for
  its Closed-year divergence.
- Approved the main Revision into immutable Budget v2; v2 references v1 and both
  versions remain present.
- Discarded a separate Revision with a reason and verified that all mutation controls
  disappeared while its history remained readable.
- The page remained non-blank, no error overlay or console error was present, and
  every observed Livewire request returned HTTP 200.

## Persistence check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
curl -I http://127.0.0.1:9000/admin
```

After restart, v1/v2, realignment/action history, divergence evidence and Discarded
Proposal remain readable. No verification step removes persistent data.
