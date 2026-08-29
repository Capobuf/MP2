# Tasks: Initial Proposal and Budget v1

**Input:** Design documents from `specs/007-proposal-budget-v1/`

**Prerequisites:** `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/ui.md`, `quickstart.md`

**Tests:** Mandatory. Write behavior-focused unit, rejection, authorization,
tenancy, rollback, idempotency, concurrency and Filament tests before each
implementation increment. Run them only against the dedicated `testing` database.

**Organization:** Tasks are grouped by independently demonstrable user story. Every
phase contains at most eight substantial implementation tasks and ends with a
focused test, boot and inspectability checkpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: May proceed in parallel because it touches different files and has no
  unfinished dependency.
- **[Story]**: Maps the task to one of the five S6 user stories.
- Every implementation task names concrete files or directories.

## Phase 1: Setup and verified-baseline reconciliation

**Purpose:** Preserve S3–S5 behavior and establish the exact S6 traceability boundary.

- [X] T001 Reconcile all 16 S6 canonical FR rows, seven S6 invariant rows, informative-relation boundaries and S7–S11 boundaries in `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, and `specs/000-product-roadmap/invariant-test-map.md` without marking unbuilt behavior implemented.
- [X] T002 Run the unchanged complete Pest baseline, Laravel boot and tenant route smoke checks; record only reproducible S6 commands or corrections in `specs/007-proposal-budget-v1/quickstart.md`.
- [X] T003 Verify `.gitignore` and detected tool ignore rules cover Laravel/PHP/Node runtime artifacts without changing dependency source or adding a dependency in `.gitignore`, `.dockerignore`, and related existing configs.

**Checkpoint:** S3–S5 remain green and S6 has no traceability or dependency ambiguity.

---

## Phase 2: Foundational Proposal and Budget persistence

**Purpose:** Establish forward-only tenant-safe persistence, closed vocabularies,
immutable models and authorization shared by all stories.

**⚠️ CRITICAL:** No user-story implementation begins until this phase passes.

- [X] T004 [P] Add failing schema, company-isolation, uniqueness, source-shape, immutability and physical-deletion rejection coverage in `tests/Feature/Proposals/ProposalPersistenceTest.php` and `tests/Feature/Proposals/BudgetPersistenceTest.php`.
- [X] T005 Add forward-only S6 migrations under `database/migrations/` for Proposals, Items, Actions, Budget headers, source rows, evidence, Expense copy lineage and Proposal-owned private attachments, with restrictive same-company foreign keys and generated uniqueness from `data-model.md`; do not edit applied migrations.
- [X] T006 [P] Implement Proposal, ProposalItem and ProposalAction models/factories in `app/Models/` and `database/factories/`, including relationships, terminal immutability, revision fields and physical-delete rejection.
- [X] T007 [P] Implement BudgetSnapshot, BudgetSourceRow and BudgetEvidence models/factories in `app/Models/` and `database/factories/`, including immutable update/delete guards and autonomous casts.
- [X] T008 [P] Add closed Proposal purpose/status/source/readiness/action/reason enums in `app/Domain/Proposals/` with exhaustive label and unknown-value unit coverage in `tests/Unit/Domain/Proposals/ProposalVocabularyTest.php`.
- [X] T009 [P] Add `ProposalPolicy` and `BudgetSnapshotPolicy` in `app/Policies/`, mapping exact-company read/manage/approve capabilities and denying update/delete of terminal history, with authorization matrix coverage in `tests/Feature/Proposals/ProposalAuthorizationTest.php`.
- [X] T010 Extend Company, Exercise, Expense, Project, Contract, User and Attachment relationships plus the typed audit vocabulary in `app/Models/` and `app/Domain/Company/AuditEventType.php`, preserving existing public behavior.
- [X] T011 Run Phase 2 tests against `testing`, Laravel boot and route inspection; fix only Phase 2 regressions in the files above.

**Checkpoint:** Tenant-safe Proposal/Budget persistence and authorization exist with
no user-visible mutation path.

---

## Phase 3: User Story 1 — Deterministic Proposal initialization (Priority: P1) 🎯 MVP

**Goal:** Create the sole isolated Draft for an eligible Exercise and capture exactly
the canonical live source set with read-only Actual context.

**Independent Test:** Initialize mixed autonomous Expense, Project and Contract data;
verify exact inclusion, archived visibility, no prior-year copy, no live mutation,
concurrent uniqueness and company isolation.

- [X] T012 [P] [US1] Add failing pure inclusion and stable canonical-payload tests for all three source types and boundary states in `tests/Unit/Domain/Proposals/ProposalSourceCatalogTest.php` and `tests/Unit/Domain/Proposals/ProposalSourceSnapshotTest.php`.
- [X] T013 [P] [US1] Add failing initialization Action tests for eligibility, exact Items, read-only Actuals, archived sources, rollback, idempotency, duplicate/concurrent Drafts and cross-company rejection in `tests/Feature/Proposals/InitializeProposalTest.php`.
- [X] T014 [US1] Implement deterministic source inclusion, source snapshot ordering, whole-source fingerprinting and plan-only result extraction in `app/Domain/Proposals/ProposalSourceCatalog.php` and `app/Domain/Proposals/ProposalSourceSnapshot.php`.
- [X] T015 [US1] Implement locked atomic idempotent `InitializeProposal` in `app/Actions/Proposals/InitializeProposal.php`, including Items, initialization event and no live-source writes.
- [X] T016 [P] [US1] Add failing Filament tests for Exercise initialization affordance, tenant list/view, read-only Actual display, archived labels and disabled reasons in `tests/Feature/Proposals/ProposalInitializationUiTest.php`.
- [X] T017 [US1] Implement tenant-scoped Proposal Resource list/view foundations and the Exercise `Inizializza proposta` action under `app/Filament/Resources/Proposals/` and `app/Filament/Resources/Exercises/Pages/ViewExercise.php`.
- [X] T018 [US1] Run US1 tests against `testing`, boot the application and inspect Exercise-to-Proposal navigation without mutating the development database.

**Checkpoint:** User Story 1 is independently demonstrable and a Draft remains a
pure isolated copy of planning facts plus read-only Actual context.

---

## Phase 4: User Story 2 — Typed Expense planning (Priority: P1)

**Goal:** Prepare new, copied and existing Expense plan changes without touching live
Expenses or Actuals.

**Independent Test:** Replace Estimates, copy an autonomous Expense, create a planned
Expense and reject every Actual/container/classification operation forbidden by the
canonical rules while live rows remain unchanged.

- [X] T019 [P] [US2] Add failing action-envelope and recursive Budget-key rejection tests for Expense action shapes in `tests/Unit/Domain/Proposals/ProposalActionPayloadTest.php` and `tests/Unit/Domain/Proposals/BudgetPayloadGuardTest.php`.
- [X] T020 [P] [US2] Add failing Expense planning tests for Estimate create/change/annul/restore/zero, new Expense, canonical copy/lineage, supported ownership/reference changes, Actual-presence restrictions, rollback and retry in `tests/Feature/Proposals/PlanExpenseTest.php`.
- [X] T021 [US2] Implement strict versioned typed action validation and plan-result calculation for Expense actions in `app/Domain/Proposals/ProposalActionPayload.php` and `app/Domain/Proposals/ExpensePlan.php`.
- [X] T022 [US2] Implement atomic idempotent `PlanExpense` and `CopyExpenseIntoProposal` Actions in `app/Actions/Proposals/`, incrementing Proposal revision and appending typed events only after validation.
- [X] T023 [P] [US2] Add failing Filament tests for new/copy/Edit Estimates, lineage, Actual read-only messaging, residual repositioning and absent destructive/future controls in `tests/Feature/Proposals/ProposalExpenseUiTest.php`.
- [X] T024 [US2] Add Expense source-specific planning actions and result/lineage detail to the Proposal view under `app/Filament/Resources/Proposals/`.
- [X] T025 [US2] Run Expense Proposal tests plus focused S3 Expense regressions against `testing`, boot and inspect the UI.

**Checkpoint:** Expense planning is complete and cannot modify live reality before approval.

---

## Phase 5: User Story 2 — Typed Project planning and ProposalItem references (Priority: P1)

**Goal:** Prepare a new/existing Project, valid annual planning and a new child
Expense linked through ProposalItemID only.

**Independent Test:** Create a Planned Project and child Expense, prepare supported
state/classification/Estimate actions, reject Actual reclassification and confirm no
live Project/transition/classification/Expense exists.

- [X] T026 [P] [US2] Add failing Project plan calculation and ProposalItem reference compatibility tests in `tests/Unit/Domain/Proposals/ProjectPlanTest.php`.
- [X] T027 [P] [US2] Add failing Project planning Action tests for create, continuation, Estimate child changes, Cost Center, supported lifecycle, new-child references, stale retry and MUST NOT cases in `tests/Feature/Proposals/PlanProjectTest.php`.
- [X] T028 [US2] Implement strict Project action validation, state-at-date result calculation and ProposalItem reference rules in `app/Domain/Proposals/ProjectPlan.php` and `app/Domain/Proposals/ProposalItemReference.php`.
- [X] T029 [US2] Implement atomic idempotent `PlanProject` Action in `app/Actions/Proposals/PlanProject.php`, persisting new Items/actions without live identities.
- [X] T030 [P] [US2] Add failing Filament tests for new/existing Project planning, new child Expense linkage and absent Riporto/Riprogrammazione controls in `tests/Feature/Proposals/ProposalProjectUiTest.php`.
- [X] T031 [US2] Add Project source-specific actions and ProposalItem selectors to the Proposal UI under `app/Filament/Resources/Proposals/`.
- [X] T032 [US2] Run Project Proposal tests plus focused S4 regressions against `testing`, boot and inspect the UI.

**Checkpoint:** New related Project/Expense objects exist only as Proposal Items.

---

## Phase 6: User Story 2 — Typed Contract planning (Priority: P1)

**Goal:** Prepare a new/existing Contract with valid plan-only economic, lifecycle,
renewal, deadline and classification decisions.

**Independent Test:** Create a Planned Contract, prepare a future condition and
renewal/lifecycle changes, verify requested/minimum/effective dates and reject manual
Contract Estimates, overlaps, prorata and used-Supplier changes with no live writes.

- [X] T033 [P] [US2] Add failing Contract plan/cycle/effective-date calculation tests and payload-shape rejections in `tests/Unit/Domain/Proposals/ContractPlanTest.php`.
- [X] T034 [P] [US2] Add failing Contract planning Action tests for create, continuation, condition/economic, lifecycle, renewal/deadline, Cost Center, reconfirmation, retry and rollback in `tests/Feature/Proposals/PlanContractTest.php`.
- [X] T035 [US2] Implement strict Contract typed action validation by composing verified Contract state/cycle/allocation rules in `app/Domain/Proposals/ContractPlan.php`.
- [X] T036 [US2] Implement atomic idempotent `PlanContract` Action in `app/Actions/Proposals/PlanContract.php`, including requested/minimum/effective date evidence and no live writes.
- [X] T037 [P] [US2] Add failing Filament tests for new/existing Contract planning, explicit date confirmation and absent manual Estimate/prorata/Supplier/S8+ controls in `tests/Feature/Proposals/ProposalContractUiTest.php`.
- [X] T038 [US2] Add Contract source-specific actions, date impact display and confirmation to the Proposal UI under `app/Filament/Resources/Proposals/`.
- [X] T039 [US2] Run Contract Proposal tests plus focused S5 regressions against `testing`, boot and inspect the UI.

**Checkpoint:** All three first-level source types have typed, plan-only Proposal behavior.

---

## Phase 7: User Story 2 — Deterministic Project–Contract links (Priority: P1)

**Goal:** Prepare only canonical `Collegato a` links between live or new Project and
Contract Items without economic effects.

**Independent Test:** Link live/new endpoints in the same Proposal, reject wrong
types/company/Proposal/duplicates and every non-canonical relation request, and observe zero
allocation/state change.

- [X] T040 [P] [US2] Add failing relation validation and economic-neutrality tests in `tests/Unit/Domain/Proposals/ProposalRelationPlanTest.php`.
- [X] T041 [P] [US2] Add failing relation Action tests for live/new endpoints, duplicate, tenant, type, Proposal and non-canonical relation cases in `tests/Feature/Proposals/PlanProposalRelationTest.php`.
- [X] T042 [US2] Implement strict relation plan validation and atomic idempotent `PlanProposalRelation` in `app/Domain/Proposals/ProposalRelationPlan.php` and `app/Actions/Proposals/PlanProposalRelation.php`.
- [X] T043 [US2] Add `Collega Progetto e Contratto` UI and immutable action display under `app/Filament/Resources/Proposals/`, with no structured source-replacement input.
- [X] T044 [US2] Run relation tests and focused existing Project–Contract link regressions against `testing`, boot and inspect the UI.

**Checkpoint:** US2 is complete across Expenses, Projects, Contracts and supported relations.

---

## Phase 8: User Story 3 — Readiness, stale membership and impact review (Priority: P1)

**Goal:** Recalculate the complete Draft into exact canonical states, impacts,
warnings and explained blocks before approval.

**Independent Test:** Change one source, add a qualifying source, archive a source,
close an affected Exercise and introduce each closed inconsistency class; verify the
whole-source status and zero live effects.

- [X] T045 [P] [US3] Add failing pure readiness/state/reason and exact multi-Exercise impact tests in `tests/Unit/Domain/Proposals/ProposalReadinessTest.php` and `tests/Unit/Domain/Proposals/ProposalImpactPlanTest.php`.
- [X] T046 [P] [US3] Add failing review Action tests for changed fingerprints/revisions, new membership, archive, missing data, invalid actions/relations, closed Exercises, authorization, rollback and idempotent events in `tests/Feature/Proposals/ReviewProposalReadinessTest.php`.
- [X] T047 [US3] Implement closed-code readiness evaluation and immutable per-Exercise/source impact construction in `app/Domain/Proposals/ProposalReadiness.php` and `app/Domain/Proposals/ProposalImpactPlan.php`.
- [X] T048 [US3] Implement locked `ReviewProposalReadiness` in `app/Actions/Proposals/ReviewProposalReadiness.php`, inserting new Items as `Da prendere in visione` and never resolving S7 states.
- [X] T049 [P] [US3] Add failing Filament tests for all four statuses, exact reason messages, affected-Exercise tables and disabled approval in `tests/Feature/Proposals/ProposalReadinessUiTest.php`.
- [X] T050 [US3] Implement readiness cards, exact impact tables, warnings/blocks and refresh action under `app/Filament/Resources/Proposals/`.
- [X] T051 [US3] Run readiness tests, boot and inspect stale/new/invalid UI paths without persistent development-data mutation.

**Checkpoint:** Every approval precondition is visible, deterministic and explained.

---

## Phase 9: User Story 4 — Atomic live plan application (Priority: P1)

**Goal:** Revalidate and apply every supported typed action to live sources exactly once.

**Independent Test:** Approve mixed existing/new sources, resolve ProposalItemIDs,
then inject stale revisions and failures at each apply stage to prove no partial live
state or duplicate object.

- [X] T052 [P] [US4] Add failing approval lock/revalidation, existing-source apply, new identity/reference resolution, cross-Exercise, stale-set, authorization, rollback injection and retry tests in `tests/Feature/Proposals/ApproveProposalTest.php`.
- [X] T053 [P] [US4] Add failing pure live-apply mapping tests for every supported action family in `tests/Unit/Domain/Proposals/ProposalActionApplicationTest.php`.
- [X] T054 [US4] Implement Expense plan application and canonical copy lineage in `app/Actions/Proposals/ApplyExpensePlan.php`, reusing exact Line validation while preserving Actuals.
- [X] T055 [US4] Implement Project plan application and new Project/child Expense identity resolution in `app/Actions/Proposals/ApplyProjectPlan.php`.
- [X] T056 [US4] Implement Contract plan application with canonical conditions, lifecycle, renewal and annual allocation rules in `app/Actions/Proposals/ApplyContractPlan.php`.
- [X] T057 [US4] Implement Project–Contract link application for resolved live endpoints in `app/Actions/Proposals/ApplyProposalRelations.php`.
- [X] T058 [US4] Implement the ordered locking, reauthorization, re-enumeration and live-apply portion of `ApproveProposal` in `app/Actions/Proposals/ApproveProposal.php` with injectable failure checkpoints used only by tests.
- [X] T059 [US4] Run approval apply/rollback/retry tests plus focused S3–S5 mutation regressions against `testing`, then boot the application.

**Checkpoint:** Approval can apply the complete live plan atomically but is not yet exposed until snapshot materialization is integrated.

---

## Phase 10: User Story 4 — Budget v1, evidence and typed Timeline in the same transaction (Priority: P1)

**Goal:** Finish approval by materializing one autonomous Budget v1, retaining
evidence, appending typed events and making the Proposal terminal in the same commit.

**Independent Test:** Approve once, fail at header/row/evidence/event/status stages,
retry the same UUID and verify exactly one complete Budget/object/event set.

- [X] T060 [P] [US4] Add failing snapshot schema, recursive forbidden-key, header/row total consistency and source-detail unit tests in `tests/Unit/Domain/Proposals/BudgetSnapshotPayloadTest.php`.
- [X] T061 [P] [US4] Add failing approval materialization, evidence retention, event sequencing, status immutability, rollback-at-every-stage and retry-count tests in `tests/Feature/Proposals/ApproveProposalSnapshotTest.php`.
- [X] T062 [US4] Implement strict source-specific Budget payload construction and consistency checks in `app/Domain/Proposals/BudgetSnapshotPayload.php`.
- [X] T063 [US4] Implement Budget header/event/row materialization in dependency order and retained Draft/live attachment evidence within the existing approval transaction in `app/Actions/Proposals/MaterializeBudgetV1.php`.
- [X] T064 [US4] Complete `ApproveProposal` with deterministic Proposal/action/domain/Budget audit event sequences and terminal Proposal update in `app/Actions/Proposals/ApproveProposal.php` and `app/Domain/Company/AuditEventType.php`.
- [X] T065 [US4] Add authorized immutable evidence download routing in `app/Http/Controllers/BudgetEvidenceDownloadController.php`, `routes/web.php`, and `app/Policies/BudgetSnapshotPolicy.php`.
- [X] T066 [US4] Run complete US4 tests, boot and inspect persisted Budget/evidence/audit records through read-only commands.

**Checkpoint:** Invariant 28.23 and Budget v1 creation are fully transactional and idempotent.

---

## Phase 11: User Story 4 — Approval UI (Priority: P1)

**Goal:** Let an authorized approver confirm the exact readiness impact and reach the
created Budget v1 through Filament.

**Independent Test:** Submit aligned/stale/unauthorized/foreign/retry cases through
Livewire and verify exact notifications, no double submit and correct redirect.

- [X] T067 [P] [US4] Add failing Filament approval tests for capability, tenant, readiness, final revalidation, new Draft evidence upload, existing evidence selection, external facts, retry and redirect in `tests/Feature/Proposals/ProposalApprovalUiTest.php`.
- [X] T068 [US4] Implement the final-impact approval modal and stable operation-ID lifecycle in `app/Filament/Resources/Proposals/Pages/ViewProposal.php` and related schema/action files.
- [X] T069 [US4] Add Proposal/Budget navigation, success/failure domain notifications and contextual Exercise links under `app/Filament/Resources/Proposals/`, `app/Filament/Resources/Budgets/`, and `app/Filament/Resources/Exercises/`.
- [X] T070 [US4] Run approval UI tests, boot and inspect the authenticated Proposal-to-Budget redirect.

**Checkpoint:** The complete P1 Proposal-to-v1 journey is usable through Filament.

---

## Phase 12: User Story 5 — Immutable Budget v1 reading (Priority: P2)

**Goal:** Read autonomous Budget header, source rows/details and retained evidence
after arbitrary supported live changes or Archive.

**Independent Test:** Capture Budget payload, mutate/archive/detach live sources and
verify byte-for-byte Budget values, totals and evidence plus absent baseline Actuals.

- [X] T071 [P] [US5] Add failing autonomy/immutability tests across Expense, Project, Contract, classification, condition, Archive and detached attachment changes in `tests/Feature/Proposals/BudgetAutonomyTest.php`.
- [X] T072 [P] [US5] Add failing tenant/read-only Filament list/view/drill-down/evidence tests and forbidden-baseline assertions in `tests/Feature/Proposals/BudgetResourceTest.php`.
- [X] T073 [US5] Implement tenant-scoped read-only Budget Resource list/view schemas and pages under `app/Filament/Resources/Budgets/`, including source-specific materialized detail and evidence.
- [X] T074 [US5] Add Budget links from approved Proposal and Exercise views while retaining archived materialized labels under `app/Filament/Resources/Proposals/` and `app/Filament/Resources/Exercises/`.
- [X] T075 [US5] Extend the Company Timeline view for Proposal/Budget subjects, impacts, references and deterministic event sequences in `app/Filament/Pages/CompanyAudit.php` with focused coverage in `tests/Feature/Proposals/ProposalTimelineUiTest.php`.
- [X] T076 [US5] Run US5 and complete focused S6 tests against `testing`, boot and inspect Budget pages after live mutations.

**Checkpoint:** Budget v1 is an independently readable immutable historical reference.

---

## Phase 13: Polish, exclusions and local verification

**Purpose:** Close S6 with authoritative invariant/MUST NOT coverage, complete gates,
authenticated browser evidence and truthful traceability.

- [X] T077 [P] Add or tighten S6 rejection tests for Actual/Forecast/Residual/Variance/Closing fields, physical deletion, non-v1 creation, realignment resolution, Riporto/Riprogrammazione, non-canonical relations, fuzzy matching, parallel Drafts and other S7–S11 controls in `tests/Feature/Proposals/ProposalExcludedBehaviorTest.php`.
- [X] T078 [P] Add explicit primary-invariant mapping tests for 28.17, 28.19–28.21, 28.23, 28.47–28.48 and canonical FR coverage documentation in `tests/Feature/Proposals/S6InvariantTest.php` and `specs/007-proposal-budget-v1/quickstart.md`.
- [X] T079 Run Pint, PHPStan, complete Pest, `composer validate --strict`, `composer audit`, Laravel boot and `git diff --check`; fix only S6 or exposed regression defects in their owning files.
- [X] T080 Execute the authenticated browser sequence in `specs/007-proposal-budget-v1/quickstart.md`, including Expense/Project/Contract actions, new linked objects, approval, live mutation/archive, immutable Budget re-read, authorization and console/network error inspection.
- [X] T081 Run normal Sail stop/start persistence and local `/admin` HTTP checks without resetting the development database, then re-open the stored Proposal and Budget.
- [X] T082 Reconcile all task checkboxes and implementation evidence in `specs/007-proposal-budget-v1/tasks.md` and `quickstart.md`; leave S6 as implemented until convergence and all gates are complete.

**Verification evidence (2026-08-21):** Pint, PHPStan, Composer validation/audit,
Laravel boot and `git diff --check` pass. After correcting the obsolete S5 exclusion
assertion and the browser-exposed regressions, the isolated complete Pest suite
passes 402 tests/3012 assertions in 90.28 seconds. T079 is complete and S6 is
`verified`.

**Checkpoint:** S6 is locally verified and independently demonstrable.

---

## Phase 14: Convergence

- [X] T083 CRITICAL complete Expense planning, UI and rejection coverage for ownership, open-Exercise moves, Supplier, direct Cost Center, reverse/restore, Estimate annul/restore/zero and residual-plan repositioning per S6-FR-015–S6-FR-017 and US2/AC1–AC3 (partial).
- [X] T084 Complete Project child-Expense planning, supported Estimate changes, annual Cost Center and lifecycle UI/application coverage per S6-FR-018 and US2/AC4/AC6 (partial).
- [X] T085 CRITICAL replace client-supplied Contract economic minima with canonical S5 calculation/reconfirmation and complete condition-overlap, lifecycle, renewal/deadline, annual Cost Center, no-prorata and economic-Supplier rejection coverage per S6-FR-019–S6-FR-020 and US2/AC5 (contradicts).
- [X] T086 Build exact multi-Exercise/source readiness impact with old/new allocation and state, unchanged Budget references, other Drafts made stale, warnings, closed reasons and full semantic action/relation revalidation per S6-FR-024–S6-FR-031 and SC-003–SC-004 (partial).
- [X] T087 CRITICAL complete approval lock/revalidation and canonical live-application audit so successful and failed attempts, every applied mutation, before/after facts, affected Exercises, references and deterministic retry counts are explained without partial effects per S6-FR-031–S6-FR-034 and S6-FR-047–S6-FR-048 (partial).
- [X] T088 Replace generic Budget detail with strict source-specific autonomous payloads and drill-down, including owner labels, Estimate Lines, transitions, relations, Contract composition/deadlines, approval references and detail-to-row-to-header consistency checks per S6-FR-036–S6-FR-046 and US5/AC1–AC5 (partial).
- [X] T089 Complete Proposal Item/readiness detail and approval confirmation UI with baseline/result allocation, per-Item reasons, warnings, unchanged Budgets, affected sources/Exercises and final impact while preserving stable operation identity per UI contract and US3–US4 (partial).
- [X] T090 Add same-company database integrity for Budget evidence Attachment references and complete evidence/attachment/external-approval Timeline visibility and authorization coverage per S6-FR-001, S6-FR-045–S6-FR-048 and SC-009 (partial).

---

## Phase 15: Second convergence remediation

- [X] T091 CRITICAL project every planned Contract condition change into overlap/readiness validation and reject duplicate economic changes against the same superseded condition, with approval regression coverage per S6-FR-019, S6-FR-029 and canonical §§12.9, 12.14, 18.
- [X] T092 Validate an Expense destination Project against the complete same-Proposal Project timeline, for both live Project IDs and ProposalItemIDs, so only Planned/Open destinations receive new planning per S6-FR-016 and canonical §12.7.
- [X] T093 CRITICAL make automatic source enumeration include an autonomous reversed Expense when it still owns Estimates or Actuals, mark it read-only as applicable, and cover the exact inclusion/exclusion predicate per S6-FR-007–S6-FR-009 and canonical §7.6.2.
- [X] T094 Restore an existing archived Project–Contract relation on approval instead of creating a second identity, preserving its note/revision and emitting the typed restoration audit evidence per S6-FR-021, S6-FR-047–S6-FR-048 and canonical §§6.16, 12.11, 22.4.
- [X] T095 CRITICAL add tenant-scoped manual inclusion of an eligible Closed/Cancelled Project or Cessated/Cancelled Contract as a baseline Proposal Item, with audit and UI actions, so subsequent explicit reopen/reactivation planning is possible per S6-FR-007, S6-FR-018–S6-FR-019 and canonical §7.6.3.

---

## Dependencies and execution order

### Phase dependencies

- Phase 1 preserves the baseline and starts first.
- Phase 2 depends on Phase 1 and blocks every story.
- US1 depends on Phase 2.
- US2 Expense, Project, Contract and relation phases proceed in that order because
  later Items reference earlier Proposal source primitives.
- US3 depends on complete action coverage from US2.
- US4 live application depends on US3 readiness; snapshot materialization and UI
  follow the live apply foundation.
- US5 depends on completed approval and autonomous snapshot persistence.
- Polish depends on all stories.

### Within each phase

- Write the listed tests before production code and observe the intended failure.
- Persist models before Actions, Actions before Filament wiring.
- Use one stable operation UUID per user attempt and rotate only after success.
- End with focused `testing` database tests, boot and UI inspection.
- Do not continue while the current checkpoint is red.

### Parallel opportunities

- `[P]` test/model/policy tasks touch separate files after their prerequisites.
- Pure payload/inclusion tests can be prepared independently from Action tests.
- Source-specific application code uses separate files but the prescribed execution
  remains sequential so every phase checkpoint is auditable.

## Implementation strategy

1. Preserve and verify the S3–S5 baseline.
2. Build tenant-safe immutable persistence and closed vocabularies.
3. Deliver exact isolated initialization.
4. Add typed plan actions one source family at a time.
5. Add deterministic readiness before any approval mutation.
6. Apply the plan and materialize Budget v1 in one idempotent transaction.
7. Complete read-only Budget UI, exclusions, gates and browser validation.
8. Run convergence; append and implement any remaining S6 work before verification.
