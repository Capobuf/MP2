# Research: S0 Foundation and Live Development Environment

**Date:** 2026-08-17

## Decision 1 — Laravel 13 + Filament 5 + PHP 8.3

**Decision:** Use Laravel 13 and Filament 5 with PHP 8.3.

**Rationale:**

- Laravel 13 supports the required application architecture and MySQL.
- Filament 5 provides the panel/login/dashboard foundation and later CRUD/table/form
  primitives.
- PHP 8.3 is Laravel 13's minimum family baseline and is already used successfully by
  the user's AssestMe repository.
- Choosing 8.3 rather than a newer PHP line minimizes avoidable future shared-hosting
  friction while production hosting is still intentionally deferred.

**Evidence:**

- Laravel 13 installation documentation.
- Filament 5 documentation.
- `Capobuf/AssestMe/composer.json` uses PHP `^8.3`, Laravel `^13.8` and Filament
  `^5.0`.

## Decision 2 — Laravel Sail instead of a custom Docker stack

**Decision:** Use Laravel Sail with only the MySQL service needed by S0.

**Rationale:**

- first-party Laravel tooling;
- supported on Linux;
- persistent MySQL volume;
- dedicated test database behavior;
- Composer/Artisan commands execute in the container;
- reduces custom Docker maintenance and permission logic.

**Rejected alternative:** custom PHP container + bespoke compose.

The custom stack could remove unused binaries from the image, but would add Dockerfile,
PHP-extension, user-mapping and server-start logic that Sail already maintains.

**Important boundary:** MP2 may edit generated `compose.yaml`; it does not edit Sail
package code under `/vendor`.

## Decision 3 — MySQL 8.4 family baseline

**Decision:** Use MySQL 8.4 family for S0 development and tests.

**Rationale:**

The user accepted either MySQL or MariaDB. One engine is selected to avoid a duplicate
matrix. MySQL 8.4 is an LTS family available through the official Docker image.

**Rejected alternative:** support both MySQL and MariaDB from S0.

There is no current product requirement that benefits from carrying both environments.

**Migration note:** Production hosting is deferred. MariaDB can still be selected later
if needed, but the switch must occur before concurrency-sensitive behavior is
considered stable and must rerun DB-specific tests.

## Decision 4 — Persistent development DB + disposable test DB

**Decision:**

```text
development = mp2
testing     = testing
```

**Rationale:**

The project owner wants to keep data and inspect ongoing development. Tests need
destructive isolation. Sail already provides a natural separate testing database
pattern.

The test harness gets an explicit safety guard so a configuration mistake fails
before destructive test setup.

## Decision 5 — Environment-based deterministic dev administrator

**Decision:** `.env` contains:

```text
DEV_ADMIN_NAME
DEV_ADMIN_EMAIL
DEV_ADMIN_PASSWORD
```

Bootstrap persists the explicitly requested local password equal to the login email.

A project-owned Artisan command ensures the configured technical administrator exists.
It refuses production.

**Reference:** AssestMe already used the same three environment concepts and a local
admin bootstrap command. MP2 keeps the credential in `.env` and synchronizes the
local-only password to the login email by explicit owner instruction.

## Decision 6 — No frontend build in S0

**Decision:** Do not install npm dependencies or require Vite in S0.

Filament supplies the S0 UI. If the Laravel scaffold contains unused Vite boilerplate,
remove it after confirming it has no references.

**Reference:** AssestMe successfully registers small custom CSS/JS assets directly in
its Filament panel provider. This demonstrates that a SPA is not required for
successful custom Filament UX.

**Future trigger for Vite:** compiled theme/Tailwind customization, npm dependency,
significant JS bundling, or another concrete need.

## Decision 7 — Shield deferred to S1

**Decision:** No permission plugin in S0.

S0's administrator exists only to enter the technical panel. The canonical
per-Azienda capability model begins in S1, where Shield can be evaluated against real
scope and authorization requirements.

## Decision 8 — No Dusk in S0 by default

**Decision:** Feature/HTTP tests plus manual quickstart acceptance are sufficient for
S0 unless implementation uncovers a browser-specific defect.

**Rationale:** Browser automation is useful when needed but carries startup/runtime
cost that does not improve S0 coverage proportionally.

## Decision 9 — No external starter kit

**Decision:** clean Laravel + Filament + current-slice dependencies.

The verified MP2 S0 codebase becomes the project's own foundation.

## Decision 10 — Current Spec Kit workflow

**Decision:** Use GitHub Spec Kit 0.16.1 as the package-generation baseline and the
documented Spec-of-Specs technique.

The package does not copy Spec Kit managed core templates/scripts. The official CLI
initializes those files, and this package overlays only MP2-owned artifacts.

## Sources checked

- `https://laravel.com/docs/13.x/installation`
- `https://laravel.com/docs/13.x/sail`
- `https://filamentphp.com/docs/5.x/getting-started`
- `https://filamentphp.com/docs/5.x/deployment`
- `https://hub.docker.com/_/mysql`
- `https://github.com/github/spec-kit/blob/main/CHANGELOG.md`
- `https://github.com/github/spec-kit/blob/main/docs/concepts/spec-of-specs.md`
- `https://github.com/Capobuf/AssestMe/blob/main/composer.json`
- `https://github.com/Capobuf/AssestMe/blob/main/.env.example`
- `https://github.com/Capobuf/AssestMe/blob/main/scripts/bootstrap-local.sh`
- `https://github.com/Capobuf/AssestMe/blob/main/app/Providers/Filament/AdminPanelProvider.php`
