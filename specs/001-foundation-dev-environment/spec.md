# Feature Specification: Foundation and Live Development Environment

**Feature Branch:** `001-foundation-dev-environment`  
**Created:** 2026-08-17  
**Status:** Ready for implementation  
**Roadmap ID:** S0

## User Scenarios & Testing

### User Story 1 — Clean bootstrap and login (Priority: P1)

As the project owner, I can initialize a clean Linux checkout, receive stable local
administrator credentials, open Filament, and sign in without manually installing
PHP or Composer on the host.

**Why this priority:** No later domain slice is useful if the application cannot be
bootstrapped and inspected reliably.

**Independent Test:** Start from a clean checkout with Docker and Spec Kit already
available, run the documented bootstrap, then sign in at `/admin` using the
credentials stored in `.env`.

**Acceptance Scenarios:**

1. **Given** a clean checkout with no `.env` and no `vendor/`, **When** the bootstrap
   runs, **Then** dependencies, application key, persistent DB, migrations, and the
   development administrator are created without host PHP/Composer.
2. **Given** a clean development checkout, **When** bootstrap completes, **Then** the
   explicitly requested local-only password is set equal to the login email, written
   to `.env`, and printed with the login URL.
3. **Given** the same environment is bootstrapped again, **When** bootstrap completes,
   **Then** the same credentials remain valid and no second administrator is created.
4. **Given** an unauthenticated browser, **When** it requests the Filament panel,
   **Then** access is redirected to login.
5. **Given** the development administrator credentials, **When** the user signs in,
   **Then** the standard Filament dashboard is visible.

---

### User Story 2 — Persistent live development visible on the LAN (Priority: P2)

As the project owner, I can leave the development stack running, refresh the
application from another device on the same LAN, and keep manually entered
development data across normal container restarts.

**Why this priority:** The owner wants continuous visibility into implementation
progress rather than screenshots or milestone-only demonstrations.

**Independent Test:** Create a harmless development record available in S0 or use the
administrator itself as persistence evidence, stop/start containers without deleting
volumes, and verify the record remains. Open `/admin` from another LAN device.

**Acceptance Scenarios:**

1. **Given** the stack is running, **When** a LAN device opens the printed LAN URL on
   port 9000, **Then** the Filament login is reachable.
2. **Given** development data exists, **When** containers are stopped and started
   normally, **Then** the data remains.
3. **Given** bootstrap is rerun, **When** migrations are already applied, **Then** no
   destructive database reset occurs.
4. **Given** S0 is complete, **When** the owner logs in, **Then** no fake company,
   exercise, supplier, project, contract, expense, or budget data exists.

---

### User Story 3 — Safe automated quality checks (Priority: P3)

As the maintainer, I can run automated tests locally and in GitHub Actions without
risking the persistent development database.

**Why this priority:** Automated testing is required from the first slice, but must not
destroy the live development state.

**Independent Test:** Run the complete test command, prove it uses the dedicated
MySQL test database, then verify development data is unchanged.

**Acceptance Scenarios:**

1. **Given** the development DB is `mp2`, **When** tests run, **Then** they use the
   dedicated `testing` database.
2. **Given** test environment configuration is wrong and points to `mp2`, **When** the
   suite starts, **Then** it fails before destructive test setup.
3. **Given** a push or PR, **When** GitHub Actions runs, **Then** Composer validation,
   dependency audit, formatting, static analysis, migrations, tests, and a bootstrap
   smoke check must pass.
4. **Given** S0 has no browser-only behavior, **When** CI runs, **Then** it does not
   install or execute Dusk merely for completeness.
5. **Given** S0 has no custom compiled frontend, **When** CI runs, **Then** it does not
   install npm packages or execute a Vite build.

## Edge Cases

- `.env` exists but `APP_KEY` is blank.
- `.env` exists but `DEV_ADMIN_PASSWORD` is blank.
- development administrator exists before bootstrap rerun.
- MySQL container is still starting when migrations are requested.
- port 9000 is already in use.
- LAN IP cannot be detected; local URL must still be printed and bootstrap must not
  fail solely because the informational LAN URL is unavailable.
- `vendor/` is absent after clone.
- a previous bootstrap created development data.
- test configuration accidentally targets the development DB.
- Docker commands create root-owned files on the Linux host.
- package installation scaffolds unused Vite files.
- a user attempts to run development-admin bootstrap with `APP_ENV=production`.

## Requirements

### Functional Requirements

- **S0-FR-001**: A clean Linux checkout MUST be bootstrappable using Docker without
  host PHP or Composer.
- **S0-FR-002**: S0 MUST use Laravel 13, Filament 5, and PHP 8.3 as the project
  baseline.
- **S0-FR-003**: The development Docker environment MUST be based on Laravel Sail and
  MUST include only services required by S0.
- **S0-FR-004**: MySQL MUST be the S0 database engine and the development database
  MUST persist across normal container stop/start.
- **S0-FR-005**: Automated tests MUST use an isolated MySQL database using the same
  engine family as development.
- **S0-FR-006**: The application MUST expose the Filament panel on host port 9000 and
  be reachable from the local LAN while the stack is running.
- **S0-FR-007**: S0 MUST NOT intentionally publish the development application to the
  Internet or introduce tunnel/HTTPS infrastructure.
- **S0-FR-008**: Bootstrap MUST create `.env` from `.env.example` when absent.
- **S0-FR-009**: `.env.example` MUST document the explicitly requested local-only
  development-admin password.
- **S0-FR-010**: Bootstrap MUST set the local development-admin password equal to its
  login email and persist it to `.env`.
- **S0-FR-011**: Normal bootstrap reruns MUST NOT rotate the persisted development
  administrator password.
- **S0-FR-012**: Development-admin provisioning MUST be idempotent and MUST keep one
  administrator for the configured development email.
- **S0-FR-013**: Development-admin provisioning MUST refuse to operate in production.
- **S0-FR-014**: Bootstrap completion MUST print local URL, available LAN URL, admin
  email, and admin password.
- **S0-FR-015**: After login, S0 MUST show the standard Filament dashboard without
  fake domain functionality.
- **S0-FR-016**: MFA MUST NOT be required in S0.
- **S0-FR-017**: S0 MUST NOT seed domain demonstration data.
- **S0-FR-018**: Filament Shield MUST be deferred to S1.
- **S0-FR-019**: S0 MUST NOT require a frontend build, npm install, `node_modules`, or
  Vite project configuration.
- **S0-FR-020**: Unused default Vite scaffolding MAY be removed after proving no S0
  page references it.
- **S0-FR-021**: Normal bootstrap MUST use forward migrations and MUST NOT run
  `migrate:fresh`, truncate, or destructive reseeding against the development DB.
- **S0-FR-022**: Test bootstrap MUST fail closed before destructive setup if it is not
  running in `testing` or the configured test DB is not the dedicated test database.
- **S0-FR-023**: GitHub Actions MUST run Composer validation/audit, Pint check,
  Larastan, migrations, Pest, and an application/bootstrap smoke check.
- **S0-FR-024**: Dusk MUST be omitted from S0 unless implementation evidence proves a
  critical S0 behavior cannot be tested proportionally otherwise.
- **S0-FR-025**: The implementation MUST NOT create domain entities beyond the
  technical user/authentication data needed to enter Filament.
- **S0-FR-026**: The implementation MUST NOT modify or patch `/vendor`,
  `/node_modules`, or plugin source.
- **S0-FR-027**: Files created by bootstrap/container commands MUST remain writable by
  the Linux host user and MUST NOT normally be left root-owned.
- **S0-FR-028**: At every S0 implementation checkpoint the application MUST remain
  bootable and inspectable from the browser.

### Key Entities

- **Development Administrator**: technical local user used only to enter the S0
  Filament panel. It is not the final per-Azienda authorization model.
- **Development Environment Configuration**: local `.env` values including generated
  development credentials. It is not committed.
- **Development Database**: persistent MySQL database `mp2`.
- **Testing Database**: disposable MySQL database `testing`.

## Success Criteria

- **SC-001**: From a clean checkout, the documented bootstrap completes without host
  PHP or Composer and ends with working login credentials.
- **SC-002**: A second bootstrap preserves the development DB and the same administrator
  credentials.
- **SC-003**: The owner can open `/admin` from another device on the same LAN while
  containers are running.
- **SC-004**: The complete S0 test suite can reset its own test data while leaving the
  development database unchanged.
- **SC-005**: GitHub Actions is green without Node/Vite or Dusk work that S0 does not
  need.
- **SC-006**: Normal PR CI is designed to complete in roughly three minutes and is
  reviewed if it persistently exceeds five minutes.
- **SC-007**: No domain object or behavior from S1+ is implemented in S0.

## Assumptions explicitly approved for S0

- Development host is Linux.
- Development access is required from the LAN.
- A dedicated Nginx service is not required in development.
- MySQL and MariaDB were both acceptable; the technical plan selects MySQL as one
  baseline rather than maintaining two equivalent stacks.
- The application name `MP2` is provisional.
