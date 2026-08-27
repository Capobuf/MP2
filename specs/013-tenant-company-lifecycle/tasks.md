# Tasks: Tenant Azienda e ciclo di vita

**Input**: Design documents from `/specs/013-tenant-company-lifecycle/`

**Prerequisites**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/`

**Tests**: Required by FR-TL-045 and the user request. Add focused tests with each coherent behavior, then run the repository-wide gate only after all stories are complete.

**Organization**: Tasks are dependency-ordered. Story labels map to the six independently testable stories in `spec.md`; shared schema and tenancy work is completed first because every story depends on it.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: safe to implement in parallel because the task touches different files and has no incomplete dependency.
- **[USn]**: user story traceability.
- Every task names its target path and the requirements that must be true when complete.

## Phase 1: Shared data foundation

**Purpose**: Introduce the one-to-one technical Tenant without yet changing domain behavior.

- [x] T001 [P] Add failing MySQL-backed tests for one-to-one constraints, active default and deterministic Company backfill in `tests/Feature/Tenancy/TenantCompanyMigrationTest.php`; cover existing Company IDs, no missing/duplicate Tenant and invalid status rejection (FR-TL-001–004, FR-TL-009–010).
- [x] T002 Create `App\Domain\Company\TenantCompanyStatus` and the minimal `App\Models\TenantCompany` with `company_id` as non-incrementing primary/route key, casts, display name and required `company()` relation in `app/Domain/Company/TenantCompanyStatus.php` and `app/Models/TenantCompany.php` (FR-TL-001–004).
- [x] T003 Implement the forward migration and active backfill in `database/migrations/2026_08_26_000100_create_tenant_companies_table.php`; preserve every `companies.id`, enforce one Tenant per Company, cascade only TenantCompany when Company is later removed, and make `down()` leave Company/domain rows untouched (FR-TL-003–004, FR-TL-009–010).
- [x] T004 [P] Add `Company::tenantCompany()` in `app/Models/Company.php` and make `database/factories/CompanyFactory.php` create exactly one active linked Tenant after each Company without recursion; archived test state is obtained by updating that linked Tenant, never by creating a second one (FR-TL-001–003).
- [x] T005 Add the Filament ownership `tenantCompany()` relation to `app/Models/BudgetSnapshot.php`, `app/Models/ClosingSnapshot.php`, `app/Models/Contract.php`, `app/Models/CostCenter.php`, `app/Models/Exercise.php`, `app/Models/Expense.php`, `app/Models/Project.php`, `app/Models/Proposal.php`, and `app/Models/Supplier.php`, plus the matching inverse relations in `app/Models/TenantCompany.php` (FR-TL-002–003, FR-TL-008).
- [x] T006 Run `tests/Feature/Tenancy/TenantCompanyMigrationTest.php` on isolated MySQL and verify the count/anomaly queries from `specs/013-tenant-company-lifecycle/data-model.md`; fix only new schema/model issues before proceeding (SC-001).

**Checkpoint**: every existing Company maps to exactly one active TenantCompany with unchanged identity and domain rows.

---

## Phase 2: User Story 1A - Operational tenant authorization (Priority: P1) 🎯 MVP

**Goal**: Filament resolves `TenantCompany`, while `CompanyCapability` and active state jointly control access.

**Independent Test**: An authorized user can enter active Tenant A, cannot enter active Tenant B or an archived Tenant by direct URL, and platform admin has no domain bypass.

- [x] T007 [P] Extend `tests/Feature/Company/CompanyTenancyTest.php` and `tests/Feature/Company/CompanyBoundaryTest.php` with failing active/archived, URL-guessing and platform-admin-no-bypass cases using `TenantCompany` as the Filament tenant (FR-TL-005–007, FR-TL-014, FR-TL-044).
- [x] T008 Update `app/Models/User.php` so `getTenants()` returns only active TenantCompany records with related `visualizza`, `canAccessTenant()` validates Tenant type/status/capability, `hasCapability()` requires an active related Tenant, and `canAccessPanel()` allows `admin` only to platform admins or users with `visualizza` on at least one active Tenant while reserving the future `platform` panel strictly to platform admins (FR-TL-005–007, FR-TL-011, FR-TL-014–016, FR-TL-033).
- [x] T009 Add persistent active-tenant rejection middleware in `app/Http/Middleware/EnsureTenantCompanyIsActive.php` and configure native `TenantCompany` tenancy, ownership relationship and persistent tenant middleware in `app/Providers/Filament/AdminPanelProvider.php`; keep the numeric route key and current admin URL shape (FR-TL-006, FR-TL-008, FR-TL-014, FR-TL-044).
- [x] T010 Update the nine operational Resource roots in `app/Filament/Resources/{Budgets/BudgetResource.php,Closings/ClosingResource.php,Contracts/ContractResource.php,CostCenters/CostCenterResource.php,Exercises/ExerciseResource.php,Expenses/ExpenseResource.php,Projects/ProjectResource.php,Proposals/ProposalResource.php,Suppliers/SupplierResource.php}` to resolve the related Company and preserve explicit `company_id` scoping alongside Filament ownership association (FR-TL-002, FR-TL-008).
- [x] T011 [P] Add Resource isolation tests for automatic creation association, list/query scoping and cross-tenant record IDs in `tests/Feature/Tenancy/TenantCompanyIsolationTest.php`; cover all nine Resource model families with datasets and assert no foreign record fields leak (FR-TL-008, FR-TL-044–045).
- [x] T012 Run the focused tenancy/boundary/isolation tests and inspect `php artisan route:list --path=admin` to prove `Company` is no longer the panel tenant model while existing numeric tenant paths remain addressable for active authorized users (FR-TL-006–010, SC-001–002).

**Checkpoint**: operational tenant selection and Resource roots are secure on the new tenant model.

---

## Phase 3: User Story 1B - Operational pages, widgets and test migration (Priority: P1)

**Goal**: Remove every runtime assumption that `Filament::getTenant()` returns Company and retain all existing operational behavior.

**Independent Test**: Representative create/list/view/custom-page/widget flows operate in Tenant A, never show Tenant B and deny archived Tenant A.

- [x] T013 Update contract-specific Tenant resolution in `app/Filament/Resources/Contracts/{Pages/CreateContract.php,Schemas/ContractForm.php,Tables/ContractsTable.php}` to use the related Company without changing contract calculations or states (FR-TL-002, FR-TL-008).
- [x] T014 [P] Update expense/project Tenant resolution in `app/Filament/Resources/Expenses/{Pages/CreateExpense.php,Pages/ListExpenses.php,Schemas/ExpenseForm.php,Tables/ExpensesTable.php,Widgets/ExpenseOverview.php}` and `app/Filament/Resources/Projects/{Pages/CreateProject.php,Pages/ListProjects.php,Schemas/ProjectForm.php,Tables/ProjectsTable.php}` without changing economic behavior (FR-TL-002, FR-TL-008).
- [x] T015 [P] Update exercise/cost-center/supplier Tenant resolution in `app/Filament/Resources/Exercises/{Pages/CloseExercise.php,Pages/CreateExercise.php,Pages/ViewExercise.php}`, `app/Filament/Resources/CostCenters/Pages/CreateCostCenter.php`, and `app/Filament/Resources/Suppliers/Pages/CreateSupplier.php` (FR-TL-002, FR-TL-008).
- [x] T016 Update custom pages in `app/Filament/Pages/{CompanyAccess.php,CompanyAudit.php,CompanySettings.php,ContractDeadlines.php,Dashboard.php,Reports.php}` and widgets in `app/Filament/Widgets/{EconomicChartWidget.php,EconomicSummary.php}` to require TenantCompany and resolve its Company explicitly (FR-TL-002, FR-TL-008, FR-TL-016).
- [x] T017 Update custom Livewire context in `app/Livewire/ExerciseContextSelector.php` and `app/Livewire/ExpenseDetail.php`, including tenant switching checks, so archived/missing Tenant fails and no fallback Company is selected (FR-TL-006, FR-TL-008, FR-TL-014).
- [x] T018 Mechanically migrate every `Filament::setTenant(Company)` setup found under `tests/Feature/` to a linked `TenantCompany`, preserving the test's original Company domain fixture and assertions; use `rg "setTenant\(" tests/Feature` as a zero-omission gate (FR-TL-003, FR-TL-008, FR-TL-045).
- [x] T019 [P] Add archived/cross-tenant rejection coverage for `routes/web.php` Attachment, Budget Evidence and report PDF routes in `tests/Feature/Tenancy/TenantCompanyIsolationTest.php`, verifying policy denial and absence of response metadata/body leakage (FR-TL-016, FR-TL-044–045).
- [x] T020 Run all existing Feature groups that set a Filament tenant plus `tests/Feature/Tenancy/TenantCompanyIsolationTest.php`; confirm no domain formula, state, action or report assertion was changed to make tenancy tests pass (FR-TL-008, SC-002).

**Checkpoint**: all operational surfaces consistently translate TenantCompany to Company and existing behavior is preserved.

---

## Phase 4: User Story 2 - Archive, restore and automatic suspension (Priority: P1)

**Goal**: Super Admin can reversibly suspend a Tenant without changing its domain, and automatic processing cannot mutate it.

**Independent Test**: Archive preserves a full data snapshot but blocks UI, direct Action, download and renewals; Restore returns the identical domain and real-date processing resumes.

- [x] T021 [P] Add failing transition, authorization, preservation, redundant-transition and rollback cases in `tests/Feature/Tenancy/TenantCompanyLifecycleTest.php` and `tests/Feature/Tenancy/TenantCompanyAuthorizationTest.php`, including users with every CompanyCapability but no platform flag (FR-TL-011–016, FR-TL-019, FR-TL-043–045).
- [x] T022 Implement platform-only create/archive/restore/destroy policy methods and transactional lock/state validation in `app/Policies/TenantCompanyPolicy.php`, `app/Actions/Tenancy/ArchiveTenantCompany.php`, and `app/Actions/Tenancy/RestoreTenantCompany.php`; use Company→Tenant lock order and make invalid transitions fail explicitly (FR-TL-011–013, FR-TL-018–019, FR-TL-035, FR-TL-037).
- [x] T023 Verify the censused public actor-facing mutators under `app/Actions/{Closing,LateCorrections,MasterData,Operations,Proposals}` plus `app/Actions/{SyncCompanyCapabilities.php,UpdateCompanySettings.php}` retain Company lock and post-lock Gate; do not duplicate guards in the internal helper files classified in `research.md`, but add a representative selected-before-archive concurrency regression to `tests/Feature/Tenancy/TenantCompanyLifecycleTest.php` and fail the task if any new command/job calls such a helper as an unguarded application boundary (FR-TL-015, FR-TL-018, FR-TL-035, FR-TL-043).
- [x] T024 [P] Add archived/restore renewal cases to `tests/Feature/Contracts/ProcessContractRenewalsCommandTest.php`: excluded before actor lookup, zero domain/audit changes, other Tenant unaffected, real overdue dates processed after Restore and repeated run idempotent (FR-TL-017, FR-TL-020, FR-TL-045).
- [x] T025 Filter the selection query in `app/Console/Commands/ProcessContractRenewalsCommand.php` to Companies with active TenantCompany while preserving actor selection, multi-expiry catch-up and existing Action Gate; do not add a second scheduler or queue (FR-TL-017, FR-TL-020).
- [x] T026 Add a race regression where a renewal is selected before Archive but attempts mutation afterward in `tests/Feature/Tenancy/TenantCompanyLifecycleTest.php`, proving lock/Gate revalidation yields no post-Archive commit (FR-TL-017–018, FR-TL-045).
- [x] T027 Run lifecycle, authorization, isolation and renewal command tests, then compare all representative Company/capability/Exercise/Proposal/Budget/Snapshot/Contract/Project records before Archive and after Restore; only Tenant status/timestamps may differ (FR-TL-013, FR-TL-019–020, SC-003–004).

**Checkpoint**: Archive/Restore is reversible, secure and effective across HTTP and automatic paths.

---

## Phase 5: User Story 4 - Global Super Admin management (Priority: P1)

**Goal**: Provide a tenantless native Filament surface for active and archived Tenant lifecycle management.

**Independent Test**: A platform admin with no accessible operational Tenant can open `/platform`, see both states and run only state-valid actions; an ordinary user is denied.

- [x] T028 [P] Add failing panel access/list/action-visibility tests in `tests/Feature/Tenancy/PlatformTenantManagementTest.php`, including zero active Tenant, no CompanyCapability, ordinary user denial and active/archived datasets (FR-TL-011, FR-TL-032–035).
- [x] T029 Create the non-tenant `platform` panel with shared auth and isolated discovery in `app/Providers/Filament/PlatformPanelProvider.php`, register it in `bootstrap/providers.php`, and finalize panel-ID-specific access in `app/Models/User.php` (FR-TL-032–033).
- [x] T030 Create the read-only global Resource/list page/table in `app/Filament/Platform/Resources/TenantCompanies/TenantCompanyResource.php`, `app/Filament/Platform/Resources/TenantCompanies/Pages/ListTenantCompanies.php`, and `app/Filament/Platform/Resources/TenantCompanies/Tables/TenantCompaniesTable.php`; show only Company name/ID, active|archived, technical updated time and explicit status filter, with no bulk/edit/delete defaults (FR-TL-032–034).
- [x] T031 Wire Archive and Restore table actions to their application Actions in `app/Filament/Platform/Resources/TenantCompanies/Tables/TenantCompaniesTable.php`; use state-specific visibility plus confirmation copy from `contracts/lifecycle-actions.md`, but rely on server policy/state validation for security (FR-TL-011–012, FR-TL-034–035).
- [x] T032 Run `tests/Feature/Tenancy/PlatformTenantManagementTest.php`, inspect `/platform` route list for absence of a tenant segment, and verify operational Resources are not discovered by the platform panel nor platform Resource by admin panel (FR-TL-032–035, SC-005).

**Checkpoint**: lifecycle management is globally reachable only to Super Admin, independently of operational Tenant availability.

---

## Phase 6: User Story 3A - Destruction schema and file completion (Priority: P1)

**Goal**: Make complete database deletion structurally safe and file deletion durable without claiming distributed atomicity.

**Independent Test**: A Company root delete inside the dedicated test transaction removes the full tenant-owned fixture, preserves Users/other Tenant, and failed storage remains represented by one retryable manifest row.

- [x] T033 [P] Add schema-level full-graph and rollback tests in `tests/Feature/Tenancy/TenantCompanyDestructionTest.php`; populate every direct/indirect table listed in `data-model.md`, cycles, immutable models, shared User and a second Tenant, then prove zero target residues and unchanged global/other-tenant rows (FR-TL-024–027, FR-TL-043, FR-TL-045).
- [x] T034 Implement the forward FK migration in `database/migrations/2026_08_26_000200_enable_tenant_company_deletion.php`, first comparing MySQL `information_schema` with every constraint in `specs/013-tenant-company-lifecycle/contracts/delete-foreign-key-matrix.md`, then applying its exact CASCADE/two-SET-NULL matrix while leaving every User FK RESTRICT; provide a reversible `down()` for isolated tests and never edit historical migrations (FR-TL-024–027).
- [x] T035 [P] Add pending-manifest model tests for unique disk/path, no Company FK and persisted failure metadata in `tests/Feature/Tenancy/TenantFileDeletionTest.php` (FR-TL-028–031).
- [x] T036 Create `pending_file_deletions` and its minimal model in `database/migrations/2026_08_26_000300_create_pending_file_deletions_table.php` and `app/Models/PendingFileDeletion.php`; fields/constraints must exactly match `data-model.md` and contain no Tenant business data (FR-TL-028–030).
- [x] T037 [P] Extend `tests/Feature/Tenancy/TenantFileDeletionTest.php` for deduped same-Tenant Attachment/Evidence paths, paths referenced by another Tenant, absent file, delete false, exception, retry success, repeat no-op and other-Tenant file preservation using Laravel storage fakes/failure doubles (FR-TL-028–031, FR-TL-045).
- [x] T038 Implement exact-file idempotent cleanup in `app/Actions/Tenancy/DeletePendingTenantFiles.php` and CLI orchestration in `app/Console/Commands/DeletePendingTenantFilesCommand.php`; remove manifest only on absent/success, persist sanitized failure, return non-zero for remaining failures, and never recursively delete a directory (FR-TL-028–031).
- [x] T039 Schedule `tenant-files:cleanup` hourly beside the existing renewal schedule in `routes/console.php`, then run schema/full-graph and file cleanup tests on isolated MySQL (FR-TL-029–031, SC-006–008).

**Checkpoint**: DB cascade graph and durable storage cleanup primitives are proven independently of the UI.

---

## Phase 7: User Story 3B - Authorized permanent destruction (Priority: P1)

**Goal**: Expose one atomic database operation for active/archived Tenant with two simple, distinct confirmations.

**Independent Test**: Neither single confirmation deletes anything; both confirmations as Super Admin delete the whole target, preserve global/foreign data and truthfully report pending storage.

- [x] T040 [P] Add Action-level tests in `tests/Feature/Tenancy/TenantCompanyDestructionTest.php` for non-platform denial, every CompanyCapability combination, active/archived targets, each confirmation missing, stale target, DB exception rollback and concurrent lifecycle request (FR-TL-011, FR-TL-021–027, FR-TL-035, FR-TL-045).
- [x] T041 Implement a minimal immutable result object and `DestroyTenantCompany` in `app/Actions/Tenancy/TenantDestructionResult.php` and `app/Actions/Tenancy/DestroyTenantCompany.php`; validate two booleans server-side, generate the operation UUID internally, lock Company→Tenant, collect/dedupe target paths, exclude paths referenced by other Companies, insert the exclusive-file manifest, delete exactly one Company in one transaction, and invoke cleanup only after commit (FR-TL-021–031, FR-TL-035).
- [x] T042 Add the platform table destruction action in `app/Filament/Platform/Resources/TenantCompanies/Tables/TenantCompaniesTable.php` as two sequential Wizard steps with separate unchecked confirmations/actions, explicit categories/irreversibility copy, availability in both states and truthful complete-versus-pending notifications (FR-TL-021–023, FR-TL-030, FR-TL-034).
- [x] T043 [P] Extend `tests/Feature/Tenancy/PlatformTenantManagementTest.php` with Livewire assertions that completing only step one never calls destruction, a forged step-two-only payload is rejected, both sequential steps do call it, active/archived are supported, and pending-file copy never claims DB/storage atomicity (FR-TL-021–023, FR-TL-030, FR-TL-034–035).
- [x] T044 Verify post-delete denial for former admin/resource/Attachment/Evidence/report URLs and unaffected access to the second Tenant in `tests/Feature/Tenancy/TenantCompanyIsolationTest.php` (FR-TL-024–025, FR-TL-044–045).
- [x] T045 Run both destruction test files twice—once normal and once with injected DB/storage failures—and inspect `pending_file_deletions` to demonstrate the two allowed observable states from `contracts/destruction.md` (SC-006–008, SC-011).

**Checkpoint**: permanent destruction is complete, irreversible, Super-Admin-only and honest about file completion.

---

## Phase 8: User Story 5 - Atomic registration (Priority: P2)

**Goal**: Every future registration creates exactly one active TenantCompany and Company with existing initial capabilities/audit.

**Independent Test**: Success returns the linked Tenant and creates all expected records; injected failure at each stage leaves none.

- [x] T046 [P] Extend `tests/Feature/Company/CreateCompanyTest.php` with pair one-to-one, active default, unchanged capability/audit, non-platform denial and failure-injection rollback assertions; update `tests/Feature/Company/CompanyTenancyTest.php` for pre-persistence `Annulla` with zero records and successful registration returning a TenantCompany (FR-TL-003–004, FR-TL-036–037, FR-TL-045).
- [x] T047 Update `app/Actions/CreateCompany.php` to create TenantCompany inside the existing Company/capability/audit transaction while preserving its `Company` return contract for all current callers, without model events or a second creation path (FR-TL-003, FR-TL-036–037).
- [x] T048 Update `app/Filament/Pages/Tenancy/RegisterCompany.php` so `getModel()` names TenantCompany, Filament `canView()` is governed by platform-only `TenantCompanyPolicy::create`, then receive the Company from `CreateCompany` and return its required TenantCompany relation; preserve current fields, Company validation and pre-persistence cancel behavior (FR-TL-036–037).
- [x] T049 Re-run Company creation/tenancy tests and query for Companies without TenantCompany after factory, registration and failure cases; require zero orphans and no changed Company IDs (FR-TL-003, FR-TL-010, SC-001, SC-009).

**Checkpoint**: migration and all application creation paths enforce the same one-to-one invariant.

---

## Phase 9: User Story 6 - N+1-only closing decision (Priority: P2)

**Goal**: Remove “Gestione continuata/terminata” from implementation and persist only the N+1 decision, with no Tenant lifecycle effect.

**Independent Test**: `Non creare N+1` records `not_created`, leaves Tenant active and permits later manual N+1 creation; all removed terms disappear from app/factory/test code.

- [x] T050 [P] Add migration/model compatibility tests for mapping `not_created_management_terminated` to `not_created`, updating `closing_snapshots_next_exercise_shape`, rejecting mismatched next-exercise references and reversible rollback in `tests/Feature/Closing/ClosingSnapshotTest.php` (FR-TL-041–042, FR-TL-045).
- [x] T051 Implement the forward data/enum/check migration in `database/migrations/2026_08_26_000400_rename_next_exercise_disposition.php`, dropping/recreating `closing_snapshots_next_exercise_shape` in a safe order, mapping existing rows before narrowing values, and restoring mapping/check in `down()` for isolated tests (FR-TL-041–042).
- [x] T052 Rename closing input/review logic to `create_next_exercise` and N+1-only issue/reason codes in `app/Actions/Closing/NormalizeClosingInput.php`, `app/Actions/Closing/PrepareExerciseClosing.php`, `app/Actions/Closing/ReviewExerciseClosing.php`, and `app/Actions/Closing/CloseExercise.php`; preserve all economic, transfer, idempotency and initialization rules (FR-TL-038–042).
- [x] T053 Update disposition validation/display and UI copy in `app/Models/ClosingSnapshot.php`, `app/Filament/Resources/Closings/Schemas/ClosingInfolist.php`, `app/Filament/Resources/Exercises/Pages/CloseExercise.php`, and `resources/views/filament/resources/exercises/pages/close-exercise.blade.php` to show only `Crea N+1`/`Non creare N+1` (FR-TL-038–042).
- [x] T054 Update fixture semantics in `database/factories/HistoricalErrorAnnotationFactory.php`, `database/factories/LateCorrectionFactory.php`, and `tests/Pest.php`, plus all affected Closing tests under `tests/Feature/Closing/`, replacing removed keys/codes without weakening existing Chiusura assertions (FR-TL-041–042, FR-TL-045).
- [x] T055 Add explicit regression to `tests/Feature/Closing/CloseExerciseTest.php` and `tests/Feature/Closing/ClosingUiTest.php` proving `Non creare N+1` leaves Tenant active, performs no archive operation and allows a later authorized `CreateExercise` for N+1 (FR-TL-038–040, SC-010).
- [x] T056 Run the Closing test group and the zero-match scan from `quickstart.md`; implementation/factory/test paths must contain no removed management terminology while canonical historical text remains untouched (FR-TL-038–042, SC-010).

**Checkpoint**: N+1 is an Exercise decision only and cannot act as a second offboarding path.

---

## Phase 10: Cross-cutting verification and cleanup

**Purpose**: Prove the complete story without adding behavior.

- [x] T057 [P] Re-scan `routes/console.php`, `app/Console`, `app/Jobs`, listeners/subscribers and `ShouldQueue` references; document any newly discovered tenant-owned process in `specs/013-tenant-company-lifecycle/research.md` and apply both active-selection and locked authorization boundaries before completion (FR-TL-017–020).
- [x] T058 [P] Re-scan all `Filament::getTenant()`, `setTenant()`, Resource queries and custom `company_id` filters under `app/`, `resources/`, and `tests/`; resolve every remaining Company-as-tenant assumption or explicitly prove it is a domain Company use (FR-TL-002, FR-TL-006–010).
- [x] T059 Run the full quickstart migration-count, Archive/Restore preservation, automatic-process, destruction/failure and N+1 scenarios on isolated MySQL; record actual command results in the implementation handoff without modifying this Spec Kit into a test log (FR-TL-001–045).
- [x] T060 Run `composer validate --strict`, `npm run build`, `composer audit --locked --no-interaction`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, and the full isolated `vendor/bin/pest`; fix only failures caused by this feature (FR-TL-045).
- [x] T061 Perform authenticated real-browser verification of `/platform`, Archive denial in `/admin`, Restore, both destruction confirmations and truthful pending-file notification; inspect console, Livewire and HTTP failures and preserve accessibility/keyboard behavior (SC-005, SC-012).
- [x] T062 Review the complete diff against FR-TL-001–045 and the mandatory final checklist in the user request: no orphan Company/Tenant, no Company tenant model, no lost capability, no archived bypass/process, no User deletion, no ordinary internal deletes, no second N+1 offboarding, no package/role/multi-database or unrelated feature (SC-001–012).

---

## Dependencies & Execution Order

### Phase dependencies

- Phase 1 blocks every story.
- Phases 2–3 establish the operational tenant boundary and block lifecycle, platform UI, registration and meaningful end-to-end verification.
- Phase 4 depends on Phase 2 authorization and can be implemented before the platform UI.
- Phase 5 depends on Archive/Restore Actions from Phase 4.
- Phase 6 schema/cleanup can begin after Phase 1, but Phase 7 destruction integration depends on Phases 4–6.
- Phase 8 registration depends on Phase 2 panel tenancy.
- Phase 9 depends on Phase 1 only at the schema level, but should follow lifecycle work to make the non-coupling regression explicit.
- Phase 10 depends on every selected feature phase.

### Story dependencies

```text
Shared foundation
  └── US1A operational authorization
        ├── US1B operational surfaces
        ├── US2 Archive/Restore + processes
        │     └── US4 platform management
        │           └── US3B destruction UI
        └── US5 atomic registration

Shared foundation ──> US3A deletion schema ──> US3B destruction
Shared foundation ──> US6 N+1 semantics
```

### Parallel opportunities

- T001 and T004 can prepare tests/factory separately before T002–T003 integration.
- Resource relation/query work (T010) and isolation test datasets (T011) touch separate files.
- T013–T015 split distinct Resource families; T016 and T019 also touch independent paths.
- Process tests (T024) can proceed separately from lifecycle Action implementation after contracts are fixed.
- Destruction schema tests (T033) and manifest tests (T035) are independent; cleanup tests (T037) can be prepared while the FK migration is implemented.
- N+1 tests/migration (T050–T051) and logic/UI paths (T052–T053) are separable after the target enum is fixed.

## Implementation Strategy

### First secure increment

1. Complete Phase 1.
2. Complete Phases 2–3.
3. Stop and validate US1: active authorized access works; cross-tenant and archived access fail everywhere.

### Lifecycle increment

1. Complete Phase 4 Actions/process behavior.
2. Add Phase 5 platform management.
3. Stop and validate reversible Archive/Restore with unchanged domain.

### Destruction increment

1. Complete and validate Phase 6 schema/storage primitives.
2. Complete Phase 7 Action/UI.
3. Stop on any uncensused table, orphan, User deletion, rollback leak or untracked file failure.

### Final increments

Complete atomic registration, N+1 terminology and the full cross-cutting gate. Do not mark the feature complete from happy-path panel behavior alone.

## Requirement coverage map

| Requirement group | Owning tasks |
|---|---|
| FR-TL-001–004 one-to-one/states | T001–T006 |
| FR-TL-005–010 capability/native tenancy/migration | T007–T020 |
| FR-TL-011–020 authorization/lifecycle/processes | T021–T032 |
| FR-TL-021–031 destruction/storage | T033–T045 |
| FR-TL-032–035 platform management/concurrency | T028–T032, T040–T043 |
| FR-TL-036–037 registration | T046–T049 |
| FR-TL-038–042 N+1 | T050–T056 |
| FR-TL-043–045 safeguards/tests | T011, T019–T027, T033–T045, T054–T062 |

## Notes

- Do not edit `/vendor`, `/node_modules`, historical migrations or canonical domain text.
- Do not run destructive migration/reset commands against the persistent development database.
- A task is complete only when its stated behavior and rejection cases pass; checking the box is not evidence.
- If the live schema differs from the FK inventory when implementation begins, stop the deletion migration, update `research.md`/`data-model.md` with the exact difference and do not guess an ownership rule.
