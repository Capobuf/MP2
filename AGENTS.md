# MP2 Agent Instructions

## Purpose

This file tells coding agents how to work in MP2.

The agent's role is to implement and verify the requested work faithfully.
It must not redesign the product, invent missing behavior, or expand scope on its own.

Prefer the simplest implementation that correctly satisfies the requested behavior
and the relevant domain constraints.

## Sources of truth

Use sources according to the work being performed.

1. The explicit task or user instruction defines the requested scope and expected outcome.
2. `docs/domain/Specifica_Canonica_Semplificata_v4.md` is authoritative for MP2 domain behavior.
3. The active feature/slice brief, when provided, defines the decisions and acceptance criteria for that work but may not contradict the canonical domain.
4. Repository code, database schema and tests describe the current implementation.
5. `specs/000-product-roadmap/` provides coverage and traceability, not an implementation methodology.
6. Design, brand, visual-reference or UX documents explicitly supplied with a task are authoritative for that task's visual direction.

When sources conflict, do not silently reconcile them.

Do not invent a rule to fill a gap.
Identify the exact conflict or missing decision and its implementation impact.
Continue any independent work that does not depend on that decision.

## Context discipline

Read what is necessary to perform the current task correctly.

Do not read the entire repository or all documentation by default.

For domain work:
- locate and read the canonical sections directly relevant to the requested behavior;
- read adjacent or referenced sections only when needed to understand dependencies;
- inspect the existing implementation paths affected by the change.

For UI/UX work:
- read the supplied design/brand/reference material;
- inspect the affected screens, components, data, actions, permissions and states;
- inspect related UI only when needed for consistency.

Spec Kit artifacts are historical implementation material unless the task explicitly
requires them.

Do not load `.specify/**`, `.agents/skills/speckit-*`, or completed slice
`plan.md`, `research.md`, `tasks.md`, `data-model.md`, `contracts/` and
`quickstart.md` by default.

Consult an old slice document only when it contains necessary historical rationale
that cannot be established from the canonical domain, current task and current code.

If a file is truncated by a tool, continue reading it in chunks until the required
content has actually been read.

## Working model

Inspect before changing.

Understand the existing path that implements the requested behavior and reuse it
where appropriate.

For a defined feature or slice, treat the supplied decisions as settled.
Do not reopen product decisions unless implementation reveals a real contradiction,
missing case or structural gap.

Implement one coherent behavior at a time.

Prefer working software and direct verification over producing planning artifacts.

Do not create specification, research, planning, checklist or task documents unless
the user explicitly asks for them.

Do not implement future roadmap functionality early.

Do not make unrelated refactors while implementing a feature or fixing a bug.

## Simplicity

Use Laravel, Eloquent, Filament and existing project patterns directly when they solve
the problem.

Do not introduce speculative abstractions or infrastructure.

Avoid adding:
- repository or service layers without a concrete need;
- CQRS or event sourcing;
- generalized engines for a single known case;
- caches, queues or Redis without a demonstrated requirement;
- adapters around framework APIs without a demonstrated requirement;
- feature flags for behavior that is not optional;
- fallback paths for states that should fail clearly;
- duplicate validation or defensive guards that do not protect a real requirement;
- retry, locking or idempotency mechanisms unless required by the domain, concurrency
  model or an observed failure mode.

Existing defensive code is not automatically a pattern to copy.

Required safety properties such as tenant isolation, authorization, historical
immutability, atomicity and domain-required idempotency must still be preserved.

Complexity must be justified by an actual MP2 requirement.

## Backend and domain code

Keep responsibilities direct:

- `app/Models/` for persistence and model relationships;
- `app/Domain/` for deterministic domain calculations and rules when separation is useful;
- `app/Actions/` for meaningful mutating application operations and transactions;
- `app/Policies/` for authorization;
- `app/Filament/` for presentation and interaction.

Do not create an architectural layer merely to move code out of another file.

Complex domain mutations must not exist only inside Filament callbacks.

Use transactions when an operation must be atomic.

Use database locks when concurrent execution can violate an actual invariant.
Do not lock unrelated records defensively.

Validate at the boundary where invalid data can actually enter or where a domain
invariant must be protected.
Do not repeat equivalent validation at multiple layers without a concrete reason.

Fail explicitly when an operation cannot be completed correctly.
Do not silently repair, reinterpret or ignore invalid domain state.

## UI and UX

MP2 is a product, not a generic Filament administration interface.

Filament is an implementation framework, not the visual authority.

Existing repository UI is authoritative for available data, actions, permissions,
states and implemented workflows. It is not automatically an aesthetic reference.

Never invent:
- actions;
- states;
- permissions;
- relationships;
- data;
- metrics or KPIs;
- workflow steps;
- business meaning.

When a design brief or visual reference is supplied, follow its visual language,
quality target and explicit constraints.
Do not copy a reference's layout mechanically unless requested.

Prefer clear hierarchy, direct interactions and low interaction cost.

Avoid adding UI controls that duplicate an interaction already available more
naturally.

Keep important related information and actions close to the user's current context.

Use progressive disclosure for secondary information rather than forcing unnecessary
navigation.

Account for loading, empty, disabled, validation and error states when they can
actually occur.

Preserve accessibility, keyboard usability, readable contrast and responsive behavior.

For significant visible changes, inspect the result in a real browser when the
available environment permits it.
Do not declare a visual result verified solely because the code compiles.

## Testing and verification

Testing protects behavior; it is not the development methodology.

Test-first development is not mandatory.

During implementation:
- run the smallest relevant automated checks after coherent changes;
- use focused Unit tests for deterministic rules;
- use Feature/Livewire tests for workflows, authorization, validation and persistence;
- add a regression test for a bug that could recur;
- test rejection when a relevant MUST NOT rule exists;
- test rollback when atomicity is an actual requirement;
- test retries when idempotency is an actual requirement.

Do not create tests for trivial framework behavior merely to increase coverage.

Do not run the complete suite after every small change.

For UI work, combine appropriate automated tests with direct visual/interaction
verification when possible.

Before declaring a complete feature or slice finished, run the repository-wide
quality gate defined by the current CI workflow and fix failures caused by the work.

A task is not complete merely because the happy path works.
Compare the completed behavior against every requirement and acceptance criterion
provided for that task and report any uncovered case.

## Database and migrations

The development database is persistent.

Never run destructive reset operations against it.

Do not rewrite a migration that has already been committed and used by the shared
development environment.
Create a forward corrective migration instead.

Automated destructive database operations are allowed only against the isolated
testing environment according to `docs/testing-policy.md`.

Do not mutate historical data merely to make a new implementation easier.

## Dependencies

Before adding a dependency, verify that the current stack cannot solve the concrete
problem proportionally.

Follow `docs/dependency-policy.md`.

Do not add packages for convenience when Laravel, Filament or existing dependencies
already provide the required behavior.

Never modify dependency source under `/vendor/**`, `/node_modules/**`, or installed
plugin source.

## Code quality

Prefer readable, direct code over clever code.

Keep functions and classes focused on meaningful responsibilities, but do not split
code mechanically just to reduce file size.

Remove dead code instead of retaining fallbacks for hypothetical future use.

Use existing naming and formatting conventions.

Comments are for non-obvious intent, constraints or reasoning.
Do not comment obvious code.

Code comments must be in English.

User-facing copy follows MP2's application language and terminology.

Do not leave placeholders, TODO implementations or abbreviated sections in completed work.

## Change discipline

Preserve behavior outside the requested scope.

Before changing an existing shared abstraction, identify its current callers and
consider the effect on them.

Do not solve one local problem by creating inconsistent behavior elsewhere.

When several solutions are valid, prefer the one with:
- fewer concepts;
- fewer moving parts;
- less new code;
- lower coupling;
- clearer domain correspondence.

Do not sacrifice correctness to reduce code size.

## Completion

Before finishing, review the resulting diff as a whole.

Verify that:
- the requested behavior is actually present;
- no requested functionality was omitted;
- no unrequested functionality was introduced;
- relevant domain rules still hold;
- related existing behavior was not accidentally changed;
- focused verification passed;
- UI changes were visually inspected when possible;
- no unnecessary defensive code or abstraction was introduced.

Report what was changed, what was verified, and any remaining limitation or unresolved
domain decision.

Never claim a check was run or a behavior was verified if it was not.

