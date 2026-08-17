# Tasks: Exercises, Expenses and Lines

**Input**: Design documents from `/specs/004-exercises-expenses/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/ui.md`

**Tests**: Mandatory. Write behavior-focused rejection, rollback, idempotency, tenancy,
authorization and UI tests before each implementation increment. Run them only through
the dedicated `testing` database.

**Organization**: Tasks are grouped by independently demonstrable user story. Every
phase contains no more than eight tasks and ends with a non-destructive checkpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: May be implemented in parallel because it touches different files and has
  no unfinished dependency.
- **[Story]**: Maps the task to one of the six S3 user stories.
- Every task names the concrete files or directories it changes.

## Phase 1: Setup and reconciliation

**Purpose**: Preserve the verified S2 baseline and make S3's technical boundary explicit.

- [X] T001 Reconcile S3 ownership, category A–E conclusions and the incremental autonomous-only coverage of FR-005/FR-051/FR-052/invariant 28.4 in `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, and `specs/000-product-roadmap/invariant-test-map.md` without marking unbuilt behavior implemented or verified.
- [X] T002 Declare the already-installed PHP BCMath extension as the exact-decimal runtime requirement through `composer.json` and `composer.lock`, retaining the dependency rationale and removal consequence in `specs/004-exercises-expenses/research.md`.
- [X] T003 Run the unchanged S2 focused gate, application boot, and `/admin` HTTP smoke check; record only reproducible S3 commands or corrections in `specs/004-exercises-expenses/quickstart.md`.

**Checkpoint**: The S2 UI remains inspectable and S3 has no dependency or traceability ambiguity.

---

## Phase 2: Foundational domain and persistence

**Purpose**: Establish forward-only schema, tenant barriers, exact arithmetic, shared models and authorization before any story UI.

**⚠️ CRITICAL**: No user-story implementation begins until this phase passes.

- [X] T004 [P] Add failing schema, model, tenant-isolation, database-constraint and deletion-rejection tests in `tests/Feature/Expenses/ExpensePersistenceTest.php`.
- [X] T005 Add new forward-only composite tenant key and S3 table migrations under `database/migrations/` for `exercises`, `expenses`, and `expense_lines`, with restrictive foreign keys, uniqueness and local checks from `data-model.md`; do not edit applied migrations.
- [X] T006 [P] Implement `Exercise`, `Expense`, and `ExpenseLine` models and factories in `app/Models/` and `database/factories/`, including tenant relationships, derived OriginKey, exact scopes/totals, revision fields and physical-delete rejection.
- [X] T007 [P] Add closed enums/value definitions for Exercise state and Line type plus exact string-decimal sum, comparison, multiplication and half-up rounding code in `app/Domain/Expenses/`, with failing-first unit coverage in `tests/Unit/Domain/Expenses/`.
- [X] T008 [P] Add `ExercisePolicy`, `ExpensePolicy`, and `ExpenseLinePolicy` in `app/Policies/`, mapping read access and economic mutation to the existing Company capabilities while denying physical deletion, with focused authorization coverage in `tests/Feature/Expenses/ExpenseAuthorizationTest.php`.
- [X] T009 Extend the typed audit vocabulary and reusable S3 snapshot/impact construction in `app/Domain/Company/AuditEventType.php` and `app/Domain/Expenses/`, preserving the existing append-only envelope and unique `operation_id`, with unit coverage in `tests/Unit/Domain/Expenses/ExpenseAuditSnapshotTest.php`.
- [X] T010 Run the Phase 2 tests against `testing`, `php artisan about`, and a non-mutating `/admin` smoke check; fix only Phase 2 regressions in the files above.

**Checkpoint**: Forward-only persistence, exact calculations, tenant isolation and authorization are usable without exposing future-slice concepts.

---

## Phase 3: User Story 1 — Manage open Exercises (Priority: P1) 🎯 MVP

**Goal**: An authorized operator can create and inspect unique open calendar Exercises for the active Company.

**Independent Test**: Create past, current and future years; reject a Company duplicate; list newest first and view derived zero totals without edit, close, reopen or delete controls.

- [X] T011 [P] [US1] Add failing transaction, authorization, idempotency, duplicate-year and cross-tenant tests for Exercise creation in `tests/Feature/Expenses/CreateExerciseTest.php`.
- [X] T012 [P] [US1] Add failing Filament tests for tenant-scoped list/create/view access and absent future-slice actions in `tests/Feature/Expenses/ExerciseResourceTest.php`.
- [X] T013 [US1] Implement the locked, atomic and idempotent `CreateExercise` command in `app/Actions/Operations/CreateExercise.php`, including one complete audit event.
- [X] T014 [US1] Implement the Italian tenant-scoped `ExerciseResource`, schemas, table and list/create/view pages under `app/Filament/Resources/Exercises/`, including derived totals and contextual new-Expense link but no edit/lifecycle/delete actions.
- [X] T015 [US1] Run the US1 tests against `testing`, boot the application and inspect the Exercise routes without mutating the persistent database.

**Checkpoint**: User Story 1 is independently usable and testable.

---

## Phase 4: User Story 2 — Register an autonomous Expense with initial Lines (Priority: P1)

**Goal**: Create one tenant-safe autonomous Expense and at least one validated manual Line atomically, with exact totals and one Timeline event.

**Independent Test**: Create an Expense with mixed Estimate/Actual Lines; verify OriginKey, totals, mismatch acknowledgement, rollback, retry idempotency, future-Actual rejection and no persisted line-less Expense.

- [X] T016 [P] [US2] Add failing create-command tests for validation, same-Company/open references, exact totals, future Actuals, warning acknowledgement, rollback, audit envelope and idempotency in `tests/Feature/Expenses/CreateExpenseTest.php`.
- [X] T017 [P] [US2] Add failing tenant URL, list/create/view form, archived-reference display, initial-Line repeater and absent future-field tests in `tests/Feature/Expenses/ExpenseResourceTest.php`.
- [X] T018 [US2] Implement shared manual-Line validation and authoritative amount/product-warning behavior in `app/Domain/Expenses/ManualExpenseLine.php`, with focused unit tests in `tests/Unit/Domain/Expenses/ManualExpenseLineTest.php`.
- [X] T019 [US2] Implement atomic `CreateExpense` in `app/Actions/Operations/CreateExpense.php`, locking and revalidating Company, Exercise and optional master data, inserting all initial Lines and one audit event under a stable operation ID.
- [X] T020 [US2] Implement `ExpenseResource` list/view/create foundations, form and table schemas, and pages under `app/Filament/Resources/Expenses/`, including tenant-scoped active selectors, exact derived columns, stable operation ID and server-confirmed mismatch warning.
- [X] T021 [US2] Add the contextual, non-authoritative Exercise preselection from the Exercise view to Expense creation in `app/Filament/Resources/Exercises/Pages/ViewExercise.php` and revalidate it in the create command.
- [X] T022 [US2] Run the US2 tests against `testing`, boot the application and inspect both Resource route families without persistent-data mutation.

**Checkpoint**: User Stories 1 and 2 work independently; an Expense can never be committed without an initial Line.

---

## Phase 5: User Story 3 — Maintain Lines without erasing history (Priority: P1)

**Goal**: Add, edit, annul and restore Lines while preserving identities, history and all economic rules.

**Independent Test**: Exercise each transition and rejection, including negative Actual notes, zero reasons, future years, reversed Expenses, warning refresh, current-state no-ops and atomic audit.

- [X] T023 [P] [US3] Add failing action tests for create/update/annul/restore, exact recalculation, operation idempotency, rollback and concurrency guards in `tests/Feature/Expenses/ManageExpenseLineTest.php`.
- [X] T024 [P] [US3] Add failing Filament Relation Manager tests for Italian fields/actions, precision, warning confirmation, archived state, reversed-Expense blocking and absence of delete/bulk destruction in `tests/Feature/Expenses/ExpenseLinesRelationManagerTest.php`.
- [X] T025 [US3] Implement atomic `CreateExpenseLine` in `app/Actions/Operations/CreateExpenseLine.php`, reusing manual-Line validation and incrementing locked revisions with one audit event.
- [X] T026 [US3] Implement atomic `UpdateExpenseLine` in `app/Actions/Operations/UpdateExpenseLine.php`, preserving identity and requiring a current warning acknowledgement/revision.
- [X] T027 [US3] Implement idempotent `SetExpenseLineActive` annul/restore transitions in `app/Actions/Operations/SetExpenseLineActive.php`, including full restore revalidation and no event for current-state requests.
- [X] T028 [US3] Implement `ExpenseLinesRelationManager` and its form/table schemas under `app/Filament/Resources/Expenses/RelationManagers/`, wiring only the explicit Actions and refreshing owner totals.
- [X] T029 [US3] Run the US3 tests against `testing`, boot the application and inspect the nested Line UI without persistent-data mutation.

**Checkpoint**: User Stories 1–3 preserve Line history and exact current economic truth.

---

## Phase 6: User Story 4 — Reclassify or move an autonomous Expense safely (Priority: P2)

**Goal**: Preview and atomically confirm a whole-Expense Exercise, Supplier or direct Cost Center change without changing identities.

**Independent Test**: Preview old/new exact impacts, require a reason when moving Actuals, reject future Actuals/stale previews/cross-tenant or inactive references, then verify one atomic event and unchanged IDs.

- [X] T030 [P] [US4] Add failing impact-plan calculation, stale-revision, lock-order, validation, rollback, retry and audit tests in `tests/Feature/Expenses/MoveOrReclassifyExpenseTest.php` and `tests/Unit/Domain/Expenses/ExpenseImpactPlanTest.php`.
- [X] T031 [P] [US4] Add failing Filament tests for preview invalidation, reason/confirmation flow, active same-tenant options and read-only current archived references in `tests/Feature/Expenses/MoveOrReclassifyExpenseActionTest.php`.
- [X] T032 [US4] Implement the immutable preview payload and exact per-Exercise before/after/delta calculation in `app/Domain/Expenses/ExpenseImpactPlan.php`.
- [X] T033 [US4] Implement locked preview confirmation in `app/Actions/Operations/UpdateExpense.php`, reauthorizing and revalidating all revisions/references before one mutation and one audit event.
- [X] T034 [US4] Add ordinary description/notes edit plus the separate Italian `Sposta o riclassifica` preview/confirm action to the Expense pages under `app/Filament/Resources/Expenses/`, never exposing consequential references to ordinary CRUD save.
- [X] T035 [US4] Run the US4 tests against `testing`, boot the application and inspect the preview action without persistent-data mutation.

**Checkpoint**: User Story 4 provides explainable, stale-safe whole-Expense reclassification.

---

## Phase 7: User Story 5 — Storno and restore an Expense (Priority: P2)

**Goal**: Exclude or reinclude an eligible Expense without deleting it or its Lines.

**Independent Test**: Storna only an active Expense in an open Exercise with no active non-zero Actual, restore only a reversed Expense in an open Exercise, require reason, preserve identity, and make repeated state requests event-free no-ops.

- [X] T036 [P] [US5] Add failing lifecycle tests for eligibility, the offsetting-Actual edge case, authorization, rollback, idempotency, exact impacts and audit in `tests/Feature/Expenses/SetExpenseReversedTest.php`.
- [X] T037 [P] [US5] Add failing Filament tests for mutually exclusive Italian actions, required reason, impact summary, disabled explanations and no delete in `tests/Feature/Expenses/ExpenseLifecycleActionTest.php`.
- [X] T038 [US5] Implement locked idempotent `SetExpenseReversed` in `app/Actions/Operations/SetExpenseReversed.php`, using existential `HasActuals`, preserving records and recording one complete event only on change.
- [X] T039 [US5] Wire `Storna` and `Ripristina` into Expense table/view actions under `app/Filament/Resources/Expenses/` with server revalidation and post-action refresh.
- [X] T040 [US5] Run the US5 tests against `testing`, boot the application and inspect lifecycle visibility without persistent-data mutation.

**Checkpoint**: User Story 5 implements reversible exclusion with no physical deletion.

---

## Phase 8: User Story 6 — Explain economic changes in the Timeline (Priority: P2)

**Goal**: Make every S3 mutation explainable in the existing Company Timeline with exact affected-Exercise impacts.

**Independent Test**: Filter from an Expense and inspect newest-first typed events showing actor, subject, operation ID, before/after, reason and exact allocation/actual impacts with no edit/delete affordance.

- [X] T041 [P] [US6] Add failing cross-command envelope, ordering, subject-filter and immutable-event tests in `tests/Feature/Expenses/ExpenseTimelineTest.php`.
- [X] T042 [P] [US6] Add failing Company Audit page tests for readable S3 subjects, expandable snapshots/impacts, Expense filter and no mutation controls in `tests/Feature/Filament/CompanyAuditTest.php`.
- [X] T043 [US6] Extend the existing Company Audit page under `app/Filament/Pages/CompanyAudit.php` to render S3 event subjects, affected Exercise years, before/after, exact impacts, reason and operation references while preserving tenant scope and newest-first order.
- [X] T044 [US6] Add the contextual filtered-Timeline link from the Expense view under `app/Filament/Resources/Expenses/Pages/ViewExpense.php` and keep all audit records read-only.
- [X] T045 [US6] Run the US6 and complete S3 focused suites against `testing`, boot the application and inspect Timeline navigation without persistent-data mutation.

**Checkpoint**: All six S3 stories are explainable through one append-only Company Timeline.

---

## Phase 9: Polish and local verification

**Purpose**: Close S3 with exclusion guards, full local quality gates and accurate evidence, without remote CI or publication.

- [X] T046 [P] Add or tighten negative UI assertions for absent Project/Contract, Budget, closing, reporting, attachment and physical-delete controls across `tests/Feature/Expenses/`.
- [X] T047 Run `composer validate`, `composer audit`, Pint, Larastan and the complete Pest suite through Sail, confirming all automated tests use the `testing` database; fix only S3 regressions in S3-owned files.
- [X] T048 Execute `specs/004-exercises-expenses/quickstart.md`: application boot, local/LAN HTTP checks, non-destructive forward migration, complete browser journeys, tenant isolation, console check and normal stop/start persistence.
- [X] T049 Reconcile completed checkboxes and local evidence in `specs/004-exercises-expenses/tasks.md`, `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, and `specs/000-product-roadmap/invariant-test-map.md`; retain `implemented` rather than `verified`, and retain later-slice rows as planned where their full container cases remain unrepresentable.

**Checkpoint**: S3 is locally implemented, quality-gated and documented; no commit, push, PR or remote-CI monitoring has occurred.

### Local implementation evidence — 2026-08-17

- The forward-only S3 migrations were applied as persistent database batch 4; no
  previously applied migration was edited and no reset/truncate/volume removal ran.
- `composer validate --strict`, `composer audit`, Pint and Larastan passed through
  Sail; the complete Pest suite passed with 113 tests and 926 assertions against the
  dedicated `testing` database.
- Browser validation covered Exercise/Expense creation, authoritative amount warning,
  future-Actual move rejection, exact two-year move preview and confirmation,
  Expense storno/restore, Line annul/restore, filtered Timeline detail, absence of
  destructive/future-slice controls, read-only Operator UI and cross-tenant 404
  behavior. Browser console and page-error collections were empty.
- A normal `sail stop` / `sail up -d` retained 2 Companies, 2 Users, Exercises 2026
  and 2027, 2 Expenses with stable IDs/OriginKeys, 6 active Lines and 50 append-only
  audit events. Local and LAN `/admin` endpoints both returned the expected 302 to
  login after restart.
- S3 and its fully representable FR/invariant rows are `implemented`, not `verified`:
  remote CI was intentionally not monitored. FR-005, FR-051, FR-052 and invariant
  28.4 remain `planned` until S4/S5 make the Project and Contract cases real.

---

## Dependencies and execution order

### Phase dependencies

- **Phase 1** preserves the baseline and must complete first.
- **Phase 2** depends on Phase 1 and blocks every user story.
- **US1 (Phase 3)** depends on Phase 2.
- **US2 (Phase 4)** depends on Phase 2 and consumes an Exercise from US1.
- **US3 (Phase 5)** depends on the Expense aggregate created in US2.
- **US4 (Phase 6)** depends on US2/US3 totals and revisions.
- **US5 (Phase 7)** depends on US2/US3 `HasActuals` and totals.
- **US6 (Phase 8)** integrates the completed command event envelopes from US1–US5.
- **Polish (Phase 9)** depends on all selected stories.

### Within each phase

- Add the listed failing tests before the corresponding production code.
- Implement persistence/models before Actions, Actions before Filament wiring.
- Use one stable operation ID per user attempt; rotate only after success.
- End with the phase's `testing`-database tests, boot and UI inspection.
- Do not begin a later phase while the current checkpoint is red.

### Parallel opportunities

- Tests marked `[P]` touch separate files and can be prepared independently.
- Unit domain tests and policy tests in Phase 2 are independent after migrations/models.
- US4 and US5 are conceptually independent after US3, but the prescribed run remains
  sequential so each checkpoint is auditable.

## Implementation strategy

1. Preserve the working S2 baseline and document the S3 boundary.
2. Build the smallest shared persistence/domain foundation.
3. Deliver Exercises, then atomic Expense creation, then Line maintenance.
4. Add impact-confirmed reclassification and reversible lifecycle operations.
5. Complete the existing Company Timeline and run the full local gate.
6. Stop with local changes only; publication remains an explicit user decision.
