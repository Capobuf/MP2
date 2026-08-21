---

description: "Dependency-ordered implementation tasks for S5 Contracts"
---

# Tasks: Contracts

**Input**: Design documents from `specs/006-contracts/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/ui.md`, and `quickstart.md`

**Tests**: Required by the feature specification, canonical verification rules, and
project policy. In each phase, add the listed failing tests before implementation and
run them only against the guarded `testing` database.

**Organization**: Tasks are grouped by user story. No phase contains more than eight
tasks. Every phase ends with focused tests, application boot, and an inspectable
tenant UI. Directed `Sostituisce` relations remain excluded and receive no task.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can be worked in parallel because it touches different files and has no
  dependency on another incomplete task in the same phase.
- **[Story]**: Maps the task to one of the six user stories in `spec.md`.
- Every task names the exact files it creates or changes.

## Phase 1: Setup and failing foundation checks

**Purpose**: Pin the shared S5 persistence, audit, and authorization contracts before
adding implementation.

- [X] T001 [P] Add failing multi-event operation-sequence and legacy sequence-zero regression coverage in `tests/Feature/Company/AuditOperationSequenceTest.php`.
- [X] T002 [P] Add failing Contract schema tests for company foreign keys, lifecycle/condition uniqueness, Expense ownership XOR/system origin, annual classification, Project link, Attachment owner XOR, and physical-delete restrictions in `tests/Feature/Contracts/ContractSchemaTest.php`.
- [X] T003 [P] Add failing tenant/capability policy tests for Contracts, child facts, links, attachments, and guessed cross-company identifiers in `tests/Feature/Contracts/ContractAuthorizationTest.php`.

**Checkpoint**: The new tests fail for missing S5 schema and behavior while the
existing S0-S4 suite remains unchanged.

---

## Phase 2: Foundational Contract persistence

**Purpose**: Supply the schema, models, factories, audit sequencing, and authorization
that block every user story.

**⚠️ CRITICAL**: No user-story phase starts before this phase passes.

- [X] T004 Extend typed audit vocabulary and ordered event persistence with a forward migration in `database/migrations/2026_08_20_000100_extend_audit_event_sequence.php`, `app/Models/AuditEvent.php`, and `app/Domain/Company/AuditEventType.php` while preserving sequence zero for S0-S4 Actions.
- [X] T005 Create company-scoped Contract, renewal-configuration, lifecycle-fact, and economic-condition tables with forward constraints and indexes in `database/migrations/2026_08_20_000200_create_contracts_table.php`, `database/migrations/2026_08_20_000300_create_contract_renewal_configurations_table.php`, `database/migrations/2026_08_20_000400_create_contract_lifecycle_facts_table.php`, and `database/migrations/2026_08_20_000500_create_contract_conditions_table.php`.
- [X] T006 Add annual Contract classifications and Contract/system ownership to Expenses with same-company FKs, owner XOR checks, origin rules, and conditional generated-Estimate uniqueness in `database/migrations/2026_08_20_000600_create_contract_exercise_classifications_table.php` and `database/migrations/2026_08_20_000700_add_contract_ownership_to_expenses_table.php`.
- [X] T007 Add the bounded Project-Contract link and private Attachment metadata tables, including active-link uniqueness and exactly-one-owner checks, in `database/migrations/2026_08_20_000800_create_project_contract_links_table.php` and `database/migrations/2026_08_20_000900_create_attachments_table.php`.
- [X] T008 Implement Contract aggregate models and factories with casts, company scopes, stable relations, and no ordinary delete path in `app/Models/Contract.php`, `app/Models/ContractRenewalConfiguration.php`, `app/Models/ContractLifecycleFact.php`, `app/Models/ContractCondition.php`, `app/Models/ContractExerciseClassification.php`, `database/factories/ContractFactory.php`, `database/factories/ContractConditionFactory.php`, and `database/factories/ContractLifecycleFactFactory.php`.
- [X] T009 Implement link/attachment models and factories and extend existing Company, Exercise, Expense, ExpenseLine, Project, and User relations in `app/Models/ProjectContractLink.php`, `app/Models/Attachment.php`, `database/factories/ProjectContractLinkFactory.php`, `database/factories/AttachmentFactory.php`, `app/Models/Company.php`, `app/Models/Exercise.php`, `app/Models/Expense.php`, `app/Models/ExpenseLine.php`, `app/Models/Project.php`, and `app/Models/User.php`.
- [X] T010 Add exact-company `visualizza`/`modifica_operativita` authorization for Contract records, links, and attachments in `app/Policies/ContractPolicy.php`, `app/Policies/ProjectContractLinkPolicy.php`, `app/Policies/AttachmentPolicy.php`, and `app/Providers/AppServiceProvider.php`.
- [X] T011 Make the Phase 1 tests pass, run the existing Company/Expense/Project regression groups, and verify Laravel boot through `tests/Feature/Company/AuditOperationSequenceTest.php`, `tests/Feature/Contracts/ContractSchemaTest.php`, `tests/Feature/Contracts/ContractAuthorizationTest.php`, and `bootstrap/app.php`.

**Checkpoint**: Forward migrations apply without editing S0-S4 migrations; the
application boots and tenant-safe Contract persistence is ready.

---

## Phase 3: User Story 1 — Create and inspect Contracts (Priority: P1) 🎯 MVP

**Goal**: Create an active, planned, or late-censused Contract atomically with its
first condition, renewal terms, annual classification, initial generated Estimate,
and complete typed Timeline evidence.

**Independent Test**: Create active, future, invalid, and past-start Contracts; verify
stable identity, state at date, initial composition, no partial rows, tenant
isolation, open-Exercise-only calculation, and unchanged closed-year data.

### Tests for User Story 1

- [X] T012 [P] [US1] Add failing unit coverage for Contract state-at-date, company-local reference dates, anchored cycle enumeration, attribution, exact annual composition, and the base elapsed-renewal schedule required by late census in `tests/Unit/Domain/Contracts/ContractStateTimelineTest.php`, `tests/Unit/Domain/Contracts/ContractAnnualAllocationTest.php`, and `tests/Unit/Domain/Contracts/ContractRenewalScheduleTest.php`.
- [X] T013 [P] [US1] Add failing Action tests for valid/invalid atomic creation, explicit initial renewal-configuration effectiveness, idempotent retry, all required event sequences, archived Supplier rejection, and late census that derives elapsed renewals while recalculating only open Exercises in `tests/Feature/Contracts/CreateContractTest.php`.
- [X] T014 [P] [US1] Add failing Filament/Livewire tests for Contract list/create/view/descriptive-edit forms, viewer read-only behavior, undefined expiry, annual situations, and tenant URL isolation in `tests/Feature/Contracts/ContractResourceTest.php`.

### Implementation for User Story 1

- [X] T015 [US1] Implement closed Contract vocabularies and deterministic state/date/cycle/allocation values, plus the base anchored elapsed-renewal schedule needed by late census, with exact decimal arithmetic in `app/Domain/Contracts/ContractState.php`, `app/Domain/Contracts/ContractCycleType.php`, `app/Domain/Contracts/ContractAttributionMode.php`, `app/Domain/Contracts/ContractStateTimeline.php`, `app/Domain/Contracts/ContractCycle.php`, `app/Domain/Contracts/ContractAnnualAllocation.php`, and `app/Domain/Contracts/ContractRenewalSchedule.php`.
- [X] T016 [US1] Implement idempotent transactional creation, late-census renewal derivation, generated Estimate materialization, open-Exercise locking, initial classification, descriptive updates, and ordered typed events in `app/Actions/Operations/CreateContract.php`, `app/Actions/Operations/UpdateContract.php`, and `app/Actions/Operations/RecalculateContractEstimates.php`.
- [X] T017 [US1] Build the tenant Contract Resource and descriptive create/view/edit surfaces in `app/Filament/Resources/Contracts/ContractResource.php`, `app/Filament/Resources/Contracts/Pages/ListContracts.php`, `app/Filament/Resources/Contracts/Pages/CreateContract.php`, `app/Filament/Resources/Contracts/Pages/ViewContract.php`, `app/Filament/Resources/Contracts/Pages/EditContract.php`, `app/Filament/Resources/Contracts/Schemas/ContractForm.php`, `app/Filament/Resources/Contracts/Schemas/ContractInfolist.php`, and `app/Filament/Resources/Contracts/Tables/ContractsTable.php`.
- [X] T018 [US1] Run the US1 tests plus Company/Expense/Project regressions, boot Laravel, and smoke `/admin` using `tests/Unit/Domain/Contracts/`, `tests/Feature/Contracts/CreateContractTest.php`, `tests/Feature/Contracts/ContractResourceTest.php`, and `bootstrap/app.php`.

**Checkpoint**: US1 is independently demonstrable as the S5 MVP.

---

## Phase 4: User Story 2 — Generate exact annual Estimates (Priority: P1)

**Goal**: Manage valid non-overlapping conditions and maintain one stable system
Estimate per Contract/Exercise with exact composition and no prorata or manual
mutation path.

**Independent Test**: Exercise all cycles, anchors, attribution modes, validity gaps,
zero materialization, overlap rejections, generated-source protections, retry, and
multi-year recalculation.

### Tests for User Story 2

- [X] T019 [P] [US2] Extend the failing recurrence matrix for 28/29/30/31, leap years, cycle-start/end attribution, gaps, cessation-crossing cycles, and year boundaries in `tests/Unit/Domain/Contracts/ContractAnnualAllocationTest.php`.
- [X] T020 [P] [US2] Add failing feature tests for condition creation/annulment, overlap and invalid-state rejection, stable system Expense/Line identity, never-materialized zero, retry, rollback, and forbidden generated-source mutations in `tests/Feature/Contracts/ManageContractConditionTest.php` and `tests/Feature/Contracts/RecalculateContractEstimatesTest.php`.
- [X] T021 [P] [US2] Add failing Livewire tests for annual composition and condition actions with no raw edit/delete or generated Estimate controls in `tests/Feature/Contracts/ContractConditionsRelationManagerTest.php` and `tests/Feature/Contracts/ContractAnnualSituationUiTest.php`.

### Implementation for User Story 2

- [X] T022 [US2] Implement condition validation, inclusive non-overlap checks, state eligibility, annul/restore history, and exact affected-year discovery in `app/Domain/Contracts/ContractConditionRules.php`, `app/Actions/Operations/CreateContractCondition.php`, and `app/Actions/Operations/SetContractConditionAnnulled.php`.
- [X] T023 [US2] Complete idempotent generated Estimate/Line updates and exact composition Timeline payloads in `app/Actions/Operations/RecalculateContractEstimates.php`, `app/Models/Expense.php`, and `app/Models/ExpenseLine.php`.
- [X] T024 [US2] Block manual create/edit/annul/reverse/move/Actual paths for generated Contract Expenses and Lines in `app/Actions/Operations/CreateExpense.php`, `app/Actions/Operations/CreateExpenseLine.php`, `app/Actions/Operations/UpdateExpense.php`, `app/Actions/Operations/UpdateExpenseLine.php`, `app/Actions/Operations/SetExpenseLineActive.php`, and `app/Actions/Operations/SetExpenseReversed.php`.
- [X] T025 [US2] Implement condition and annual-situation presentation with exact composition and domain errors in `app/Filament/Resources/Contracts/RelationManagers/ContractConditionsRelationManager.php`, `app/Filament/Resources/Contracts/RelationManagers/ContractAnnualSituationsRelationManager.php`, and `app/Filament/Resources/Expenses/ExpenseResource.php`, then run US2 tests, boot, and smoke `/admin`.

**Checkpoint**: US2 produces exact annual Contract allocation without invoicing,
matching, prorata, or mutable system Estimates.

---

## Phase 5: User Story 3 — Lifecycle, expiry, and renewal (Priority: P1)

**Goal**: Manage dated lifecycle facts and historical renewal configurations, process
elapsed expiries independently of page access, and derive correct past/current/future
state.

**Independent Test**: Verify activation, cessation, reactivation, cancellation,
future fact annul/replace, non-renewed expiry, multiple elapsed renewals, historical
configuration selection, projection, retry, and rollback.

### Tests for User Story 3

- [X] T026 [P] [US3] Extend the renewal-schedule unit coverage and add failing tests for lifecycle compatibility, future fact annul/replace, historical configuration selection, projected renewals, non-renewed expiry, and renewal-without-condition state in `tests/Unit/Domain/Contracts/ContractLifecycleRulesTest.php` and `tests/Unit/Domain/Contracts/ContractRenewalScheduleTest.php`.
- [X] T027 [P] [US3] Add failing transactional tests for cessation/reactivation/cancellation, open-year recalculation, missing Note rejection, stale plans, multiple event sequences, and no partial effects in `tests/Feature/Contracts/ManageContractLifecycleTest.php`.
- [X] T028 [P] [US3] Add failing command tests proving chronological idempotent renewal processing, one fact/event per expiry, per-Contract transaction isolation, projection without premature facts, and no dependency on the deadlines page in `tests/Feature/Contracts/ProcessContractRenewalsTest.php`.

### Implementation for User Story 3

- [X] T029 [US3] Extend lifecycle compatibility, projected state, expiry cessation, historical-configuration selection, and renewal scheduling values in `app/Domain/Contracts/ContractLifecycleRules.php`, `app/Domain/Contracts/ContractStateTimeline.php`, and `app/Domain/Contracts/ContractRenewalSchedule.php`.
- [X] T030 [US3] Implement explicit lifecycle mutations and future-fact annul/replace with atomic Estimate recalculation and required events, including closure of open-ended conditions at cessation and annulment of future conditions on valid pre-activation cancellation, in `app/Actions/Operations/CeaseContract.php`, `app/Actions/Operations/ReactivateContract.php`, `app/Actions/Operations/CancelContract.php`, `app/Actions/Operations/AnnulContractLifecycleFact.php`, and `app/Actions/Operations/ReplaceContractLifecycleFact.php`.
- [X] T031 [US3] Implement complete renewal-configuration changes and due-renewal processing with historical terms, deterministic event sequences, and per-Contract retry receipts in `app/Actions/Operations/UpdateContractRenewal.php` and `app/Actions/Operations/ProcessContractRenewals.php`.
- [X] T032 [US3] Add the scheduler-independent Artisan entry point and schedule registration in `app/Console/Commands/ProcessContractRenewalsCommand.php` and `routes/console.php`, and implement lifecycle/renewal managers in `app/Filament/Resources/Contracts/RelationManagers/ContractLifecycleRelationManager.php` and `app/Filament/Resources/Contracts/RelationManagers/ContractRenewalsRelationManager.php`.
- [X] T033 [US3] Run US3 plus US1/US2 regression tests, execute the renewal command twice, boot Laravel, and smoke `/admin` using `tests/Unit/Domain/Contracts/`, `tests/Feature/Contracts/ManageContractLifecycleTest.php`, `tests/Feature/Contracts/ProcessContractRenewalsTest.php`, and `bootstrap/app.php`.

**Checkpoint**: Contract state and next expiry are deterministic without any
page-triggered mutation.

---

## Phase 6: User Story 4 — Change Contract economics without silent prorata (Priority: P1)

**Goal**: Apply real agreement changes only at the canonical effective boundary and
distinguish them from material input corrections, with immutable previews and atomic
multi-Exercise effects.

**Independent Test**: Verify requested/minimum/effective dates, explicit confirmation,
blocked unavailable boundaries, correction rules, Supplier first-use locking,
stale-plan rejection, exact per-year impacts, and rollback.

### Tests for User Story 4

- [X] T034 [P] [US4] Add failing unit tests for minimum/effective-date calculation, future not-started replacement, missing boundary, no-prorata explanation, and immutable impact fingerprints in `tests/Unit/Domain/Contracts/ContractEconomicChangePlanTest.php`.
- [X] T035 [P] [US4] Add failing Action tests for real changes, material corrections, multi-Exercise locks/revisions, stale confirmation, forced rollback, required event sets, and unchanged approved references in `tests/Feature/Contracts/ChangeContractEconomicsTest.php`.
- [X] T036 [P] [US4] Add failing tests proving Supplier changes stop after a non-zero generated Estimate or any active Actual Line including zero while archived historical Supplier use remains readable; keep the later Budget/Closing triggers documented without creating future-slice models in `tests/Feature/Contracts/ChangeContractSupplierTest.php`.

### Implementation for User Story 4

- [X] T037 [US4] Implement deterministic economic-change boundary, exact impact, reason, and fingerprint values in `app/Domain/Contracts/ContractEconomicChangePlan.php` and `app/Domain/Contracts/ContractImpactFingerprint.php`.
- [X] T038 [US4] Implement real condition change and separate material-correction Actions with ordered locks, reauthorization, confirmation-date recheck, open-year atomicity, and typed events in `app/Actions/Operations/ChangeContractCondition.php` and `app/Actions/Operations/CorrectContractCondition.php`.
- [X] T039 [US4] Implement the Contract first-economic-use predicate and extend the descriptive update Action with constrained Supplier changes, including the active-zero-Actual rule, in `app/Domain/Contracts/ContractEconomicUse.php`, `app/Actions/Operations/UpdateContract.php`, and `app/Models/Contract.php`.
- [X] T040 [US4] Add preview/confirm UI for real changes, corrections, and Supplier updates with requested/minimum/effective dates and no-prorata messaging in `app/Filament/Resources/Contracts/RelationManagers/ContractConditionsRelationManager.php`, `app/Filament/Resources/Contracts/Pages/EditContract.php`, and `app/Filament/Resources/Contracts/Schemas/ContractForm.php`.
- [X] T041 [US4] Run US4 plus earlier Contract tests, forced rollback cases, boot, and `/admin` smoke through `tests/Unit/Domain/Contracts/ContractEconomicChangePlanTest.php`, `tests/Feature/Contracts/ChangeContractEconomicsTest.php`, `tests/Feature/Contracts/ChangeContractSupplierTest.php`, and `bootstrap/app.php`.

**Checkpoint**: Economic changes are explicit, exact, revision-safe, and never
silently prorated or shifted.

---

## Phase 7: User Story 5 — Record and move Contract Actual Expenses (Priority: P2)

**Goal**: Record Contract Actuals and move whole manual Expenses among autonomous,
Project, and Contract ownership while preserving identity and counting every amount
once.

**Independent Test**: Create ordinary and declared terminal Actuals and exercise all
ownership directions, state rules, Supplier/classification inheritance, generated
Estimate rejection, stale preview, identity preservation, rollback, and totals.

### Tests for User Story 5

- [X] T042 [P] [US5] Add failing tests for ordinary Active Actuals, Planned rejection, terminal late/cessation/reimbursement/correction declarations, negative/zero Lines, archived Supplier continuity, and no cycle matching in `tests/Feature/Contracts/CreateContractActualTest.php`.
- [X] T043 [P] [US5] Extend failing ownership tests through autonomous, Project, and Contract directions with owner XOR, stable IDs, exact totals once, Supplier warning/retention, nullable annual classification, stale confirmation, and rollback in `tests/Feature/Expenses/MoveOrReclassifyExpenseTest.php` and `tests/Feature/Projects/MoveProjectExpenseTest.php`.
- [X] T044 [P] [US5] Add failing Livewire tests for conditional owner fields, Contract Actual declarations, read-only system Expenses, preview/confirm, viewer restrictions, and rejected cross-tenant URLs in `tests/Feature/Contracts/ContractExpensesRelationManagerTest.php` and `tests/Feature/Expenses/ExpenseResourceTest.php`.

### Implementation for User Story 5

- [X] T045 [US5] Extend Expense snapshots, impact plans, owner resolution, aggregates, and audit payloads for autonomous/Project/Contract ownership in `app/Domain/Expenses/ExpenseAuditSnapshot.php`, `app/Domain/Expenses/ExpenseImpactPlan.php`, `app/Models/Expense.php`, `app/Models/Exercise.php`, `app/Models/Project.php`, and `app/Models/Contract.php`.
- [X] T046 [US5] Enforce Contract Actual kind/state/Note rules and Supplier/classification derivation in `app/Domain/Contracts/ContractActualKind.php`, `app/Actions/Operations/CreateExpense.php`, `app/Actions/Operations/CreateExpenseLine.php`, `app/Actions/Operations/UpdateExpenseLine.php`, and `app/Actions/Operations/SetExpenseLineActive.php`.
- [X] T047 [US5] Implement revision-safe whole-Expense movement among every supported owner with exact previews, owner XOR, state declarations, Supplier replacement warning, classification rules, and generated-source exclusion in `app/Actions/Operations/MoveOrReclassifyExpense.php` and `app/Actions/Operations/UpdateExpense.php`.
- [X] T048 [US5] Extend Expense/Project/Contract Filament forms, tables, infolists, and relation managers for owner selection, inherited fields, Contract Actual declarations, and preview/confirm movement in `app/Filament/Resources/Expenses/Schemas/ExpenseForm.php`, `app/Filament/Resources/Expenses/Schemas/ExpenseInfolist.php`, `app/Filament/Resources/Expenses/Tables/ExpensesTable.php`, `app/Filament/Resources/Expenses/Pages/CreateExpense.php`, `app/Filament/Resources/Expenses/Pages/EditExpense.php`, `app/Filament/Resources/Projects/RelationManagers/ProjectExpensesRelationManager.php`, and `app/Filament/Resources/Contracts/RelationManagers/ContractExpensesRelationManager.php`.
- [X] T049 [US5] Run US5 and complete Expense/Project regressions, verify aggregate query counts, boot, and `/admin` smoke using `tests/Feature/Contracts/CreateContractActualTest.php`, `tests/Feature/Expenses/`, `tests/Feature/Projects/`, and `bootstrap/app.php`.

**Checkpoint**: Manual Expenses retain identity through every supported owner and
never duplicate economic contribution.

---

## Phase 8: User Story 6 — Classify, relate, attach, archive, and explain (Priority: P2)

**Goal**: Deliver annual classification, informational deadlines, bounded
Project-Contract links, private immutable attachment versions, terminal Archive, and
complete readable Timeline evidence.

**Independent Test**: Reclassify one year, filter every canonical deadline field,
link/archive/restore without economic propagation, authorize/download/detach
attachments, archive only terminal Contracts, and inspect immutable ordered events.

### Tests for User Story 6

- [X] T050 [P] [US6] Add failing domain/feature tests for annual reclassification, new-Exercise inheritance, deadline/notice calculations and filters, Project-link uniqueness/no propagation, terminal Archive/restore, and complete Timeline ordering in `tests/Unit/Domain/Contracts/ContractDeadlineTest.php`, `tests/Feature/Contracts/ReclassifyContractTest.php`, `tests/Feature/Contracts/ContractDeadlinesTest.php`, `tests/Feature/Contracts/ProjectContractLinkTest.php`, and `tests/Feature/Contracts/SetContractArchivedTest.php`.
- [X] T051 [P] [US6] Add failing private-storage tests for Contract/Expense/Line upload, checksum metadata, authenticated same-company download, guessed URL rejection, logical detach, retained blob/row, replacement identity, idempotent retry, typed Timeline events, and transaction/storage rollback with `Storage::fake('local')` in `tests/Feature/Contracts/AttachmentTest.php`.
- [X] T052 [P] [US6] Add failing Livewire tests for classification previews, deadline page fields/filters, links, attachments, Archive visibility, Timeline detail, viewer read-only mode, and absence of reminders/delete/`Sostituisce` controls in `tests/Feature/Contracts/ContractGovernanceUiTest.php`.

### Implementation for User Story 6

- [X] T053 [US6] Implement Contract annual classification impact/revision handling and extend Exercise initialization without creating values in `app/Domain/Contracts/ContractClassificationImpactPlan.php`, `app/Actions/Operations/UpdateContractClassification.php`, and `app/Actions/Operations/CreateExercise.php`.
- [X] T054 [US6] Implement deadline derivation plus Project link and Contract Archive/restore Actions with no economic propagation in `app/Domain/Contracts/ContractDeadline.php`, `app/Actions/Operations/CreateProjectContractLink.php`, `app/Actions/Operations/SetProjectContractLinkArchived.php`, and `app/Actions/Operations/SetContractArchived.php`.
- [X] T055 [US6] Implement idempotent immutable private upload, authorized streaming download, replacement, logical detachment, typed Timeline events, and blob cleanup only on failed uncommitted upload in `app/Actions/Operations/UploadAttachment.php`, `app/Actions/Operations/DetachAttachment.php`, `app/Http/Controllers/AttachmentDownloadController.php`, and `routes/web.php`.
- [X] T056 [US6] Build the read-only deadline page and governance UI integrations for classifications, links, Contract/Expense/Line attachments, Archive, and Timeline in `app/Filament/Pages/ContractDeadlines.php`, `resources/views/filament/pages/contract-deadlines.blade.php`, `app/Filament/Resources/Contracts/RelationManagers/ContractClassificationsRelationManager.php`, `app/Filament/Resources/Contracts/RelationManagers/ProjectContractLinksRelationManager.php`, `app/Filament/Resources/Contracts/RelationManagers/ContractAttachmentsRelationManager.php`, `app/Filament/Resources/Projects/RelationManagers/ProjectContractLinksRelationManager.php`, `app/Filament/Resources/Expenses/RelationManagers/ExpenseAttachmentsRelationManager.php`, `app/Filament/Resources/Expenses/RelationManagers/ExpenseLinesRelationManager.php`, and `app/Filament/Pages/CompanyAudit.php`.
- [X] T057 [US6] Run US6 plus all prior story regressions, verify private storage and tenant isolation, boot, and `/admin` smoke using `tests/Unit/Domain/Contracts/ContractDeadlineTest.php`, `tests/Feature/Contracts/`, `tests/Feature/Expenses/`, `tests/Feature/Projects/`, and `bootstrap/app.php`.

**Checkpoint**: S5 governance is inspectable and tenant-safe without reminders,
physical deletion, economic link propagation, or directed replacement.

---

## Phase 9: Polish and cross-cutting verification

**Purpose**: Prove the integrated S5 slice, preserve previous slices, and close
traceability without expanding scope.

- [X] T058 [P] Add focused N+1/query-count regression coverage for Contract lists, annual situations, deadline filters, and aggregate totals in `tests/Feature/Contracts/ContractAggregateQueryTest.php`.
- [X] T059 [P] Add persistence/restart and immutable identity coverage for Contracts, generated Expenses/Lines, renewal facts, links, attachments, and audit sequences in `tests/Feature/Contracts/ContractPersistenceTest.php`.
- [X] T060 [P] Add negative surface coverage proving no physical delete, prorata, matching, invoice/payment, reminder, carryover, Proposal/Budget/Closing, full-reporting, or `Sostituisce` UI/action exists in `tests/Feature/Contracts/ContractExcludedBehaviorTest.php`.
- [X] T061 Re-run Pint, Larastan, Composer validation/audit, the complete guarded Pest suite, and application boot; fix only S5-caused findings in `app/`, `database/`, `tests/`, `composer.json`, and `composer.lock` without modifying `vendor/` or `node_modules/`.
- [X] T062 Execute every non-destructive automated, renewal-command, browser, attachment, and restart journey and record any evidence/corrections directly in `specs/006-contracts/quickstart.md`.
- [X] T063 Reconcile implemented evidence against S5 requirements and update only substantiated statuses while leaving directed `Sostituisce` planned in `specs/000-product-roadmap/traceability.md`, `specs/000-product-roadmap/invariant-test-map.md`, and `specs/000-product-roadmap/roadmap.md`.
- [X] T064 Validate final spec/plan/task consistency, checklist state, Markdown, and absence of unresolved placeholders in `specs/006-contracts/spec.md`, `specs/006-contracts/plan.md`, `specs/006-contracts/research.md`, `specs/006-contracts/data-model.md`, `specs/006-contracts/contracts/ui.md`, `specs/006-contracts/tasks.md`, and `specs/006-contracts/checklists/requirements.md`.

---

## Phase 10: Convergence — archived activity and attachment history

**Purpose**: Close the final implementable gaps found by the post-T064 comparison
without expanding S5 or resolving directed `Sostituisce`.

- [X] T065 Add failing regressions proving Contract Timeline retains Expense/Line attachment events after ownership movement and archived Contracts reject renewal, link-state, and attachment-detachment mutations in `tests/Feature/Contracts/AttachmentTest.php` and `tests/Feature/Contracts/SetContractArchivedTest.php`.
- [X] T066 Persist the event-time Contract owner in attachment Timeline snapshots and include it in Contract filtering in `app/Actions/Operations/UploadAttachment.php`, `app/Actions/Operations/DetachAttachment.php`, and `app/Models/AuditEvent.php`.
- [X] T067 Enforce the archived-Contract boundary in renewal, link-state, and attachment-detachment Actions in `app/Actions/Operations/UpdateContractRenewal.php`, `app/Actions/Operations/SetProjectContractLinkArchived.php`, and `app/Actions/Operations/DetachAttachment.php`.
- [X] T068 Run focused convergence tests, the complete quality gate, Laravel boot, `/admin` smoke, and reconcile quickstart evidence plus roadmap status without changing FR-095 or invariant 28.60.

---

## Phase 11: Convergence — renewal retry authorization

**Purpose**: Ensure idempotent renewal retries still reauthorize the exact Company.

- [X] T069 Add a failing regression proving an existing renewal-operation receipt is not disclosed to a caller without exact-company `modifica_operativita` in `tests/Feature/Contracts/ContractAuthorizationTest.php`.
- [X] T070 Reauthorize before resolving renewal idempotency receipts while retaining the locked submission-time check in `app/Actions/Operations/UpdateContractRenewal.php`.
- [X] T071 Run the focused authorization regression, complete quality gate, Laravel boot, `/admin` smoke, and final cross-artifact consistency checks.

---

## Dependencies and execution order

### Phase dependencies

- **Phase 1** has no dependency and creates failing shared-contract tests.
- **Phase 2** depends on Phase 1 and blocks every user story.
- **US1 / Phase 3** depends on Phase 2 and is the MVP.
- **US2 / Phase 4** depends on US1 because creation uses the same annual-allocation
  engine and generated Estimate identity.
- **US3 / Phase 5** depends on US1 and US2 because lifecycle and renewal recalculate
  Contract Estimates.
- **US4 / Phase 6** depends on US2 and US3 because economic boundaries use current
  conditions, lifecycle, and renewal configuration.
- **US5 / Phase 7** depends on US1 and US2; it may start after US2 if US3/US4 are
  developed separately, but its final regression gate includes completed P1 stories.
- **US6 / Phase 8** depends on US1, US2, and US5 for annual values, Expense/Line
  attachment owners, and full Timeline integration.
- **Phase 9** depends on every story selected for delivery.
- **Phase 10** depends on T064 and contains only gaps demonstrated by the final
  cross-artifact convergence review.
- **Phase 11** depends on Phase 10 and closes the final renewal-retry authorization
  gap found by the second convergence review.

### User story completion order

```text
Foundation
└── US1 Create/inspect
    └── US2 Exact annual Estimates
        ├── US3 Lifecycle/renewal
        │   └── US4 Economic change
        └── US5 Contract Actuals/ownership
            └── US6 Governance/deadlines/evidence
```

US3 and US5 may proceed in parallel after US2 because they change separate primary
files; coordinate shared changes to `Contract.php`, Estimate recalculation, and audit
types. US4 follows US3. US6 integrates the complete preceding surface.

### Within each story

- Write the listed tests first and confirm they fail for the intended missing
  behavior.
- Implement deterministic domain values before transactional Actions.
- Implement Actions before Filament callbacks and pages.
- Reauthorize, lock, recompute, and revision-check every confirmed impact plan.
- End the phase with focused tests, guarded regressions, Laravel boot, and `/admin`
  inspection.

## Parallel opportunities

### Shared foundation

```text
T001 Audit sequencing tests
T002 Schema constraints tests
T003 Authorization tests
```

### User Story 1

```text
T012 State/annual-allocation unit tests
T013 Creation/late-census Action tests
T014 Contract Resource tests
```

### User Story 2

```text
T019 Recurrence matrix tests
T020 Condition/recalculation feature tests
T021 Condition and annual-situation UI tests
```

### User Story 3

```text
T026 Lifecycle/renewal unit tests
T027 Lifecycle transactional tests
T028 Scheduled command/idempotency tests
```

### User Story 4

```text
T034 Economic-boundary unit tests
T035 Economic-change transaction tests
T036 Supplier first-use tests
```

### User Story 5

```text
T042 Contract Actual tests
T043 Three-owner movement regressions
T044 Expense/Contract Livewire tests
```

### User Story 6

```text
T050 Classification/deadline/link/archive tests
T051 Attachment storage/authorization tests
T052 Governance UI tests
```

## Implementation strategy

### MVP first

1. Complete Phases 1 and 2.
2. Complete US1 in Phase 3.
3. Stop and validate active, planned, invalid, and late-censused Contract creation,
   first annual composition, Timeline events, boot, and tenant UI.
4. Demonstrate the MVP before adding the remaining stories if a smaller review point
   is useful.

### Incremental delivery

1. Add US2 for complete condition and annual Estimate behavior.
2. Add US3 and US4 for lifecycle, renewal, and safe economic changes.
3. Add US5 for Contract Actuals and complete first-level Expense ownership.
4. Add US6 for classification, deadlines, links, attachments, Archive, and Timeline.
5. Complete Phase 9 only after every included story checkpoint passes.

## Notes

- No task installs a dependency or edits installed source.
- No task runs `migrate:fresh`, truncates the development database, resets volumes,
  or physically deletes persisted domain objects.
- A generated Estimate is never exposed to manual mutation.
- A task touching multiple open Exercises must use one immutable preview and one
  atomic confirmed operation.
- The category-E `Sostituisce` ownership-movement gap remains documented and outside
  implementation.
