# Tasks: MP2 Business Data Backup

**Input**: design artifacts in `/specs/015-business-data-backup/`

**Tests**: required by FR-BDB-042 and the requested delivery gate.

## Phase 1: Setup

**Purpose**: install only the two required integrations and align runtime declarations.

- [x] T001 Add `maatwebsite/excel:^4.0` and `yaza/laravel-google-drive-storage:^5.0` to `composer.json` and `composer.lock`
- [x] T002 [P] Declare the optional `google` filesystem disk in `config/filesystems.php` and environment examples without embedding credentials
- [x] T003 [P] Add the XLSX/Drive PHP extensions to `config/installer.php` and `.github/workflows/ci.yml`

---

## Phase 2: Foundational restore schema

**Purpose**: permit only the historical nullability required by the restore and journal successful imports.

- [x] T004 Create `database/migrations/2026_08_30_000100_prepare_business_backup_restore.php` for the exact nullable author/proposal FKs and compatible annulment checks
- [x] T005 Create `database/migrations/2026_08_30_000200_create_business_backup_imports_table.php`
- [x] T006 [P] Add `app/Models/BusinessBackupImport.php` and its Company/User relationships
- [x] T007 [P] Add migration/schema tests in `tests/Feature/BusinessBackup/BusinessBackupSchemaTest.php`

**Checkpoint**: existing local Actions still require actors; imported facts can represent an unavailable source author.

---

## Phase 3: User Story 1 — Download a readable backup (P1)

**Goal**: produce a complete, deterministic, safe XLSX for one active Company.

**Independent Test**: export minimal and representative Companies, open the workbook, and verify views, hidden contract, checksums, decimals, formula-like text, long text, attachment inventory, and authorization.

- [x] T008 [P] [US1] Implement the static V1 schemas and portable-reference registry in `app/BusinessBackup/V1/BusinessBackupContract.php` and `app/BusinessBackup/V1/PortableReferences.php`
- [x] T009 [P] [US1] Implement canonical cells, decimal/JSON encoding, checksum, and payload chunking in `app/BusinessBackup/V1/PortablePayload.php`
- [x] T010 [US1] Implement the consistent-snapshot graph collector in `app/BusinessBackup/V1/BusinessBackupCollector.php`
- [x] T011 [US1] Implement visible and hidden sheets with explicit string binding in `app/BusinessBackup/V1/BusinessBackupWorkbook.php`
- [x] T012 [US1] Implement authorized temporary artifact generation in `app/Actions/BusinessBackup/ExportBusinessBackup.php`
- [x] T013 [US1] Add the tenant `Backup dati` download page in `app/Filament/Pages/BusinessDataBackup.php`
- [x] T014 [P] [US1] Add contract/serialization tests in `tests/Unit/BusinessBackup/BusinessBackupContractTest.php`
- [x] T015 [US1] Add export and tenant authorization tests in `tests/Feature/BusinessBackup/ExportBusinessBackupTest.php`

**Checkpoint**: US1 is independently usable as a local download.

---

## Phase 4: User Story 2 — Validate and preview restore (P1)

**Goal**: reject invalid packages before writes and show a Platform Admin a decision-ready preview.

**Independent Test**: validate a valid package and mutations covering schema, checksum, formulas, types, references, lineage, totals, duplicate system estimates, and incomplete reprogramming; assert the database is unchanged.

- [x] T016 [US2] Implement workbook loading, exact schema checks, chunk expansion, and checksum verification in `app/BusinessBackup/V1/BusinessBackupValidator.php`
- [x] T017 [US2] Add reference, enum, decimal, ownership, lineage, closing, correction, system-estimate, reprogramming, and total validation in `app/BusinessBackup/V1/BusinessBackupValidator.php`
- [x] T018 [P] [US2] Implement immutable preview data in `app/BusinessBackup/BackupPreview.php`
- [x] T019 [US2] Implement Platform Admin-only upload/preview in `app/Filament/Platform/Pages/ImportCompanyBackup.php`
- [x] T020 [P] [US2] Add validator mutation tests in `tests/Feature/BusinessBackup/BusinessBackupValidatorTest.php`
- [x] T021 [US2] Add Platform page authorization/preview/no-write tests in `tests/Feature/BusinessBackup/ImportCompanyBackupPageTest.php`

**Checkpoint**: US2 can validate and preview without creating any Company.

---

## Phase 5: User Story 3 — Restore as a new Company (P1)

**Goal**: import the validated graph atomically into a new active Tenant and make retry idempotent.

**Independent Test**: restore a valid representative package, compare the included business graph, retry it, inject a persistence failure, and confirm no users, source capabilities, audit, proposals, or attachment records were imported.

- [x] T022 [US3] Implement ordered package-ref-to-local-ID persistence in `app/Actions/BusinessBackup/ImportBusinessBackup.php`
- [x] T023 [US3] Rebuild portable origin keys, snapshot references, materialized detail, and reprogramming effects in `app/Actions/BusinessBackup/ImportBusinessBackup.php`
- [x] T024 [US3] Create Tenant membership/default capabilities for only the importing Platform Admin and write the journal in the same transaction
- [x] T025 [US3] Add final in-transaction count/total verification, duplicate-package serialization, and completed retry resolution
- [x] T026 [US3] Wire confirmation and success navigation into `app/Filament/Platform/Pages/ImportCompanyBackup.php`
- [x] T027 [P] [US3] Add atomicity/idempotency/exclusion tests in `tests/Feature/BusinessBackup/ImportBusinessBackupTest.php`
- [x] T028 [US3] Add full business-graph round-trip comparison in `tests/Feature/BusinessBackup/BusinessBackupRoundTripTest.php`

**Checkpoint**: US3 produces exactly one complete new Company or no write.

---

## Phase 6: User Story 4 — Continue normal operation (P1)

**Goal**: prove imported historical data remains usable by current MP2 workflows.

**Independent Test**: after restore, recalculate a Contract, reverse/change an active reprogramming, and approve a Revision from an imported Budget.

- [x] T029 [US4] Render null imported authors neutrally in affected Filament history views while preserving actor requirements in normal Actions
- [x] T030 [US4] Add imported Contract system-estimate recalculation test in `tests/Feature/BusinessBackup/RestoredContractContinuityTest.php`
- [x] T031 [US4] Add imported active reprogramming reversal/change test in `tests/Feature/BusinessBackup/RestoredReprogrammingContinuityTest.php`
- [x] T032 [US4] Add imported Budget-to-Revision vN+1 test in `tests/Feature/BusinessBackup/RestoredRevisionContinuityTest.php`
- [x] T033 [US4] Compare every canonical S11 report before/after restore in `tests/Feature/BusinessBackup/BusinessBackupReportingEquivalenceTest.php`

**Checkpoint**: imported materialized history supports the three required continuation workflows and equivalent reporting.

---

## Phase 7: User Story 5 — Drive and scheduler-ready command (P2)

**Goal**: store the exact generated XLSX on configured Drive and expose the same engine to operations.

**Independent Test**: compare local artifact bytes with a fake Drive write, verify unavailable UI when unconfigured, and run the command for active and archived Tenants.

- [x] T034 [US5] Implement byte-preserving Drive storage in `app/Actions/BusinessBackup/StoreBusinessBackupOnDrive.php`
- [x] T035 [US5] Add conditional Drive action/status to `app/Filament/Pages/BusinessDataBackup.php`
- [x] T036 [US5] Add `business-backup:export` in `app/Console/Commands/ExportBusinessBackupCommand.php`
- [x] T037 [P] [US5] Add fake-disk byte-equivalence tests in `tests/Feature/BusinessBackup/StoreBusinessBackupOnDriveTest.php`
- [x] T038 [P] [US5] Add command active/archived/error tests in `tests/Feature/BusinessBackup/ExportBusinessBackupCommandTest.php`

**Checkpoint**: UI and command publish the same immutable workbook through configured destinations.

---

## Phase 8: Release verification

**Purpose**: verify the whole requested contract and repository quality gate.

- [x] T039 Run focused BusinessBackup tests and fix only failures caused by this feature
- [x] T040 Execute every scenario in `specs/015-business-data-backup/quickstart.md`
- [x] T041 Inspect tenant and platform pages in a real browser, including empty, disabled, validation, error, and responsive states
- [x] T042 Review the complete diff against FR-BDB-001–048 and all acceptance scenarios
- [x] T043 Run the repository-wide CI quality gate and record any external limitation accurately

---

## Dependencies and execution order

- Phase 1 precedes XLSX/Drive code; Phase 2 precedes restore.
- US1 establishes the package consumed by US2; US2 establishes validated input consumed by US3.
- US4 requires US3. US5 requires US1 but is otherwise independent of restore.
- Tests marked `[P]` touch distinct files but implementation remains sequential in this single-agent delivery.
- No phase contains more than eight tasks; each task names an executable outcome and file path.

## Requirement traceability

| Requirements | Tasks |
|---|---|
| FR-BDB-001–012, 036–040 | T008–T015 |
| FR-BDB-013–027 | T010, T017, T022–T028 |
| FR-BDB-028 | T004, T029 |
| FR-BDB-029–035, 041 | T016–T028 |
| FR-BDB-042 | T028, T033 |
| FR-BDB-043–046 | T013, T034–T038 |
| FR-BDB-047 | T003, T043 |
| FR-BDB-048 | T008, T042 |
