# Testing and CI Policy

## Objective

Tests must protect domain behavior without becoming a second product.

The project optimizes for:

- fast feedback;
- deterministic behavior;
- high-value regression protection;
- safe separation between live development data and disposable test data.

It does not optimize for a coverage percentage.

## Environment separation

### Development

The development database is persistent.

Normal commands MUST NOT:

- run `migrate:fresh`;
- truncate all tables;
- reseed destructively;
- rotate the development administrator password.

The user must be able to keep manual development data while watching the application
evolve.

### Testing

Automated tests use the same database engine family as development, but a completely
separate database.

S0 convention:

```text
development: mp2
testing:     testing
```

Test bootstrap MUST fail closed if:

- `APP_ENV` is not `testing`; or
- the active DB name is not the configured test database.

`RefreshDatabase`, `migrate:fresh`, transactional resets, factories, and test seeders
are allowed only inside the testing environment.

### Production

Future production verification is non-destructive smoke testing only.

It may verify:

- application availability;
- DB connectivity;
- expected migration state;
- Filament panel/login availability;
- scheduler/configuration health.

It MUST NOT reset data or administrator credentials.

## Test levels

### Unit

Use for deterministic rules with meaningful branching:

- formulas;
- date calculations;
- state-at-date;
- inclusion predicates;
- report classifiers;
- availability limits.

Do not unit-test framework getters or trivial Eloquent wiring.

### Feature / Livewire

This is the default level for user workflows:

- authorization;
- validation;
- Filament actions;
- persistence;
- transaction outcomes;
- user-visible blocking messages.

### Browser / Dusk

Dusk is not a default dependency of every slice.

Add Dusk only where a critical interaction cannot be tested proportionally through
Feature/Livewire tests, for example complex client-side interaction or a regression
that exists only in a real browser.

Browser tests should cover journeys, not duplicate every field validation.

## Minimum behavior policy

For each implemented behavior:

| Behavior | Minimum test |
|---|---|
| Important deterministic rule | focused unit test |
| User workflow | happy-path Feature/Livewire test |
| Relevant MUST NOT | rejection test |
| Atomic operation | rollback/no-partial-effect test |
| Idempotent operation | retry/double-execution test |
| Bug fix | regression test |
| Historical immutability | attempted-mutation rejection / unchanged-history test |

Canonical tests explicitly required by §30 of the domain are mandatory when their
owning slice is implemented.

## Local workflow

During implementation, run the smallest relevant checks needed for fast feedback.

Run broader focused tests when a coherent behavior is complete.

Do not rerun the full repository suite after every implementation step or cosmetic
change.

Before declaring a complete feature or slice finished, run the same repository-wide
quality gates required by the current CI workflow.

## Pull-request CI

The current `.github/workflows/ci.yml` is the executable source of truth for the
repository-wide CI gate.


## CI performance

Target: approximately 3 minutes for a normal PR.

A persistent duration above 5 minutes triggers:

- profiling;
- duplicate-test review;
- parallelization where useful;
- separation of rare heavy tests if this preserves safety.

The response to a slow suite is not to remove unique invariant coverage.

## Test naming and traceability

Domain tests SHOULD reference the canonical FR or invariant in their class/test name,
dataset label, or docblock when this improves discoverability.

Example:

```text
CarryoverLimitTest
- INV-28.13: negative actual cannot create carryover above allocated
```

The roadmap invariant matrix identifies the primary slice responsible for adding each
invariant's first authoritative test.
