# Quickstart: Initial Proposal and Budget v1

All automated checks use the dedicated `testing` database. Do not run destructive
reset commands against the persistent development database.

## Automated focused checks

```bash
./vendor/bin/sail test tests/Unit/Domain/Proposals
./vendor/bin/sail test tests/Feature/Proposals
./vendor/bin/sail artisan about --only=environment,cache,drivers
```

Expected: inclusion, canonical payload, readiness, snapshot and all Proposal feature
tests pass; the application boots against MySQL.

## Canonical invariant evidence

`tests/Feature/Proposals/S6InvariantTest.php` maps the primary S6 invariants without
claiming later-slice behavior:

| Canonical invariant | Executable evidence |
|---|---|
| 28.17 Budget immutabile | Budget header, rows and evidence reject update/delete |
| 28.19 Proposta isolata | Draft Estimate action leaves live Expense and Lines unchanged |
| 28.20 Proposta solo sul piano | Recursive payload guard rejects Actual movement/reclassification keys |
| 28.21 Nuovi oggetti proposti | New Expense remains a ProposalItem with no live Expense ID before approval |
| 28.23 Approvazione atomica | Injected post-materialization failure rolls back live, Budget and Proposal status |
| 28.47 Snapshot autonome | Live rename/archive does not change the materialized Budget payload |
| 28.48 Schema Budget | Materialized detail excludes Actual, Residual, Variance, Closing and Forecast |

The broader S6 functional requirements are covered by the focused Unit and Feature
suites named in `tasks.md`: authorization and tenant isolation (S6-FR-001–002),
initialization and membership (003–010), typed planning (011–023), readiness
(024–030), atomic approval/idempotency (031–035), materialization and evidence
(036–046), Timeline (047–048), and explicit exclusions (049–050).

## Full local quality gate

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail composer format:test
./vendor/bin/sail composer analyse
./vendor/bin/sail composer test
git diff --check
```

Expected: no validation error, vulnerability, formatting issue, static-analysis
error, test failure or whitespace error.

## Authenticated vertical demonstration

Use the deterministic development administrator from local `.env` and select one
Company tenant. Prepare an Open Exercise with no Budget and qualifying autonomous
Expense, Project and Contract sources, including Actuals on at least one source.

1. Open the Exercise and initialize the Proposal.
2. Confirm that exact qualifying sources appear and prior-year autonomous Expenses
   and Actual Lines are not copied into editable plan values.
3. Prepare an Expense Estimate action, a Project planning action and a Contract
   planning action.
4. Copy one prior autonomous Expense and verify lineage/new Proposal identity.
5. Create one new Project and one new child Expense linked by ProposalItemID.
6. Add one Project–Contract `Collegato a` relation.
7. Run readiness and inspect affected Exercises, exact impacts and no blocks.
8. Approve with an `approva_budget` user and open Budget v1.
9. Verify the live plan changed only after approval and Actuals remained unchanged.
10. Change/rename/archive supported live objects and detach a live attachment.
11. Reopen Budget v1 and confirm header, rows, details, total and evidence are
    unchanged and still readable.

Also verify:

- a `visualizza`-only user can read but cannot mutate;
- a `gestisce_proposte` user cannot approve without `approva_budget`;
- a user from another Company receives no access;
- browser console errors and failed network requests are absent;
- no Revision, realignment-resolution, Riporto/Riprogrammazione, Closing, Forecast,
  structured source-replacement, delete or full report/export control is present.

## Atomicity and retry evidence

The automated approval suite injects failures before live apply, during new-object
resolution, and during snapshot/evidence/event materialization. Each case must leave
zero partial records. Repeating the successful approval operation UUID must return
the same Budget ID with unchanged counts for live objects, rows, evidence and events.

## Non-destructive persistence check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
curl -I http://127.0.0.1:9000/admin
```

After a normal restart the approved Proposal, Budget v1, materialized rows/details,
evidence and Timeline remain. No verification step deletes persistent data.

## Local verification evidence — 2026-08-21

- Forward-only development migrations `2026_08_21_000100` and
  `2026_08_21_000200` ran in batch 7; no reset or reseed was used.
- The focused S6 suite passed 80 tests/450 assertions before convergence. After the
  browser regressions, targeted runs passed 11 tests/127 assertions and 9 tests/78
  assertions.
- PHPStan reports no errors; Pint, Composer validation, Composer audit, Laravel boot
  and `git diff --check` pass.
- After correcting the obsolete S5 assertion and the browser-exposed regressions,
  the isolated complete Pest suite passed 402 tests/3012 assertions in 90.28
  seconds. The slice status is `verified`.
- Authenticated browser evidence created and approved Proposal #1/Budget #1, then
  Proposal #2/Budget #2 with a new Project, a ProposalItem-linked child Expense, a
  new Contract with an annual condition, and a `Collegato a` relation. Budget #2
  totals EUR 725.50.
- Renaming the live Project after approval did not alter Budget #2's materialized
  label or total. Browser console/page errors were empty and observed Livewire
  requests returned HTTP 200.
- A normal Sail stop/start preserved Proposal #1, Budget #1, four materialized rows
  and approval evidence; `/admin` returned the expected redirect and the stored
  Budget reopened successfully.
