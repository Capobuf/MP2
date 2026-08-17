# MP2 Constitution

**Version:** 1.0.0  
**Ratified:** 2026-08-17  
**Last amended:** 2026-08-17

This constitution governs how the canonical MP2 domain is converted into software.
It supersedes ad-hoc implementation preferences, but it never supersedes the
functional rules in `docs/domain/Specifica_Canonica_Semplificata_v4.md`.

## Core Principles

### I. Canonical Domain Authority — NON-NEGOTIABLE

The canonical domain specification is the only authority for functional and
economic behavior.

Implementation MUST NOT:

- invent an unstated domain rule;
- complete ambiguity by assumption;
- use a technical shortcut that changes observable behavior;
- silently choose between contradictory domain rules.

Every new functional case MUST first be classified using categories A–E from the
canonical domain. A category-E structural gap blocks implementation of the affected
rule until an explicit domain decision exists.

Rationale: implementation freedom exists only for technically equivalent solutions.

### II. Simplicity and Proportionality

MP2 MUST prefer the simplest design that satisfies the current slice and known
domain constraints.

Complexity requires a current, concrete justification. Future-proofing alone is not
sufficient.

The project MUST NOT introduce speculative layers, distributed architecture,
generic repositories, CQRS, event sourcing, background infrastructure, caching,
plugins, or frontend frameworks without a requirement that needs them now.

Framework-native Laravel and Filament capabilities are preferred over custom code
when they preserve the domain.

Rationale: unnecessary architecture increases agent context, failure modes, and
maintenance cost without increasing product value.

### III. Vertical Slices with Complete Traceability

The product roadmap MUST cover every canonical FR and invariant, but implementation
proceeds through bounded vertical slices.

Each detailed slice MUST be:

- independently understandable;
- demonstrable through observable behavior;
- independently testable;
- small enough for one implementation phase to remain within agent context.

Future slices MUST remain roadmap entries until they are ready to enter the normal
Spec Kit `specify → plan → tasks → implement` cycle.

Each canonical FR has one primary roadmap slice. Cross-cutting behavior may appear in
several slices but MUST NOT lose its primary traceability anchor.

Rationale: global completeness and incremental implementation are separate concerns.

### IV. Dependency Integrity

`/vendor/**`, `/node_modules/**`, and installed plugin source are immutable
dependencies from MP2's perspective.

Agents MUST NOT modify or intentionally index those directories. Supported package
commands and public extension points are allowed.

A new dependency MUST be justified by the current slice and recorded before
installation. Starter kits and plugins are not default architecture.

Rationale: dependency source changes are fragile, hard to upgrade, and invisible to
normal package ownership.

### V. Explicit Domain Operations

Filament owns presentation and interaction. Non-trivial domain behavior belongs in
deterministic domain code and explicit application Actions.

Operations requiring transactionality, locking, idempotency, multi-Esercizio impact,
or immutable-history behavior MUST NOT exist only as anonymous UI callbacks.

Current state remains model-backed; the Timeline is append-only audit, not general
event sourcing.

Rationale: domain rules must remain testable independently from the UI.

### VI. Proportional Test Discipline — NON-NEGOTIABLE

Tests protect domain behavior and delivery safety; they are not an end in themselves.

The minimum policy is:

- important deterministic rule → focused unit test;
- user workflow → feature/Livewire test;
- relevant MUST NOT → rejection test;
- atomic operation → rollback/no-partial-effect test;
- idempotent operation → retry/double-execution test;
- bug fix → regression test.

Tests MUST run against a dedicated testing database using the same DB engine family as
development. The persistent development database MUST NOT be reset by the test suite.

Future production verification is limited to non-destructive smoke tests.

No coverage percentage is a project objective. CI SHOULD normally complete within
about three minutes; persistent runs beyond five minutes trigger profiling and
simplification before adding redundant coverage.

Rationale: testing effort must remain proportional to product risk.

### VII. Reproducible, Inspectable Development

A clean Linux checkout MUST be bootstrappable through documented commands without
requiring host PHP or Composer.

S0 MUST provide:

- Docker-based development;
- persistent development data;
- isolated testing data;
- deterministic development-admin credentials stored only in local `.env`;
- a Filament login and dashboard;
- LAN browser access while the development stack is running.

Bootstrap MUST be idempotent and MUST NOT rotate credentials or destroy development
data on normal reruns.

After each implementation phase the application MUST remain bootable and inspectable.

Rationale: the owner must be able to observe development continuously, not only after
large milestones.

### VIII. Historical and Transactional Integrity

When a canonical rule requires immutability, atomicity, idempotency, revision
validation, or locking, those properties are implementation requirements rather than
optional optimizations.

No operation may create a partially applied economic state.

Historical Snapshots are materialized and autonomous as required by the domain.
Closed years are not silently recalculated.

Rationale: technical simplification may reduce machinery but may not weaken domain
guarantees.

### IX. Agent Operational Discipline

Agents MUST read the complete canonical domain and complete current-slice artifacts
before implementation.

If a file is truncated by tooling, the agent MUST continue reading it in chunks.

Implementation runs SHOULD be bounded to one phase at a time. A phase with more than
eight implementation tasks SHOULD be split before execution unless the tasks are
demonstrably trivial.

Unrelated refactors and future-slice implementation are prohibited.

Code comments are written in English and only for non-obvious behavior.

Rationale: bounded context and explicit scope reduce hallucination and cascading
changes.

## Technology Baseline

Technology belongs in plans rather than product specs. The initial approved baseline
is nevertheless constrained by the decisions already made:

- provisional application name: MP2;
- repository: `Capobuf/MP2`;
- Linux development host;
- PHP 8.3 baseline;
- Laravel 13;
- Filament 5;
- Laravel Sail for the development Docker environment;
- MySQL 8.4 family baseline for S0;
- Pest for automated tests;
- no external starter kit;
- no Redis or permanent queue worker in S0;
- no MFA in S0;
- no Filament Shield until S1;
- no Node/Vite build requirement in S0;
- no demo domain data in S0;
- development access exposed to the LAN, not intentionally to the Internet.

A later plan MAY replace a technical baseline when the change is explicitly justified
and does not alter canonical behavior.

## Governance

### Authority order

1. Canonical domain specification for functional/economic behavior.
2. This constitution for development governance.
3. Current slice `spec.md` for slice scope and user-visible requirements.
4. Current slice `plan.md` and contracts for technical implementation.
5. `tasks.md` for execution order.

A lower-level artifact MUST NOT override a higher-level one.

### Amendment

Constitution changes require:

- explicit rationale;
- assessment of affected specs/plans/tasks;
- semantic-version change.

Versioning:

- MAJOR: removes or redefines a binding principle incompatibly;
- MINOR: adds a principle or materially expands governance;
- PATCH: clarification with no behavioral governance change.

### Compliance gate

Every `plan.md` MUST contain a Constitution Check before implementation.

Any failed MUST-level principle blocks implementation unless the artifact is corrected.
Complexity exceptions must be documented in the plan's Complexity Tracking section.

### Domain changes

The canonical domain is not amended through the constitution.

A domain change requires a separate explicit domain decision and a new canonical
version. The roadmap and traceability matrices must then be reconciled before further
implementation of affected slices.
