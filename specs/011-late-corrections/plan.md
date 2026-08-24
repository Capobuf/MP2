# Implementation Plan: Correzioni post-Chiusura

**Branch**: `main` | **Date**: 2026-08-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/011-late-corrections/spec.md`

## Summary

S10 adds the two canonical post-Closing operations without weakening S9 immutability:
an authorized user can append a late Actual correction to a compatible historical
manual Expense or to a new manual Expense in the same historical container, and can
append a non-economic historical-error annotation. Existing Actual rows, Closing
Snapshots, Budgets, Carryover, state and historical attribution remain unchanged.

Use two explicit transactional Actions and two small immutable records. A late
correction record identifies exactly one newly appended Actual line and its historical
context. A historical annotation stores a closed error kind, recorded/correct values
and materialized affected references. Reuse existing company capabilities, Expense
models, Closing Snapshot, AuditEvent and Attachment storage. Extend attachments only
for annotation ownership; correction evidence stays attached to its generated
ExpenseLine. No report engine, correction service layer, historical rewrite path or
new dependency is introduced.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13.17+, Filament 5, existing `ext-bcmath`; no new
package or plugin

**Storage**: Existing MySQL 8.4-family Expenses, Expense Lines, Closing Snapshots,
Audit Events and Attachments plus two bounded forward-only S10 migrations

**Testing**: Pest 4 focused model, Action, authorization, rollback, immutability,
Livewire and invariant tests against the isolated `testing` database; repository-wide
CI gate only after the feature is complete

**Target Platform**: Linux-hosted server-rendered Laravel/Filament application through
Laravel Sail

**Project Type**: Single tenant-aware web application

**Performance Goals**: One bounded locked mutation per correction or annotation;
set-based local history loading; no reconstruction of historical state and no
per-row source reload in list views

**Constraints**: Exact company isolation; `visualizza`/`corregge_esercizio_chiuso`
separation; Closed Exercise only; append-only Actuals and annotations; immutable
Closing/Budget/Carryover/history; exact decimals; archived historical Supplier access;
operation-ID retry safety; Italian UI; no S11 reporting/export behavior

**Scale/Scope**: Two explicit mutations, two immutable models, one existing Exercise
and Closing context, one attachment-owner extension, three bounded user journeys; no
production throughput claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain authority | PASS — behavior maps directly to FR-042, FR-044, FR-045, FR-083 and invariants 28.29–28.31. |
| Category A–E discipline | PASS — §§14.9 and 24 define the operations, allowed targets, required evidence and prohibited historical changes; no category-E decision is introduced. |
| Simplicity and proportionality | PASS — direct Eloquent models, two Actions, existing AuditEvent/Attachment/Filament patterns; no repository, service or rules framework. |
| Slice boundary | PASS — full current-knowledge reports, comparisons, categories and exports remain in S11. |
| Dependency integrity | PASS — no dependency or dependency-source change. |
| Explicit domain operations | PASS — both mutations are explicit transactional Actions, not UI callbacks. |
| Historical and transactional integrity | PASS — original rows and all immutable snapshots remain untouched; each domain mutation locks, revalidates and commits atomically. |
| Forward-only migrations | PASS — S9 and earlier migrations remain unchanged. |
| Proportional tests | PASS — focused tests cover append-only behavior, rejection rules, rollback, immutability and the three owning invariants. |
| Inspectability | PASS — each vertical phase ends with focused checks and Laravel boot; browser verification is reserved for completed visible journeys. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/011-late-corrections/
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
├── Actions/LateCorrections/
│   ├── RecordLateCorrection.php
│   └── RecordHistoricalErrorAnnotation.php
├── Domain/LateCorrections/
│   ├── HistoricalErrorKind.php
│   └── HistoricalExpenseCompatibility.php
├── Filament/Resources/
│   ├── Exercises/Pages/ViewExercise.php
│   ├── Exercises/Schemas/ExerciseInfolist.php
│   └── Closings/Schemas/ClosingInfolist.php
├── Models/
│   ├── LateCorrection.php
│   ├── HistoricalErrorAnnotation.php
│   ├── Expense.php
│   ├── ExpenseLine.php
│   ├── Exercise.php
│   ├── ClosingSnapshot.php
│   └── Attachment.php
└── Policies/
    ├── LateCorrectionPolicy.php
    ├── HistoricalErrorAnnotationPolicy.php
    └── ExercisePolicy.php

database/
├── factories/
├── migrations/2026_08_24_000100_create_late_corrections.php
└── migrations/2026_08_24_000200_create_historical_error_annotations.php

tests/
├── Unit/Domain/LateCorrections/
└── Feature/LateCorrections/
```

**Structure Decision**: Keep the established single Laravel application. Use one
small deterministic compatibility rule and explicit transactional Actions. Reuse the
existing Exercise/Closing screens for context instead of adding a parallel correction
workspace or reporting resource.

## Design Phases

### Phase 0 — Research

[research.md](research.md) resolves persistence identity, compatible historical
Expense selection, immutable annotation vocabulary, attachment ownership, retry
receipts, locking and the S10/S11 presentation boundary. No functional clarification
remains.

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines immutable correction/annotation records,
  relationships, constraints and lock order.
- [contracts/ui.md](contracts/ui.md) defines the tenant-aware Italian journeys,
  historical-source selection and unavailable controls.
- [quickstart.md](quickstart.md) defines focused verification, final quality gate and
  authenticated demonstrations.

### Implementation phase boundary

Implementation follows [tasks.md](tasks.md) one smallest useful vertical slice at a
time. The first slice records and displays one late Actual correction end to end. The
second adds historical-error annotations. The final slice adds shared evidence,
invariant coverage and delivery verification. Each phase contains at most eight
substantial tasks.

## Complexity Tracking

No constitution violation requires an exception.