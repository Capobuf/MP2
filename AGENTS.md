# MP2 Agent Instructions

These rules apply to every coding agent and every implementation slice.

## Mandatory read order

Before changing code, read completely:

1. `.specify/memory/constitution.md`;
2. `docs/domain/Specifica_Canonica_Semplificata_v4.md`;
3. the roadmap;
4. the current slice's `spec.md`, `plan.md`, `research.md`, `data-model.md`,
   `contracts/`, and `tasks.md`.

Do not rely on excerpts or summaries when the complete file is available.
If a tool truncates a file, continue in chunks until EOF.

## Domain authority

The canonical domain file is the sole functional authority.

- Do not invent missing economic or functional rules.
- Do not infer behavior from framework conventions.
- Do not silently reconcile contradictions.
- Classify new cases using domain categories A–E.
- A category-E structural gap blocks implementation of that rule until the domain
  is explicitly reopened.
- Technical decisions may not alter observable domain behavior.

## Simplicity

Implement the smallest design that satisfies the current slice and known domain
constraints.

Do not add:

- speculative abstractions;
- repository patterns;
- CQRS;
- generic service layers;
- event sourcing;
- microservices;
- Redis;
- queues;
- frontend frameworks;
- caches;
- plugins;
- packages;

unless the current slice provides a concrete reason.

Use Laravel and Filament directly when they already solve the problem.

## Slice boundary

Work only on the current slice and its demonstrated prerequisites.

The roadmap guarantees future coverage; it is not permission to implement future
slices early.

An implementation run SHOULD execute one task phase at a time. If a phase contains
more than eight implementation tasks, split it before execution.

After every phase:

- the application MUST boot;
- the current UI MUST remain inspectable;
- focused tests MUST pass;
- no unrelated refactor may be left behind.

## Dependency boundary

Never modify, patch, format, refactor, move, delete, or intentionally index:

- `/vendor/**`;
- `/node_modules/**`;
- installed plugin source.

Running Composer, autoloading, package discovery, framework commands, or supported
plugin commands is allowed.

Use only public extension/configuration APIs. A fork or persistent patch requires a
separate explicit technical decision.

## Code organization

Prefer these Laravel boundaries when needed:

- `app/Models/`: persistence models;
- `app/Domain/`: deterministic domain calculations/rules;
- `app/Actions/`: mutating application operations and transactions;
- `app/Filament/`: presentation and interaction;
- `app/Policies/`: authorization.

Do not create empty architectural layers in advance.

Complex domain mutation MUST NOT live only inside Filament Resource callbacks.

## Migrations

Do not rewrite a migration after it has been committed and used by a shared
development environment. Create a new corrective migration.

Never use `migrate:fresh`, truncate, or destructive reset commands against the
persistent development database.

## Tests

Tests are proportional, behavior-focused, and mandatory for implemented domain
behavior.

- Use the dedicated testing database.
- Never run the normal automated suite against the development database.
- Future production checks are smoke tests only and non-destructive.
- Add regression tests for fixed bugs.
- Test MUST NOT rules with at least one rejection case.
- Test atomicity and rollback for atomic operations.
- Test idempotency where the domain requires it.
- Do not chase a coverage percentage.

Dusk is introduced only for UI journeys that cannot be covered proportionally by
lower-level tests.

## Code style

- Keep code direct and readable.
- Remove dead code rather than commenting it out.
- Comment only non-obvious behavior.
- Code comments MUST be in English.
- User-facing text follows the application language requirements.
- Do not add ceremonial or redundant comments.

## Dependencies

Before adding a dependency, record:

- the current slice need;
- why Laravel/Filament core is insufficient;
- maintenance/compatibility status;
- licensing implications;
- the exit/removal consequence.

Dependencies are installed through the package manager and lockfile only.

## Git hygiene

Never commit:

- `.env`;
- generated local credentials;
- database volumes;
- private runtime artifacts.

Keep commits and PRs scoped to the current slice.
