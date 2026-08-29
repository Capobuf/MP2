# Implementation Plan: Initial Proposal and Budget v1

**Branch:** `main` | **Date:** 2026-08-21 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/007-proposal-budget-v1/spec.md`

## Summary

S6 introduces one persistent, company-owned initial Proposal for an open Exercise
without a Budget. The Proposal deterministically captures every qualifying Expense,
Project, and Contract, keeps Actuals read-only, stores only validated source-specific
plan actions, exposes stale/new/inconsistent readiness states, previews all affected
open Exercises, and applies the complete plan only during an atomic and idempotent
approval. Successful approval creates exactly one immutable, autonomous Budget v1
with source-type detail, lineage, typed decisions, approval evidence, and Timeline
references.

The implementation extends the existing Laravel/Filament application directly:
forward-only MySQL tables, Eloquent models and policies, focused deterministic code
under `app/Domain/Proposals`, explicit transactional Actions under
`app/Actions/Proposals`, and tenant-scoped Filament Resources. No package, plugin,
queue, cache, generic repository, event sourcing, or frontend framework is added.

## Technical Context

**Language/Version:** PHP 8.3

**Primary Dependencies:** Laravel 13, Filament 5, existing `ext-bcmath`; no new
dependency or plugin

**Storage:** MySQL 8.4 family for Proposal/Budget records; existing private Laravel
`local` disk for retained attachment blobs; persistent `mp2` and isolated `testing`
databases

**Testing:** Pest 4 unit, Action/model, policy, Filament/Livewire, concurrency,
rollback, idempotency, storage-evidence, and browser tests under the existing
testing-database guard

**Target Platform:** Linux-hosted server-rendered Laravel/Filament application via
Laravel Sail

**Project Type:** Single tenant-aware web application

**Performance Goals:** Initialization and readiness use bounded set-based queries;
ordinary Proposal pages complete in one request; approval performs one bounded
transaction and does not load source rows per table row

**Constraints:** Exact company isolation; `visualizza`, `gestisce_proposte`, and
`approva_budget` authorization; open Exercise without any Budget; one active Draft;
source and membership concurrency validation; plan-only isolation; exact decimal EUR
net of VAT; typed actions rather than database patches; stable Proposal identity;
ordered locks; all-or-nothing approval; command idempotency; materialized immutable
Budget v1; no physical deletion; no Actual/Forecast/Closing values in the Budget;
Italian user-facing text; no S7+ resolution or revision workflows

**Scale/Scope:** Two new Resources, six persistent Proposal/Budget records,
source-specific immutable JSON contracts, and five bounded P1/P2 journeys; no
production throughput claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain authority | PASS — behavior maps to FR-011, FR-012, FR-015–FR-023, FR-027–FR-028, FR-085–FR-086, FR-097 and invariants 28.17, 28.19–28.21, 28.23, 28.47–28.48. |
| Category A–E discipline | PASS — only canonical `Collegato a` is exposed; structured source replacement is absent under §32. |
| Simplicity and proportionality | PASS — direct models, source-specific value code, Actions, policies, native Filament, existing audit and private storage only. |
| Slice boundary | PASS — S7 realignment resolution and revisions, S8 carryover/reprogramming, S9 Closing, S10 late correction, S11 complete reporting, Forecast and parallel alternatives remain absent. |
| Dependency integrity | PASS — no dependency is added and dependency source remains untouched. |
| Explicit domain operations | PASS — initialization, action mutation, readiness and approval are explicit Actions; approval is not a Filament callback-only mutation. |
| Historical and transactional integrity | PASS — ordered locks, fingerprints/revisions, operation IDs, one transaction, append-only audit and autonomous snapshot rows provide the required guarantees. |
| Forward-only migrations | PASS — existing migrations remain unchanged; S6 adds only new tables and additive lineage/revision columns required by the current slice. |
| Proportional tests | PASS — inclusion, isolation, MUST NOT rules, tenancy, authorization, stale membership, rollback injection, retry, immutability and UI journeys receive authoritative tests. |
| Inspectability | PASS — every implementation phase contains at most eight substantial tasks and ends with focused tests, application boot and UI inspection. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/007-proposal-budget-v1/
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
├── Domain/Proposals/
├── Filament/Resources/{Proposals,Budgets}/
├── Filament/Resources/Exercises/
├── Models/{Proposal,ProposalItem,ProposalAction,BudgetSnapshot,
│           BudgetSourceRow,BudgetEvidence}.php
└── Policies/{Proposal,BudgetSnapshot}Policy.php

database/
├── factories/
└── migrations/*_{create_proposals,create_proposal_items,
                   create_proposal_actions,create_budget_snapshots,
                   create_budget_source_rows,create_budget_evidence,
                   add_expense_copy_lineage,extend_attachment_ownership}_*.php

tests/
├── Unit/Domain/Proposals/
└── Feature/Proposals/
```

**Structure Decision:** Extend the established single Laravel application. Pure
inclusion, action-shape, readiness, impact and snapshot validation live under
`app/Domain/Proposals`; state-changing orchestration and locking live in explicit
Proposal Actions; Filament remains presentation and interaction.

## Design Phases

### Phase 0 — Research

Persistence, typed action envelopes, source fingerprints, deterministic inclusion,
lock order, atomic approval, operation idempotency, snapshot materialization,
evidence retention and Filament interaction are resolved in
[research.md](research.md). No unresolved technical question remains.

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines fields, constraints, state transitions,
  immutable payload contracts, revision/fingerprint rules and lock order.
- [contracts/ui.md](contracts/ui.md) defines Italian tenant UI journeys, readiness
  messages, action boundaries and unavailable later-slice controls.
- [quickstart.md](quickstart.md) defines non-destructive automated and authenticated
  browser validation.

### Implementation phase boundary

Implementation follows [tasks.md](tasks.md), one bounded phase at a time. Each phase
contains at most eight substantial implementation tasks and ends with focused tests
against `testing`, Laravel boot and an inspectable `/admin` UI.

## Complexity Tracking

No constitution violation requires an exception.
