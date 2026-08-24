# Tasks: Correzioni post-Chiusura

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/ui.md`, `quickstart.md`

**Tests**: Required by the specification and constitution. Add only focused tests that protect the current vertical behavior; run the complete quality gate in the final phase.

**Organization**: Tasks are grouped into independently demonstrable vertical slices. Each implementation phase contains at most eight substantial tasks.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files and has no unmet dependency.
- **[Story]**: Maps the task to a user story in `spec.md`.
- Every task names its exact file scope.

## Phase 1: User Story 1 — Registrare un Effettivo tardivo (Priority: P1) 🎯 MVP

**Goal**: Append one canonical late Actual correction end to end while every Closing and historical fact remains immutable.

**Independent Test**: On a Closed Exercise, append a positive or negative Actual to an explicit compatible manual Expense or create a new manual late Expense in the same historical owner context; verify authorization, tenant isolation, required declaration/reason, archived Supplier handling, retry, rollback and unchanged Closing/Budget/Carryover/history.

### Tests for User Story 1

- [X] T001 [P] [US1] Add focused persistence, append-only, same-company/same-Exercise, immutable-record and retry-receipt tests for `LateCorrection` in `tests/Feature/LateCorrections/LateCorrectionPersistenceTest.php`.
- [X] T002 [P] [US1] Add compatibility rule tests for manual/system, owner, Exercise, reversed state, Actual support and Archived historical Supplier cases in `tests/Unit/Domain/LateCorrections/HistoricalExpenseCompatibilityTest.php`.
- [X] T003 [US1] Add Action tests for compatible Expense append, incompatible/absent selection creating a new same-context Expense, positive/negative compensation, original-line reference, required closed-year declaration, rejection of a current-year amount, capability/tenant rejection, stale state, rollback and immutable Closing/Budget/Carryover/history in `tests/Feature/LateCorrections/RecordLateCorrectionTest.php` and `tests/Feature/LateCorrections/LateCorrectionImmutabilityTest.php`.

### Implementation for User Story 1

- [X] T004 [US1] Add the forward-only `late_corrections` migration, immutable `LateCorrection` model/factory, exact relationships and typed audit event in `database/migrations/2026_08_24_000100_create_late_corrections.php`, `app/Models/LateCorrection.php`, `database/factories/LateCorrectionFactory.php`, `app/Models/{Company,Exercise,ClosingSnapshot,Expense,ExpenseLine}.php`, and `app/Domain/Company/AuditEventType.php`.
- [X] T005 [US1] Implement the closed historical-Expense compatibility rule without matching or fallback in `app/Domain/LateCorrections/HistoricalExpenseCompatibility.php`.
- [X] T006 [US1] Implement locked, authorized, atomic and retry-safe late correction creation in `app/Actions/LateCorrections/RecordLateCorrection.php` and exact correction policies in `app/Policies/LateCorrectionPolicy.php` and `app/Policies/ExercisePolicy.php`; do not weaken ordinary Closed-Exercise guards.
- [X] T007 [US1] Add the Italian `Registra correzione tardiva` Closed-Exercise action, immutable correction details and owner-aware retained-evidence upload for generated correction ExpenseLines without granting ordinary Closed-year attachment powers; add focused journey, attachment authorization, unavailable-control and cross-company tests in `app/Filament/Resources/Exercises/Pages/ViewExercise.php`, `app/Filament/Resources/Exercises/Schemas/ExerciseInfolist.php`, `app/Actions/Operations/UploadAttachment.php`, `app/Policies/AttachmentPolicy.php`, and `tests/Feature/LateCorrections/LateCorrectionUiTest.php`; run Phase 1 focused tests and Laravel boot.

**Checkpoint**: An authorized user can append and inspect a late Actual correction without changing any historical row or immutable snapshot.

---

## Phase 2: User Story 2 — Annotare un errore storico (Priority: P1)

**Goal**: Append a typed, non-economic historical-error annotation with immutable evidence and zero historical mutation.

**Independent Test**: Record every closed canonical error kind against a Closed Exercise, attach optional evidence, retry and force failures; verify exact tenant/capability, immutable facts, zero economic impact and unchanged Closing/Budget/Carryover/state/classification.

### Tests for User Story 2

- [X] T008 [P] [US2] Add schema/model tests for closed error kinds, versioned recorded/correct facts, affected references, immutable annotation rows, same-company Closing reference and operation receipt in `tests/Feature/LateCorrections/HistoricalErrorAnnotationPersistenceTest.php`.
- [X] T009 [US2] Add Action tests for every canonical error kind, required facts/reason/source references, authorization, tenant isolation, stale Closed context, retry, rollback and zero economic effect in `tests/Feature/LateCorrections/RecordHistoricalErrorAnnotationTest.php`.

### Implementation for User Story 2

- [X] T010 [US2] Add the forward-only annotation/attachment-owner migration, immutable model/factory, closed enum and relationships in `database/migrations/2026_08_24_000200_create_historical_error_annotations.php`, `app/Models/HistoricalErrorAnnotation.php`, `database/factories/HistoricalErrorAnnotationFactory.php`, `app/Domain/LateCorrections/HistoricalErrorKind.php`, and `app/Models/{Company,Exercise,ClosingSnapshot,Attachment}.php`.
- [X] T011 [US2] Implement locked, authorized, atomic and retry-safe non-economic annotation creation plus exact policies/audit in `app/Actions/LateCorrections/RecordHistoricalErrorAnnotation.php`, `app/Policies/HistoricalErrorAnnotationPolicy.php`, `app/Policies/ExercisePolicy.php`, and `app/Domain/Company/AuditEventType.php`.
- [X] T012 [US2] Extend the existing attachment upload path only for immutable annotation ownership and reject detachment from correction/annotation evidence in `app/Actions/Operations/UploadAttachment.php`, `app/Policies/AttachmentPolicy.php`, and `app/Models/Attachment.php`; add focused owner, retention and authorization tests in `tests/Feature/LateCorrections/HistoricalErrorAnnotationAttachmentTest.php`.
- [X] T013 [US2] Add the Italian `Annota errore storico` action, zero-economic-impact confirmation and immutable annotation details with focused Livewire tests in `app/Filament/Resources/Exercises/Pages/ViewExercise.php`, `app/Filament/Resources/Exercises/Schemas/ExerciseInfolist.php`, and `tests/Feature/LateCorrections/HistoricalErrorAnnotationUiTest.php`; run Phase 2 focused tests and Laravel boot.

**Checkpoint**: Every canonical historical error can be recorded and inspected without an economic or historical write.

---

## Phase 3: User Story 3 — Consultare l'evidenza locale (Priority: P2)

**Goal**: Present corrections and annotations as separate immutable local histories without implementing S11 reporting.

**Independent Test**: Open Closed Exercise and Closing contexts with multiple corrections and annotations; verify complete operation detail, retained evidence, empty states, tenant isolation and the absence of report/export or mutation controls.

### Tests for User Story 3

- [X] T014 [P] [US3] Add local history read, ordering, empty-state, archived-source evidence, read authorization and direct cross-company access tests in `tests/Feature/LateCorrections/LateCorrectionHistoryTest.php`.
- [X] T015 [P] [US3] Add Closing-context Livewire tests proving corrections, annotations and immutable Closing values remain distinct and no S11 controls appear in `tests/Feature/LateCorrections/LateCorrectionHistoryUiTest.php`.

### Implementation for User Story 3

- [X] T016 [US3] Add ordered tenant-scoped correction/annotation relationships and eager loading to `app/Models/{Exercise,ClosingSnapshot,LateCorrection,HistoricalErrorAnnotation}.php` without report aggregation or event replay.
- [X] T017 [US3] Present the two Italian local evidence collections, retained attachments, immutable Closing references and explanatory zero states in `app/Filament/Resources/Exercises/Schemas/ExerciseInfolist.php` and `app/Filament/Resources/Closings/Schemas/ClosingInfolist.php`; run Phase 3 focused tests and Laravel boot.

**Checkpoint**: S10 evidence is inspectable in context while complete current-knowledge reporting remains absent.

---

## Phase 4: Cross-Cutting Verification and Delivery

**Purpose**: Prove the owning invariants, explicit exclusions, visible journeys and repository-wide compatibility before status changes.

- [X] T018 [P] Add authoritative invariant 28.29, 28.30 and 28.31 tests in `tests/Feature/LateCorrections/S10InvariantTest.php`.
- [X] T019 [P] Add explicit rejection coverage for ordinary Closed-year mutation, historical reclassification, reopening, Carryover/Snapshot/Budget recalculation, matching, report/export and other S11 behavior in `tests/Feature/LateCorrections/S10ExcludedBehaviorTest.php`.
- [X] T020 Run all focused S10 tests and Laravel boot; fix only S10-caused failures.
- [X] T021 Perform the authenticated browser late-correction and historical-annotation journeys from `quickstart.md`, including archived Supplier/evidence, terminal read-only state and absence of console/Livewire errors.
- [X] T022 Run the final repository quality gate from `quickstart.md`; fix only failures caused by S10.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 / US1** has no S10 dependency and delivers the smallest useful vertical slice.
- **Phase 2 / US2** can start after Phase 1 establishes shared correction namespace and Exercise integration; its migration is separately forward-only.
- **Phase 3 / US3** depends on both immutable record types.
- **Phase 4** depends on every selected user story.

### User Story Dependencies

- **US1** is independently demonstrable: append and inspect one late Actual correction.
- **US2** is independently demonstrable after the shared context: append and inspect one zero-impact annotation.
- **US3** integrates the two local evidence histories but adds no mutation or reporting semantics.

### Within Each User Story

- Add the named focused tests before or with the coherent behavior and confirm they defend observable contracts.
- Implement persistence and domain invariants before the Filament action.
- Run only the phase's focused tests and Laravel boot at its checkpoint.
- Do not run the complete suite until Phase 4.

### Parallel Opportunities

- T001 and T002 touch independent test levels.
- T008 can be prepared independently from the US1 UI after Phase 1 persistence is stable.
- T014 and T015 cover independent model/read and UI surfaces.
- T018 and T019 are independent final verification files.

## Implementation Strategy

### MVP First

1. Complete T001–T007 only.
2. Demonstrate one compatible-Expense correction and one new same-context Expense correction.
3. Run mandatory implementation review and correct only Phase 1 findings until `REVIEW_PASS`.

### Incremental Delivery

1. Add US1 late Actual correction.
2. Add US2 historical-error annotation and annotation evidence.
3. Add US3 integrated local history.
4. Run final invariants, browser journey and repository quality gate once.

## Notes

- No task modifies `/vendor/**`, `/node_modules/**`, installed plugin source or a used migration.
- No task adds a package, generic repository/service layer, alternate ledger, report engine, matching, fallback or compatibility path.
- Canonical Spec Kit artifacts are updated by the Sol orchestrator, not by `implementer`.
- Roadmap/traceability/invariant statuses change only after verified behavior and `implementation-reviewer` returns `REVIEW_PASS`.
