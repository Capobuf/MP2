---
description: "Implementation tasks for shared-hosting installer and release ZIP"
---

# Tasks: Installazione shared hosting e release ZIP

**Input**: Design documents from `/specs/014-shared-hosting-installer/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`

**Tests**: Explicitly required by the feature; coverage remains focused on installation/deploy risks.

**Organization**: Tasks are grouped by user story. No phase contains more than eight implementation tasks.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: can run in parallel because it changes independent files and has no incomplete dependency.
- **[Story]**: maps to the user stories in `spec.md`.
- Every task includes concrete repository paths.

## Phase 1: Setup

**Purpose**: Add the reviewed dependency together with the production-only state binding so package auto-discovery never leaves local/testing intercepted at a phase checkpoint.

- [X] T001 Add exact dependency `relayercore/laravel-installer:1.5.0` through Composer, move unused runtime tool `laravel/tinker` from `require` to `require-dev`, and commit the resulting `composer.json` and `composer.lock` without manual lockfile edits.
- [X] T002 Immediately create `app/Installer/Support/Mp2InstallationStateManager.php`, bind it in `app/Providers/AppServiceProvider.php` so non-production is always installed and production is marker-backed, and reassert `app.debug=false` in production after the earlier package provider boot.
- [X] T003 Create `config/installer.php` from the pinned package config and `app/Installer/Steps/CheckRequirements.php` as a thin package subclass: set MP2 name/theme, PHP >=8.3, the exact runtime extension list, WeasyPrint 69.0 runtime diagnosis, `memory_limit=-1` as valid, non-blocking OPcache, admin model/callbacks, production seeder and final MP2 step order.
- [X] T004 [P] Create `lang/vendor/installer/it/installer.php` with complete Italian copy for all package strings used by the configured pipeline.
- [X] T005 [P] Create `.env.production.example` with the production-safe shared-hosting baseline and no APP_KEY, Sail credentials, or `DEV_ADMIN_*`.
- [X] T006 Make `database/seeders/DatabaseSeeder.php` production-safe by removing the current test-user creation while leaving `app/Console/Commands/EnsureDevAdmin.php` responsible for local development credentials.

**Checkpoint**: Dependency and static configuration are ready; package auto-discovery does not intercept local/testing and production debug remains false.

---

## Phase 2: Foundational Installation Safety

**Purpose**: Prove the foundational package integration before implementing mutating steps.

- [X] T007 Add `tests/Feature/Installation/InstallerAvailabilityTest.php` covering local/testing pass-through, production-like uninstalled gating via test binding, post-marker `/install` blocking, `APP_DEBUG=false` after provider boot and unlimited-memory requirements behavior.
- [X] T008 Run the focused foundation + installation availability tests and verify the existing dev login remains reachable without `storage/installed`.
- [X] T009 Add `INSTALLER_TEST_DATABASE=testing_installer` and fail-closed schema creation/grant to `.github/workflows/quality.yml`; create `tests/Feature/Installation/InstallerDatabaseSafetyTest.php` to verify `APP_ENV=testing`, a `testing_` target distinct from default `testing`, and a separate connection, then inspect the runtime step list and verify no native environment or migration step remains active.

**Checkpoint**: Adding the package no longer changes the development/testing access model.

---

## Phase 3: User Story 1 - Installare una nuova istanza dalla Web UI (Priority: P1) 🎯 MVP

**Goal**: Configure a production instance and migrate a fresh MySQL database entirely from the wizard.

**Independent Test**: With a prepared empty MySQL schema and writable temporary `.env`, the configured steps validate the host, write the instance settings and build the full MP2 schema without creating demo/test users.

### Tests for User Story 1

- [X] T010 [P] [US1] Create `tests/Feature/Installation/EnvironmentConfigurationTest.php` covering MySQL-only state, a non-empty MySQL password surviving the main-form submit, existing-schema connection success/failure, no database auto-create, safe `.env` writes and no sensitive logging.
- [X] T011 [P] [US1] Create `tests/Feature/Installation/MigrationSafetyTest.php` against dedicated schema `testing_installer`, repeating the fail-closed environment/name/connection assertions before any destructive setup and covering immediate empty recheck, production-safe seed, partial migration failure without cleanup and blocked retry until a new explicit reset.

### Implementation for User Story 1

- [X] T012 [US1] Implement `app/Installer/Steps/ConfigureEnvironment.php` to validate the public URL and existing MySQL schema, test the specific DB connection, write `APP_URL` and `DB_*`, verify the environment writer result, and never create a database.
- [X] T013 [P] [US1] Override `resources/views/vendor/installer/steps/environment.blade.php` to expose only URL + MySQL fields and an Italian connection-test UX, and `resources/views/vendor/installer/steps/migrations.blade.php` to remove the native demo-data toggle/copy.
- [X] T014 [US1] Implement `app/Installer/Steps/RunMigrations.php` to recheck tables/views on the effective `mysql` connection immediately before forward migrations, run only the production-safe seeder, never wipe on failure and block retry when a partial schema remains.
- [X] T015 [US1] Update `config/installer.php` so the configured pipeline uses the MP2 environment/migration steps and no generic database auto-create/migration step from the package.
- [X] T016 [US1] Verify the fresh-database scenario from `quickstart.md` with focused Pest tests.

**Checkpoint**: A fresh database can be configured and migrated from the browser path.

---

## Phase 4: User Story 2 - Azzerare consapevolmente un database non vuoto (Priority: P1)

**Goal**: Allow reuse of a non-empty DB without ever making destructive behavior implicit.

**Independent Test**: A sentinella table survives all invalid/no-confirmation attempts and disappears only after the exact database-name confirmation; the step then proves the schema is empty.

### Tests for User Story 2

- [X] T017 [P] [US2] Create `tests/Feature/Installation/DatabasePreparationTest.php` on `testing_installer`, repeating the fail-closed environment/name/connection assertions before any wipe and never using `testing`/`mp2`; cover empty DB, table+view non-empty block, wrong confirmation, exact confirmation, reset permission failure, actual configured target and post-reset table/view verification.

### Implementation for User Story 2

- [X] T018 [US2] Implement `app/Installer/Steps/PrepareDatabase.php` to inspect the effective configured MySQL schema, expose table/view state, validate the exact configured DB-name confirmation and run/verify `db:wipe --database=mysql --drop-views --force` only after explicit authorization.
- [X] T019 [P] [US2] Create `resources/views/vendor/installer/steps/prepare-database.blade.php` with the destructive warning, target DB name, typed confirmation and clear empty/ready states.
- [X] T020 [US2] Connect `PrepareDatabase` and `RunMigrations` through the configured pipeline; both must read the target from the reloaded MySQL config and migration must independently recheck emptiness rather than trust client state or a stale readiness flag.
- [X] T021 [US2] Verify a simulated migration failure leaves the partial schema, direct retry is blocked, and only a fresh exact-name confirmation in `PrepareDatabase` can authorize its removal.

**Checkpoint**: Non-empty database handling satisfies the destructive-action contract.

---

## Phase 5: User Story 3 - Creare l'amministratore e configurare lo scheduler (Priority: P1)

**Goal**: Finish with a valid platform admin, explicit scheduler setup and a locked installer.

**Independent Test**: Native admin validation creates an MP2 platform admin; scheduler strings use the real artisan path and require confirmation; finalization leaves a valid key and marker and blocks `/install`.

### Tests for User Story 3

- [X] T022 [P] [US3] Create `tests/Feature/Installation/AdminFinalizationTest.php` covering `is_platform_admin=true`, no invented password policy, direct/premature `finish()` rejection, final APP_KEY change/validity, marker write failure, marker creation and post-install route gating.
- [X] T023 [P] [US3] Create `tests/Feature/Installation/SchedulerConfigurationTest.php` covering absolute artisan path, full crontab, command-only form, editable PHP CLI command and mandatory confirmation.

### Implementation for User Story 3

- [X] T024 [US3] Implement `app/Installer/Callbacks/PromotePlatformAdmin.php` and wire it as `on_admin_created` in `config/installer.php`.
- [X] T025 [US3] Implement `app/Installer/Steps/ConfigureScheduler.php` and `resources/views/vendor/installer/steps/scheduler.blade.php` with copyable strings and confirmation checkbox.
- [X] T026 [US3] Implement `app/Installer/Callbacks/FinalizeInstallation.php` to validate server-side completion of every configured step with scheduler last, platform-admin existence and scheduler confirmation before synchronously changing/verifying APP_KEY; make `Mp2InstallationStateManager::markInstalled()` verify marker writes.
- [X] T027 [P] [US3] Override `resources/views/vendor/installer/steps/admin.blade.php` to translate hard-coded UI while preserving native processing and remove the misleading strength meter/"Min. 8" copy that the native backend does not enforce.
- [X] T028 [US3] Override `resources/views/vendor/installer/installer.blade.php` and `resources/views/vendor/installer/layouts/installer.blade.php` to remove the broken Alpine `pw/pc` submit rewrite, BookFlow text and CDN confetti, then use MP2 identity, Italian success copy and `/admin/login` redirect without redesigning the wizard.
- [X] T029 [US3] Verify the complete wizard ordering and the scheduler/finalization scenarios in `quickstart.md`.

**Checkpoint**: The browser installation reaches a locked, login-ready MP2 instance.

---

## Phase 6: User Story 4 - Ottenere una release ZIP verificata a ogni push (Priority: P1)

**Goal**: Produce a self-contained, traceable deployment ZIP from every successful push and smoke-test the actual extracted archive.

**Independent Test**: A push produces `mp2-<branch>-<sha>.zip`; the archive matches the allow/deny contract, contains production-only Composer dependencies, bootstraps from a clean extraction and can migrate an isolated MySQL schema.

### Tests for User Story 4

- [X] T030 [P] [US4] Create `tests/Feature/Installation/ReleaseContractTest.php` asserting the production env template contains no dev credentials and codifying required/forbidden paths including Tinker, `public/hot`, bootstrap caches, installer progress, runtime storage and dotfiles.

### Implementation for User Story 4

- [X] T031 [US4] Update `.github/workflows/quality.yml` so branch `push` uses `branches: ['**']` while existing pull-request quality and the earlier `testing_installer` provisioning remain intact.
- [X] T032 [US4] Add `.github/workflows/hosting-release.yml`, triggered only after a successful push `Quality` run, to check out the validated SHA, install production dependencies, build Vite assets, publish installer assets, copy the exact root allowlist, and create fresh `bootstrap/cache` plus `storage` skeletons instead of copying runtime trees.
- [X] T033 [US4] Add release metadata generation to `.github/workflows/hosting-release.yml`: copy `.env.production.example` to staging `.env`, inject a cryptographically random Laravel APP_KEY, write the validated full SHA to `REVISION`, and derive a sanitized `mp2-<branch>-<short-sha>.zip` name.
- [X] T034 [US4] Add required/forbidden path validation and ZIP creation to `.github/workflows/hosting-release.yml`, explicitly preserving `.env` and `public/.htaccess` while excluding Tinker, `public/hot`, `bootstrap/cache/*.php`, `storage/installed`, installer progress and all runtime/dev state.
- [X] T035 [US4] Add a clean-extraction smoke in `.github/workflows/hosting-release.yml` that uses `testing_installer_smoke`, changes only the extracted copy's `.env`, and proves Artisan bootstrap, migrations and HTTP installer/login availability with production dependencies and no checkout paths.
- [X] T036 [US4] Upload the already-created ZIP from `Hosting Release` only after the release validations and extracted smoke succeed.

**Checkpoint**: Every valid push produces one tested deployment archive.

---

## Phase 7: Polish & Cross-Cutting Validation

**Purpose**: Verify the complete slice and leave a clean handoff for the subsequent production-update CI.

- [X] T037 Review the completed diff against `specs/014-shared-hosting-installer/contracts/release-artifact.md` and ensure `.env`/`storage` are clearly separable persistent instance state for the next slice.
- [X] T038 Run focused installation tests, then the repository-wide gate defined by `.github/workflows/quality.yml`; profile/simplify if CI duration persistently exceeds the project policy.
- [X] T039 Execute the manual scenarios in `specs/014-shared-hosting-installer/quickstart.md` that are not meaningfully covered by automation, especially copyable scheduler UX and a fresh browser installation.
- [X] T040 Inspect the final ZIP contents and the wizard in a real browser; confirm Italian copy, MP2 branding, no BookFlow/CDN references and no unrequested setup fields.
- [X] T041 Record any host-specific limitation discovered during verification in `specs/014-shared-hosting-installer/quickstart.md` without adding provider-specific implementation unless it violates the generic runtime contract.

---

## Dependencies & Execution Order

### Phase Dependencies

```text
Phase 1 Setup
    ↓
Phase 2 Foundational Safety
    ↓
Phase 3 US1 Fresh Installation
    ↓
Phase 4 US2 DB Reset Safety
    ↓
Phase 5 US3 Admin + Scheduler + Finalization
    ↓
Phase 6 US4 CI Release
    ↓
Phase 7 Final Validation
```

US2 depends on the environment connection from US1. US3 depends on successful schema installation. The release artifact in US4 must package the completed wizard, therefore its production build is intentionally last among user stories.

### Parallel Opportunities

- T004, T005 and T006 can proceed after the T001–T002 safety pair without touching the same files.
- T007 can be authored while T002/T003 are implemented, then executed afterward.
- T010/T011 test design can proceed before T012/T014 implementation.
- T017 and T019 can proceed in parallel once the DB preparation contract is fixed.
- T022 and T023 cover independent admin/finalization and scheduler paths.
- T027 can proceed independently from the scheduler backend classes.
- T030 can be authored while CI staging work T031–T036 is prepared.

## Implementation Strategy

### MVP

The first meaningful implementation checkpoint is the end of Phase 5:

```text
upload runtime tree
→ /install
→ MySQL
→ safe database preparation
→ migrations
→ admin
→ scheduler
→ login
```

At that point the installer behavior is complete. Phase 6 makes it deliverable as the requested downloadable artifact.

### Incremental Delivery

1. Add package without disturbing dev/test.
2. Make fresh install work.
3. Protect non-empty DB.
4. Complete admin/scheduler/finalization.
5. Package and smoke the exact runtime ZIP.
6. Stop. Do not implement the future production update flow in this slice.

## Notes

- Tests are included because the user explicitly requires deployability/operability verification.
- No task edits dependency source.
- No task introduces a browser-test framework.
- Any code comments added must be English and only for non-obvious constraints.
- The next slice may consume `REVISION`, `.env` persistence and storage persistence, but must not be pre-implemented here.
