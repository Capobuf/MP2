# Data Model: Contracts

## Contract

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; never reused |
| `company_id` | Company reference | Required; restrict deletion |
| `supplier_id` | Supplier reference | Required; same Company; active for new selection |
| `title` | string | Required/trimmed; technical max 255; not identity |
| `notes` | nullable text | Explanatory |
| `contractual_start_date` | date | Company-local first possible Active day |
| `next_expiry_date` | nullable date | Current live projection; not before start |
| `renewal_anchor_date` | nullable date | Most recent manually approved expiry anchor |
| `automatic_renewal` | boolean | Required; default true |
| `renewal_duration_months` | nullable positive integer | Required when automatic renewal and expiry are defined |
| `notice_days` | nullable non-negative integer | Calendar days |
| `archived_at` | nullable technical timestamp | Visibility only |
| `revision` | unsigned monotone integer | Aggregate preview/source token |
| timestamps | technical UTC | Never substitute for economic dates |

Constraints include unique `(id, company_id)`, a composite same-company Supplier
foreign key, checks for expiry/start and renewal-duration coherence, and indexes for
Company/archive/title, Supplier, next expiry, renewal flag, and deadline filters. No
ordinary delete is available.

The current lifecycle state is not stored. The current renewal fields are a live
projection that must remain reproducible from the latest effective renewal
configuration and materialized renewal facts.

```text
OriginKey = "contract:" + Contract.id
```

Relations: lifecycle facts, renewal configurations, economic conditions, annual
classifications, generated/manual Expenses, Project links, attachments, and Timeline.

## Contract Renewal Configuration

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Append-only historical fact |
| `company_id` | Company reference | Equal to Contract Company |
| `contract_id` | Contract reference | Composite same-company FK |
| `effective_from` | date | Required company-local configuration date, including the initial configuration |
| `automatic_renewal` | boolean | Complete configuration, not a patch |
| `expiry_anchor_date` | nullable date | Manually approved anchor |
| `renewal_duration_months` | nullable positive integer | Required for automatic renewal with anchor |
| `notice_days` | nullable non-negative integer | Calendar days |
| `created_by_id` | User reference | Author |
| timestamps | technical UTC | Creation evidence |

The configuration effective at a date is the last row ordered by
`(effective_from, id)`. A change appends a complete row and atomically updates the
Contract projection. It never rewrites configurations or renewal facts already
effective. Duplicate effective configurations for one Contract are rejected by the
Action under Contract lock; a no-op creates neither row nor event.

Contract creation requires the user to declare the initial configuration's
`effective_from`; it is not inferred from the technical census timestamp. This lets
a late census select the configuration declared effective at each elapsed expiry
without treating the registration date as an economic date.

## Contract Lifecycle Fact

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never reused or physically deleted |
| `company_id` | Company reference | Equal to Contract Company |
| `contract_id` | Contract reference | Required composite same-company FK |
| `type` | `activation`, `cessation`, `expiry_cessation`, `reactivation`, `cancellation`, `renewal` | Closed vocabulary |
| `declared_contractual_date` | date | Date declared by the domain operation |
| `state_change_date` | nullable date | First day in new state; null for renewal |
| `renewed_expiry_date` | nullable date | Required only for renewal identity |
| `renewal_configuration_id` | nullable configuration reference | Configuration used at an expiry |
| `reason` | nullable text | Required only for cessation, reactivation, cancellation, or other canonical case |
| `created_by_id` | User reference | Author |
| `annulled_at` | nullable technical timestamp | Only future facts may be annulled |
| `annulled_by_id` | nullable User reference | Required with annulment |
| `annulment_reason` | nullable text | Audit explanation for annul/replace |
| timestamps | technical UTC | Fact creation metadata |

Display status is derived:

```text
annulled_at != null                              -> Annulled
relevant effective date > Company-local today  -> Planned
otherwise                                       -> Effective
```

MySQL generated nullable keys enforce one non-annulled state-changing fact per
Contract/state-change date and one non-annulled renewal per
Contract/renewed-expiry date. The full non-annulled sequence is validated under
Contract lock. An effective fact cannot be edited or annulled; correction uses a new
canonical fact.

Cessation stores the declared last Active day and `state_change_date` as the next
day. Activation/reactivation store the first Active day in both date roles.
`expiry_cessation` distinguishes automatic expiry without renewal, which requires no
invented Note, from explicit cessation.

## Contract state at date

`ContractStateTimeline` applies start, non-annulled facts, and the configuration
historically effective at each expiry.

```text
date < contractual_start_date -> Planned
activation/reactivation       -> Active from state_change_date
cessation/expiry_cessation     -> Cessated from state_change_date
cancellation                   -> Cancelled
renewal                        -> remains Active
```

For a future date, automatic renewals are projected from effective configurations
without creating facts. A source absent from persisted history before its census
still retains its real contractual start. Its creation operation derives current
state, deadlines, and elapsed renewals from that date, records the technical census
date in the Timeline, and recalculates only affected open Exercises. Approved
Budgets and Closing Snapshots are never retroactively populated or recalculated.

## Contract Economic Condition

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never reused or physically deleted |
| `company_id` | Company reference | Equal to Contract Company |
| `contract_id` | Contract reference | Required composite same-company FK |
| `cycle` | `monthly`, `quarterly`, `semiannual`, `annual` | Exactly one |
| `attribution_mode` | `cycle_start`, `cycle_end` | Exactly one |
| `amount` | decimal(19,2) | Authoritative EUR net VAT; non-negative |
| `valid_from` | date | Inclusive original anchor |
| `valid_to` | nullable date | Inclusive; not before valid_from |
| `reason` | nullable text | Required for material correction/annulment when applicable |
| `created_by_id` | User reference | Author |
| `annulled_at` | nullable technical timestamp | Annulled conditions do not calculate |
| `annulled_by_id` | nullable User reference | Required with annulment |
| timestamps | technical UTC | Audit metadata |

No database range type is introduced. The Action locks the Contract and all its
conditions, rejects overlap among non-annulled inclusive intervals, and validates
state compatibility. The first condition cannot precede contractual start.

A real economic change closes the prior interval on the day before the confirmed
effective boundary and inserts a new condition anchored to that boundary. A future
condition whose first cycle has not begun may be replaced while keeping its original
future `valid_from`. A declared material correction may update the original row only
when every recalculated Exercise is open; Timeline retains exact before/after values.

## Derived cycle and annual composition

No cycle table exists. `ContractCycle` is a deterministic value with condition ID,
cycle start, attribution date, and amount.

```text
months = monthly:1, quarterly:3, semiannual:6, annual:12
cycleStart(k) = add_months_anchored(valid_from, k * months)
```

Anchored addition always returns to the original day and uses month end only when
that day is absent. A cycle is eligible only when its start lies in the inclusive
validity interval and Contract state is Active on that start.

```text
cycle_start mode -> attributionDate = cycleStart
cycle_end mode   -> attributionDate = next cycleStart
annualEstimate(E) = exact sum(amount where attributionDate year = E)
```

No prorata or Actual matching is present. A cycle begun while Active keeps its full
amount even if end attribution follows cessation.

## Contract Exercise Classification

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | No ordinary deletion |
| `company_id` | Company reference | Required |
| `contract_id` | Contract reference | Same Company |
| `exercise_id` | Exercise reference | Same Company; open for mutation |
| `cost_center_id` | nullable Cost Center reference | Null = Unclassified |
| timestamps | technical UTC | Change history is in Timeline |

Constraints mirror Project classifications: unique `(contract_id, exercise_id)` and
composite company foreign keys. Manual choice accepts only active Cost Centers;
existing archived references remain readable. A new Exercise copies the latest known
classification, including null or an archived historical reference, without creating
Expenses, Lines, or values.

## Expense ownership and origin extension

Add to Expense:

| Field | Shape | Rules |
|---|---|---|
| `contract_id` | nullable Contract reference | Same Company |
| `origin` | `manual`, `system` | Existing rows backfilled/default manual |

Database and Action invariants:

```text
NOT (project_id IS NOT NULL AND contract_id IS NOT NULL)
```

```text
(project_id IS NOT NULL OR contract_id IS NOT NULL)
    -> direct_cost_center_id IS NULL
```

```text
origin = system -> contract_id IS NOT NULL
```

A generated nullable key creates unique `(contract_id, exercise_id)` only for
`origin = system`, leaving manual Contract Expenses unrestricted.

- A manual Contract Expense contains only Actual Lines.
- A system Contract Expense contains one generated Estimate Line.
- System Expense/Line creation, edit, annul/restore, reverse/restore, and movement
  are unavailable to manual paths.
- A Contract Expense's stored `supplier_id` must equal the Contract Supplier; its
  Cost Center is derived from the annual Contract classification.
- Entering a Contract requires only Actual Lines, clears direct Cost Center, replaces
  Supplier after explicit warning, and preserves Expense/Line IDs.
- Leaving may retain the Contract Supplier. Autonomous destination receives the
  explicit selected active direct Cost Center or null/Unclassified; another
  container inherits its nullable annual classification.
- Moving out never moves or copies the generated system Estimate.

## Generated Contract Estimate

The existing Expense and one ExpenseLine form the stable generated Estimate for one
Contract/Exercise. Recalculation locks the Contract, affected open Exercises,
conditions, system Expenses, and Lines.

```text
new amount > 0 and no row -> create Expense + one Estimate Line
existing row              -> update the same Line amount and composition evidence
existing row becomes 0    -> keep Expense and Line at 0
new amount = 0 and no row -> create nothing
```

The current composition is derived from Contract facts and conditions and included
in the Contract detail and recalculation Timeline payload. It does not create a
cycle-to-Actual link.

## Contract Actual Expense

Manual Contract Expenses reuse the existing Expense/Line schema and Action paths.
Ordinary Actual creation requires Contract Active on the company-local technical
registration date. Planned rejects ordinary Actuals. Cessated/Cancelled accepts only
declared late charge, cessation cost, reimbursement, or correction with Note in an
open Exercise, without state change.

`HasActuals` retains its existing non-zero predicate for totals/state rules. Supplier
immutability is different: any active Actual Line, including zero, constitutes first
economic use for Contract Supplier changes.

## Project Contract Link

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never reused/deleted |
| `company_id` | Company reference | Required |
| `project_id` | Project reference | Same Company |
| `contract_id` | Contract reference | Same Company |
| `note` | nullable text | Informative only |
| `archived_at` | nullable technical timestamp | Active or Archived |
| `revision` | unsigned monotone integer | Stale restore/update token |
| timestamps | technical UTC | Audit metadata |

Composite foreign keys enforce Company equality. A generated nullable active marker
enforces one active link per Project/Contract pair. Archive and restore preserve the
row and change no amount, state, ownership, classification, or carryover.

No non-canonical informative relation has a table, enum case, UI, or Action. The
`Collegato a` model and its economic-neutrality tests verify FR-095 and invariant
28.60.

## Attachment

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity/version | Never reused or overwritten |
| `company_id` | Company reference | Required |
| `contract_id` | nullable Contract reference | Exactly one owner field is set |
| `expense_id` | nullable Expense reference | Same Company validated |
| `expense_line_id` | nullable ExpenseLine reference | Owning Expense must have same Company |
| `storage_disk` | string | Fixed to configured private disk in S5 |
| `storage_path` | unique string | Immutable non-public key |
| `original_name` | string | Display only |
| `media_type` | nullable string | Observed upload metadata |
| `size_bytes` | unsigned integer | Stored metadata |
| `sha256` | fixed hexadecimal string | Content verification, not business identity |
| `uploaded_by_id` | User reference | Required |
| `detached_at` | nullable technical timestamp | Hidden from live object, blob retained |
| `detached_by_id` | nullable User reference | Required with detachment |
| timestamps | technical UTC | Upload evidence |

A check enforces exactly one owner column. Contract and Expense use same-company
foreign keys; Line tenancy is rechecked through its Expense because the existing Line
table intentionally has no company column. Upload creates a new immutable blob and
row. Replacement is another Attachment. Detachment never deletes the file or row.
Download reauthorizes the user's `visualizza` capability for the owning Company.

Future approval, Revision, Closing, late-correction, or historical-annotation records
can retain the stable Attachment ID. Those workflows are not created in S5.

## Audit event operation sequence

Add `event_sequence` unsigned integer default `0` to AuditEvent. Replace unique
`operation_id` with unique `(operation_id, event_sequence)` in a forward migration.
Existing S0-S4 Actions remain sequence zero. S5 events use deterministic sequence
numbers ordered by causal domain step; missed renewals are ordered by expiry.

A retry first loads all events for the operation ordered by sequence, verifies their
types/subjects, and returns the already-applied result. A no-op produces no event.
Contract Timeline filtering uses immutable subject/reference payloads so Expense
movement does not erase history.

## Impact plans and fingerprints

Each consequential preview includes:

- operation kind and target Contract;
- Contract, Exercise, Expense, condition, and configuration revision inputs;
- affected open Exercises and source IDs;
- exact allocation/Actual before and after per Exercise;
- old/new state, terms, classification, Supplier, or owner as applicable;
- requested, minimum, and effective dates when economic conditions change;
- unchanged Budget references and later-slice Proposal placeholders only when they
  actually exist in future slices;
- warnings, blocks, and canonical reason requirements;
- deterministic fingerprint.

Confirmation recomputes after authorization and ordered locks. Any mismatch rejects
the complete operation.

## Revision effects

- Contract detail, lifecycle, condition, renewal, classification, Supplier,
  archive/restore, or link mutation increments Contract revision.
- Contract Expense/Line create, update, annul/restore, reverse/restore, or ownership
  movement increments Expense and Contract revisions as applicable.
- Project-Contract link mutation increments both source revisions.
- Every allocation, Actual, state, or classification change increments every affected
  open Exercise revision.
- Attachment upload/detach increments its live owner's revision but not Exercise
  revision because it changes no economic value or state.
- Retry and no-op create no revision or additional Timeline event.

## Global lock order

```text
Company
-> affected Exercises ascending ID
-> affected Projects ascending ID
-> affected Contracts ascending ID
-> renewal configurations/lifecycle facts/conditions/classifications by stable key
-> affected Expenses ascending ID
-> Lines ascending ID
-> Supplier/Cost Center references ascending ID
-> Project-Contract links ascending ID
-> Attachments ascending ID
```

All cross-Exercise changes and each Contract's due-renewal batch are one database
transaction. The scheduled command processes separate Contracts independently so one
invalid Contract does not create a cross-Contract partial transaction.
