# Tasks: Projects

**Input**: Design documents from `/specs/005-projects/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/ui.md`

**Tests**: Mandatory. Write behavior-focused rejection, rollback, idempotency,
tenancy, authorization, exact-calculation, and UI tests before each implementation
increment. Run them only through the dedicated `testing` database.

**Organization**: Tasks are grouped by independently demonstrable user story. Every
phase contains no more than eight implementation tasks and ends with a
non-destructive checkpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: May be implemented in parallel because it touches different files and has
  no unfinished dependency.
- **[Story]**: Maps the task to one of the six S4 user stories.
- Every task names the concrete files or directories it changes.

## Phase 1: Setup and S3 reconciliation

**Purpose**: Preserve the verified S3 baseline and make the bounded S4 traceability
state explicit before implementation.

- [X] T001 Reconcile the verified S3 evidence and S4 active boundary in `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, `specs/000-product-roadmap/invariant-test-map.md`, `specs/004-exercises-expenses/spec.md`, and `specs/004-exercises-expenses/tasks.md`, leaving shared Contract cases planned.
- [X] T002 Confirm the S4 specification, research, model, UI contract, and checklist contain no unresolved placeholders or category-E gap in `specs/005-projects/` and correct documentation-only inconsistencies.
- [X] T003 Run the unchanged S3 full test gate, application boot, and non-mutating `/admin` smoke check; record only reproducible S4 prerequisite corrections in `specs/005-projects/quickstart.md`.

**Checkpoint**: S3 remains green and inspectable; S4 has no scope, dependency, or
traceability ambiguity.

---

## Phase 2: Foundational Project domain and persistence

**Purpose**: Establish tenant-safe forward schema, deterministic state/overspend
rules, shared models, authorization, and audit vocabulary before any story UI.

**⚠️ CRITICAL**: No user-story implementation begins until this phase passes.

- [X] T004 [P] Add failing schema, same-company constraint, relationship, generated active-date uniqueness, ownership-XOR, and deletion-rejection tests in `tests/Feature/Projects/ProjectPersistenceTest.php`.
- [X] T005 Add forward-only migrations under `database/migrations/` for `projects`, `project_transitions`, `project_exercise_classifications`, and nullable `expenses.project_id` exactly as defined in `specs/005-projects/data-model.md`; do not edit applied migrations.
- [X] T006 [P] Implement `Project`, `ProjectTransition`, and `ProjectExerciseClassification` models and factories in `app/Models/` and `database/factories/`, plus Project/Exercise/Expense Company relationships and physical-delete rejection.
- [X] T007 [P] Add failing-first unit coverage and closed Project state/transition-status/Actual-kind/overspend definitions plus pure state-at-date, annual-reference-date, sequence validation, and overspend decision code in `app/Domain/Projects/` and `tests/Unit/Domain/Projects/`.
- [X] T008 [P] Add `ProjectPolicy`, `ProjectTransitionPolicy`, and `ProjectExerciseClassificationPolicy` in `app/Policies/`, mapping reads and operational mutations to existing exact-company capabilities while denying physical deletion, with coverage in `tests/Feature/Projects/ProjectAuthorizationTest.php`.
- [X] T009 Extend `app/Domain/Company/AuditEventType.php`, `app/Models/AuditEvent.php`, and focused Project snapshot/impact builders in `app/Domain/Projects/` with Project references and overspend payloads while preserving unique `operation_id`, covered by `tests/Unit/Domain/Projects/ProjectAuditSnapshotTest.php`.
- [X] T010 Run Phase 2 tests through the `testing` database, `php artisan about`, and a non-mutating `/admin` smoke check; fix only Phase 2 regressions in the files above.

**Checkpoint**: Project facts, calculations, ownership integrity, tenant isolation,
authorization, and audit primitives are usable without future-slice concepts.

---

## Phase 3: User Story 1 — Create and inspect Projects (Priority: P1) 🎯 MVP

**Goal**: An authorized operator can create a stable Project with an initial dated
state and annual classification, then inspect its current and annual zero-value view.

**Independent Test**: Create Planned/Open Projects with past/current/future dates and
optional classification; verify `Assente alla data`, zero values, stable OriginKey,
idempotency, read-only tenancy, and no Expense creation.

- [X] T011 [P] [US1] Add failing creation, normalization, open-Exercise/classification validation, atomicity, idempotency, stable identity, and cross-tenant tests in `tests/Feature/Projects/CreateProjectTest.php`.
- [X] T012 [P] [US1] Add failing Filament tenant list/create/view/edit, current/annual reference-date, viewer-read-only, direct-URL, and absent-delete/future-control tests in `tests/Feature/Projects/ProjectResourceTest.php`.
- [X] T013 [US1] Implement locked atomic idempotent Project creation with initial annual classification and one complete event in `app/Actions/Operations/CreateProject.php`.
- [X] T014 [US1] Implement descriptive-only Project update with revision and audit in `app/Actions/Operations/UpdateProject.php`, never exposing lifecycle or economic references through ordinary edit.
- [X] T015 [US1] Implement exact per-Exercise Project aggregates and annual situations in `app/Models/Project.php` and `app/Domain/Projects/ProjectAnnualSituation.php`, without stored totals or carryover placeholders.
- [X] T016 [US1] Implement the Italian tenant-scoped `ProjectResource`, schemas, table, and list/create/view/edit pages under `app/Filament/Resources/Projects/`, including OriginKey, current/annual state, zero values, and no delete/future-slice actions.
- [X] T017 [US1] Run US1 tests against `testing`, boot the application, and inspect Project routes without mutating persistent validation data.

**Checkpoint**: User Story 1 is independently usable and testable as the S4 MVP.

---

## Phase 4: User Story 2 — Manage dated Project transitions (Priority: P1)

**Goal**: Schedule, annul, and replace canonical transitions while preserving a
deterministic state at every date and immutable effective history.

**Independent Test**: Exercise every allowed pair and all rejection cases, annul and
replace future transitions, then verify past/current/future state and the absence of
edit/delete for effective facts.

- [X] T018 [P] [US2] Add failing sequence, duplicate-date, reason, effective-transition immutability, full-future-revalidation, retry, concurrency, rollback, and audit tests in `tests/Feature/Projects/ManageProjectTransitionTest.php`.
- [X] T019 [P] [US2] Add failing Filament tests for Italian transition table/actions, allowed destinations, annual date display, future-only annul/replace, domain errors, and no edit/delete in `tests/Feature/Projects/ProjectTransitionsRelationManagerTest.php`.
- [X] T020 [US2] Implement locked idempotent transition scheduling and direct effective recording in `app/Actions/Operations/CreateProjectTransition.php`, validating the complete sequence and incrementing Project revision.
- [X] T021 [US2] Implement locked idempotent future annulment in `app/Actions/Operations/AnnulProjectTransition.php`, retaining the row and revalidating the remaining sequence.
- [X] T022 [US2] Implement atomic future replacement in `app/Actions/Operations/ReplaceProjectTransition.php`, linking old/new transition IDs in one complete event and rolling back both sides on failure.
- [X] T023 [US2] Implement `ProjectTransitionsRelationManager` and transition forms/tables under `app/Filament/Resources/Projects/RelationManagers/`, wiring only explicit Actions and state-at-date refresh.
- [X] T024 [US2] Run US2 tests against `testing`, boot the application, and inspect transition/current/annual views without persistent-data mutation.

**Checkpoint**: User Stories 1–2 expose deterministic, immutable Project lifecycle
history.

---

## Phase 5: User Story 3 — Plan and record Project Expenses (Priority: P1)

**Goal**: Create and maintain Project-owned Expenses whose exact Lines contribute
once at Project and Exercise level while enforcing Project state for Estimates and
Actuals.

**Independent Test**: Create multiple child Expenses and mixed Lines across Suppliers;
verify inherited classification, exact totals/no double counting, ordinary/opening/
late Actual rules, Project revisions, rollback, and all S3 validation.

- [X] T025 [P] [US3] Add failing Project Expense creation, state eligibility, atomic opening, late/reimbursement/corrective declaration, mandatory-note, exact-total, no-double-counting, and rollback tests in `tests/Feature/Projects/CreateProjectExpenseTest.php`.
- [X] T026 [P] [US3] Add failing cross-action regression tests for child Line create/update/annul/restore and Expense reverse/restore, Project/Exercise revisions, inherited classification, overspend inputs, and S3 rules in `tests/Feature/Projects/ManageProjectExpenseTest.php`.
- [X] T027 [P] [US3] Add failing Filament tests for Project Expense relation, prefilled creation, container link, Supplier-per-Expense, hidden direct Cost Center, Actual declaration controls, and excluded Contract fields in `tests/Feature/Projects/ProjectExpensesRelationManagerTest.php`.
- [X] T028 [US3] Extend `app/Actions/Operations/CreateExpense.php` and `app/Filament/Resources/Expenses/Pages/CreateExpense.php` for optional real Project ownership, state/declaration validation, atomic opening, Project locking/revision, inherited classification, and complete audit.
- [X] T029 [US3] Extend `app/Actions/Operations/CreateExpenseLine.php`, `UpdateExpenseLine.php`, and `SetExpenseLineActive.php` with owning-Project locks, state/declaration/overspend-note checks, Project revision, and Project-referenced audit.
- [X] T030 [US3] Extend `app/Actions/Operations/SetExpenseReversed.php` with owning-Project locks, exact before/after Project impacts, overspend-note checks, Project revision, and unchanged S3 eligibility.
- [X] T031 [US3] Implement Project Expense relation UI and container/inherited-classification presentation across `app/Filament/Resources/Projects/RelationManagers/ProjectExpensesRelationManager.php` and `app/Filament/Resources/Expenses/`, reusing the existing Line manager.
- [X] T032 [US3] Run US3 plus complete S3 Expense regression tests against `testing`, boot the application, and inspect both Expense contexts without persistent-data mutation.

**Checkpoint**: User Stories 1–3 deliver the complete P1 Project lifecycle and
economic source without double counting.

---

## Phase 6: User Story 4 — Reclassify a Project for an Exercise (Priority: P2)

**Goal**: Preview and atomically change one Project's entire annual Cost Center
classification, and initialize classifications when an Exercise is created.

**Independent Test**: Reclassify active → active → Unclassified with existing
Actuals; verify exact one-year impact, stale/archived/cross-tenant rejection, and
latest-known automatic initialization without economic rows.

- [X] T033 [P] [US4] Add failing classification impact-plan, exact totals, one-year isolation, stale revision, archived/manual selection, cross-company, retry, rollback, and audit tests in `tests/Feature/Projects/ReclassifyProjectTest.php` and `tests/Unit/Domain/Projects/ProjectClassificationImpactPlanTest.php`.
- [X] T034 [P] [US4] Add failing new-Exercise initialization tests for latest-known including archived historical continuity, Unclassified fallback, stable Projects, and zero economic creation in `tests/Feature/Projects/InitializeProjectClassificationsTest.php`.
- [X] T035 [P] [US4] Add failing Filament preview/confirm, affected-Expense, active-selector, archived-display, stale-preview, and annual-isolation tests in `tests/Feature/Projects/ReclassifyProjectActionTest.php`.
- [X] T036 [US4] Implement immutable exact annual classification preview/fingerprint in `app/Domain/Projects/ProjectClassificationImpactPlan.php`.
- [X] T037 [US4] Implement locked preview confirmation in `app/Actions/Operations/UpdateProjectClassification.php`, rechecking revisions, references, all annual values, and one complete event before atomic save.
- [X] T038 [US4] Extend `app/Actions/Operations/CreateExercise.php` to lock Projects by ID and seed latest-known classifications atomically without creating Expenses, Lines, or amounts.
- [X] T039 [US4] Add annual `Riclassifica` preview/confirm UI under `app/Filament/Resources/Projects/` and update Exercise/Cost Center presentation for inherited archived references.
- [X] T040 [US4] Run US4 and Exercise regression tests against `testing`, boot the application, and inspect annual classification flows without persistent-data mutation.

**Checkpoint**: User Story 4 provides complete annual classification and deterministic
new-year initialization.

---

## Phase 7: User Story 5 — Move a whole Expense between S4 containers (Priority: P2)

**Goal**: Preview and atomically move one stable Expense autonomous ↔ Project or
Project ↔ Project with all Lines, classifications, state rules, and impacts intact.

**Independent Test**: Complete all three move directions with Estimate-only and
Actual Expenses; verify IDs, direct/inherited classification, same-year Exercise
total, Project deltas, atomic opening/late attribution, stale rejection, and rollback.

- [X] T041 [P] [US5] Add failing whole-container impact-plan tests for old/new owner revisions, exact Project/Exercise deltas, preserved IDs, direct/inherited classification, fingerprint, and same-year invariant in `tests/Unit/Domain/Projects/ProjectExpenseMoveImpactPlanTest.php`.
- [X] T042 [P] [US5] Add failing action tests for every supported direction, Estimate/Actual state rules, atomic opening, late/corrective reason, archived/reversed/closed/cross-company/Contract rejection, retry, stale preview, lock order, and rollback in `tests/Feature/Projects/MoveProjectExpenseTest.php`.
- [X] T043 [P] [US5] Add failing Filament tests for owner selection, conditional Project/direct Cost Center fields, exact preview, identity statement, input invalidation, reason/declaration/opening controls, and unsupported-owner absence in `tests/Feature/Projects/MoveProjectExpenseActionTest.php`.
- [X] T044 [US5] Extend `app/Domain/Expenses/ExpenseImpactPlan.php` with immutable S4 owner, Project revision, inherited/direct classification, and exact Project/Exercise before/after impacts while retaining S3 movement behavior.
- [X] T045 [US5] Extend locked preview/confirm in `app/Actions/Operations/UpdateExpense.php` using the global lock order, complete state/declaration/overspend checks, direct Cost Center clearing/selection, all revision increments, and one event.
- [X] T046 [US5] Extend the `Sposta o riclassifica` action and Expense form/table/infolist under `app/Filament/Resources/Expenses/` with S4 container fields and preview while exposing no Contract destination.
- [X] T047 [US5] Run US5 plus S3 move/reclassification regressions against `testing`, boot the application, and inspect all move directions without persistent-data mutation.

**Checkpoint**: User Story 5 safely corrects every ownership case representable in S4.

---

## Phase 8: User Story 6 — Explain overspend, archive, and Project history (Priority: P2)

**Goal**: Surface causal overspend warnings, reversible terminal-only Project archive,
and a complete immutable Project-filtered Timeline.

**Independent Test**: Create/increase/decrease overspend with both note settings,
archive/restore every eligible/ineligible state, then inspect all Project-related
events even after Expense movement.

- [X] T048 [P] [US6] Add failing cross-command overspend tests for exact crossing/increase/no-event predicates, required note, one causal event, retry, rollback, and notification payload in `tests/Feature/Projects/ProjectOverspendTest.php`.
- [X] T049 [P] [US6] Add failing Project archive/restore tests for state-at-current-date eligibility, unchanged values/classifications/history, archived activity rejection, idempotency, and audit in `tests/Feature/Projects/SetProjectArchivedTest.php`.
- [X] T050 [P] [US6] Add failing Project Resource and Timeline tests for action visibility, read-only viewer, immutable historical Project references, filtering after Expense moves, newest-first detail, and no delete in `tests/Feature/Projects/ProjectLifecycleUiTest.php` and `tests/Feature/Projects/ProjectTimelineTest.php`.
- [X] T051 [US6] Implement locked idempotent terminal-only archive/restore with current company-local state and one complete event in `app/Actions/Operations/SetProjectArchived.php`.
- [X] T052 [US6] Integrate overspend result/required-note handling and notifications across Project-capable Actions and forms in `app/Actions/Operations/`, `app/Domain/Projects/`, and `app/Filament/Resources/{Projects,Expenses}/`.
- [X] T053 [US6] Extend `app/Filament/Pages/CompanyAudit.php`, `app/Models/AuditEvent.php`, and Project/Expense view links for immutable Project filtering, exact impacts, state, reason, references, and overspend detail after ownership changes.
- [X] T054 [US6] Wire archive/restore and filtered Timeline actions into `app/Filament/Resources/Projects/`, with no action for ineligible current states and no economic side effects.
- [X] T055 [US6] Run US6 and complete focused S4 suites against `testing`, boot the application, and inspect archive/Timeline/notification behavior without persistent-data mutation.

**Checkpoint**: All six S4 stories are explainable, reversible where canonical, and
visible through one append-only Company Timeline.

---

## Phase 9: Polish and local verification

**Purpose**: Close S4 with exclusion guards, full local quality gates, browser
validation, persistence checks, and accurate evidence without premature publication.

- [X] T056 [P] Add or tighten negative UI and request assertions for absent carryover, reprogramming, Contract, Proposal, Budget, Closing, attachment, Forecast, full-reporting, suspended-state, percentage classification, Project Supplier, and physical-delete controls across `tests/Feature/Projects/` and `tests/Feature/Expenses/`.
- [X] T057 [P] Add aggregate-query regression coverage for Project/Exercise lists and annual situations, preventing per-row Line loading and first-level double counting in `tests/Feature/Projects/ProjectAggregateQueryTest.php`.
- [X] T058 Run `composer validate`, `composer audit`, Pint, Larastan, and the complete Pest suite through Sail, confirming all automated tests use the `testing` database; fix only S4 regressions in S4-owned or directly extended S3 files.
- [X] T059 Execute `specs/005-projects/quickstart.md`: forward migration, application boot, local/LAN HTTP checks, complete browser journeys, tenant isolation, browser console check, and normal stop/start persistence without destructive reset.
- [X] T060 Reconcile completed checkboxes and local evidence in `specs/005-projects/tasks.md`, `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, and `specs/000-product-roadmap/invariant-test-map.md`; mark only locally demonstrated S4 rows implemented and keep shared Contract cases planned until S5.

**Checkpoint**: S4 is locally implemented, quality-gated, inspectable, and documented;
commit, push, PR, and remote CI remain a separate explicit publication step.

### Local implementation evidence — 2026-08-18

- The forward-only S4 migrations were already applied and `artisan migrate --force`
  reported no pending migration; no shared migration was edited and no destructive
  reset, truncate, volume removal, commit, push, or remote-CI action ran.
- `composer validate --strict`, `composer audit`, Pint, and Larastan passed through
  Sail. The complete Pest suite passed with 190 tests and 1,672 assertions against
  the dedicated `testing` database; the focused Project/Expense suite passed with
  107 tests and 985 assertions.
- Browser validation covered absent/future state, transition scheduling,
  annulment/replacement, Project Expenses with distinct Suppliers, exact first-level
  totals, Planned atomic opening, declared corrective Actual on Closed, annual
  reclassification, new-Exercise seeding, stable whole-Expense moves with and without
  Actuals, overspend creation/increase/non-increase and mandatory-note rollback,
  terminal archive/restore, and immutable filtered Timeline details. Read-only
  behavior and rejection matrices remained covered by the focused UI/action tests;
  a cross-tenant Project URL returned 404 and browser console/page errors were empty.
- A normal `sail stop` / `sail up -d` retained 5 Projects, 4 transitions, 7 annual
  classifications, 6 Expenses, 18 Lines, and 84 append-only events with the same
  IDs, ownership, revisions, and archive state. The restarted Project UI rendered
  without an error overlay; local and LAN `/admin` endpoints both returned the
  expected 302 to login.
- S4 and its primary FR/invariant rows are `implemented`, not `verified`, because no
  publication or remote CI was requested. FR-005, FR-051, FR-052, and invariant 28.4
  remain `planned` until S5 makes the Contract ownership cases representable.

---

## Dependencies and execution order

### Phase dependencies

- **Phase 1** preserves the baseline and must complete first.
- **Phase 2** depends on Phase 1 and blocks every user story.
- **US1 (Phase 3)** depends on Phase 2.
- **US2 (Phase 4)** depends on the Project aggregate from US1.
- **US3 (Phase 5)** depends on US1 state/aggregate and can consume US2 transitions.
- **US4 (Phase 6)** depends on US1 annual situations and US3 Project Expenses.
- **US5 (Phase 7)** depends on US3 ownership/economic paths and US4 inheritance.
- **US6 (Phase 8)** integrates causal outcomes from US1–US5.
- **Polish (Phase 9)** depends on all selected stories.

### Within each phase

- Add the listed failing tests before corresponding production code.
- Implement persistence/domain before Actions, Actions before Filament wiring.
- Use one stable operation ID per user attempt; rotate only after success.
- Apply the global lock order from `data-model.md` in every extended S3 Action.
- End with the phase's `testing`-database tests, boot, and UI inspection.
- Do not begin a later phase while the current checkpoint is red.

### Parallel opportunities

- Tests marked `[P]` touch separate files and can be prepared independently.
- Unit domain, policy, and persistence tests in Phase 2 are independent before their
  implementation converges.
- After US1, transition work and Project Expense test preparation are separable, but
  the prescribed implementation run remains phase-sequential for auditable gates.
- In US4–US6, unit impact tests and Filament contract tests can be prepared in
  parallel before shared Actions are changed.

## Parallel examples

### User Story 1

```text
T011 CreateProject action tests
T012 ProjectResource UI tests
```

### User Story 3

```text
T025 Project Expense creation tests
T026 cross-action economic regression tests
T027 Project Expense Filament tests
```

### User Story 6

```text
T048 overspend command tests
T049 archive/restore tests
T050 lifecycle and Timeline UI tests
```

## Implementation strategy

### MVP first

1. Complete Setup and Foundational phases.
2. Complete User Story 1.
3. Stop and validate standalone Project creation/inspection before transitions or
   economic ownership are added.

### Incremental delivery

1. Deliver stable Project identity and annual zero-value views.
2. Add deterministic dated lifecycle.
3. Add child Expenses and exact economics.
4. Add annual classification, then whole-Expense movement.
5. Finish overspend, archive, Timeline, and the complete local gate.

## Notes

- `[P]` tasks never authorize concurrent edits to the same file.
- Shared S3 Actions are extended only where S4 creates a demonstrated invariant.
- Every MUST NOT rule has at least one rejection test; atomic operations include
  rollback and retry coverage.
- Complete one phase at a time and keep the tenant UI inspectable after every phase.
