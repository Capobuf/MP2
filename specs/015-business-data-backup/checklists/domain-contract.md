# Backup Contract Requirements Quality Checklist

**Purpose**: verify that the XLSX and restore requirements are complete, unambiguous, measurable, and traceable before implementation.

**Created**: 2026-08-30

## Scope and exclusions

- [x] CHK001 Is semantic equivalence scoped to exactly one Company and distinguished from technical instance equality? [Completeness, Spec §FR-BDB-001]
- [x] CHK002 Are included business domains and excluded technical/security domains stated explicitly enough to prevent schema-dump behavior? [Completeness, Spec §FR-BDB-002–003]
- [x] CHK003 Are Proposal data, attachment binaries, storage coordinates, source users/capabilities, and ordinary technical metadata excluded without ambiguity? [Clarity, Spec §FR-BDB-003, §FR-BDB-020, §FR-BDB-026]
- [x] CHK004 Is the boundary between this backup, PDF reporting, disaster recovery, editable templates, and future file bundles explicit? [Scope, Spec §FR-BDB-048]

## Workbook contract

- [x] CHK005 Are format, filename, version, visible views, hidden restore sheets, headers, cell representations, and checksum input defined exactly once? [Completeness, Contract §Invariants–Canonical checksum]
- [x] CHK006 Are portable identities required for every relationship and are source IDs/fuzzy matching forbidden? [Clarity, Spec §FR-BDB-010–012; Contract §Portable reference prefixes]
- [x] CHK007 Are exact decimal syntax, date/time representation, UTF-8 handling, formula prevention, and long-cell reconstruction objectively testable? [Measurability, Spec §FR-BDB-004, §FR-BDB-037–039]
- [x] CHK008 Does the manifest specify enough fields and per-sheet evidence to diagnose and reject tampering without implying signatures? [Completeness, Spec §FR-BDB-006, §FR-BDB-036]
- [x] CHK009 Are visible sheets explicitly non-authoritative and protected from becoming a second import contract? [Consistency, Contract §Visible sheets]

## Domain fidelity

- [x] CHK010 Are Expense-line presence, zero-net actuals, reversal/annulment, ownership, and copied lineage separately preserved? [Coverage, Spec §FR-BDB-014]
- [x] CHK011 Are Project transitions/classifications/deferrals and Contract renewal/lifecycle/condition/classification histories specified without replaying current-time Actions? [Coverage, Spec §FR-BDB-015–018, §FR-BDB-034]
- [x] CHK012 Is active reprogramming represented with enough portable before/after evidence to validate and later reverse only its own effects? [Completeness, Spec §FR-BDB-016; Contract §`_MP2_project_deferrals`]
- [x] CHK013 Are Budget and Closing materialized details required as explicit portable contracts rather than opaque current payload serialization? [Clarity, Spec §FR-BDB-021–025; Contract §Portable materialized detail]
- [x] CHK014 Are business-only BudgetEvidence and inventory-only Attachments distinguished so restore cannot create fictitious files? [Consistency, Spec §FR-BDB-026–027]
- [x] CHK015 Is imported-author absence narrowly specified while new local operations remain actor-attributed? [Clarity, Spec §FR-BDB-028]

## Restore guarantees

- [x] CHK016 Are authorization, preview-before-write, new-Company-only behavior, name collision, initial capability assignment, and retry behavior all explicit? [Completeness, Spec §FR-BDB-029–035]
- [x] CHK017 Does rejection coverage enumerate structural, referential, domain, lineage, total, Contract, Closing, correction, and reprogramming failures? [Coverage, Spec §FR-BDB-041]
- [x] CHK018 Are validation atomicity and rollback criteria phrased so the database can be proven unchanged on every prevalidation or transaction failure? [Measurability, Spec §FR-BDB-033–035; SC-003–004]
- [x] CHK019 Does round-trip success define both full included-graph equivalence and every canonical S11 report dimension? [Measurability, Spec §FR-BDB-042; SC-001–002]
- [x] CHK020 Are post-restore Contract, reprogramming, and Revision workflows named as acceptance obligations rather than assumed from historical readability? [Coverage, Spec US4; SC-005]

## Operations and release

- [x] CHK021 Is Drive constrained to the byte-identical XLSX and is scheduling exposed without inventing cadence or retention? [Scope, Spec §FR-BDB-043–046]
- [x] CHK022 Are runtime-extension and shared-hosting verification requirements tied to resolved dependencies and the repository quality gate? [Traceability, Spec §FR-BDB-047; SC-010]

## Notes

- All checklist questions are satisfied by the specification or the normative workbook contract.
- This checklist evaluates requirements quality; implementation behavior is verified by `tasks.md` and automated tests.
