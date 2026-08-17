# Implementation Plan: Projects

**Branch:** `agent/projects` | **Date:** 2026-08-17 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/005-projects/spec.md`

## Summary

S4 introduces company-owned, multi-year Projects as first-level economic sources.
Authorized users create Projects, manage their dated canonical state transitions,
classify them by Cost Center for each Exercise, attach existing manual Expenses,
move whole Expenses between autonomous and Project ownership, inspect exact annual
totals, receive overspend warnings, and archive only terminal Projects.

The implementation extends the S3 Laravel/Filament vertical slice directly: three
Project models, deterministic state/overspend domain code, explicit transactional
Actions, one tenant-scoped Resource, and targeted extensions to Exercise, Expense,
Line, and Timeline behavior. Totals remain derived from authoritative active Lines.
No carryover, reprogramming, Contract, Proposal, Budget, Closing, attachment,
Forecast, report subsystem, package, plugin, cache, queue, or generic service layer
is introduced.

## Technical Context

**Language/Version:** PHP 8.3
**Primary Dependencies:** Laravel 13, Filament 5, existing `ext-bcmath`; no new
package or plugin
**Storage:** MySQL 8.4 family; persistent `mp2`, isolated `testing`; three new
forward-only Project tables and one forward-only Expense ownership extension
**Testing:** Pest 4 unit, action, model, and Filament/Livewire feature tests under the
existing test-database guard
**Target Platform:** Linux-hosted server-rendered web application through Laravel Sail
**Project Type:** Single Laravel/Filament tenant-aware web application
**Performance Goals:** Tenant lists and ordinary mutations complete in one normal
request; annual totals use set-based aggregate queries without per-row Line loading
**Constraints:** Exact-company isolation, `modifica_operativita` mutation boundary,
company-local dates, exact decimal EUR net IVA, deterministic state at date,
revision-safe previews, atomic audit, stable identities, idempotent commands, no
physical deletion, no closed-Exercise mutation, no double counting, no S5+ behavior
**Scale/Scope:** One new Resource with three bounded related views, extensions to two
existing Resources and six Project journeys; no production-throughput claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain is sole functional authority | PASS — scope maps to S4-owned FR-055–058 and FR-079–081 while extending the real Project cases of FR-005, FR-051 and FR-052. |
| Category A–E discipline | PASS — canonical state, classification, ownership and overspend rules are complete for S4; later canonical concepts remain explicitly deferred and no E gap is present. |
| Smallest proportional design | PASS — direct models, domain value code, Actions, policies and native Filament Resources only. |
| Slice boundary | PASS — carryover, reprogramming, Contracts, Proposals, Budgets, Closing and full reporting are neither stored nor exposed. |
| Dependency boundary | PASS — no dependency or installed source changes and no new dependency is needed. |
| Explicit domain operations | PASS — transitions, reclassification, ownership moves, archive and economic mutation checks live in transactional Actions rather than UI callbacks. |
| Historical and transactional integrity | PASS — stable IDs, append-only transitions/events, restrictive FKs, monotone revisions and deterministic locks protect each operation. |
| Forward-only migrations | PASS — S0–S3 migrations remain unchanged; S4 adds new tables and a corrective ownership migration only. |
| Proportional tests | PASS — date calculation, MUST NOT rules, exact totals, overspend predicates, tenancy, retry, stale preview and rollback receive focused tests. |
| Inspectable after each phase | PASS — every implementation phase is capped at eight tasks and ends with boot, focused tests and tenant-UI inspection. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/005-projects/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── ui.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/Operations/
├── Domain/Projects/
├── Filament/Resources/Projects/
├── Filament/Resources/{Exercises,Expenses}/
├── Models/{Project,ProjectTransition,ProjectExerciseClassification}.php
└── Policies/{Project,ProjectTransition,ProjectExerciseClassification}Policy.php

database/
├── factories/{Project,ProjectTransition,ProjectExerciseClassification}Factory.php
└── migrations/*_{create_projects,create_project_transitions_and_classifications,add_project_to_expenses}_*.php

tests/
├── Unit/Domain/Projects/
├── Feature/Projects/
└── Feature/Expenses/
```

**Structure Decision:** Extend the established single Laravel application in its
model/action/policy/Filament boundaries. `app/Domain/Projects` contains only pure
state, annual-reference, exact-impact, and overspend decisions; mutating orchestration
stays in `app/Actions/Operations`.

## Design Phases

### Phase 0 — Research

State-at-date, transition persistence, annual classification, aggregate ownership,
overspend, revisions, locks, audit, and UI reuse are resolved in
[research.md](research.md).

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines fields, constraints, derived values,
  transition rules, revision tokens, and lock order.
- [contracts/ui.md](contracts/ui.md) defines the Italian tenant UI, explicit impact
  confirmations, Project Expense behavior, and excluded controls.
- [quickstart.md](quickstart.md) defines non-destructive automated and browser
  validation.

### Implementation phase boundary

Implementation follows [tasks.md](tasks.md), one phase at a time. Every phase has at
most eight implementation tasks and ends with application boot, inspectable current
UI, and focused checks against the `testing` database.

## Complexity Tracking

No constitution violation requires an exception.
