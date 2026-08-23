# Quickstart and Verification: Carryover and Reprogramming

## Feature context

The package includes machine-local:

```json
{"feature_directory":"specs/009-carryover-reprogramming"}
```

as `.specify/feature.json`, so the current Spec Kit implementation command resolves
S8 after the ZIP is expanded at repository root.

If that local file is not used, set:

```bash
export SPECIFY_FEATURE_DIRECTORY=specs/009-carryover-reprogramming
```

before invoking the implementation skill.

## Pre-implementation sanity check

Confirm current repository still contains:

```text
specs/008-revisions-alignment-multiyear/
app/Domain/Proposals/ProposalImpactPlan.php
app/Domain/Proposals/ProposalReadiness.php
app/Domain/Proposals/BudgetSnapshotPayload.php
app/Actions/Proposals/ApproveProposal.php
app/Actions/Proposals/MaterializeBudgetSnapshot.php
app/Models/Project.php
app/Models/Exercise.php
```

Confirm the current canonical domain still states the reviewed S8 formulas and rules.
If the canonical file materially changed after SHA:

```text
122e8af31e98789940672a5c0e8ddbb84f2441c6
```

reconcile the spec before coding.

Do not re-run `/speckit.specify`, `/speckit.plan`, or `/speckit.tasks`; this package
already contains their outputs.

## Focused verification during implementation

Run the smallest relevant test file after each coherent implementation block.

Suggested focused groups:

```bash
php artisan test tests/Unit/Domain/Projects/ProjectDeferralValuesTest.php
php artisan test tests/Feature/Projects/ProjectDeferralPersistenceTest.php
php artisan test tests/Feature/Projects/ProjectCarryoverTotalsTest.php
php artisan test tests/Feature/Proposals/PlanProjectDeferralTest.php
php artisan test tests/Feature/Proposals/ApproveProjectCarryoverTest.php
php artisan test tests/Feature/Proposals/ApproveProjectReprogrammingTest.php
php artisan test tests/Feature/Projects/ChangeProjectDeferralTest.php
php artisan test tests/Feature/Proposals/ProjectDeferralReadinessTest.php
php artisan test tests/Feature/Proposals/ProjectDeferralBudgetTest.php
php artisan test tests/Feature/Proposals/S8InvariantTest.php
```

If tests are consolidated during implementation, update this file to the actual paths.

## Required scenario matrix

### A. Formula

1. Allocation 10,000 / Actual 6,000 -> residual 4,000 / max 4,000.
2. Allocation 10,000 / Actual -1,000 -> residual 11,000 / max 10,000.
3. Allocation 0 / Actual -1,000 -> residual 1,000 / max 0.
4. Allocation 4,000 / Actual 6,000 -> residual 0 / max 0.

### B. Carryover

1. Open N and N+1.
2. Continuing non-terminal Project.
3. Maximum 6,000.
4. Choose provisional Carryover 4,000.
5. Approve N+1 Proposal.
6. Verify:
   - source Estimates unchanged;
   - source allocation unchanged;
   - destination carryover 4,000;
   - destination allocation +4,000;
   - Budget row carries 4,000 / provisional;
   - existing source Budget unchanged;
   - retry duplicates nothing.
7. Add source Actual reducing current max below 4,000.
8. Verify:
   - live carryover stays 4,000;
   - warning is visible;
   - existing Budget unchanged;
   - later Revision cannot approve 4,000 unchanged.

### C. Reprogramming

1. Open N and N+1.
2. Source Project has at least two active Estimate lines.
3. Select explicit reductions totaling 4,000.
4. Verify preview:
   - source -4,000;
   - destination +4,000;
   - Carryover zero;
   - generated destination plans grouped by source Expense.
5. In a separate stale-data case, change a source-year Estimate or Actual after the
   Draft decision and verify the Proposal requires S7 realignment or becomes
   canonically inconsistent; apply nothing from the stale confirmation.
6. Approve a fresh aligned case.
7. Verify:
   - source lines reduced/annulled exactly;
   - destination Expense/line IDs are new;
   - `CopiedFromOriginKey` points to source Expense;
   - no Actual copied;
   - active `ProjectDeferral` stores exact resolved effects;
   - destination Budget estimates include new rows;
   - approved reprogrammed amount is descriptive only;
   - source Budget unchanged.
8. Retry approval and verify no duplicate effects.
9. Add later Actual and verify executed Reprogramming remains valid.
10. Start a Revision from that live state, replace the active Reprogramming with
    `Nessuna` or `Riporto`, approve, and verify the exact same persisted-ID reversal
    rules run atomically before the new state is applied.
9. Separate feasibility case: source allocation `6,000` consists of `5,000`
   received Carryover + `1,000` active Estimates, Actual `0`. Verify canonical
   availability displays `6,000`, but source reductions/selectable Reprogramming
   cannot exceed `1,000`; no received Carryover is modified.

### D. Mode reversal

1. Verify the direct live action cannot create a new transfer from `Nessuna`; that
   path remains Proposal/Revision.
2. Start from successful Reprogramming.
3. Add an independent `Nuova allocazione` in destination.
4. Change mode to `Nessuna`.
5. Verify:
   - exact source lines restored;
   - exact Reprogramming destination Estimate lines annulled;
   - independent new allocation unchanged;
   - Budgets unchanged;
   - affected Drafts to realign.
6. Repeat from Reprogramming and change to Carryover.
7. Verify same reversal plus positive provisional Carryover, with the Carryover
   maximum calculated after restoring the source allocation and using current source
   Actuals.

### E. Independent modification block

1. Execute Reprogramming.
2. Independently modify one involved source or destination Estimate line.
3. Attempt Reprogramming -> None/Carryover.
4. Verify:
   - operation blocked;
   - no line overwritten;
   - deferral state unchanged;
   - no partial event/economic effect.
5. In a separate case, modify an involved Estimate line and then restore its visible
   fields to their previous values through normal operations.
6. Verify reversal is still blocked because the line-specific revision changed.

### F. Terminal and closed states

Verify:

- Project `Chiuso` at source Dec 31 -> only `Nessuna`.
- Project `Cancellato` at source Dec 31 -> only `Nessuna`.
- with an already-live `Riporto` or `Riprogrammazione`, an ordinary Project
  transition that would make the source-year 31-December state terminal is rejected
  without changing the deferral; after explicitly setting the mode to `Nessuna`, the
  transition may proceed if all ordinary transition rules pass.
- a Proposal may plan terminal transition + `Nessuna` together and approve atomically.
- source Closed -> no S8 mode edit.
- destination Closed -> no S8 mode edit.
- non-consecutive Exercises -> reject.
- other-company Project/Exercise -> reject.

### G. Non-Project exclusion

Verify no Carryover path exists for:

- standalone Expense;
- Contract;
- Cost Center;
- Supplier.

### H. New allocation declaration

With existing Project in N+1:

1. create independent destination plan;
2. verify UI/action is `Nuova allocazione`;
3. Note required;
4. verify no `CopiedFromOriginKey` is invented;
5. verify later Reprogramming reversal leaves it unchanged.

## Atomic failure injection

Use the current repository's established transaction-failure testing style only where
needed. Do not add a new production failure-injection mechanism solely for S8 if the
same rollback can be proven by an exception from a mocked/collaborating apply step or
database constraint.

At minimum force failure:

- after source reductions but before destination creation;
- after destination creation but before deferral state/snapshot completion;
- during Proposal approval before Budget completion.

Every case must roll back all S8 live writes.

## Final automated gate

Use the current CI workflow as executable source of truth.

At package creation the expected gate includes, at minimum:

```bash
composer validate --strict
composer audit --locked --no-interaction
npm ci
npm run build
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
php artisan test
git diff --check
```

Run against the isolated testing database according to `docs/testing-policy.md`.

Do not run destructive reset commands against the persistent development database.

## Authenticated browser demonstration

Demonstrate non-destructively in the development environment:

1. Project current values show maximum transferable availability.
2. Proposal N+1 -> Project -> `Rinvio` -> partial Carryover.
3. Approval -> Budget shows separate provisional Carryover.
4. Source change -> canonical over-limit warning.
5. Separate scenario -> explicit Reprogramming line selection and generated
   destination preview.
6. Approval -> source/destination values and lineage visible.
7. Project -> `Gestisci rinvio` -> Reprogramming to None/Carryover exact reversal.
8. Independent new allocation survives.
9. Timeline explains the mode decision/change.
10. No browser console errors or failed Livewire requests.

Record actual evidence below during implementation; do not pre-mark anything as
passed.

## Evidence

### Focused tests

- `vendor/bin/sail pest` over the 12 S8 Unit/Feature test files listed above plus
  `ProjectDeferralUiTest.php` and `S8ExcludedBehaviorTest.php`: **56 passed, 301
  assertions**.
- The same implementation was then exercised by the complete repository suite:
  **521 passed, 3,771 assertions**.
- Coverage includes the four exact formula cases, mutual exclusion, tenant and
  authorization boundaries, Open/consecutive Exercises, Carryover approval and
  warning, balanced explicit-line Reprogramming, failure rollback checkpoints,
  retries, later Actuals, exact persisted-ID reversal, independent destination
  allocation, terminal state, immutable Budget rows, and S9-S11 exclusions.

### Full quality gate

- `composer validate --strict`: passed.
- `composer install --no-interaction --prefer-dist --no-progress`: lock file verified;
  nothing to install, update, or remove.
- `composer audit --locked --no-interaction`: passed with no vulnerability advisory.
- `npm ci --no-audit --no-fund` and `npm run build`: passed with Vite 8.2.2.
- Isolated testing migration: passed (`Nothing to migrate`).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress`: passed with no errors.
- `vendor/bin/sail pest`: passed, **521 tests / 3,771 assertions**.
- `/admin/login` smoke request on the running application: HTTP success.
- `git diff --check` for the tracked implementation/test diff: passed. The
  workspace-wide command reports only the pre-existing user-owned trailing blank
  line in `AGENTS.md`; that unrelated file was deliberately left untouched. The
  edited untracked S8 delivery files (`spec.md`, `tasks.md`, `quickstart.md`) were
  checked separately and contain no trailing whitespace.

### Browser

- Authenticated tenant-scoped demonstration completed in the persistent development
  environment with dedicated Projects and Exercises 2040-2043.
- Carryover: planned and approved a partial provisional value of EUR 4,000; Budget
  separated Estimates, Carryover state and Allocation. A later source change reduced
  the current maximum to EUR 1,000 while the live EUR 4,000 value remained unchanged
  and the exact over-limit warning became visible.
- Reprogramming: planned an explicit EUR 2,000 reduction from one source Estimate
  line and an independent EUR 600 `Nuova allocazione`; preview, approval, new
  destination identities and `CopiedFromOriginKey` lineage were visible. Budget
  showed approved Estimates/Allocation EUR 2,600 and descriptive Reprogramming EUR
  2,000.
- Direct `Riprogrammazione -> Nessuna`: preview reported one source line to restore
  and one generated destination line to annul. Confirmation restored source
  allocation to EUR 5,000, left only the independent EUR 600 destination allocation,
  and did not change the existing Budget values.
- The Project Timeline displayed `Rinvio progetto modificato`, both Exercises, and
  the supplied reason. Browser error and console collections were empty; no failed
  Livewire interaction was observed.
