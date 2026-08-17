# Implementation Plan: Foundation and Live Development Environment

**Branch:** `001-foundation-dev-environment`  
**Date:** 2026-08-17  
**Spec:** `specs/001-foundation-dev-environment/spec.md`

## Summary

Build the smallest repeatable Laravel/Filament development foundation that can be
inspected live from the LAN while protecting persistent development data from the
automated test suite.

Use Laravel 13 + Filament 5 + PHP 8.3, Laravel Sail, and a single MySQL 8.4-family
service. Provide deterministic local administrator bootstrap via `.env`, no MFA, no
demo domain data, no Shield, and no frontend build pipeline.

## Technical Context

**Language/Version:** PHP 8.3

**Primary Dependencies:** Laravel 13, Filament 5, Laravel Sail (dev), Pest, Larastan,
Laravel Pint

**Storage:** MySQL 8.4 family; persistent `mp2` development DB and disposable
`testing` DB

**Testing:** Pest; Laravel Feature tests; no Dusk in S0 unless later justified

**Target Platform:** Linux development host; Docker Engine/Compose; browser access
from host and local LAN

**Project Type:** single Laravel web application

**Performance Goals:** no S0 application performance target beyond responsive
development use; normal PR CI approximately 3 minutes

**Constraints:** no host PHP/Composer requirement; no dev DB reset; no frontend build;
no dependency-source edits; port 9000; no intentional Internet exposure

**Scale/Scope:** foundation only; one technical administrator; no economic domain
entities

## Constitution Check

**Gate result: PASS**

- Canonical domain authority: PASS — S0 implements no economic domain behavior.
- Simplicity: PASS — one app container, one MySQL service, no speculative services.
- Vertical slice: PASS — independently demonstrable bootstrap/login/live environment.
- Dependency integrity: PASS — project files/config only; no vendor source edits.
- Explicit domain operations: N/A for S0 economic domain.
- Test discipline: PASS — separate MySQL test DB and safety guard.
- Reproducible development: PASS — this is the primary S0 outcome.
- Historical/transactional integrity: N/A except DB persistence safety.
- Agent discipline: PASS — bounded phases and explicit file paths.

Re-check after implementation design: required before S0 is marked complete.

## Phase 0 — Research result

See `research.md`.

No unresolved clarification remains.

MySQL was selected from the user-approved MySQL/MariaDB alternatives as a single
technical baseline to avoid dual matrices.

## Phase 1 — Design

### Development container

Install Laravel Sail as a development dependency and publish a project-owned
`compose.yaml`.

Keep only:

- `laravel.test`;
- `mysql`.

Configure the application runtime for PHP 8.3.

Configure the MySQL service on the 8.4 family and retain its named volume.

Publish host port 9000.

Do not add Redis, Selenium, mail services, search services, or dedicated Nginx.

### Clean-checkout bootstrap

`scripts/bootstrap-dev.sh` is the supported entry point.

When Composer dependencies are absent, use Laravel Sail's Composer Docker bootstrap
pattern with host UID/GID, then use `vendor/bin/sail` for application commands.

The script is fail-fast and idempotent.

### Development administrator

Add `app/Console/Commands/EnsureDevAdmin.php`.

The command:

- refuses production;
- validates required env values;
- uses configured email as the idempotent lookup key;
- creates or synchronizes the development user so `.env` credentials remain valid;
- does not create roles or Azienda permissions.

Password synchronization is acceptable only for this local technical bootstrap user.
The command is never a production provisioning path.

### Filament panel

Install Filament's panel builder and expose `/admin`.

Use the native login and Dashboard.

Do not enable MFA in S0.

Do not create domain Resources.

### Frontend

S0 has no compiled custom frontend.

If the Laravel scaffold produces unused Vite files, remove them after verifying there
are no `@vite` or package references required by S0.

Filament manages its own package assets through supported commands.

### Test isolation

`phpunit.xml` selects DB `testing`.

Add an application test safety assertion in `tests/TestCase.php` or an equivalent
central test bootstrap. It must fail before destructive setup when:

- app environment is not testing; or
- active DB name differs from the dedicated testing DB.

This guard is itself tested without connecting destructive helpers to development.

### CI

Add `.github/workflows/quality.yml` with MySQL test service and only the quality gates
in `contracts/ci-contract.md`.

Do not set up Node or Dusk.

## Project Structure

### Documentation

```text
specs/001-foundation-dev-environment/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── env-contract.md
│   ├── dev-runtime-contract.md
│   └── ci-contract.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source code after S0

```text
.
├── app/
│   ├── Console/Commands/EnsureDevAdmin.php
│   ├── Models/User.php
│   └── Providers/Filament/AdminPanelProvider.php
├── bootstrap/
├── config/
├── database/
│   └── migrations/
├── public/
├── resources/
│   └── views/
├── routes/
├── scripts/
│   └── bootstrap-dev.sh
├── storage/
├── tests/
│   ├── Feature/Foundation/
│   └── TestCase.php
├── .env.example
├── .github/workflows/quality.yml
├── .gitignore
├── compose.yaml
├── composer.json
├── composer.lock
├── phpstan.neon
└── phpunit.xml
```

`app/Domain`, `app/Actions`, and `app/Policies` are not created empty in S0.

## Implementation checkpoints

### Checkpoint A — Framework boot

Laravel + Filament + Sail + MySQL start and `/admin/login` responds.

### Checkpoint B — Stable credentials

Clean bootstrap generates `.env` credentials; rerun preserves usable credentials;
login reaches Dashboard.

### Checkpoint C — Persistent live environment

Normal stop/start preserves data and LAN URL works.

### Checkpoint D — Test/CI safety

Tests use only `testing`, the safety guard works, and all CI-equivalent gates pass
locally. The remote GitHub Actions run remains pending publication of this workflow.

S0 is not complete until all four checkpoints pass.

## Complexity Tracking

No constitution violations or complexity exceptions are required.

## Post-implementation Constitution Check — 2026-08-17

**Gate result: PASS, remote CI pending**

- Canonical/domain scope: PASS — only the technical `User` needed for Filament exists.
- Simplicity and dependency integrity: PASS — Sail application + MySQL only; no
  dependency source was edited.
- Test discipline: PASS — MySQL `testing` is explicit and the central guard rejects
  `local/mp2` before Laravel test setup.
- Reproducibility and persistence: PASS — clean Docker-only bootstrap, idempotent
  administrator provisioning, named volume, and stop/start evidence are recorded.
- Live inspection: PASS — login responds locally and through the host LAN address.

Owner-requested changes to the initial S0 plan are host port `9000` and a local-only
password equal to the login email (`admin@mp2.local`). Both are reflected in the
feature contracts. Production provisioning remains explicitly refused.
