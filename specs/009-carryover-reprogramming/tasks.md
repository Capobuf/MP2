# Tasks: Carryover and Reprogramming

**Input**: Design documents from `specs/009-carryover-reprogramming/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/ui.md`, `quickstart.md`

**Tests**: Required. Write focused tests for the behavior being implemented and run
the repository-wide heavy gate only in the final phase.

**Organization**: Tasks are grouped by independently testable behavior. Do not add
new abstractions merely because a task list names a responsibility separately.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can be worked independently because it does not touch the same production
  files or depend on an unfinished task.
- **[Story]**: Maps to a user story in `spec.md`.
- Every task names the expected file scope. Inspect current code before editing and
  narrow the scope when possible.

## Phase 1: Persistence and deterministic values

**Purpose**: Add the one live state record and exact canonical formulas before any
Proposal/UI behavior.

- [X] T001 [P] [US1] Add failing formula tests for canonical §8.4 including both
  negative-Actual cap cases in
  `tests/Unit/Domain/Projects/ProjectDeferralValuesTest.php`.
- [X] T002 [P] [US1] Add failing schema/model tests for one
  `Project + N + N+1` row, same-company/consecutive validation expectations, closed
  mode values, and no physical delete path in
  `tests/Feature/Projects/ProjectDeferralPersistenceTest.php`.
- [X] T003 [US1] Add
  `database/migrations/2026_08_23_000200_create_project_deferrals_table.php`,
  `database/migrations/2026_08_23_000210_add_revision_to_expense_lines_table.php`,
  `app/Models/ProjectDeferral.php`,
  `app/Domain/Projects/ProjectDeferralMode.php`, and
  `database/factories/ProjectDeferralFactory.php`; expose `ExpenseLine.revision`
  according to `data-model.md` without changing economic line semantics.
- [X] T004 [US1] Implement the small canonical residual/maximum helper in
  `app/Domain/Projects/ProjectDeferralValues.php` using existing decimal utilities.
- [X] T005 [US1] Extend `app/Models/Project.php` and `app/Models/Exercise.php` so
  received Carryover contributes exactly once to Project/Exercise allocation while
  Actual calculations remain unchanged.
- [X] T006 [US1] Add focused aggregate tests for Carryover received, no double count,
  Contract/standalone exclusion, and Exercise totals in
  `tests/Feature/Projects/ProjectCarryoverTotalsTest.php`; add focused line-version
  regression coverage proving `UpdateExpenseLine`, `SetExpenseLineActive`, and
  Proposal-applied Project Estimate mutation increment only the touched eligible
  `ExpenseLine.revision`, while creation starts at `0` and a no-op does not increment.
- [X] T007 Run T001/T002/T006 focused tests plus Laravel boot; do not run the full
  suite yet.

**Checkpoint**: S8 current-state persistence exists and §8.4/§8.6 values are correct
without Proposal behavior.

---

## Phase 2: Proposal vocabulary, snapshot and deterministic planning

**Purpose**: Make one typed Project deferral choice representable and replayable in a
Draft without touching live reality.

- [X] T008 [P] [US2] Add failing payload tests for the three mutually exclusive
  deferral modes, mandatory rinvio reason for both `Riporto` and
  `Riprogrammazione`, reason when replacing/removing an already-applied mode,
  captured/revalidated source-year context, explicit
  Reprogramming reductions/destination generation, `Nuova allocazione` Note
  requirement, and generic `CreateExpense` bypass rejection for an already-live
  Project in `tests/Feature/Proposals/PlanProjectDeferralTest.php`.
- [X] T009 [US2] Add `PlanProjectDeferral` and `CreateProjectAllocation` to
  `app/Domain/Proposals/ProposalActionType.php` and their closed validation shapes to
  `app/Domain/Proposals/ProposalActionPayload.php`.
- [X] T010 [US2] Extend `app/Domain/Proposals/ProposalSourceSnapshot.php` so the
  destination-year Project baseline/fingerprint includes incoming deferral facts and
  S8 can capture/revalidate a canonical source-year Project fingerprint/context;
  source Estimate/Actual changes and exact selected-line changes must be detectable
  before approval.
- [X] T011 [US2] Extend `app/Domain/Proposals/ProjectPlan.php` and
  `app/Domain/Proposals/ProposalActionReplay.php` to apply/replay one typed
  `PlanProjectDeferral` result without field merge or live writes.
- [X] T012 [US2] Implement locked idempotent Proposal planning and deterministic
  one-to-one destination preview in
  `app/Actions/Proposals/PlanProjectDeferral.php`; do not create live destination
  Expenses in Draft.
- [X] T013 [US2] Reuse the existing planned-Expense creation path for the distinct
  `CreateProjectAllocation` action with mandatory Note stored in the existing
  `ProposalAction.reason` field in `app/Actions/Proposals/PlanExpense.php` and the
  minimum related validation files; reject the generic `CreateExpense` backend path
  for a new Expense under an already-live Project so UI/API callers cannot bypass the
  declaration.
- [X] T014 [US2] Run focused planning/replay tests plus Laravel boot.

**Checkpoint**: A Draft can express exactly one S8 mode and independent new
allocation without any live economic mutation.

---

## Phase 3: Readiness and multi-Exercise impact

**Purpose**: Activate S8's already-defined inconsistency vocabulary and make every
annual effect visible before approval.

- [X] T015 [P] [US2] Add failing readiness tests for over-limit Carryover,
  Reprogramming above current availability, unbalanced Reprogramming, conflicting
  modes, terminal Project including a terminal state produced by a planned
  transition, destination Project state/reopen requirements, closed/non-consecutive
  Exercises, stale source, and valid later-Actual behavior in
  `tests/Feature/Proposals/ProjectDeferralReadinessTest.php`.
- [X] T016 [US2] Extend `app/Domain/Proposals/ProposalReadiness.php` and
  `app/Domain/Proposals/ProjectPlan.php` to activate existing
  `CarryoverAboveLimit`, `ReprogrammingAboveAvailable`,
  `ReprogrammingUnbalanced`, and `DeferralModesConflict` reasons without adding a
  catch-all S8 reason; preserve S7 `Da riallineare` precedence until the changed
  whole-source baseline is explicitly realigned.
- [X] T017 [US2] Extend `app/Domain/Proposals/ProposalImpactPlan.php` to calculate
  exact source/destination deltas for Carryover, Reprogramming, active
  Reprogramming reversal, and independent new allocation while preserving S7
  Open/Closed/Budget/Draft semantics.
- [X] T018 [US2] Ensure `app/Domain/Proposals/ProposalSourceCatalog.php` includes a
  Project when canonical automatic inclusion is satisfied by received Carryover;
  do not broaden unrelated source predicates.
- [X] T019 [US2] Run focused readiness/impact tests and existing S7 multi-Exercise
  regression tests.

**Checkpoint**: Every S8 Draft is either exactly valid/aligned or blocked by an
existing canonical reason, with exact annual impact.

---

## Phase 4: User Story 3 — Provisional Carryover approval

**Goal**: Apply Carryover as a distinct destination allocation component while source
Estimates remain unchanged.

**Independent Test**: Approve partial Carryover, retry, then lower the source maximum
and verify live warning and later Revision block.

- [X] T020 [P] [US3] Add Carryover approval, source-unchanged, destination allocation,
  invalid zero/over-limit, retry, rollback, and immutable-Budget tests in
  `tests/Feature/Proposals/ApproveProjectCarryoverTest.php`.
- [X] T021 [US3] Add `ProjectDeferralChanged` to
  `app/Domain/Company/AuditEventType.php` and extend the Proposal applied-event
  mapping only as needed in `app/Actions/Proposals/MaterializeBudgetSnapshot.php`.
- [X] T022 [US3] Implement the Carryover branch of
  `app/Actions/Proposals/ApplyProjectDeferral.php` and integrate its locks/revalidation
  into `app/Actions/Proposals/ApproveProposal.php` inside the existing top-level
  transaction.
- [X] T023 [US3] Add the canonical over-limit live provisional warning calculation to
  the Project current-value presentation path without auto-correction.
- [X] T024 [US3] Run focused Carryover approval tests plus relevant Proposal approval,
  Budget autonomy, and Project aggregate regressions.

**Checkpoint**: Provisional Carryover is live, distinct, idempotent, and never moves
source Estimates.

---

## Phase 5: User Story 4 — Reprogramming approval

**Goal**: Apply a balanced explicit source reduction and deterministic new
destination Estimates atomically.

**Independent Test**: Reprogram across multiple source Expense/Estimate lines, force
failures, retry, inspect resolved identities/lineage, and add later Actuals.

- [X] T025 [P] [US4] Add Reprogramming apply tests for partial/full source-line
  reduction, multi-Expense grouping, exact balance, the case where received Carryover
  makes canonical availability exceed actually reducible source Estimates,
  source-year fingerprint/revision staleness, new IDs, `CopiedFromOriginKey`,
  explicit optional supplier handling when the source supplier is Archived, no
  Actual copy, later Actual non-retroactivity, Proposal/Revision replacement of an
  already-live Reprogramming with `Nessuna`/`Riporto` via exact reversal, retry, and
  company/state rejection in
  `tests/Feature/Proposals/ApproveProjectReprogrammingTest.php`.
- [X] T026 [P] [US4] Add atomic failure tests covering failure after source
  reduction, after destination creation, and before final Budget completion in
  `tests/Feature/Proposals/ApproveProjectReprogrammingTest.php`, using existing test
  seams where possible rather than adding a new production checkpoint API.
- [X] T027 [US4] Complete
  `app/Actions/Proposals/ApplyProjectDeferral.php` as the shared S8 state-transition
  engine: exact reversal when an approved Proposal/Revision leaves an active
  Reprogramming, exact source-line reduction/annulment when entering
  Reprogramming, deterministic destination Expense/Estimate creation, current
  deferral update, resolved effect map, and proportional revision increments.
- [X] T028 [US4] Extend `app/Actions/Proposals/ApproveProposal.php` locking so every
  referenced source Estimate line/current deferral is revalidated before first S8
  write and the S8 apply stays inside the existing approval transaction.
- [X] T029 [US4] Ensure Proposal approval audit contains resolved Reprogramming
  effects and per-Exercise allocation deltas in
  `app/Actions/Proposals/MaterializeBudgetSnapshot.php` without creating a second
  audit ledger.
- [X] T030 [US4] Run focused Reprogramming tests plus S7 approval atomicity,
  idempotency, and multi-Exercise regressions.

**Checkpoint**: Reprogramming moves plan exactly once and preserves enough exact live
state for future canonical reversal.

---

## Phase 6: User Story 5 — Direct live mode change and exact reversal

**Goal**: Change a live mode before Closing without overwriting independent work.

**Independent Test**: Exercise every mode transition, independent-line modification
block, Draft realignment, retry, authorization, and immutable Budgets.

- [X] T031 [P] [US5] Add live mode-transition tests for
  `carryover -> none`, `carryover -> reprogramming`,
  `reprogramming -> none`, and `reprogramming -> carryover`, plus rejection of a
  direct new transfer from `none`, in
  `tests/Feature/Projects/ChangeProjectDeferralTest.php`.
- [X] T032 [P] [US5] Add exact reversal tests proving only recorded source lines are
  restored, only recorded destination Estimate lines are annulled, independent new
  allocation/Actuals are preserved, `reprogramming -> carryover` validates against
  the maximum recalculated after hypothetical source restoration with current
  Actuals, modify-then-restore of an involved line is still detected through its
  recorded line `revision`, and one independently changed involved line or independently
  reversed/moved involved parent Expense blocks the whole operation in
  `tests/Feature/Projects/ChangeProjectDeferralTest.php`.
- [X] T033 [P] [US5] Add authorization, tenant, Open/Closed, consecutive-year,
  required-reason, direct `Riporto -> Riprogrammazione` destination-state rejection
  when a reopen/open transition would be needed, stale-preview, idempotency, affected-
  Draft realignment, and live Project-transition rejection when the resulting
  source-year 31-December state becomes terminal while outgoing mode is non-`Nessuna`
  tests
  in `tests/Feature/Projects/ChangeProjectDeferralTest.php`.
- [X] T034 [US5] Implement side-effect-free preview and locked/idempotent confirm in
  `app/Actions/Operations/ChangeProjectDeferral.php`, reusing the same deterministic
  validation/destination generation rules as Proposal S8 without a generic operation
  framework.
- [X] T035 [US5] Reuse/extract the smallest existing S7 helper necessary to mark all
  affected Draft Project items `to_realign`; avoid duplicating broad Proposal
  invalidation logic across Actions. In the same phase, add the smallest shared
  terminal-deferral predicate to `CreateProjectTransition.php`,
  `ReplaceProjectTransition.php`, and `AnnulProjectTransition.php` so a live
  transition cannot leave a terminal-at-31-December Project with an outgoing
  non-`Nessuna` mode; do not auto-change the deferral.
- [X] T036 [US5] Run focused live-change tests plus Project policy/audit and S7
  realignment regressions.

**Checkpoint**: An active Reprogramming can be reversed exactly or blocked safely;
independent work and Budgets are untouched.

---

## Phase 7: User Story 6 — Budget, Timeline and UI

**Goal**: Make the S8 decision inspectable without exposing implementation JSON as
the primary interface.

**Independent Test**: Render all modes, new allocation, warning, Proposal previews,
Budget rows, direct mode change, and Timeline.

- [X] T037 [P] [US6] Add Budget row tests for Estimates vs Carryover,
  provisional state, allocation sum, Reprogrammed detail/lineage, None zeros, and
  no double counting in `tests/Feature/Proposals/ProjectDeferralBudgetTest.php`.
- [X] T038 [US6] Replace S7's Project deferral zero placeholders and use resolved live
  values in `app/Domain/Proposals/BudgetSnapshotPayload.php`; keep the plan-only
  payload guard valid and existing Budget rows immutable.
- [X] T039 [P] [US6] Add Proposal/Project Filament tests for exact mode options,
  formula labels, generated Reprogramming preview, terminal/zero-max disabled
  reasons, `Nuova allocazione`, canonical warning, and direct `Gestisci rinvio`
  action in `tests/Feature/Projects/ProjectDeferralUiTest.php` and the smallest
  relevant Proposal UI test file.
- [X] T040 [US6] Implement Project current-value/deferral presentation and live mode
  action in `app/Filament/Resources/Projects/Pages/ViewProject.php` and
  `app/Filament/Resources/Projects/Schemas/ProjectInfolist.php`.
- [X] T041 [US6] Implement Proposal `Rinvio` and `Nuova allocazione` controls, exact
  impact summaries, and canonical readiness messages in
  `app/Filament/Resources/Proposals/Pages/ViewProposal.php` and
  `app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php`.
- [X] T042 [US6] Expose approved deferral fields in the existing Budget infolist
  through `app/Filament/Resources/Budgets/Schemas/BudgetInfolist.php` and ensure
  Timeline rendering uses the new functional event label.
- [X] T043 [US6] Run focused Budget/UI tests plus Laravel boot and Vite build.

**Checkpoint**: Users can understand what moved, what did not, and why from normal MP2
screens.

---

## Phase 8: Canonical invariant and exclusion verification

**Purpose**: Prove all S8-owned invariants and keep S9–S11 out.

- [X] T044 [P] Add authoritative 28.11–28.16 coverage in
  `tests/Feature/Proposals/S8InvariantTest.php`, including the two exact
  negative-Actual examples, received-Carryover-vs-reducible-Estimates Reprogramming
  feasibility, Proposal terminal-state behavior, and ordinary live
  Project-transition terminal compatibility; add focused regression assertions
  for the earlier-slice rules listed under `Regression Obligations From Earlier
  Slices` in `spec.md` where S8 touched their code paths.
- [X] T045 [P] Add rejection coverage for split Carryover/Reprogramming, automatic
  maximum, non-Project Carryover, fuzzy reversal, Closed-year mutation, Budget
  rewrite, Actual copying/mutation, and premature Closing/consolidation behavior in
  `tests/Feature/Proposals/S8ExcludedBehaviorTest.php`.
- [X] T046 [P] Add/extend authorization and tenant isolation coverage for Proposal,
  approval, and direct live S8 paths in the existing relevant policy/feature test
  files.
- [X] T047 Run every focused S8 test plus the existing S7 Proposal/Revision/
  multi-Exercise regression group; fix only actual S8-caused failures.

**Checkpoint**: Primary FRs/invariants and MUST NOT rules have direct evidence.

---

## Phase 9: Final quality gate and delivery

**Purpose**: Verify repository-wide compatibility, real UI behavior, and traceability
only after implementation is complete.

- [X] T048 Update `specs/009-carryover-reprogramming/quickstart.md` with actual focused
  test evidence; do not record commands that were not run.
- [X] T049 Run the current repository-wide quality gate from CI, including Composer
  validation/audit, Vite build, Pint, PHPStan, full isolated Pest suite, and
  `git diff --check`; fix S8-caused failures.
- [X] T050 Perform the authenticated browser scenarios from `quickstart.md` for
  Carryover, warning, Reprogramming, exact reversal, independent new allocation,
  Budget, and Timeline; record actual evidence.
- [X] T051 Review the final diff against every S8-FR-001–S8-FR-040 and every S8 edge case;
  remove unnecessary fallback/defensive code and any abstraction not justified by
  the complexity budget.
- [X] T052 Only after passing evidence, update
  `specs/000-product-roadmap/traceability.md`,
  `specs/000-product-roadmap/invariant-test-map.md`,
  `specs/000-product-roadmap/roadmap.md`, and this spec status from planned/
  implemented to the highest status actually proven.

**Done when**:

- all tasks are checked;
- FR-059/060/061 are implemented and evidenced;
- invariants 28.11–28.16 have authoritative tests;
- no S9 behavior was introduced;
- full quality gate passed;
- authenticated UI path was inspected;
- S8 traceability reflects evidence rather than intent.
