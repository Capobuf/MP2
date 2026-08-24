# Tasks: Reportistica ed esportazione

**Input**: Design documents from `/specs/012-reporting-exports/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Required by the user and specification for deterministic rules, authoritative invariants, MUST NOT cases, tenant isolation, UI, PDF and browser verification.

**Organization**: Tasks are grouped by user story and executed one coherent phase at a time.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can be performed in parallel because it changes distinct files and has no dependency on another incomplete task in the same phase.
- **[Story]**: Maps the task to a user story in `spec.md`.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Add only the dependency and directories justified by the approved PDF decision.

- [X] T001 Add `dompdf/dompdf:^3.1.6` through Composer, update `composer.json`/`composer.lock`, run strict validation and locked audit, and create the Reporting namespaces under `app/Domain/Reporting/`, `app/Actions/Reporting/`, and `app/Support/Reporting/`

**Checkpoint**: Dependency resolves without advisories or framework conflicts; no dependency source is modified.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Establish the closed reporting vocabulary and validated in-memory structures used by every story.

**⚠️ CRITICAL**: No user story work begins before this phase passes focused tests.

- [X] T002 [P] Implement closed enums and Italian labels for report kinds, references, categories, modification dimensions and secondary labels—explicitly excluding `Sostituito`—in `app/Domain/Reporting/ReportKind.php`, `ActualReference.php`, `ComparisonCategory.php`, `ModificationDimension.php`, and `SecondaryLabel.php`
- [X] T003 [P] Implement immutable normalized structures for references, sources and results in `app/Domain/Reporting/ReportReference.php`, `ReportSource.php`, and `ReportResult.php`
- [X] T004 Implement explicit `ReportDefinition` input validation and per-report reference requirements without defaults/fallbacks in `app/Domain/Reporting/ReportDefinition.php`
- [X] T005 Add focused vocabulary and definition tests, including unknown values, missing references, mismatched year measures and absence of `Sostituito`, in `tests/Unit/Domain/Reporting/ReportDefinitionTest.php`

**Checkpoint**: Foundation ready; all unsupported/incoherent definitions fail explicitly before queries.

---

## Phase 3: User Story 1 — Leggere la situazione annuale senza ambiguità (Priority: P1) 🎯 MVP

**Goal**: Generate an authorized annual executive report with explicit metadata and distinct Budget, Current, Closing and Current Knowledge measures.

**Independent Test**: An Exercise with Budget v1/v2 and Closing renders all required measures, exact selected Budget/Actual references and canonical dates; an unauthorized company viewer is rejected.

### Tests for User Story 1

- [X] T006 [P] [US1] Add annual metadata/reference and formula coverage for open, closed, no-Budget and missing-Closing cases in `tests/Feature/Reporting/ReportBuilderTest.php`
- [X] T007 [P] [US1] Add exact-company `visualizza`, guessed-tenant and cross-company reference rejection coverage in `tests/Feature/Reporting/ReportAuthorizationTest.php`

### Implementation for User Story 1

- [X] T008 [US1] Implement tenant-authorized Budget, live and Closing first-level source normalization plus canonical annual reference dates in `app/Actions/Reporting/BuildReport.php`
- [X] T009 [US1] Implement annual executive totals/header with explicit Budget and Actual labels in `app/Actions/Reporting/BuildReport.php`
- [X] T010 [US1] Create the tenant-scoped Filament Report page with no implicit selections, dependent options and explicit validation in `app/Filament/Pages/Reports.php`
- [X] T011 [US1] Render initial, empty, validation and annual-result states with complete header and accessible controls in `resources/views/filament/pages/reports.blade.php`
- [X] T012 [US1] Run US1 focused tests and inspect the authenticated annual page for correct labels and no console/Livewire errors

**Checkpoint**: US1 works independently as a read-only annual executive report.

---

## Phase 4: User Story 2 — Spiegare ogni variazione con classificazione deterministica (Priority: P1)

**Goal**: Compare explicit references with exact identity rules, one primary category, overlapping labels and neutral child detail.

**Independent Test**: Standalone Expense, Project children and Contract system/manual rows produce exactly one primary source each, all four primary categories and no inferred autonomous matching.

### Tests for User Story 2

- [X] T013 [P] [US2] Add pure category, dimension, label, zero-net `HaEffettivi`, reversal and no-fuzzy-matching cases in `tests/Unit/Domain/Reporting/ComparisonEngineTest.php`
- [X] T014 [P] [US2] Add authoritative INV-28.50/28.51 tests for first-level labels, exactly-one category and non-additive overlapping labels in `tests/Feature/Reporting/S11InvariantTest.php`

### Implementation for User Story 2

- [X] T015 [US2] Implement exact OriginKey correlation, explicit CopiedFrom derivation and primary category/dimension comparison in `app/Domain/Reporting/ComparisonEngine.php`
- [X] T016 [US2] Implement canonical secondary-label rules for unplanned, planned-not-occurred, without-actuals, state, carryover, corrections, attribution and explicit deadline interval in `app/Domain/Reporting/ComparisonEngine.php`
- [X] T017 [US2] Build neutral Project/Contract child facts, delta explanations, available events/reasons/evidence and the canonical insufficient-explanation marker in `app/Actions/Reporting/BuildReport.php`
- [X] T018 [US2] Extend `resources/views/filament/pages/reports.blade.php` with category/label counts, comparison rows and progressive drill-down without counting child rows as primary
- [X] T019 [US2] Run US2 focused tests and manually reconcile category counts against unique first-level sources

**Checkpoint**: US2 independently proves FR-087/FR-088 and INV-28.50/28.51.

---

## Phase 5: User Story 3 — Comprendere correzioni successive alla Chiusura (Priority: P1)

**Goal**: Compose Current Knowledge from immutable Closing values plus separately visible late corrections and non-economic annotations.

**Independent Test**: Closing Actual 100 with +30/-10 corrections and an annotation reports 100, +30, -10, +20 and 120 while Closing residual/saving/carryover remains byte-for-byte unchanged.

### Tests for User Story 3

- [X] T020 [P] [US3] Add Closing-vs-Current-Knowledge, positive/negative/individual correction, annotation and archived-label cases in `tests/Feature/Reporting/ClosedKnowledgeReportTest.php`
- [X] T021 [P] [US3] Add rejection tests proving reports cannot mutate/recalculate Closing, Budget, carryover, historical attribution or actual-source identity in `tests/Feature/Reporting/ReportingMustNotTest.php`

### Implementation for User Story 3

- [X] T022 [US3] Normalize closed-year Current Knowledge from `ClosingSourceRow` plus `LateCorrection.source_origin_key` while preserving materialized labels, classification, state, residual/saving/unused/carryover in `app/Actions/Reporting/BuildReport.php`
- [X] T023 [US3] Attach positive, negative, net and individual corrections plus affected Historical Error Annotations with zero economic impact in `app/Actions/Reporting/BuildReport.php`
- [X] T024 [US3] Extend `resources/views/filament/pages/reports.blade.php` with distinct Closing/Current Knowledge sections and immutable Closing labels
- [X] T025 [US3] Run US3 focused tests and inspect a Closed Exercise report before/after archive to verify materialized autonomy

**Checkpoint**: US3 independently proves FR-043/FR-096 and never recalculates a Closing decision.

---

## Phase 6: User Story 4 — Approfondire aggregazioni e report specialistici (Priority: P2)

**Goal**: Deliver all named report families and full drill-down with exact, non-duplicated Project, Contract and Supplier aggregation.

**Independent Test**: Every §25.14-25.22 report builds from valid references and reconciles its total through children/lines; Supplier totals include dedicated no-supplier/carryover buckets without adding Project totals.

### Tests for User Story 4

- [X] T026 [P] [US4] Add report-kind matrix tests for Budget vs Actual, Budget vs Current Allocation, Operational Variance, Budget versions, Exercises, Carryovers, Contracts and Projects in `tests/Feature/Reporting/SpecializedReportsTest.php`
- [X] T027 [P] [US4] Add Supplier attribution and authoritative INV-28.52 double-counting regressions across standalone, Project, Contract and carryover amounts in `tests/Unit/Domain/Reporting/ReportingAggregationTest.php` and `tests/Feature/Reporting/S11InvariantTest.php`

### Implementation for User Story 4

- [X] T028 [US4] Implement non-duplicating executive/source and Supplier aggregations with reconciliation components in `app/Domain/Reporting/ReportAggregator.php`
- [X] T029 [US4] Implement all §25.14-25.22 report projections, same-measure year comparison and explicit Contract interval behavior in `app/Actions/Reporting/BuildReport.php`
- [X] T030 [US4] Complete deep drill-down for cost centers, sources, child expenses, lines, conditions/cycles, carryovers, events and annotations in `resources/views/filament/pages/reports.blade.php`
- [X] T031 [US4] Run US4 focused tests and reconcile every specialist total to `0.00` difference from its components

**Checkpoint**: US4 covers every named report and proves INV-28.52 regression safety.

---

## Phase 7: User Story 5 — Esportare un report semanticamente completo (Priority: P2)

**Goal**: Download one authenticated PDF containing the same complete semantics and drill-down as the generated report.

**Independent Test**: A valid report returns a `%PDF-` attachment containing all metadata, definitions and values; cross-tenant input is rejected and no export/report record or file remains.

### Tests for User Story 5

- [X] T032 [P] [US5] Add PDF signature, headers, semantic content, UI equivalence, no-persistence and renderer security coverage in `tests/Feature/Reporting/ReportPdfTest.php`
- [X] T033 [P] [US5] Add Livewire coverage for export visibility, explicit definition parameters and unauthorized/cross-tenant download rejection in `tests/Feature/Reporting/ReportUiTest.php`

### Implementation for User Story 5

- [X] T034 [P] [US5] Implement a project-owned escaped A4 PDF template with complete header, definitions, rows, drill-down, corrections and annotations in `resources/views/reports/pdf.blade.php`
- [X] T035 [US5] Implement one-document Dompdf rendering with DejaVu Sans, remote resources disabled and restricted chroot in `app/Support/Reporting/ReportPdfRenderer.php`
- [X] T036 [US5] Implement authenticated server-side report reconstruction and sanitized attachment response in `app/Http/Controllers/ReportPdfController.php` and register the guarded route in `routes/web.php`
- [X] T037 [US5] Add the `Esporta PDF` action to `app/Filament/Pages/Reports.php` and `resources/views/filament/pages/reports.blade.php` using definition parameters only
- [X] T038 [US5] Run US5 focused tests, download/open the PDF and inspect required semantics and absence of persisted artifacts

**Checkpoint**: US5 independently fulfills the clarified PDF contract and §25.24.

---

## Phase 8: Polish & Cross-Cutting Verification

**Purpose**: Prove the full vertical story, quality gate and traceability without overstating verification.

- [X] T039 [P] Add final cross-story regression coverage for all relevant MUST NOT rules and FR-S11-001-FR-S11-036 gaps in `tests/Feature/Reporting/ReportingMustNotTest.php` and `tests/Feature/Reporting/ReportingRequirementsTest.php`
- [X] T040 Run all focused Reporting Unit/Feature tests, Pint on touched files and PHPStan; fix only failures caused by S11
- [X] T041 Execute the complete authenticated browser journey in `specs/012-reporting-exports/quickstart.md`, including every report family, drill-down, PDF opening, tenant rejection and console/Livewire checks
- [X] T042 Run the full `.github/workflows/quality.yml` gate in the isolated testing environment plus `git diff --check`
- [X] T043 Review the complete diff against all six canonical FRs, INV-28.50/28.51, INV-28.52 regression, scope exclusions, dependency policy and Snapshot immutability
- [X] T044 Record exact automated/browser/PDF/CI evidence in `specs/012-reporting-exports/quickstart.md` and mark every completed task in `specs/012-reporting-exports/tasks.md`
- [X] T045 Update S11 rows in `specs/000-product-roadmap/roadmap.md`, `traceability.md`, and `invariant-test-map.md` to `implemented`; change to `verified` only if independent demonstration and CI evidence satisfy the roadmap definition, and leave S9 unchanged without separate formal evidence

**Checkpoint**: S11 is reported honestly as implemented or verified according to recorded evidence; unresolved S9 status is explicit.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: starts immediately.
- **Phase 2 Foundation**: depends on T001 and blocks all stories.
- **US1**: starts after Phase 2 and establishes source loading/UI shell.
- **US2**: depends on normalized sources from US1.
- **US3**: depends on US1 source loading and US2 comparison result shape.
- **US4**: depends on US1-US2; can be developed alongside US3 only if changes to `BuildReport.php` are coordinated.
- **US5**: depends on a complete `ReportResult`; PDF template work T034 can start after the result contract stabilizes.
- **Phase 8**: depends on all selected stories.

### User Story Dependencies

```text
Foundation
   └── US1 Annual executive
          └── US2 Comparison semantics
                 ├── US3 Closed knowledge
                 └── US4 Specialist aggregation
                        └── US5 PDF export
                               └── Full verification
```

### Parallel Opportunities

- T002 and T003 touch distinct foundational files.
- T006/T007, T013/T014, T020/T021, T026/T027 and T032/T033 are independent test files within their phases.
- T034 can proceed separately from controller/renderer implementation after `ReportResult` is stable.
- T039 can be prepared while browser demo data is being assembled, but T040-T045 remain sequential evidence gates.

## Parallel Examples

### User Story 2

```text
Task T013: Pure comparison engine tests in tests/Unit/Domain/Reporting/ComparisonEngineTest.php
Task T014: Authoritative invariant tests in tests/Feature/Reporting/S11InvariantTest.php
```

### User Story 4

```text
Task T026: Specialist report matrix in tests/Feature/Reporting/SpecializedReportsTest.php
Task T027: Supplier/double-counting tests in tests/Unit/Domain/Reporting/ReportingAggregationTest.php and S11InvariantTest.php
```

### User Story 5

```text
Task T032: PDF response/content/security tests in tests/Feature/Reporting/ReportPdfTest.php
Task T033: Filament export interaction/authorization tests in tests/Feature/Reporting/ReportUiTest.php
Task T034: Project-owned PDF Blade template in resources/views/reports/pdf.blade.php
```

## Implementation Strategy

### MVP First

1. Complete Setup and Foundation.
2. Complete US1.
3. Stop and validate the annual executive report independently.

### Incremental Delivery

1. US1 makes references and annual measures observable.
2. US2 adds canonical classification and drill-down semantics.
3. US3 preserves Closing versus Current Knowledge.
4. US4 completes specialist reports and aggregation.
5. US5 exports the same result to PDF.
6. Phase 8 proves the full slice and updates evidence/status conservatively.

## Notes

- Tests are required but are not a license to duplicate framework behavior or build a second reporting implementation.
- `[P]` means different files and no incomplete dependency, not permission to edit the same shared file concurrently.
- No task may introduce `Sostituisce`, `Sostituito`, fuzzy matching, Forecast, arbitrary as-of reconstruction, queue/cache or report persistence.
- The development database must never be reset; destructive test operations remain confined to `testing`.
