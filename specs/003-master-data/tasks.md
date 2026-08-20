# Tasks: Master Data

**Input:** Design documents in `specs/003-master-data/`  
**Prerequisites:** S1 application, Company tenancy, exact-company capabilities, and
isolated MySQL `testing` database

**Tests:** Required by the constitution and S2 specification. Every user-story phase
starts with focused behavior/rejection tests and is validated before the next phase.

## Format

`[ID] [P?] [Story?] Description with file path`

- **[P]**: May run independently after its phase prerequisites.
- **[USn]**: Maps to the numbered user story in `spec.md`.

## Phase 1: Setup and roadmap reconciliation

**Purpose:** Lock the S2 boundary and reconcile the first Archive/no-delete invariant
ownership before code is introduced.

- [X] T001 Confirm S2 quality, category A–E conclusions, and no unresolved clarification in `specs/003-master-data/spec.md` and `specs/003-master-data/checklists/requirements.md`
- [X] T002 Reconcile FR-009, FR-010, FR-082, the S2 foundation of FR-083, and invariants 28.44–28.46 in `specs/000-product-roadmap/traceability.md` and `specs/000-product-roadmap/invariant-test-map.md`
- [X] T003 Confirm the no-new-package/native-Resource design and S3+ exclusions in `specs/003-master-data/research.md`, `specs/003-master-data/plan.md`, and `docs/plugin-register.md`

**Checkpoint:** S2 has complete artifacts, no category-E blocker, and explicit roadmap
ownership without moving Project/Contract annual Cost Center behavior into S2.

---

## Phase 2: Foundational master-data boundary

**Purpose:** Add forward schema, stable models, ownership relationships, retry
identity, deletion protection, factories, and exact-company authorization shared by
all stories.

**Critical:** No user-story phase starts until the schema/model boundary passes on
the isolated `testing` database.

- [X] T004 [P] Add failing schema, ownership, non-uniqueness, active/archive-scope, and physical-deletion rejection tests in `tests/Feature/MasterData/MasterDataBoundaryTest.php` (S2-FR-001, S2-FR-003–013, S2-FR-021)
- [X] T005 Add forward-only Supplier, Contact, Cost Center, and audit operation-ID schema in `database/migrations/*_create_suppliers_table.php`, `*_create_supplier_contacts_table.php`, `*_create_cost_centers_table.php`, and `*_add_operation_id_to_audit_events_table.php`
- [X] T006 [P] Add `Supplier`, `SupplierContact`, and `CostCenter` models with deletion rejection, casts, relationships, and active/archive scopes in `app/Models/` and extend `app/Models/Company.php`
- [X] T007 [P] Add Supplier, Contact, and Cost Center factories in `database/factories/SupplierFactory.php`, `database/factories/SupplierContactFactory.php`, and `database/factories/CostCenterFactory.php`
- [X] T008 Extend S2 event types and retry identity in `app/Domain/Company/AuditEventType.php` and `app/Models/AuditEvent.php`
- [X] T009 Implement exact-company read and `gestisce_anagrafiche` mutation policies in `app/Policies/SupplierPolicy.php`, `app/Policies/SupplierContactPolicy.php`, and `app/Policies/CostCenterPolicy.php`
- [X] T010 Run `tests/Feature/MasterData/MasterDataBoundaryTest.php`, Laravel boot, and current S1 focused tests to validate the foundational checkpoint

**Checkpoint:** Persisted S2 identities are company owned, non-unique descriptive
values remain allowed, archived rows are directly resolvable, and ordinary deletion
is rejected.

---

## Phase 3: User Story 1 — Manage suppliers (P1)

**Goal:** Authorized users create and maintain stable Supplier identities with the
canonical required/optional data and no implicit deduplication.

**Independent Test:** Create duplicate-name/VAT Suppliers, update one without changing
its identity, retry creation/update safely, reject cross-company or unauthorized
mutations, and roll back changes when audit persistence fails.

- [X] T011 [P] [US1] Add failing Supplier creation/update validation, duplicate-value, exact-company authorization, no-op, retry, and rollback tests in `tests/Feature/MasterData/SupplierTest.php` (S2-FR-002–005, S2-FR-017–021)
- [X] T012 [P] [US1] Add failing Supplier list/view/create/edit and guessed-tenant URL tests in `tests/Feature/MasterData/SupplierResourceTest.php` (S2-FR-001–005, S2-FR-021)
- [X] T013 [US1] Implement transactional idempotent Supplier creation in `app/Actions/MasterData/CreateSupplier.php`
- [X] T014 [US1] Implement locked no-op-aware Supplier update in `app/Actions/MasterData/UpdateSupplier.php`
- [X] T015 [US1] Add tenant-scoped Supplier resource, schema, and table in `app/Filament/Resources/Suppliers/SupplierResource.php`, `Schemas/SupplierForm.php`, and `Tables/SuppliersTable.php`
- [X] T016 [US1] Add Supplier list, create, view, and edit pages wired to Actions in `app/Filament/Resources/Suppliers/Pages/`

**Checkpoint:** Suppliers are independently usable, duplicate descriptive values do
not merge identities, and every real create/update is atomic with exactly one event.

---

## Phase 4: User Story 2 — Maintain supplier contacts (P1)

**Goal:** Authorized users add and update subordinate optional Contacts without
inventing roles or a Contact deletion/archive lifecycle.

**Independent Test:** Create/update Contacts with zero or several free role tags,
prove all fields may remain optional, reject cross-company requests, retry safely,
and verify no delete/archive action exists.

- [X] T017 [P] [US2] Add failing Contact optional-field, free-tag, authorization, no-op, retry, and rollback tests in `tests/Feature/MasterData/SupplierContactTest.php` (S2-FR-006–009, S2-FR-017–021)
- [X] T018 [P] [US2] Add failing Supplier Contact relation-manager visibility/mutation and no-delete-action tests in `tests/Feature/MasterData/SupplierContactResourceTest.php` (S2-FR-006–009, S2-FR-013, S2-FR-021)
- [X] T019 [US2] Implement transactional Contact creation and update in `app/Actions/MasterData/CreateSupplierContact.php` and `app/Actions/MasterData/UpdateSupplierContact.php`
- [X] T020 [US2] Add read-for-viewers and create/edit-for-managers Contact UI without delete/archive controls in `app/Filament/Resources/Suppliers/RelationManagers/ContactsRelationManager.php`

**Checkpoint:** Contacts remain inside one tenant-scoped Supplier, optional role tags
stay truthful/free, and the UI exposes no unstated Contact lifecycle.

---

## Phase 5: User Story 3 — Manage cost-center identities (P2)

**Goal:** Authorized users create and rename stable Cost Center identities without
annual classification or economic behavior.

**Independent Test:** Create duplicate-denomination Cost Centers, rename one while
preserving identity, reject unauthorized/cross-company mutation, retry safely, and
verify no S3+ field appears.

- [X] T021 [P] [US3] Add failing Cost Center create/rename, duplicate-denomination, authorization, no-op, retry, and rollback tests in `tests/Feature/MasterData/CostCenterTest.php` (S2-FR-002, S2-FR-010–012, S2-FR-017–021)
- [X] T022 [P] [US3] Add failing Cost Center list/view/create/edit, guessed-tenant URL, and S3+ field-exclusion tests in `tests/Feature/MasterData/CostCenterResourceTest.php` (S2-FR-010–012, S2-FR-021–023)
- [X] T023 [US3] Implement transactional idempotent Cost Center creation in `app/Actions/MasterData/CreateCostCenter.php`
- [X] T024 [US3] Implement locked no-op-aware Cost Center rename in `app/Actions/MasterData/RenameCostCenter.php`
- [X] T025 [US3] Add tenant-scoped Cost Center resource, schema, and table in `app/Filament/Resources/CostCenters/CostCenterResource.php`, `Schemas/CostCenterForm.php`, and `Tables/CostCentersTable.php`
- [X] T026 [US3] Add Cost Center list, create, view, and edit pages wired to Actions in `app/Filament/Resources/CostCenters/Pages/`

**Checkpoint:** Cost Centers have stable company-owned identities and no Exercise,
classification, hierarchy, allocation, or monetary behavior.

---

## Phase 6: User Story 4 — Archive and restore master data (P2)

**Goal:** Archive/restore Suppliers and Cost Centers non-destructively while
preserving direct historical resolution, identity, data, Contacts, and audit.

**Independent Test:** Archive/restore both entity types, prove active/archive/all
filters, same-ID persistence, retained Contacts, deletion rejection, no-op/retry
idempotency, exact-company authorization, and rollback on audit failure.

- [X] T027 [P] [US4] Add failing Archive/restore identity, historical-resolution, retained-Contact, no-op, retry, authorization, deletion, and rollback tests in `tests/Feature/MasterData/ArchiveRestoreTest.php` (S2-FR-013–021)
- [X] T028 [P] [US4] Extend resource tests for active-default, archived/all filters, viewability, and absent delete actions in `tests/Feature/MasterData/SupplierResourceTest.php` and `tests/Feature/MasterData/CostCenterResourceTest.php`
- [X] T029 [US4] Implement locked idempotent Supplier Archive/restore in `app/Actions/MasterData/SetSupplierArchived.php`
- [X] T030 [US4] Implement locked idempotent Cost Center Archive/restore in `app/Actions/MasterData/SetCostCenterArchived.php`
- [X] T031 [US4] Wire Supplier Archive/restore actions and active/archived/all filters in `app/Filament/Resources/Suppliers/Tables/SuppliersTable.php` and `Pages/ViewSupplier.php`
- [X] T032 [US4] Wire Cost Center Archive/restore actions and active/archived/all filters in `app/Filament/Resources/CostCenters/Tables/CostCentersTable.php` and `Pages/ViewCostCenter.php`

**Checkpoint:** Archive only changes visibility/selectability, restoration preserves
identity/history, and no persisted S2 object is physically deleted.

---

## Phase 7: User Story 5 — Inspect master-data history (P2)

**Goal:** The existing company Timeline explains every S2 mutation with a complete,
immutable, company-scoped event envelope.

**Independent Test:** Produce all S2 event types, inspect them newest-first with
materialized previous/new values and local effective dates, prove empty economic
dimensions are explicit, and reject cross-company visibility or audit mutation.

- [X] T033 [P] [US5] Add failing complete-envelope, event-type, ordering, no-op, and cross-company Timeline tests in `tests/Feature/MasterData/MasterDataAuditTest.php` (S2-FR-017–021)
- [X] T034 [US5] Extend Italian event labels and master-data value rendering in `app/Filament/Pages/CompanyAudit.php` and `app/Domain/Company/AuditEventType.php`
- [X] T035 [US5] Validate every S2 Action supplies the complete append-only §22.2 envelope and operation identity in `app/Actions/MasterData/` and `app/Models/AuditEvent.php`

**Checkpoint:** Company viewers can explain all S2 changes without audit mutation or
cross-company disclosure.

---

## Phase 8: Polish and cross-cutting verification

**Purpose:** Verify the complete S2 slice while preserving the live S1 environment.

- [X] T036 [P] Review Italian labels, empty states, confirmations, accessibility text, and absence of delete/S3+ controls across `app/Filament/Resources/Suppliers/` and `app/Filament/Resources/CostCenters/`
- [X] T037 Run focused S2 tests, current S1 regression tests, and Laravel boot checks using only the isolated `testing` database
- [X] T038 Run Composer validation/audit, Pint, Larastan, and the complete Pest suite defined in `composer.json` through Sail
- [X] T039 Apply only forward S2 migrations to persistent development and execute the browser/persistence journey in `specs/003-master-data/quickstart.md` without destructive reset or CI monitoring
- [X] T040 Mark completed S2 tasks and update implementation evidence/statuses in `specs/003-master-data/tasks.md`, `specs/000-product-roadmap/roadmap.md`, `specs/000-product-roadmap/traceability.md`, and `specs/000-product-roadmap/invariant-test-map.md`

---

## Dependencies and Execution Order

### Phase dependencies

- Phase 1 has no implementation dependency.
- Phase 2 depends on Phase 1 and blocks every user story.
- Phase 3 depends on Phase 2 and establishes Supplier identity.
- Phase 4 depends on Phase 3 because Contacts are subordinate to Suppliers.
- Phase 5 depends only on Phase 2 but follows P1 stories for checkpoint discipline.
- Phase 6 depends on Phases 3–5 so both archivable entity types exist.
- Phase 7 depends on Phases 3–6 so every S2 event type exists.
- Phase 8 depends on all user stories.

### User story dependency graph

```text
Foundation -> US1 Suppliers -> US2 Contacts
          \-> US3 Cost Centers
US1 + US3 -----------------> US4 Archive/restore
US1 + US2 + US3 + US4 ----> US5 Timeline
```

### Parallel opportunities

- T004, T006, and T007 touch separate test/model/factory files after migration names
  are fixed, but Phase 2 remains checkpointed as one unit.
- T011 and T012 are separate direct-action and UI test files.
- T017 and T018 are separate Contact action and relation-manager test files.
- T021 and T022 are separate Cost Center action and UI test files.
- T027 and T028 separate domain Archive behavior from UI filters/actions.
- T036 may run independently after all resource behavior is stable.

## Implementation Strategy

### MVP first

1. Complete roadmap reconciliation and foundational schema/model isolation.
2. Complete US1 Supplier creation/update and validate it independently.
3. Add Contacts as the second P1 increment without introducing their unstated
   lifecycle.

### Incremental delivery

1. Add Cost Center identities with no annual behavior.
2. Add non-destructive Archive/restore and historical resolution.
3. Extend the existing Timeline for all master-data mutations.
4. Run focused, full local, browser, and persistence validation; do not monitor CI.

## Notes

- Every task follows the required checkbox, sequential ID, optional `[P]`, story
  label, and concrete path format.
- No phase contains more than eight implementation tasks.
- Tests precede their corresponding implementation work.
- S2 adds no dependency and intentionally does not implement annual Cost Center
  classification or any economic entity.

## Implementation evidence

- All 40 S2 tasks are complete and all eight phase checkpoints passed locally.
- Focused S1/S2 verification passed with 66 tests and 625 assertions against the
  dedicated `testing` database.
- The complete local gate passed with 77 tests and 657 assertions, valid/audited
  Composer metadata, Pint, Larastan, Laravel boot, and HTTP reachability.
- Only the four forward S2 migrations were applied to the persistent development
  database; the complete browser journey and a normal container stop/start preserved
  all data.
- Remote CI was intentionally not monitored, so S2 is `implemented`, not `verified`.
