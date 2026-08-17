# Tasks: Company Access and Settings

**Input:** Design documents in `specs/002-company-access-settings/`  
**Prerequisites:** S0 application and isolated MySQL test database

**Tests:** Required by the project constitution and S1 specification. Each mutation
phase starts with focused behavior and rejection tests.

## Format

`[ID] [P?] [Story?] Description with file path`

- **[P]**: May run in parallel after its phase prerequisites.
- **[USn]**: Maps to the numbered user story in `spec.md`.

## Phase 1: Setup

**Purpose:** Lock S1 scope and repair the one discovered S0 baseline-document mismatch.

- [X] T001 Confirm the resolved S1 decisions and all quality checks in `specs/002-company-access-settings/spec.md` and `specs/002-company-access-settings/checklists/requirements.md`
- [X] T002 Record the native-tenancy and no-new-package decision in `specs/002-company-access-settings/research.md` and `docs/plugin-register.md`
- [X] T003 Correct the approved development port to 9000 in `docs/decisions/technical-baseline.md`

**Checkpoint:** Plan artifacts are internally consistent and no package installation
is required.

---

## Phase 2: Foundational company boundary

**Purpose:** Add closed domain values, forward schema, models, relationships and the
authorization primitive needed by every story.

**Critical:** No user-story phase starts until this phase passes focused migration and
model tests.

- [X] T004 [P] Add canonical company enums in `app/Domain/Company/Capability.php`, `app/Domain/Company/ClosingUnclassifiedPolicy.php`, `app/Domain/Company/Setting.php`, and `app/Domain/Company/AuditEventType.php`
- [X] T005 Add forward-only S1 schema in `database/migrations/*_add_platform_admin_to_users_table.php`, `*_create_companies_table.php`, `*_create_company_capabilities_table.php`, and `*_create_audit_events_table.php`
- [X] T006 [P] Add `Company`, `CompanyCapability`, and append-only `AuditEvent` models plus casts and relationships in `app/Models/`
- [X] T007 Extend tenant/capability relationships and platform-admin state in `app/Models/User.php` and `database/factories/UserFactory.php`
- [X] T008 [P] Add `CompanyFactory` in `database/factories/CompanyFactory.php`
- [X] T009 Implement exact-company authorization methods in `app/Policies/CompanyPolicy.php` and register/discover the policy through Laravel conventions
- [X] T010 Add foundational enum, schema, relationship, and company-isolation tests in `tests/Unit/Domain/Company/` and `tests/Feature/Company/CompanyBoundaryTest.php`

**Checkpoint:** Forward migrations pass on `testing`; a user capability in Company A
does not authorize Company B.

---

## Phase 3: User Story 1 — Create a company with explicit settings (P1)

**Goal:** The S0 platform administrator creates a correctly configured company and
receives all nine capabilities atomically.

**Independent Test:** Create one company with an explicit IANA zone and verify its
defaults, ten initial audit events, nine creator assignments, tenant access, and
complete rollback for invalid or failed creation.

- [X] T011 [US1] Add company-creation success, authorization, invalid-timezone, and atomic-rollback tests in `tests/Feature/Company/CreateCompanyTest.php`
- [X] T012 [US1] Implement the transactional company creation operation in `app/Actions/CreateCompany.php`
- [X] T013 [US1] Mark the S0 administrator as platform administrator without rotating credentials in `app/Console/Commands/EnsureDevAdmin.php` and update `tests/Feature/Foundation/EnsureDevAdminCommandTest.php`
- [X] T014 [US1] Implement tenant enumeration, direct-route access, and panel-entry rules in `app/Models/User.php`
- [X] T015 [US1] Add the Italian company registration page in `app/Filament/Pages/Tenancy/RegisterCompany.php`
- [X] T016 [US1] Configure native Company tenancy and registration in `app/Providers/Filament/AdminPanelProvider.php`
- [X] T017 [US1] Add Filament registration and guessed-tenant URL tests in `tests/Feature/Company/CompanyTenancyTest.php`

**Checkpoint:** An authenticated non-platform user cannot register a company; the S0
administrator can create one and immediately enter its Dashboard.

---

## Phase 4: User Story 2 — Assign capabilities within one company (P1)

**Goal:** An authorized permission manager provisions beneficiaries out of band, then
assigns/revokes exact per-company capabilities with complete audit.

**Independent Test:** With two users and two companies, assign and revoke capabilities
in Company A, prove Company B remains unaffected, verify no-op idempotency, and reject
both missing-capability and cross-company submissions.

- [X] T018 [P] [US2] Add command contract tests in `tests/Feature/Console/ProvisionUserCommandTest.php`
- [X] T019 [P] [US2] Add capability synchronization, complete §22.2 audit envelope, no-op, self-revocation, cross-company, and rollback tests in `tests/Feature/Company/SyncCompanyCapabilitiesTest.php`
- [X] T020 [US2] Implement ordinary user provisioning in `app/Actions/ProvisionUser.php` and `app/Console/Commands/ProvisionUserCommand.php`
- [X] T021 [US2] Implement transactional capability synchronization in `app/Actions/SyncCompanyCapabilities.php`
- [X] T022 [US2] Add the Italian tenant access-management page in `app/Filament/Pages/CompanyAccess.php`
- [X] T023 [US2] Add access-management component and direct-action authorization tests in `tests/Feature/Company/CompanyAccessPageTest.php`

**Checkpoint:** The beneficiary sees only companies with explicit `visualizza`; every
real assignment change has exactly one immutable event.

---

## Phase 5: User Story 3 — Change company settings prospectively (P2)

**Goal:** An authorized settings manager changes only the three canonical settings,
with time-zone impact review and no historical rewrite.

**Independent Test:** Change each setting, preview and confirm a time-zone change,
verify one event per changed field, verify no-op behavior, and reject unauthorized or
unreviewed changes atomically.

- [X] T024 [US3] Add setting validation, authorization, preview, audit, no-op, and rollback tests in `tests/Feature/Company/UpdateCompanySettingsTest.php`
- [X] T025 [US3] Implement locked transactional setting updates in `app/Actions/UpdateCompanySettings.php`
- [X] T026 [US3] Add the Italian settings and time-zone impact-preview page in `app/Filament/Pages/CompanySettings.php`
- [X] T027 [US3] Add settings-page component tests and fixed-behavior exclusion assertions in `tests/Feature/Company/CompanySettingsPageTest.php`

**Checkpoint:** Current company settings change prospectively; prior audit and domain
history remain unchanged.

---

## Phase 6: User Story 4 — Inspect authorization and settings history (P2)

**Goal:** A company viewer inspects a read-only, company-scoped history explaining all
S1 authorization and setting changes.

**Independent Test:** View mixed S1 events newest-first, prove all required columns are
present, prove Company B rows never appear, and prove no mutation action exists.

- [X] T028 [US4] Add audit visibility, ordering, field-completeness, and cross-company rejection tests in `tests/Feature/Company/CompanyAuditPageTest.php`
- [X] T029 [US4] Add the Italian read-only company audit page in `app/Filament/Pages/CompanyAudit.php`
- [X] T030 [US4] Enforce append-only audit and the complete §22.2 event envelope at the application boundary and cover rejected update/delete attempts in `app/Models/AuditEvent.php` and `tests/Feature/Company/AuditAppendOnlyTest.php`

**Checkpoint:** Authorized viewers can explain company configuration and access
changes without any audit editing or cross-company disclosure.

---

## Phase 7: Polish and cross-cutting verification

**Purpose:** Verify the complete S1 story while preserving the live S0 environment.

- [X] T031 [P] Add Italian labels/messages and accessibility text across `app/Filament/Pages/` and `lang/vendor/filament-*/it/`
- [X] T032 Run focused S1 tests, full Pest, Pint, Larastan, Composer validate/audit, and application boot checks using the isolated `testing` database
- [X] T033 Apply forward migrations and `mp2:ensure-dev-admin` to persistent development, provision one harmless operator, and verify normal restart persistence without destructive reset
- [X] T034 Execute the browser journey in `specs/002-company-access-settings/quickstart.md`, including direct URL isolation and console-error inspection
- [X] T035 Update S1 traceability/invariant/roadmap statuses and implementation evidence in `specs/000-product-roadmap/traceability.md`, `specs/000-product-roadmap/invariant-test-map.md`, and `specs/000-product-roadmap/roadmap.md`
- [X] T036 Reconcile implementation against spec/plan/tasks and document any remaining limitation in `specs/002-company-access-settings/tasks.md`

---

## Dependencies and Execution Order

### Phase dependencies

- Phase 1 has no implementation dependency.
- Phase 2 depends on Phase 1 and blocks every user story.
- Phase 3 depends on Phase 2 and establishes working tenant creation.
- Phase 4 depends on Phase 3 because access management runs inside a created tenant.
- Phase 5 depends on Phase 3 and may follow Phase 4 for checkpoint discipline.
- Phase 6 depends on Phases 3–5 so all S1 event types exist.
- Phase 7 depends on all user stories.

### User story dependency graph

```text
Foundation -> US1 Company creation -> US2 Capability management
                            |-------> US3 Settings
US2 + US3 --------------------------> US4 Audit inspection
```

### Parallel opportunities

- T004 and T008 can proceed independently before model integration.
- T018 and T019 are separate command and domain-action test files.
- After US1, US2 domain work and US3 domain work touch different action/page files,
  though implementation remains phase-by-phase per repository policy.
- T031 documentation/translation review may proceed independently of quality commands
  after the UI stabilizes.

## Implementation Strategy

### MVP first

1. Complete Phase 2.
2. Complete US1 and demonstrate one real company with explicit settings and initial
   capabilities.
3. Keep the application bootable and inspectable before adding access management.

### Incremental delivery

1. Add per-company capability management and prove two-company isolation.
2. Add prospective settings changes and time-zone preview.
3. Add the unified read-only audit view.
4. Run full local and browser verification, then update roadmap evidence.

## Notes

- Every task follows the required checkbox, sequential ID, optional `[P]`, story label,
  and concrete path format.
- No phase contains more than eight implementation tasks.
- S1 adds no dependency and intentionally does not implement S2 or later entities.

## Implementation Evidence

- Local quality gate: Composer validation and audit, Pint, Larastan, and the complete
  Pest suite all pass; the suite contains 44 tests and 256 assertions.
- Persistent development: forward migrations, idempotent administrator provisioning,
  one ordinary operator, and a normal container stop/start preserve two companies,
  two users, twenty capability rows, and twenty-four audit events.
- Browser journey: company creation, exact-company capability management, time-zone
  preview and settings update, read-only audit, tenant navigation, direct URL
  isolation, and browser console inspection pass on port 9000.
- Remaining verification limitation: remote CI is intentionally not monitored at the
  user's request. S1 therefore remains `implemented`, not `verified`, until CI is
  independently confirmed green.
