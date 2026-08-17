# MP2 Product Roadmap — Spec of Specs

**Created:** 2026-08-17  
**Status:** Active roadmap  
**Domain source:** `docs/domain/Specifica_Canonica_Semplificata_v4.md`

## Purpose

This roadmap solves two separate problems:

1. no canonical feature may be forgotten;
2. no coding agent should implement the entire product in one oversized context.

GitHub Spec Kit's Spec-of-Specs approach is used: roadmap IDs are stable, while each
implementation slice enters its own normal `specify → plan → tasks → implement`
cycle only when dependencies are ready.

## Rules

- `S0`–`S11` IDs are immutable traceability anchors.
- Only the active slice is specified in implementation detail.
- A future slice is not implemented early merely because its requirements are known.
- Timeline/audit is added incrementally by the slice that creates each event; it is
  not postponed to reporting.
- Authorization, atomicity, idempotency and history rules are implemented when first
  required, then reused.
- Before starting the next slice, reconcile its FR/invariant rows against the
  canonical domain.
- If a slice becomes too large, split that slice into sub-specs while retaining the
  same roadmap parent ID.

## Roadmap

| ID | Planned feature directory | Slice | Depends on | Independent demonstration | Status |
|---|---|---|---|---|---|
| S0 | `001-foundation-dev-environment` | Foundation e ambiente di sviluppo live | none | Runnable Laravel/Filament dev environment, persistent DB, credentials, CI | implemented |
| S1 | `002-company-access-settings` | Azienda, accesso e impostazioni | S0 | Create company, assign per-company capabilities, change company settings | implemented |
| S2 | `003-master-data` | Anagrafiche | S1 | Manage suppliers, contacts and cost centers with archive/restore | implemented |
| S3 | `004-exercises-expenses` | Esercizi, Spese e Righe | S1,S2 | Create open year and autonomous expenses; estimates/actuals calculate correctly | verified |
| S4 | `005-projects` | Progetti | S3 | Create project, transition state, attach expenses, see allocation/actual/variance | planned |
| S5 | `006-contracts` | Contratti | S2,S3 | Create contract, generate annual estimate, handle lifecycle and deadlines | planned |
| S6 | `007-proposal-budget-v1` | Proposta e Budget iniziale | S3,S4,S5 | Prepare isolated proposal and approve immutable Budget v1 | planned |
| S7 | `008-revisions-alignment-multiyear` | Revisioni, riallineamento e impatto multi-Esercizio | S6 | Reality changes invalidate proposal; revision and multi-year actions stay atomic | planned |
| S8 | `009-carryover-reprogramming` | Riporto e Riprogrammazione | S4,S7 | Choose one deferral mode and transfer valid amount without double application | planned |
| S9 | `010-closing` | Chiusura | S5,S7,S8 | Close year with blocking checks, warnings and immutable closing snapshot | planned |
| S10 | `011-late-corrections` | Correzioni post-Chiusura | S9 | Append late actuals/annotations without reopening or reclassifying history | planned |
| S11 | `012-reporting-exports` | Reportistica ed esportazione | S6,S9,S10 | Compare explicit references with deterministic categories, drill-down and exports | planned |

## Implementation cadence

For each slice after S0:

1. select the earliest roadmap item whose dependencies are verified;
2. read all canonical sections and test cases referenced by its FR/invariant rows;
3. run `/speckit.specify` for that slice only;
4. clarify only genuine unresolved user/product decisions;
5. run `/speckit.plan`;
6. run `/speckit.tasks`;
7. run analysis/checklist if available;
8. implement one bounded phase at a time;
9. manually demonstrate the vertical behavior;
10. mark FRs/invariants `implemented` then `verified`;
11. move to the next slice.

## Definition of a verified slice

A slice is `verified` only when:

- its independent demonstration works;
- all primary FR rows assigned to it are accounted for;
- its primary invariants have authoritative tests;
- relevant MUST NOT rules have rejection tests;
- relevant atomic/idempotent operations have corresponding tests;
- CI is green;
- the application remains bootable and inspectable;
- no dependency source was modified;
- no domain behavior was invented.

## Cross-cutting concerns

### Timeline

FR-084 has primary ownership in S3 because that is where the first economic domain
mutations appear. Every later slice extends the event taxonomy required by §22.

### S3 autonomous container boundary

S3 implements only the autonomous side of FR-005, FR-051, FR-052 and invariant 28.4.
The canonical rule is complete and has no category-E gap, but its Project and Contract
cases are intentionally unrepresentable until S4 and S5 add those real containers and
foreign keys. Those shared traceability rows remain `planned` until every container
case can be exercised; S3 must not add placeholder ownership columns to claim them
early.

### Snapshot materialization

Budget snapshot behavior begins in S6. Closing snapshot behavior is completed in S9.

### Reporting

Minimal values may be displayed by earlier slices to make their behavior observable.
S11 owns the complete canonical comparison/report/export semantics.

### Attachments

Attachment storage is introduced in the earliest slice that requires actual uploaded
evidence. It must already respect the later immutable/versioned-evidence requirement;
a future snapshot slice must not require rewriting stored evidence architecture.

## No premature branch/spec creation

This package creates only:

- the roadmap;
- the fully detailed S0 feature.

Future detailed feature directories are created when each slice becomes active. This
is intentional.
