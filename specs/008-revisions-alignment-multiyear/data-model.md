# Data Model: Revisions, Realignment, and Multi-Year Impact

## Proposal extensions

| Field | Shape | Rules |
|---|---|---|
| `purpose` | `initial_budget` or `revision` | Initial when no Budget exists; Revision when at least one exists |
| `reference_budget_id` | nullable Budget reference | Null for initial; required same-company/same-Exercise latest Budget at Revision creation and approval |
| `discarded_by_id` | nullable User reference | Set only with terminal discard |
| `discarded_at` | nullable UTC timestamp | Set only with terminal discard |
| `discard_reason` | nullable text | Required for discard |
| `discard_operation_id` | nullable unique UUID | Idempotent discard receipt |

Existing company, Exercise, single-active-Draft, revision, approval and terminal
immutability rules remain. Database purpose enums are widened by a forward-only
migration; the S6 migration is not edited.

State transitions:

```text
draft(initial)  --atomic approval--> approved + immutable Budget v1
draft(revision) --atomic approval--> approved + immutable Budget vN+1
draft(any)      --discard----------> discarded

approved/discarded --any mutation--> rejected
```

## Proposal Item baseline lifecycle

Existing Item fields remain authoritative. S7 gives the alignment metadata its full
meaning:

| Field | Realignment behavior |
|---|---|
| `baseline_revision` | Replaced with the current whole-source monotone revision after successful acknowledgement/realignment |
| `baseline_fingerprint` | Recomputed from the complete canonical source snapshot |
| `baseline` | Replaced atomically; prior value retained in the append-only audit event |
| `result` | Fresh plan baseline after reload; replayed typed result after keep/manual |
| `readiness_state` | `to_review`, `to_realign`, or `inconsistent` becomes `aligned` only after successful explicit resolution |
| `readiness_reasons` | Recalculated closed-list reasons; cleared only when all predicates pass |
| `last_aligned_at/by` | Updated on explicit acknowledgement/realignment |

The Proposal revision increments once per successful resolution transaction.

## Proposal Action extensions

| Field | Shape | Rules |
|---|---|---|
| `status` | `active` or `withdrawn` | Default active; only `active → withdrawn` is allowed |
| `withdrawn_by_id` | nullable User reference | Required when withdrawn |
| `withdrawn_at` | nullable UTC timestamp | Required when withdrawn |
| `withdraw_operation_id` | nullable UUID | Required when withdrawn; links all withdrawals in one resolution |
| `withdraw_reason` | nullable text | Required for keep/manual when the canonical choice requires explanation |

The row's type, payload, sequence, creator and original operation identity remain
immutable. Existing `Proposal::actions()` and `ProposalItem::actions()` expose only
active actions; `actionHistory()` exposes active and withdrawn rows in sequence.
Global relation actions are considered to touch an Item when their payload references
that Item's ProposalItemID or live OriginKey.

## Realignment choice

Closed values:

```text
reload  = Ricarica realtà
keep    = Mantieni proposta
manual  = Rivedi manualmente
```

Input common to every choice:

- Proposal and Item identity;
- expected Proposal revision;
- operation UUID;
- current actor/company;
- explicitly confirmed impact.

Additional input:

- `keep`: non-blank reason;
- `manual`: list of active action IDs to retain; unselected touching actions are
  withdrawn; later modification appends a replacement typed action.

The AuditEvent is the immutable realignment record and stores old/new baselines,
old/new fingerprints, choice, retained/withdrawn actions and impacts.

## New-source acknowledgement

No new persistent entity is required. The Item, updated alignment metadata, Proposal
revision and one idempotent AuditEvent represent the decision. A source can be
acknowledged only from `to_review`. Any typed plan changes already prepared remain
active and are revalidated against the captured baseline.

## Readiness reason vocabulary

Stable reason codes map one-to-one to canonical §12.14:

```text
carryover_above_limit
reprogramming_above_available
reprogramming_unbalanced
deferral_modes_conflict
actual_mutation
expense_dual_owner
manual_contract_estimate
invalid_contract_condition
incompatible_transition
closed_exercise_action
archived_source_without_restore
missing_required_data
stale_concurrent_action
invalid_relation
partial_multi_exercise_effect
```

`new_source` and `source_changed` remain readiness-state reasons rather than
inconsistency predicates. Technical exceptions are not persisted as new domain
reason codes.

## Budget version extensions

| Field | Revision rule |
|---|---|
| `version` | Latest locked version + 1; unique per Exercise |
| `purpose` | `revision` |
| `previous_budget_id` | Required and points to the locked latest Budget of the same company/Exercise |
| `proposal_id` | Unique approved Revision |
| `affected_exercises` | Open applied impacts plus read-only Closed divergences |
| evidence `reason` | Required Revision reason |

Budget header, rows, details and evidence remain update/delete-protected and
autonomous. The same S6 payload guard continues to exclude Actual, Forecast,
Residual, Variance, late-correction and Closing values.

## Impact plan extension

Each annual impact adds:

| Field | Meaning |
|---|---|
| `is_open` | Current Exercise state |
| `will_apply` | True only when this confirmed operation writes the Open Exercise |
| `historical_divergence` | True when planned current rules differ from immutable Closed history |
| `divergence_reason` | Italian explanation; null when no divergence |

Closed rows retain computed before/after/delta for explanation but are never passed
to live apply routines as writable targets. A divergence AuditEvent stores those
facts and references the Proposal/Revision operation. Formal historical-error
annotations are not created in S7.

## Lock and approval order

```text
Company
-> Proposal
-> main and impact Exercises by ID
-> Budget headers for the main Exercise by version
-> current source rows by type then ID
-> relevant child Lines/transitions/conditions/lifecycle/configurations/relations
-> Proposal Items by ID
-> active Proposal Actions by sequence
-> referenced master data/evidence by ID
-> re-enumerate membership and rebuild whole-source readiness/impact
-> apply only Open supported effects
-> mark competing Draft Items to_realign
-> append Closed divergence events
-> materialize Budget vN+1 rows/evidence
-> mark Proposal approved
```

Any failure rolls back every write. A retry with the same successful operation UUID
returns the existing Budget.
