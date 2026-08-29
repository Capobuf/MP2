# Domain Delivery Requirements Checklist: Initial Proposal and Budget v1

**Purpose**: Validate that S6 requirements are complete, unambiguous, consistent and
measurable before implementation
**Created**: 2026-08-21
**Feature**: [spec.md](../spec.md)

**Note**: This checklist tests the written requirements, not the implementation.

## Requirement Completeness

- [x] CHK001 Are eligibility and rejection requirements defined for Open, Closed, already-budgeted, foreign-company and already-Draft Exercises? [Completeness, Spec §US1, S6-FR-003–S6-FR-005]
- [x] CHK002 Are exact automatic-inclusion requirements defined for Expenses, Projects, Contracts and qualifying archived sources? [Completeness, Spec §US1, S6-FR-007]
- [x] CHK003 Are read-only Actual visibility and forbidden editable/baseline uses documented? [Completeness, Spec §US1, S6-FR-008–S6-FR-009]
- [x] CHK004 Are required Proposal Item identity, lineage, baseline, action, result and readiness facts enumerated? [Completeness, Spec §Key Entities, S6-FR-010]
- [x] CHK005 Are requirements present for existing, copied and new sources of every first-level type? [Completeness, Spec §US2, S6-FR-012–S6-FR-019]
- [x] CHK006 Are requirements present for links between new Proposal Items and their atomic live-ID resolution? [Completeness, Spec §US2, S6-FR-013, S6-FR-021]
- [x] CHK007 Are approval evidence, external approval facts and attachment-retention requirements complete? [Completeness, Spec §US4–US5, S6-FR-045–S6-FR-046]
- [x] CHK008 Are materialized Budget header, first-level row and all three source-detail requirements enumerated? [Completeness, Spec §US5, S6-FR-037–S6-FR-042]

## Requirement Clarity

- [x] CHK009 Is “one active Proposal” tied unambiguously to Company, Exercise and Draft status under concurrency? [Clarity, Spec §S6-FR-004]
- [x] CHK010 Is “typed action” distinguished explicitly from a generic database patch? [Clarity, Spec §S6-FR-011]
- [x] CHK011 Are the four readiness states named exactly and tied to canonical closed predicates? [Clarity, Spec §S6-FR-024–S6-FR-030]
- [x] CHK012 Is whole-source invalidation distinguished explicitly from field-level merge? [Clarity, Spec §S6-FR-025]
- [x] CHK013 Are approval lock, re-enumeration, recalculation and revalidation obligations stated separately from the earlier readiness preview? [Clarity, Spec §S6-FR-031]
- [x] CHK014 Is Budget immutability defined as independence from current live labels, values, state, archive and selectability? [Clarity, Spec §S6-FR-036]
- [x] CHK015 Is Budget v1 uniqueness distinguished from later version lineage without implying S7 Revision behavior? [Clarity, Spec §S6-FR-003, S6-FR-035]

## Requirement Consistency

- [x] CHK016 Are Proposal isolation and approval-time live application consistent across user stories, FRs and success criteria? [Consistency, Spec §US1–US4, S6-FR-006, S6-FR-032, SC-002]
- [x] CHK017 Are Actual changes treated consistently as readiness-invalidating reality while remaining excluded from plan actions and Budget baseline? [Consistency, Spec §US1–US3, S6-FR-009, S6-FR-025, S6-FR-043]
- [x] CHK018 Are Company capabilities consistent between read, manage, review and approve journeys? [Consistency, Spec §S6-FR-001–S6-FR-002, SC-009]
- [x] CHK019 Are zero-allocation decision-bearing sources consistently included in Budget while excluded from totals? [Consistency, Spec §Edge Cases, S6-FR-038, S6-FR-044]
- [x] CHK020 Are Project–Contract `Collegato a` requirements consistent with canonical §32 and zero economic propagation? [Consistency, Spec §S6-FR-021–S6-FR-022]

## Acceptance Criteria Quality

- [x] CHK021 Can exact inclusion be objectively measured as all qualifying and no non-qualifying sources? [Measurability, Spec §SC-001]
- [x] CHK022 Can pre-approval isolation be objectively measured across allocation, Actual, ownership, classification, state and identity? [Measurability, Spec §SC-002]
- [x] CHK023 Can stale-source and source-set rejection be observed before every persisted effect? [Measurability, Spec §SC-004]
- [x] CHK024 Can rollback be measured as zero partial live, snapshot, evidence, status and event records at injected failure points? [Measurability, Spec §SC-005]
- [x] CHK025 Can retry idempotency be measured by exact Budget, object and event counts? [Measurability, Spec §SC-006]
- [x] CHK026 Can snapshot autonomy be measured after supported live changes and Archive? [Measurability, Spec §SC-007]
- [x] CHK027 Are complete-suite, static-analysis, formatting, dependency, boot and authenticated-browser completion signals explicit? [Acceptance Criteria, Spec §SC-010]

## Scenario and Edge-Case Coverage

- [x] CHK028 Are concurrent Draft creation, stale confirmation and lost-success-response retry scenarios addressed? [Coverage, Spec §Edge Cases, S6-FR-004, S6-FR-031, S6-FR-034]
- [x] CHK029 Are archived/storned source, net-zero Actual presence and source-created-after-initialization cases addressed? [Coverage, Spec §Edge Cases, S6-FR-007, S6-FR-026]
- [x] CHK030 Are new child references, invalid relation, unresolved reference and atomic resolution cases addressed? [Coverage, Spec §US2–US4, S6-FR-013, S6-FR-033]
- [x] CHK031 Are snapshot failure, evidence detachment, physical deletion and live-source mutation cases addressed? [Coverage, Spec §US4–US5, Edge Cases]

## Dependencies, Exclusions and Ambiguities

- [x] CHK032 Are S3–S5 dependencies and reused authority boundaries stated without redefining their behavior? [Dependency, Spec §Assumptions]
- [x] CHK033 Are S7 resolution/Revision, S8 carryover/reprogramming, S9 Closing, S10 late correction and S11 full reporting boundaries explicit? [Coverage, Spec §S6-FR-023, S6-FR-027, S6-FR-050]
- [x] CHK034 Is structured source replacement absent without introducing endpoint-movement rules? [Conflict, Spec §S6-FR-022, Assumptions]
- [x] CHK035 Are Forecast, fuzzy matching, parallel alternatives, arbitrary as-of reconstruction and maker-checker explicitly excluded? [Coverage, Spec §S6-FR-043, S6-FR-045, S6-FR-050]
- [x] CHK036 Are the 16 canonical S6 FRs and seven primary invariants traceably accounted for without claiming later-slice rules? [Traceability, Spec §Requirements, Assumptions]

## Notes

- Formal release-gate depth for reviewer use.
- Focus: canonical completeness, approval safety, snapshot autonomy and slice boundaries.
- Result: 36/36 requirement-quality checks pass before implementation.
