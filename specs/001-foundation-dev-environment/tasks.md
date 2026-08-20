---
description: "Task list for MP2 S0 foundation and live development environment"
---

# Tasks: Foundation and Live Development Environment

**Input:** design documents from `specs/001-foundation-dev-environment/`

**Tests:** required by the feature specification and project constitution.

**Execution rule:** implement one phase at a time. Stop at every checkpoint, run the
listed verification, and leave the application bootable.

## Format

`[ID] [P?] [Story] Description`

`[P]` means the task can be executed in parallel because it touches independent files.

## Phase 1 — Project scaffold

**Goal:** Laravel/Filament project boots before adding bootstrap automation.

- [X] **T001 [US1]** Initialize Laravel 13 in repository root using PHP 8.3-compatible
  dependencies and Pest; commit `composer.json` and `composer.lock`.
- [X] **T002 [US1]** Install Filament 5 panel builder and create
  `app/Providers/Filament/AdminPanelProvider.php` with `/admin`, native login,
  Dashboard, and no MFA.
- [X] **T003 [US1]** Install Laravel Sail as a development dependency, publish
  `compose.yaml`, keep only application + MySQL services, set PHP runtime to 8.3,
  MySQL to the 8.4 family, and host port to `${APP_PORT:-9000}`.
- [X] **T004 [P] [US1]** Configure `.gitignore` so `.env`, runtime data, test artifacts,
  `vendor/`, and any future `node_modules/` are not committed.
- [X] **T005 [P] [US1]** Add Larastan configuration in `phpstan.neon` and ensure Pint
  remains the formatter.
- [X] **T006 [US1]** Remove unused default Vite/npm scaffold files and references if
  present; verify Filament still loads without `package.json`, `vite.config.*`, npm
  install, or `@vite`.

**Checkpoint A:** start Sail and prove `/admin/login` responds before continuing.

---

## Phase 2 — Environment and safe bootstrap foundation

**Goal:** clean checkout can bootstrap without root-owned project files.

- [X] **T007 [P] [US1]** Implement `.env.example` according to
  `contracts/env-contract.md`, leaving `APP_KEY`, `DEV_ADMIN_PASSWORD`, `WWWUSER` and
  `WWWGROUP` safe/generated as specified.
- [X] **T008 [US1]** Create `scripts/bootstrap-dev.sh` with prerequisite checks,
  `.env` creation, Linux UID/GID handling, Docker-based Composer install when
  `vendor/` is absent, Sail startup, and bounded MySQL readiness wait.
- [X] **T009 [US1]** Extend `scripts/bootstrap-dev.sh` to generate `APP_KEY` only when
  blank and persist `DEV_ADMIN_PASSWORD` equal to the login email as requested.
- [X] **T010 [US1]** Ensure bootstrap uses only forward
  `php artisan migrate` against development and contains no `migrate:fresh`, truncate,
  destructive seed, or volume deletion.
- [X] **T011 [P] [US1]** Add focused shell/static verification in
  `tests/Feature/Foundation` or an appropriate project test that proves normal
  bootstrap code paths do not invoke destructive development reset commands.

**Checkpoint B1:** from an initialized checkout with no `.env`, bootstrap reaches
migrations without host PHP/Composer and project files remain writable by host user.

---

## Phase 3 — User Story 1: stable credentials and Filament login

**Goal:** owner receives reusable credentials and can enter Dashboard.

### Tests first

- [X] **T012 [P] [US1]** Add
  `tests/Feature/Foundation/EnsureDevAdminCommandTest.php` covering creation,
  idempotent rerun, and production refusal.
- [X] **T013 [P] [US1]** Add
  `tests/Feature/Foundation/FilamentAccessTest.php` covering unauthenticated panel
  protection and authenticated Dashboard access.

### Implementation

- [X] **T014 [US1]** Implement
  `app/Console/Commands/EnsureDevAdmin.php` using `DEV_ADMIN_NAME`,
  `DEV_ADMIN_EMAIL`, and `DEV_ADMIN_PASSWORD`; refuse production and keep one user per
  configured email.
- [X] **T015 [US1]** Call `mp2:ensure-dev-admin` from `scripts/bootstrap-dev.sh` and
  print local URL, detectable LAN URL, email, and persisted password at completion.
- [X] **T016 [US1]** Verify the Filament panel does not expose MFA or any S1+ domain
  Resources.

**Checkpoint B:** run bootstrap twice; same `.env` credentials work both times, one
administrator exists, Dashboard loads.

---

## Phase 4 — User Story 2: persistent live LAN development

**Goal:** owner can keep development state and watch the application from LAN.

### Tests / verification

- [X] **T017 [P] [US2]** Add
  `tests/Feature/Foundation/BootstrapPersistenceTest.php` for idempotent application
  effects that are testable without controlling Docker itself.
- [X] **T018 [US2]** Add a documented manual stop/start persistence acceptance to
  `specs/001-foundation-dev-environment/quickstart.md` and verify it against the
  implemented stack.

### Implementation

- [X] **T019 [US2]** Ensure `compose.yaml` uses a named MySQL volume and normal
  `sail stop` / `sail up -d` preserve `mp2`.
- [X] **T020 [US2]** Ensure the application port is published to the host/LAN on 9000
  without adding Nginx, HTTPS, or tunnel services.
- [X] **T021 [US2]** Implement non-fatal LAN IPv4 detection in
  `scripts/bootstrap-dev.sh`; local URL must always be printed even when detection
  fails.
- [X] **T022 [US2]** Manually verify `/admin` from a second LAN device and record the
  result in the S0 acceptance checklist/PR evidence.

**Checkpoint C:** normal container restart preserves login/data and LAN access works.

---

## Phase 5 — User Story 3: isolated tests and CI

**Goal:** automated checks can be destructive only inside the dedicated test DB.

### Tests first

- [X] **T023 [P] [US3]** Add a central test-environment safety assertion in
  `tests/TestCase.php` or equivalent, requiring `APP_ENV=testing` and DB `testing`
  before DB-reset helpers execute.
- [X] **T024 [P] [US3]** Add
  `tests/Feature/Foundation/EnvironmentIsolationTest.php` proving the safety assertion
  accepts the testing DB and rejects a development-DB configuration without actually
  resetting development data.

### Implementation

- [X] **T025 [US3]** Configure `phpunit.xml` for MySQL database `testing` and required
  deterministic test settings; do not use SQLite.
- [X] **T026 [US3]** Add `.github/workflows/quality.yml` with PHP 8.3, MySQL
  8.4-family service, Composer locked install, Composer validate/audit, Pint,
  Larastan, migrations, Pest, and application smoke verification.
- [X] **T027 [US3]** Ensure the S0 workflow does not set up Node, npm, Vite, Selenium,
  or Dusk.
- [X] **T028 [US3]** Run the complete local S0 suite, verify development credentials
  still work afterward, then run/review the GitHub Actions workflow.

**Checkpoint D:** test safety guard works and CI is green.

---

## Phase 6 — Polish and S0 verification

- [X] **T029 [P]** Update repository `README.md` with only the verified bootstrap,
  start/stop, login, test, and LAN instructions; do not duplicate the canonical
  domain.
- [X] **T030 [P]** Add concise Composer scripts for quality/focused tests only where
  they reduce command friction without hiding environment safety.
- [X] **T031** Execute every step in
  `specs/001-foundation-dev-environment/quickstart.md` from a clean checkout or clean
  reproducible clone.
- [X] **T032** Complete
  `specs/001-foundation-dev-environment/checklists/requirements.md` with evidence.
- [X] **T033** Re-run the Constitution Check in `plan.md`; document any implementation
  deviation before S0 can be marked verified.
- [X] **T034** Update `specs/000-product-roadmap/traceability.md` status for FR-099 and
  FR-100 only if S0 produced concrete enforcement/evidence; do not mark unrelated
  domain FRs implemented.
- [X] **T035** Mark roadmap S0 `verified` only after Checkpoints A–D and all S0 success
  criteria pass.

### Verification evidence — 2026-08-20

- The complete current quality gate passed against the dedicated `testing` database
  with 190 tests and 1,672 assertions; Composer validation/audit, Pint and Larastan
  also passed.
- The configured development administrator credentials still match the persisted
  administrator, the local `/admin` endpoint returns the expected redirect, and all
  forward migrations are applied without a destructive reset.
- The original S0 push run failed in Pest, but the subsequent S1, S2 and S3 `main`
  runs passed the same repository Quality workflow, including the inherited S0
  bootstrap, database isolation, test and smoke gates. S0 is therefore verified by
  the repaired cumulative baseline rather than by rewriting the failed historical
  run.

## Dependencies and execution order

```text
Phase 1
  ↓
Phase 2
  ↓
Phase 3 (US1)
  ↓
Phase 4 (US2)
  ↓
Phase 5 (US3)
  ↓
Phase 6
```

Parallel markers apply only inside the current phase. Do not start a later phase just
because one task could technically run in parallel.

## Scope guard

During these tasks DO NOT implement:

- Azienda;
- per-company capabilities;
- Shield;
- Suppliers/Contacts/Cost Centers;
- Exercises;
- Expenses/Rows;
- Projects;
- Contracts;
- Proposals/Budgets;
- Carryover/Reprogramming;
- Closing;
- Reports;
- domain Timeline.

Those remain roadmap work.
