# Specification Quality Checklist: Carryover and Reprogramming

**Purpose**: Validate S8 completeness before implementation  
**Created**: 2026-08-23  
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Specification separates user/domain behavior from implementation plan.
- [x] No new business functionality is introduced outside canonical S8 requirements.
- [x] `Riporto`, `Riprogrammazione`, and `Nuova allocazione` remain distinct concepts.
- [x] Closing consolidation is explicitly excluded.
- [x] Terminology matches the current canonical domain (`Pianificato`, `Aperto`,
  `Chiuso`, `Cancellato`).

## Requirement Completeness

- [x] FR-059 mutual exclusivity is fully covered.
- [x] FR-060 canonical formulas and Carryover behavior are fully covered.
- [x] FR-061 negative-Actual cap is explicitly covered with canonical examples.
- [x] Invariants 28.11–28.16 each map to direct proof.
- [x] Both Proposal and direct pre-Closing mode-change paths are specified.
- [x] Proposal deferral decisions capture/revalidate source-year `N` context rather
  than relying only on the destination-year Project fingerprint.
- [x] Exact Reprogramming reversal and independent-line-change blocking are specified.
- [x] Proposal/Revision approval can use the same exact reversal when replacing an
  already-live Reprogramming.
- [x] Received Carryover in source allocation does not become a synthetic reducible
  Estimate; Reprogramming feasibility and canonical availability are both specified.
- [x] Independent destination `Nuova allocazione` survives reversal.
- [x] Generic `CreateExpense` cannot bypass `Nuova allocazione` for an already-live
  Project.
- [x] Later Actual non-retroactivity is specified.
- [x] Terminal Project compatibility covers both Proposal results and later live
  Project-transition operations without hidden deferral mutation.
- [x] Over-limit already-live provisional Carryover warning/no-auto-correction is
  specified.
- [x] Budget immutability and no double-counting are specified.
- [x] Timeline facts and mandatory reason behavior are specified, including the
  initial explicit `Riporto` choice.
- [x] Tenant/capability behavior uses existing capabilities only.
- [x] Atomicity and idempotency have explicit acceptance/test requirements.
- [x] No unresolved clarification marker remains.

## Data Model Review

- [x] Exactly one new current-state entity is introduced.
- [x] The added `ExpenseLine.revision` is technical freshness metadata required for
  exact reversal, not a new domain entity or ledger.
- [x] Current state does not depend on Timeline replay.
- [x] Carryover is not represented as a synthetic Expense.
- [x] Reprogrammed destination plan uses existing Expense/ExpenseLine identities.
- [x] Exact active reversal metadata is retained without creating a historical ledger.
- [x] Missing deferral row safely resolves to `Nessuna/0`.
- [x] Existing rows require no backfill.
- [x] Existing used migrations are not edited.

## Simplicity Review

- [x] No repository/service layer is added.
- [x] No generic workflow engine is added.
- [x] No matching/FIFO/LIFO algorithm is added.
- [x] No queue/cache/package is added.
- [x] S7 Proposal readiness/impact/approval paths are extended rather than duplicated.
- [x] One line-level Reprogramming application strategy is selected instead of two
  equivalent source-side branches.
- [x] Destination Reprogramming preview is deterministic rather than free-form.
- [x] No speculative S9 consolidation implementation is included.

## Implementation Readiness

- [x] `spec.md` is complete.
- [x] `research.md` resolves technical choices.
- [x] `data-model.md` defines persistent/current and Proposal shapes.
- [x] `contracts/ui.md` defines visible workflow and errors.
- [x] `plan.md` contains an explicit Constitution Check, integration path, and
  Complexity Tracking.
- [x] `tasks.md` is executable in phases with concrete file scope.
- [x] `quickstart.md` contains the required scenario matrix and final gate.
- [x] `.specify/feature.json` points to the package feature directory.

## Review Basis

This package was reconciled against the repository state on `main` at latest reviewed
commit `baa2e05c4de838ca78365240767c1384e0737ed5` and canonical file SHA
`122e8af31e98789940672a5c0e8ddbb84f2441c6`.

S7 remote GitHub Actions status was intentionally not rechecked for this package.
S8 assumes the project owner's accepted formal S7 close and the existing S7 local
verification evidence.
