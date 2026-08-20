# Implementation Plan: Exercises, Expenses and Lines

**Branch:** `004-exercises-expenses` | **Date:** 2026-08-17 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/004-exercises-expenses/spec.md`

## Summary

S3 introduces company-owned calendar Exercises and the first live economic source:
manual autonomous Expenses composed of authoritative Estimate and Actual Lines.
Authorized users create multiple open years, calculate allocation/actual/operational
variance exactly, maintain Lines without deletion or matching, move and reclassify a
whole Expense with a confirmed impact plan, reverse/restore eligible Expenses, and
inspect complete per-year economic Timeline events.

The implementation remains a direct Laravel/Filament vertical slice: three models,
tenant-scoped Resources, policies, deterministic decimal domain code, and explicit
transactional Actions. It extends the existing audit envelope and adds no Project,
Contract, Budget, Proposal, Closing, carryover, reporting, cache, queue, repository,
or generic service layer.

## Technical Context

**Language/Version:** PHP 8.3
**Primary Dependencies:** Laravel 13, Filament 5, bundled `ext-bcmath` declared for
exact decimal arithmetic; no new package or plugin
**Storage:** MySQL 8.4 family; persistent `mp2`, isolated `testing`; three new
forward-only tables and forward-only company-reference indexes on S2 master data
**Testing:** Pest 4 unit, action, model, and Filament/Livewire feature tests under the
existing test-database guard
**Target Platform:** Linux-hosted server-rendered web application through Laravel Sail
**Project Type:** Single Laravel/Filament tenant-aware web application
**Performance Goals:** Tenant lists and ordinary mutations complete in one normal
request; list totals use aggregate queries rather than one query per displayed row
**Constraints:** Exact-company isolation, `modifica_operativita` mutation boundary,
EUR net IVA, two-decimal authoritative amounts, six-decimal descriptive factors,
no floating-point economic arithmetic, full audit atomicity, stable identity,
idempotent commands, no physical deletion, no future-year Actual, no matching, no
S4+ behavior, no frontend build
**Scale/Scope:** Two top-level Resources, one nested Line collection, six bounded
user journeys, and the existing Timeline; no production throughput claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain is sole functional authority | PASS — scope fully maps FR-001–FR-004, FR-008, FR-031–FR-033, FR-047, FR-050, FR-053–FR-054, FR-084, FR-098 and FR-101, while providing only the autonomous/non-attachment portions of shared FR-005–FR-007, FR-046 and FR-051–FR-052. |
| Category A–E discipline | PASS — narrative causes use Notes; excluded accounting/matching cases are D; no E gap blocks the exposed operations. |
| Smallest proportional design | PASS — native Resources, direct models/policies, focused decimal code, and explicit Actions only. |
| Slice boundary | PASS — only open Exercise creation and autonomous Expenses exist; Projects, Contracts and every planning/closing/reporting workflow remain absent. |
| Dependency boundary | PASS — no dependency source is modified; the already-installed PHP BCMath extension is declared solely to guarantee exact decimal arithmetic. |
| Explicit domain operations | PASS — all economic mutation, locking, validation, idempotency and audit live in Actions rather than only UI callbacks. |
| Historical and transactional integrity | PASS — Lines and Expenses retain stable IDs, events are append-only, and each mutation plus event is one transaction. |
| Forward-only migrations | PASS — applied S0–S2 migrations remain untouched; S3 uses new migrations and restrict-on-delete constraints. |
| Proportional tests | PASS — formulas, MUST NOT cases, tenant boundaries, retry/no-op, rollback and UI journeys receive focused tests. |
| Inspectable after each phase | PASS — phases keep bootable tenant pages and run focused tests against `testing` before continuing. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/004-exercises-expenses/
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
├── Domain/Expenses/
├── Filament/Resources/Exercises/
├── Filament/Resources/Expenses/
├── Models/{Exercise,Expense,ExpenseLine}.php
└── Policies/{Exercise,Expense,ExpenseLine}Policy.php

database/
├── factories/{Exercise,Expense,ExpenseLine}Factory.php
└── migrations/*_create_{exercises,expenses,expense_lines}_table.php

tests/
├── Unit/Domain/Expenses/
└── Feature/Expenses/
```

**Structure Decision:** Extend the existing single Laravel application in its
established model/action/policy/Filament boundaries. Domain code is limited to enums,
exact calculations and the impact plan; mutation orchestration stays in Actions.

## Design Phases

### Phase 0 — Research

Decimal arithmetic, schema constraints, revisions, idempotency, Filament interaction,
audit reuse and the explicit S5 attachment handoff are resolved in
[research.md](research.md).

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines fields, constraints, derived values and
  transitions.
- [contracts/ui.md](contracts/ui.md) defines Italian tenant UI behavior and impact
  confirmations without a public API.
- [quickstart.md](quickstart.md) defines non-destructive automated and browser
  validation.

### Implementation phase boundary

Implementation follows [tasks.md](tasks.md), one phase at a time. Every phase has at
most eight implementation tasks and ends with boot, current-UI inspection and
focused `testing`-database checks.

## Complexity Tracking

No constitution violation requires an exception.
