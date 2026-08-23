# Tasks: Revisions, Realignment, and Multi-Year Impact

**Input**: Design documents from
`specs/008-revisions-alignment-multiyear/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/ui.md`, `quickstart.md`

**Tests**: Required by the specification and constitution. Write focused tests first;
run the complete/heavy quality gate only in the final phase.

**Organization**: Tasks are grouped by independently testable user story and executed
one phase at a time. Every implementation phase stays at eight or fewer substantial
tasks.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files and has no unmet
  dependency.
- **[Story]**: Maps the task to a user story in `spec.md`.
- Every task names its exact file scope.

## Phase 1: Setup and Schema Extension

**Purpose**: Add the smallest forward-only persistence extension required by every
S7 story without editing the used S6 migration.

- [X] T001 Add failing schema/model persistence tests for Revision lineage, discard metadata, and one-way Proposal Action withdrawal in `tests/Feature/Proposals/RevisionPersistenceTest.php`.
- [X] T002 Create the forward-only S7 migration for Proposal/Budget purpose enums, `reference_budget_id`, discard fields, and Proposal Action withdrawal fields in `database/migrations/2026_08_23_000100_extend_proposals_for_revisions.php`.
- [X] T003 Update Proposal, ProposalAction, BudgetSnapshot, and related factories for the additive fields and active/history relations in `app/Models/Proposal.php`, `app/Models/ProposalAction.php`, `app/Models/BudgetSnapshot.php`, `database/factories/ProposalFactory.php`, `database/factories/ProposalActionFactory.php`, and `database/factories/BudgetSnapshotFactory.php`.
- [X] T004 Add closed enum values and labels for Revision purpose, action status, and realignment choices in `app/Domain/Proposals/ProposalPurpose.php`, `app/Domain/Proposals/ProposalActionStatus.php`, and `app/Domain/Proposals/ProposalRealignmentChoice.php`.
- [X] T005 Run `tests/Feature/Proposals/RevisionPersistenceTest.php`, migrate only the dedicated testing database, and verify Laravel boot without running the full suite.

**Checkpoint**: The application boots with the additive schema and existing S6 rows
retain their original meaning.

---

## Phase 2: Foundational Replay, Readiness, and Audit Vocabulary

**Purpose**: Provide shared deterministic mechanics used by realignment,
acknowledgement, Revision approval, and Closed-year evidence.

**⚠️ CRITICAL**: Complete before all user-story phases.

- [X] T006 Add failing unit tests for replaying active Expense, Project, Contract, and relation actions from a fresh whole-source baseline in `tests/Unit/Domain/Proposals/ProposalActionReplayTest.php`.
- [X] T007 Implement closed typed action replay and touching-action selection without field merge in `app/Domain/Proposals/ProposalActionReplay.php`.
- [X] T008 Add failing closed-list mapping tests for every canonical §12.14 inconsistency predicate in `tests/Unit/Domain/Proposals/ProposalReadinessReasonTest.php` and `tests/Unit/Domain/Proposals/ProposalReadinessTest.php`.
- [X] T009 Replace the S6 generic invalid-action placeholder with the complete stable inconsistency vocabulary and deterministic validation mapping in `app/Domain/Proposals/ProposalReadinessReason.php` and `app/Domain/Proposals/ProposalReadiness.php`.
- [X] T010 Extend typed append-only audit event names/labels for Revision creation, acknowledgement, three realignment choices, action withdrawal, discard, Budget Revision, and historical divergence in `app/Domain/Company/AuditEventType.php`.
- [X] T011 Run the focused replay/readiness tests and Laravel boot without running the full suite.

**Checkpoint**: Existing typed rules can be replayed from a new source baseline and
every domain inconsistency has a closed, testable reason.

---

## Phase 3: User Story 1 — Prepare a Budget Revision (Priority: P1) 🎯 MVP

**Goal**: Start one Revision Draft from current live reality in an Open Exercise with
an immutable latest-Budget comparison reference.

**Independent Test**: Approve v1, change live plan facts, initialize a Revision, and
verify current baseline, prior-Budget immutability, read-only Actuals, authorization,
tenant isolation and one-Draft concurrency.

### Tests for User Story 1

- [X] T012 [P] [US1] Add Revision initialization, latest-reference, no-clone, authorization, tenant, Open-Exercise, idempotency, and concurrent single-Draft tests in `tests/Feature/Proposals/InitializeRevisionTest.php`.
- [X] T013 [P] [US1] Add Exercise-to-Revision Filament journey and terminal/disabled-state tests in `tests/Feature/Proposals/RevisionInitializationUiTest.php`.

### Implementation for User Story 1

- [X] T014 [US1] Generalize Proposal initialization to choose initial or Revision purpose under Company/Exercise/Budget locks and capture the latest Budget reference in `app/Actions/Proposals/InitializeProposal.php`.
- [X] T015 [US1] Add Proposal/Budget reference relations and purpose-aware readiness that permits Budgets only for Revision Drafts in `app/Models/Proposal.php`, `app/Domain/Proposals/ProposalReadiness.php`, and `app/Models/Exercise.php`.
- [X] T016 [US1] Expose `Crea revisione`, purpose/reference labels, and current-vs-approved context through the existing Exercise and Proposal Filament files in `app/Filament/Resources/Exercises/Pages/ViewExercise.php`, `app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php`, and `app/Filament/Resources/Proposals/Tables/ProposalsTable.php`.
- [X] T017 [US1] Run the focused US1 tests plus Laravel boot without running the full suite.

**Checkpoint**: Revision creation is independently usable while all prior Budgets and
live Actuals remain unchanged.

---

## Phase 4: User Story 2 — Resolve a Changed Source (Priority: P1)

**Goal**: Resolve `Da riallineare` through reload, keep/replay, or manual retained
actions, atomically and without per-field merge.

**Independent Test**: Invalidate a whole source, exercise all three choices, force an
invalid replay and a mid-transaction failure, and verify baselines, active/history
actions, status, impacts, audit, rollback and retry.

### Tests for User Story 2

- [X] T018 [P] [US2] Add Action tests for all invalidating dimensions, three choices, required reason, selected-action manual review, stale revision, rollback and idempotency in `tests/Feature/Proposals/RealignProposalItemTest.php`.
- [X] T019 [P] [US2] Add Filament visibility, confirmation, action-history, exact-error and terminal-state tests in `tests/Feature/Proposals/ProposalRealignmentUiTest.php`.

### Implementation for User Story 2

- [X] T020 [US2] Permit only irreversible active-to-withdrawn Proposal Action updates and expose ordered active/history relations in `app/Models/ProposalAction.php`, `app/Models/Proposal.php`, and `app/Models/ProposalItem.php`.
- [X] T021 [US2] Implement locked idempotent whole-source reload/keep/manual resolution, typed replay, withdrawal, full baseline replacement, impact capture and audit in `app/Actions/Proposals/RealignProposalItem.php`.
- [X] T022 [US2] Ensure all planning, readiness, impact, apply, relation and snapshot paths consume active actions while history remains readable in `app/Actions/Proposals/`, `app/Domain/Proposals/`, and `app/Domain/Proposals/BudgetSnapshotPayload.php`.
- [X] T023 [US2] Add the three Italian realignment controls, previews, action history and source-wide status messages in `app/Filament/Resources/Proposals/Pages/ViewProposal.php` and `app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php`.
- [X] T024 [US2] Run the focused US2 tests plus Laravel boot without running the full suite.

**Checkpoint**: Every stale source requires and records one complete source-wide
choice; failed replay has no partial effect.

---

## Phase 5: User Story 3 — Acknowledge New Sources and Resolve Inconsistencies (Priority: P1)

**Goal**: Make every newly included source explicit and expose only canonical closed
inconsistency reasons until typed actions/data are corrected.

**Independent Test**: Insert a newly qualifying source and every currently
representable canonical inconsistency class, verify the complete closed vocabulary,
and test acknowledgement with/without an Estimate plan, exact reasons, no Actual
mutation, no generic suppression, rollback and retry.

### Tests for User Story 3

- [X] T025 [P] [US3] Add new-source acknowledgement, prepared-plan preservation, Actual isolation, stale submission, rollback and idempotency tests in `tests/Feature/Proposals/AcknowledgeProposalSourceTest.php`.
- [X] T026 [P] [US3] Add end-to-end tests for currently representable closed-list inconsistencies, vocabulary coverage for deferred S8 predicates, and UI message/control tests in `tests/Feature/Proposals/ProposalInconsistencyTest.php` and `tests/Feature/Proposals/ProposalAcknowledgementUiTest.php`.

### Implementation for User Story 3

- [X] T027 [US3] Implement locked idempotent `to_review` acknowledgement with fresh whole-source capture, active typed-action replay, no economic acknowledgement action, and audit in `app/Actions/Proposals/AcknowledgeProposalSource.php`.
- [X] T028 [US3] Preserve multiple exact inconsistency reasons across item/proposal review and remove all S6-only generic/S7-boundary messages in `app/Actions/Proposals/ReviewProposalReadiness.php`, `app/Domain/Proposals/ProposalReadiness.php`, and `app/Domain/Proposals/ProposalReadinessReason.php`.
- [X] T029 [US3] Add `Prendi visione`, plan-before-acknowledgement guidance, exact inconsistency explanations and no-suppress behavior in `app/Filament/Resources/Proposals/Pages/ViewProposal.php` and `app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php`.
- [X] T030 [US3] Run the focused US3 tests plus Laravel boot without running the full suite.

**Checkpoint**: No new source can be silently accepted and no inconsistency uses an
invented or generic category.

---

## Phase 6: User Story 4 — Apply Supported Multi-Exercise Decisions Atomically (Priority: P1)

**Goal**: Apply only supported Open-Exercise effects atomically, preserve Closed
history, record divergences, stale competing Drafts, and canonical copy lineage.

**Independent Test**: Use two Open Exercises plus a Closed year, force stale locks and
application failures, and verify impact preview, rollback, unchanged Budgets/history,
divergence audit, stale Drafts, and copy from a Closed source.

### Tests for User Story 4

- [X] T031 [P] [US4] Add deterministic Open-impact/Closed-divergence, unchanged-Budget, stale-Draft, rollback and retry tests in `tests/Feature/Proposals/ProposalMultiExerciseImpactTest.php`.
- [X] T032 [P] [US4] Add copy-from-Open/Closed source identity, lineage, Estimate-only, source-revalidation and source-immutability tests in `tests/Feature/Proposals/CopyExpenseAcrossExercisesTest.php`.

### Implementation for User Story 4

- [X] T033 [US4] Separate writable Open impacts from read-only Closed divergences and expose annual before/after/state/Budget/Draft evidence in `app/Domain/Proposals/ProposalImpactPlan.php`.
- [X] T034 [US4] Revalidate and apply only Open supported effects, mark competing Draft Items stale, and append one idempotent historical-divergence event per Closed Exercise in `app/Actions/Proposals/ApproveProposal.php`, `app/Actions/Proposals/ApplyContractPlan.php`, and `app/Actions/Proposals/MaterializeBudgetSnapshot.php`.
- [X] T035 [US4] Permit canonical copy from a Closed source Exercise while revalidating the autonomous active source and applying only to the Open destination in `app/Actions/Proposals/CopyExpenseIntoProposal.php` and `app/Domain/Proposals/ExpensePlan.php`.
- [X] T036 [US4] Label Open effects, Closed immutable divergences, unchanged Budgets and stale Drafts in `app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php` and approval/realignment summaries in `app/Filament/Resources/Proposals/Pages/ViewProposal.php`.
- [X] T037 [US4] Run the focused US4 tests plus Laravel boot without running the full suite.

**Checkpoint**: A multi-year Proposal operation is all-or-nothing for Open years and
cannot rewrite a Closed year or existing Budget.

---

## Phase 7: User Story 5 — Approve, Retry, or Discard Safely (Priority: P1)

**Goal**: Materialize exactly one next immutable Budget version or discard the Draft
without rollback of reality.

**Independent Test**: Approve/retry v2+, race the predecessor/version, inject failure,
discard/retry a separate Draft after live changes, and verify terminal immutability,
authorization and no duplicated/rolled-back effects.

### Tests for User Story 5

- [X] T038 [P] [US5] Add Revision version lineage, mandatory reason, latest-predecessor race, rollback, retry, immutable prior-version and tenant/authorization tests in `tests/Feature/Proposals/ApproveRevisionTest.php`.
- [X] T039 [P] [US5] Add discard reason, no-live-rollback, idempotency, authorization, tenant and terminal immutability tests in `tests/Feature/Proposals/DiscardProposalTest.php`.
- [X] T040 [P] [US5] Add Revision approval/discard Filament journey and Budget vN+1 predecessor display tests in `tests/Feature/Proposals/RevisionApprovalUiTest.php` and `tests/Feature/Proposals/DiscardProposalUiTest.php`.

### Implementation for User Story 5

- [X] T041 [US5] Generalize Budget materialization from v1 to locked `vN+1`, Revision purpose/predecessor/reason, existing autonomous row contracts, evidence and retry in `app/Actions/Proposals/MaterializeBudgetSnapshot.php`, replacing `app/Actions/Proposals/MaterializeBudgetV1.php` references without changing payload semantics.
- [X] T042 [US5] Add latest-Budget locks/rechecks, mandatory Revision reason, next-version approval and terminal retry behavior in `app/Actions/Proposals/ApproveProposal.php`.
- [X] T043 [US5] Implement locked idempotent reason-required discard with immutable content and one audit event in `app/Actions/Proposals/DiscardProposal.php` and enforce policy/model terminal rules in `app/Policies/ProposalPolicy.php` and `app/Models/Proposal.php`.
- [X] T044 [US5] Add purpose-aware approval label/summary, required Revision reason, discard confirmation, terminal history, and Budget predecessor display in `app/Filament/Resources/Proposals/Pages/ViewProposal.php`, `app/Filament/Resources/Budgets/Schemas/BudgetInfolist.php`, and `app/Filament/Resources/Budgets/Tables/BudgetsTable.php`.
- [X] T045 [US5] Run the focused US5 tests plus Laravel boot without running the full suite.

**Checkpoint**: Revision approval and discard are independently complete,
idempotent, authorized, atomic and terminal.

---

## Phase 8: Cross-Cutting Verification and Delivery

**Purpose**: Prove S7 invariants, exclusions, UI inspectability and repository-wide
compatibility, then update traceability only from passing evidence.

- [X] T046 [P] Add authoritative invariant 28.18, 28.22 and 28.55 tests in `tests/Feature/Proposals/S7InvariantTest.php`.
- [X] T047 [P] Add S7 authorization/tenant and explicit S8–S11, Forecast, `Sostituisce`, field-merge, physical-delete and generic-inconsistency rejection coverage in `tests/Feature/Proposals/S7ExcludedBehaviorTest.php` and `tests/Feature/Proposals/ProposalAuthorizationTest.php`.
- [X] T048 Run all focused S7 tests and Laravel boot, fix regressions within S7 scope, and record focused evidence in `specs/008-revisions-alignment-multiyear/quickstart.md`.
- [X] T049 Run the final heavy gate from `quickstart.md`: Composer validation/audit, Pint, PHPStan, complete isolated Pest suite, and `git diff --check`; fix only S7-caused failures.
- [X] T050 Perform the authenticated browser Revision-to-vN+1, three realignment choices, acknowledgement, Closed divergence, copy-from-Closed, discard and terminal-readonly demonstration; record non-destructive evidence in `specs/008-revisions-alignment-multiyear/quickstart.md`.
- [X] T051 Update S7 canonical FR and invariant statuses, roadmap status, and slice status only to the evidence-supported level in `specs/000-product-roadmap/traceability.md`, `specs/000-product-roadmap/invariant-test-map.md`, `specs/000-product-roadmap/roadmap.md`, and `specs/008-revisions-alignment-multiyear/spec.md`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1** has no dependency and provides the additive schema.
- **Phase 2** depends on Phase 1 and blocks every user story.
- **US1 / Phase 3** depends on Phase 2 and establishes Revision Drafts.
- **US2 / Phase 4** depends on Phase 2; its UI integrates with US1 Proposal purpose.
- **US3 / Phase 5** depends on Phase 2 and uses the replay mechanics from US2.
- **US4 / Phase 6** depends on US1–US3 readiness and resolution behavior.
- **US5 / Phase 7** depends on US1–US4 because approval consumes their final impact.
- **Phase 8** depends on every selected user story.

### User Story Dependencies

- **US1** is independently demonstrable after the foundation: create a Revision
  without approving it.
- **US2** is independently demonstrable on any stale Draft source.
- **US3** is independently demonstrable on a new/inconsistent Draft source.
- **US4** is independently demonstrable through impact and atomic open-year apply,
  but final approval integration follows US1–US3.
- **US5** is the terminal integration of all prior stories.

### Within Each User Story

- Write the named focused tests first and confirm the relevant new assertions fail.
- Implement domain/model behavior before Filament controls.
- Run only the phase's focused tests and Laravel boot at the checkpoint.
- Do not run the complete suite until Phase 8.

### Parallel Opportunities

- Test files marked `[P]` in the same phase touch independent files.
- US1 Action tests and UI tests can be authored in parallel.
- US2 Action tests and UI tests can be authored in parallel.
- US3 Action tests and UI/inconsistency tests can be authored in parallel.
- US4 impact tests and copy tests can be authored in parallel.
- US5 approval, discard and UI tests can be authored in parallel.
- Phase 8 invariant and exclusion tests can be authored in parallel.

## Implementation Strategy

### MVP First

1. Complete Phase 1 schema.
2. Complete Phase 2 replay/readiness foundation.
3. Complete US1 Revision creation.
4. Validate US1 independently with focused tests and Laravel boot.

### Incremental Delivery

1. Add whole-source realignment.
2. Add new-source acknowledgement and exact inconsistencies.
3. Add Open/Closed multi-Exercise impact and canonical copy.
4. Add vN+1 approval and discard.
5. Run the complete heavy delivery gate once.

## Notes

- No task modifies `/vendor/**`, `/node_modules/**`, installed plugin source, or a
  used migration.
- No task adds a package, generic repository/service layer, queue, cache, frontend
  framework, field-level merge, or generic multi-year rules engine.
- Existing dirty UI work outside these exact files remains user-owned and must be
  preserved.
