# Implementation Plan: Revisions, Realignment, and Multi-Year Impact

**Branch:** `main` | **Date:** 2026-08-23 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from
`specs/008-revisions-alignment-multiyear/spec.md`

## Summary

S7 extends the verified S6 Proposal/Budget path rather than creating another
planning system. An Open Exercise with Budget v1 or later can start one Revision
Draft from current live reality, use the latest Budget only as comparison, resolve
whole-source invalidation through the three canonical choices, acknowledge newly
included sources, discard without rollback, and approve the next immutable Budget
version atomically and idempotently.

The existing source snapshots, typed plan actions, impact calculation, ordered
locking, live application Actions, audit events, and materialized Budget rows remain
the implementation base. One forward-only migration adds Revision/discard lineage
and a one-way active/withdrawn state to Proposal actions. Small deterministic helpers
replay only the existing closed action vocabulary. Closed-year differences are
reported and audited but excluded from writes. No package, plugin, generic merge
engine, generic multi-year engine, queue, cache, or new application layer is added.

## Technical Context

**Language/Version:** PHP 8.3

**Primary Dependencies:** Laravel 13.17+, Filament 5, existing `ext-bcmath`; no new
dependency or plugin

**Storage:** Existing MySQL 8.4-family Proposal/Budget tables plus one additive
migration; existing append-only `audit_events` for realignment, acknowledgement,
discard, divergence, and Revision receipts

**Testing:** Pest 4 focused unit, Action/model, policy, transaction rollback,
idempotency, Filament/Livewire, and invariant tests against the isolated `testing`
database; complete suite and heavier quality checks only at the final gate

**Target Platform:** Linux-hosted server-rendered Laravel/Filament application via
Laravel Sail

**Project Type:** Single tenant-aware web application

**Performance Goals:** One bounded source snapshot/replay per realignment; one
set-based membership review; one ordered transaction per approval/discard; no
per-table-row source reload in Proposal lists

**Constraints:** Exact company isolation and capability checks; Open main Exercise;
one active Draft; whole-source rather than per-field realignment; closed inconsistency
vocabulary; exact decimals; no Actual mutation; immutable earlier Budgets; unique
next version; ordered locks; stale membership recheck; atomicity and operation-ID
idempotency; no physical deletion; Italian UI; no S8+ behavior

**Scale/Scope:** Extension of one Proposal Resource and one Budget Resource, three
new explicit Proposal Actions, one replay helper, one migration, and five bounded
P1 journeys; no production throughput claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain authority | PASS — behavior maps to FR-013, FR-024–FR-026, FR-029–FR-030 and invariants 28.18, 28.22 and 28.55. |
| Category A–E discipline | PASS — all delivered cases are directly specified or composable; structured source replacement is absent under §32. |
| Simplicity and proportionality | PASS — existing snapshots, value rules, Actions, audit receipts, policies and Filament screens are extended directly. |
| Slice boundary | PASS — S8 carryover/reprogramming, S9 Closing, S10 late corrections/annotations and S11 full reports/exports remain absent. |
| Dependency integrity | PASS — no dependency is added and dependency source remains untouched. |
| Explicit domain operations | PASS — realignment, acknowledgement, discard and approval remain explicit transactional Actions rather than UI-only callbacks. |
| Historical and transactional integrity | PASS — prior Budgets/Closed Exercises are immutable; version assignment, source replay and all multi-Exercise writes are locked, atomic and idempotent. |
| Forward-only migrations | PASS — the used S6 migration is not edited; S7 adds a corrective/extension migration only. |
| Proportional tests | PASS — focused tests cover each MUST NOT, rollback, retry, whole-source invalidation and invariant; the long complete suite is deferred to the final phase. |
| Inspectability | PASS — each implementation phase stays at eight or fewer substantial tasks and ends with focused checks and application boot. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/008-revisions-alignment-multiyear/
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
├── Actions/Proposals/
│   ├── InitializeProposal.php
│   ├── RealignProposalItem.php
│   ├── AcknowledgeProposalSource.php
│   ├── DiscardProposal.php
│   ├── ApproveProposal.php
│   └── MaterializeBudgetSnapshot.php
├── Domain/Proposals/
│   ├── ProposalActionReplay.php
│   ├── ProposalRealignmentChoice.php
│   ├── ProposalReadiness.php
│   ├── ProposalReadinessReason.php
│   ├── ProposalImpactPlan.php
│   └── ProposalPurpose.php
├── Filament/Resources/{Proposals,Budgets,Exercises}/
├── Models/{Proposal,ProposalItem,ProposalAction,BudgetSnapshot}.php
└── Policies/ProposalPolicy.php

database/
├── factories/
└── migrations/2026_08_23_000100_extend_proposals_for_revisions.php

tests/
├── Unit/Domain/Proposals/
└── Feature/Proposals/
```

**Structure Decision:** Keep the established single Laravel application. Reuse the
existing pure source-specific plan rules for replay, keep state-changing orchestration
in explicit Proposal Actions, and expose only the S7 controls in the current native
Filament Resources.

## Design Phases

### Phase 0 — Research

Revision lineage, action withdrawal/replay, realignment receipts, closed-list
readiness, version assignment, closed-year divergence, copy-from-Closed behavior,
locking/idempotency, and Filament interaction are resolved in
[research.md](research.md). No technical or functional clarification remains.

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines additive fields, one-way action withdrawal,
  Proposal/Budget transitions, replay rules, version constraints and lock order.
- [contracts/ui.md](contracts/ui.md) defines the Italian tenant journeys and precise
  disabled/error states.
- [quickstart.md](quickstart.md) defines focused checks, final heavy checks and the
  authenticated Revision-to-`vN+1` validation.

### Implementation phase boundary

Implementation follows [tasks.md](tasks.md), one bounded phase at a time. Each phase
contains at most eight substantial implementation tasks. Per the user instruction,
phase checkpoints use only focused tests and Laravel boot; the complete suite,
static analysis, dependency checks and browser verification run once at the end.

## Complexity Tracking

No constitution violation requires an exception.
