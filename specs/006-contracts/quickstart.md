# Quickstart: Validate Contracts

## Prerequisites

- Verified S4 stack is running on port 9000.
- Administrator has `visualizza`, `modifica_operativita`, and
  `gestisce_anagrafiche` in the validation Company.
- Active and archived Supplier/Cost Center examples and several open past/current/
  future Exercises exist.
- Automated tests use only `APP_ENV=testing` and `DB_DATABASE=testing`.
- The private Laravel `local` disk is writable; automated attachment tests use
  `Storage::fake('local')`.

## Apply S5 forward changes

```bash
./vendor/bin/sail artisan migrate
```

Do not use `migrate:fresh`, truncate, reset persistent data, remove volumes, or edit
already-applied migrations.

## Focused automated validation

```bash
./vendor/bin/sail artisan test tests/Unit/Domain/Contracts tests/Feature/Contracts
./vendor/bin/sail artisan test tests/Feature/Expenses tests/Feature/Projects
```

The focused suite must cover anchored dates, annual composition, state at date,
renewal history/projection, system Estimate identity, ownership XOR, classification,
links, attachments, tenancy, authorization, stale previews, retries, and rollback.

## Validate independent renewal processing

```bash
./vendor/bin/sail artisan contracts:process-renewals
./vendor/bin/sail artisan contracts:process-renewals
```

The first run materializes every elapsed renewal once in chronological order and
advances to the first future expiry. The second run creates no duplicate facts,
Estimates, or Timeline events. No deadlines page is opened during this check.

## Demonstrate the vertical flow

1. Sign in at `http://127.0.0.1:9000/admin` and select the validation Company.
2. Create one currently Active Contract and one future Planned Contract with active
   Suppliers, an explicit effective date for each initial renewal configuration,
   initial renewal terms, first conditions, and annual classifications. Verify one
   stable identity each and no partial record on invalid input.
3. Inspect state before/on the start date and in past/current/future Exercise views.
   Verify company-local reference dates and future facts are displayed separately.
   Register another existing Contract whose real start and elapsed renewal anchors
   predate its census and cross a closed Exercise. Verify its current state,
   deadlines, renewals, and Timeline census are correct, only open Exercises receive
   generated values, and no value in the closed Exercise changes. Approved Budget
   and Closing Snapshot immutability remains a forward compatibility rule for the
   later slices that introduce those records.
4. Create conditions anchored on 28/29/30/31 for monthly, quarterly, semiannual, and
   annual cycles with start/end attribution. Verify exact composition, year boundary,
   full-cycle amounts, and no prorata.
5. Verify one stable system Estimate Expense/Line per Contract/Exercise. Recalculate
   a materialized Estimate to zero and confirm identity remains; confirm no row is
   created for a never-materialized zero.
6. Attempt manual create/edit/move/reverse/Actual actions on a generated Estimate and
   manual Estimate Lines in a Contract. Verify every path is unavailable or rejected.
7. Plan cessation, annul/replace a future fact, reactivate with a new condition, and
   cancel a never-active Contract. Verify the state sequence, required Notes, inactive
   interval, and unchanged historical facts.
8. Process multiple elapsed automatic renewals and a non-renewed expiry. Verify one
   fact/event per expiry, historical configuration selection, projected future state,
   and `Rinnovo senza condizione economica` without invented allocation.
9. Modify renewal, expiry, duration, and notice after preview. Verify elapsed
   unmaterialized expiries are processed first and stale confirmation rolls back.
10. Request a real economic change. Verify requested/minimum/effective dates, delay
    reason, `Prorata applicato: no`, per-year impact, explicit date confirmation, and
    block when no boundary exists before cessation/non-renewed expiry.
11. Correct a declared material input error across open Exercises. Verify complete
    before/after audit and rollback if any affected Exercise is no longer open.
12. Add an ordinary Actual to Active; verify Planned rejects it. Add declared late,
    cessation, reimbursement, and corrective Actuals to terminal Contracts with Note
    and verify no reactivation or cycle matching.
13. Move a stable manual Expense through autonomous → Project → Contract → another
    Contract → Project → autonomous. Verify IDs, XOR ownership, Supplier warning/
    retention, nullable inherited/direct classification, exact totals once, and
    atomic rejection of Estimate-bearing entry to Contract.
14. Reclassify one Contract Exercise after preview. Verify all generated allocation
    and Actuals for that year move together while another Exercise is unchanged.
15. Create a new Exercise. Verify latest Contract classifications are seeded,
    including null/archived continuity, without creating values or Expenses solely
    because the year exists.
16. Use `Scadenze contratti` filters for expiry, notice limit, renewal state,
    undefined expiry, lifecycle, Supplier, and Cost Center. Verify exact day counts,
    informational labels, and absence of invoice/payment/reminder claims.
17. Create, archive, and restore a `Collegato a` Project-Contract link. Verify
    duplicate active link rejection and zero economic/state/ownership impact. Verify
    no structured source-replacement control exists.
18. Upload and download one attachment for a Contract, Expense, and Line. Verify
    private authorized access, checksum/version metadata, cross-tenant rejection,
    logical detachment, retained blob/row, and new identity on replacement.
19. Cess/cancel then archive a Contract. Verify Planned/Active archive is unavailable,
    restore preserves all identities, values, deadlines, conditions, links,
    attachments, and history.
20. Inspect filtered Contract and Company Timeline. Verify ordered event sequences,
    one event per elapsed renewal, exact annual impacts, required reasons, immutable
    references, and history after Expense movement.
21. As a viewer, verify every page is read-only. Guess another tenant's Contract and
    attachment URLs and verify no disclosure.
22. Verify absence of prorata, matching, invoice/payment, reminder, variable/tiered
    conditions, carryover, reprogramming, Proposal, Budget, Revision, Closing,
    closed-year correction, Forecast, full reporting, and non-canonical relation controls.

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

## Persistence and private evidence check

Record Contract IDs, generated Expense/Line IDs, renewal fact IDs, attachment IDs,
and private storage checksums, then restart normally:

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
```

After restart, Contract state/history, renewal configurations, next expiry,
conditions, classifications, generated Estimate identities, manual Actuals, links,
attachment downloads/checksums, OriginKeys, and Timeline events remain unchanged. No
validation step deletes persistent data or private evidence.

## Execution evidence — 21 August 2026

The S5 implementation run completed the non-destructive journeys above with this
evidence:

- `artisan test tests/Unit/Domain/Contracts tests/Feature/Contracts`: 109 tests,
  746 assertions; this includes private Contract/Expense/Line attachment upload,
  authorized download, logical detachment, replacement identity, checksum, retry,
  and storage/transaction rollback.
- `artisan test tests/Feature/Expenses tests/Feature/Projects`: 98 tests,
  980 assertions, including three-owner Expense movement and prior-slice
  regressions.
- Final `composer test`: 317 tests, 2546 assertions. `composer format:test`,
  `composer analyse`, `composer validate --strict`, and `composer audit` all passed.
- The convergence regression group passed 18 tests and 119 assertions. It proves
  that Expense/Line attachment events remain in the Contract Timeline after an
  ownership move and that archived Contracts reject renewal, link-state, and
  attachment-detachment activity until restored.
- The final authorization convergence regression proves that renewal-operation
  retries recheck exact-company `modifica_operativita` before resolving an
  idempotency receipt.
- `contracts:process-renewals` completed twice without opening the deadlines page.
  The persistent validation database contained no Contracts, so both executions
  were intentional no-op runs and created no facts or events; chronological
  materialization, retry idempotency, and per-Contract isolation are demonstrated by
  `ProcessContractRenewalsTest` on the dedicated `testing` database.
- An authenticated browser session selected Company `DOT` and loaded `/admin/1`,
  `/admin/1/contracts`, `/admin/1/contract-deadlines`, and
  `/admin/1/company-audit`. Contract and deadline empty states, deadline columns,
  Timeline detail controls, and the absence of browser-console errors were checked.
  Mutating vertical-flow steps were not run against the persistent database; their
  complete behavior and negative paths were exercised on `testing` by the focused
  suites above.
- Before the normal Sail restart the persistent snapshot was: zero Contracts,
  generated Contract Expenses/Lines, renewal facts, Project links, and attachments;
  maximum AuditEvent ID 84 and maximum event sequence 0. After `sail stop` followed
  by `sail up -d`, the snapshot was identical and Laravel booted normally.
- After restart, `/admin` returned 302 to `/admin/login`, `/admin/login` returned
  200, and the login page loaded in a fresh browser session without errors. The
  network entry point `http://10.0.0.30:9000/admin` also returned the expected 302
  to its login page.
