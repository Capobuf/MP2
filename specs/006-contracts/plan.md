# Implementation Plan: Contracts

**Branch:** `006-contracts` | **Date:** 2026-08-20 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/006-contracts/spec.md`

## Summary

S5 introduces company-owned Contracts with a stable identity, mandatory Supplier,
dated lifecycle, versioned renewal configuration, recurring economic conditions,
annual generated Estimates, manual Actual Expenses, annual Cost Center
classification, informative deadlines, Project links, optional attachments, Archive,
late census of already-existing Contracts, and complete Timeline evidence.

The implementation extends the existing Laravel/Filament S3/S4 boundaries directly:
deterministic Contract value objects, explicit transactional Actions, forward-only
MySQL schema, tenant-scoped Resources, revision-safe impact previews, and existing
Expense/Line and audit infrastructure. Renewal materialization uses an idempotent
Action invoked by a scheduled Artisan command; state and projected dates remain
deterministic even before materialization. No queue, cache, plugin, package, generic
repository, invoicing model, or frontend framework is added.

S5 implements the deterministic Project-Contract `Collegato a` relation, the only
informative relation admitted by canonical §32. Its economic neutrality completes
FR-095 and invariant 28.60.

## Technical Context

**Language/Version:** PHP 8.3

**Primary Dependencies:** Laravel 13, Filament 5, existing `ext-bcmath`; no new
package or plugin

**Storage:** MySQL 8.4 family for domain records; existing private Laravel `local`
disk for immutable attachment blobs; persistent `mp2` and isolated `testing`
databases

**Testing:** Pest 4 unit, Action/model, Filament/Livewire, command, storage, and
cross-slice regression tests under the existing testing-database guard

**Target Platform:** Linux-hosted server-rendered web application through Laravel
Sail

**Project Type:** Single tenant-aware Laravel/Filament web application

**Performance Goals:** Contract lists, deadline filters, and ordinary mutations
complete in one normal request; annual totals and compositions avoid per-Line N+1
loading; renewal processing handles each Contract as one bounded transaction

**Constraints:** Exact-company isolation, `visualizza`/`modifica_operativita`,
company-local economic dates, UTC timestamps, exact decimal EUR net of VAT,
deterministic state and anchored recurrence, no prorata or matching, stable generated
Estimate identity, revision-safe previews, multi-Exercise atomicity, idempotent
commands, append-only history, private authorized attachment access, no physical
deletion of canonical objects, no closed-Exercise recalculation, and no retroactive
insertion into approved Budgets or Closing Snapshots during late census

**Scale/Scope:** One Contract Resource, one deadline page, bounded related views and
Actions, extensions to Exercise/Expense/Line/Project/Timeline, seven new domain
records including attachments, and six user journeys; no production-throughput
claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain authority | PASS — state, dates, renewal, recurrence, annual attribution, ownership, classification, deadlines, late census, attachments, and exclusions map directly to the canon. |
| Simplicity and proportionality | PASS — direct models, value code, Actions, policies, native Filament, Laravel scheduler, and private filesystem only. |
| Vertical slice and traceability | PASS — S5 completes the Contract-dependent first-level source rules and verifies FR-095/28.60 through `Collegato a`. |
| Dependency integrity | PASS — no new dependency and no modification of installed source. |
| Explicit domain operations | PASS — lifecycle, renewal, recalculation, classification, ownership movement, archive, attachment, and relation mutations use explicit Actions rather than UI-only callbacks. |
| Proportional tests | PASS — pure date/economic rules, MUST NOT cases, tenancy, rollback, retry, stale preview, storage authorization, and UI journeys receive focused tests. |
| Historical and transactional integrity | PASS — stable IDs, typed facts, revision tokens, ordered locks, deterministic event sequences, immutable closed references during late census, and private immutable blobs preserve history and atomicity. |
| Forward-only migrations | PASS — S0-S4 migrations remain unchanged; S5 adds only forward Contract and corrective extension migrations. |
| Inspectability | PASS — the later task plan must cap implementation phases at eight tasks and end each phase with boot, focused tests, and tenant UI inspection. |
| Category A-E discipline | PASS — ordinary narratives use Notes/Timeline/attachments and unsupported commercial complexity remains category D. |

Post-design re-check: **PASS**. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/006-contracts/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── ui.md
├── checklists/
│   └── requirements.md
└── tasks.md             # dependency-ordered implementation tasks
```

### Source Code (repository root)

```text
app/
├── Actions/Operations/
├── Console/Commands/
├── Domain/Contracts/
├── Filament/Pages/
├── Filament/Resources/{Contracts,Expenses,Projects}/
├── Models/{Contract,ContractCondition,ContractLifecycleFact,
│           ContractRenewalConfiguration,ContractExerciseClassification,
│           ProjectContractLink,Attachment}.php
└── Policies/

database/
├── factories/
└── migrations/*_{create_contracts,create_contract_facts_and_conditions,
                   add_contract_to_expenses,create_contract_classifications,
                   create_project_contract_links,create_attachments,
                   extend_audit_operation_sequence}_*.php

tests/
├── Unit/Domain/Contracts/
├── Feature/Contracts/
├── Feature/Expenses/
└── Feature/Projects/
```

**Structure Decision:** Extend the established single Laravel application. Pure
state, recurrence, renewal, annual-allocation, and impact-plan decisions live in
`app/Domain/Contracts`; mutating orchestration and locking live in
`app/Actions/Operations`; Filament remains presentation and interaction only.

## Design Phases

### Phase 0 — Research

The decisions for persistence, recurrence, renewal invocation, multi-year impact,
generated Estimates, ownership, attachments, audit sequencing, and UI reuse are
resolved in [research.md](research.md). No unresolved technical question remains
inside the bounded plan.

### Phase 1 — Design and contracts

- [data-model.md](data-model.md) defines fields, relationships, constraints, derived
  values, lifecycle/configuration history, revision effects, and lock order.
- [contracts/ui.md](contracts/ui.md) defines the Italian tenant UI, explicit previews,
  deadline filters, attachment authorization, and unavailable controls.
- [quickstart.md](quickstart.md) defines non-destructive automated, command, browser,
  attachment, renewal, and persistence validation.

### Implementation phase boundary

Implementation will follow [tasks.md](tasks.md), generated separately. It must split
the large S5 surface into bounded phases with at most eight implementation tasks per
phase. Every phase ends with focused tests against `testing`, Laravel boot, and an
inspectable `/admin` UI. No non-canonical informative relation receives an implementation task.

## Complexity Tracking

No constitution violation requires an exception.
