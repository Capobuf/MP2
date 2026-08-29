# Feature Specification: Initial Proposal and Budget v1

**Feature Branch**: `main`

**Created**: 2026-08-21

**Status**: Draft

**Roadmap ID**: S6

**Input**: Continue canonical delivery with roadmap slice S6 after verified Expenses,
Projects, and Contracts: prepare one isolated initial Proposal for an open Exercise,
approve it atomically, and preserve an immutable Budget v1 snapshot.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Start the initial Proposal (Priority: P1)

As a proposal manager, I can start the single active Proposal for an open Exercise
that has no approved Budget, so every canonically relevant live source is presented
without changing operational reality.

**Why this priority**: The isolated and deterministic Proposal baseline is required
before any planning decision can be prepared or approved.

**Independent Test**: Start a Proposal for an open Exercise containing autonomous
Expenses, Projects, and Contracts in different states and verify exact automatic
inclusion, company isolation, one-active-draft cardinality, and unchanged live data.

**Acceptance Scenarios**:

1. **Given** an authorized user and an open Exercise without an approved Budget,
   **When** the initial Proposal is created, **Then** exactly the sources satisfying
   the canonical automatic-inclusion predicates appear as Proposal Items with their
   live plan baseline, stable origin, and source revision.
2. **Given** Actual Lines already present in the Exercise, **When** the Proposal is
   inspected, **Then** those Actuals are visible as read-only reality and are not
   copied into editable Proposal actions.
3. **Given** an archived source that satisfies automatic inclusion, **When** the
   Proposal is initialized, **Then** it remains visible in read-only form and is not
   treated as absent.
4. **Given** another active Draft for the same company and Exercise, a closed
   Exercise, an Exercise of another company, or an existing Budget v1, **When** the
   user attempts to start an initial Proposal, **Then** creation is rejected without
   partial Proposal Items or audit events.
5. **Given** sources in a preceding Exercise, **When** a Proposal is initialized,
   **Then** autonomous Expenses are not copied automatically and no Actual is copied.

---

### User Story 2 - Prepare plan-only actions (Priority: P1)

As a proposal manager, I can prepare typed planning actions on Expenses, Projects,
and Contracts, including new related objects, while operational objects and Actuals
remain untouched until approval.

**Why this priority**: The Proposal has value only if it can express the initial
agreed plan without becoming a second operational reality.

**Independent Test**: Prepare changes for an existing source, a copied autonomous
Expense, a new Project with a new child Expense, and a new Contract; verify resulting
planned values and that no live object, Estimate, Actual, state, or classification
changes before approval.

**Acceptance Scenarios**:

1. **Given** an aligned Proposal Item for an Expense, **When** Estimate-only actions
   are added, changed, annulled, restored, or zeroed, **Then** the Proposal shows the
   resulting allocation while the live Expense and all Actuals remain unchanged.
2. **Given** an autonomous Expense from another open Exercise, **When** it is copied
   into the Proposal, **Then** the Proposal Item records its source lineage, excludes
   Actuals, and will receive a new live identity only if approved.
3. **Given** an Expense with Actuals, **When** an action would move it, change its
   Exercise, Supplier, container, Cost Center, reverse state, or Actual Lines,
   **Then** the action is rejected; Estimate-only planning remains available.
4. **Given** a Project without Actuals in the Exercise, **When** its Estimates,
   annual Cost Center, or planned lifecycle actions are prepared, **Then** the
   Proposal records typed actions and recalculates the planned result without
   changing the live Project.
5. **Given** a Contract without Actuals in the Exercise, **When** future conditions,
   lifecycle, renewal, deadline, or annual classification actions are prepared,
   **Then** canonical cycle, state, Supplier, and no-prorata rules are applied to the
   planned result without changing the live Contract.
6. **Given** a new Project and a new Expense in the same Proposal, **When** the
   Expense is assigned to that Project, **Then** the relationship uses their Proposal
   identities and is resolved to new stable live identities only at approval.
7. **Given** a previous autonomous Expense selected for canonical copy, **When** the
   copy is prepared, **Then** it has a new Proposal identity, preserves
   `CopiedFromOriginKey`, does not share mutable state, and contains no Actuals.

---

### User Story 3 - Review approval readiness (Priority: P1)

As a proposal manager or approver, I can see whether the Draft is still aligned and
approvable, with every block explained before an approval is attempted.

**Why this priority**: Approval must never apply stale, incomplete, invalid, or
partially representable decisions.

**Independent Test**: Review an aligned Draft, then independently change a source,
add an automatically included source, archive a referenced object, and close an
affected Exercise; verify deterministic statuses and approval blocks.

**Acceptance Scenarios**:

1. **Given** an unchanged complete Draft, **When** readiness is reviewed, **Then**
   every item is Aligned and all affected open Exercises, resulting allocations,
   state changes, unchanged Budgets, warnings, and blocks are shown.
2. **Given** a referenced source changed after its Proposal baseline, **When** the
   Draft is reviewed or approval begins, **Then** the whole source is marked `Da
   riallineare` and approval is blocked without a field-level merge.
3. **Given** a newly qualifying live source after initialization, **When** readiness
   is recalculated, **Then** it appears as `Da prendere in visione` and approval is
   blocked.
4. **Given** missing mandatory data, an invalid relation, an action touching Actuals,
   an archived object without explicit restoration, an incompatible state action,
   or any affected closed Exercise, **When** readiness is evaluated, **Then** the
   relevant item is Inconsistent and the exact canonical reason is shown.
5. **Given** an action whose behavior belongs to a later roadmap slice and cannot yet
   be represented completely, **When** it is requested, **Then** it is rejected as
   non-representable rather than partially stored or silently approximated.

---

### User Story 4 - Approve atomically and create Budget v1 (Priority: P1)

As an authorized budget approver, I can confirm a fully aligned initial Proposal so
all approved plan actions become live together and one immutable Budget v1 records
the agreement.

**Why this priority**: Atomic approval is the boundary between an isolated planning
workspace and authoritative operational plan.

**Independent Test**: Approve a Proposal containing existing and new sources across
all three first-level source types, then force stale revisions, invalid actions,
authorization failure, and retry to verify all-or-nothing behavior and idempotency.

**Acceptance Scenarios**:

1. **Given** an approvable Draft and an authorized approver, **When** approval is
   confirmed, **Then** all sources and Exercises are re-enumerated, locked, and
   revalidated before any plan action is applied.
2. **Given** successful revalidation, **When** approval completes, **Then** all plan
   actions are applied, new stable identities and Proposal references are resolved,
   Budget v1 is materialized, Timeline/audit is recorded, and the Proposal becomes
   immutable and Approved in one logical operation.
3. **Given** any failure during approval, **When** the operation ends, **Then** no
   live action, new object, snapshot row, status transition, attachment evidence, or
   Timeline event is partially persisted.
4. **Given** the same approval operation is retried, **When** it has already
   succeeded, **Then** it returns the same result without creating another Budget,
   version, live object, or audit event.
5. **Given** a user without `approva_budget`, a Proposal of another company, a
   non-Draft Proposal, a stale Proposal, or a non-open affected Exercise, **When**
   approval is attempted, **Then** it is rejected without effects.

---

### User Story 5 - Read the immutable approved reference (Priority: P2)

As an authorized viewer, I can inspect Budget v1 and its evidence after live objects
change or are archived, so the original agreement remains independently readable.

**Why this priority**: Budget v1 is the immutable reference against which later
reality and revisions will be compared.

**Independent Test**: Approve Budget v1, then rename, reclassify, change, and archive
its live sources; verify that every materialized header, source row, detail, action,
relation, and retained approval attachment remains unchanged and readable.

**Acceptance Scenarios**:

1. **Given** an approved initial Proposal, **When** Budget v1 is viewed, **Then** its
   header identifies company, Exercise, version, approval, author, purpose, Proposal,
   total allocation, and all open Exercises changed by approval.
2. **Given** a Budget row for an autonomous Expense, Project, or Contract, **When**
   its detail is opened, **Then** all canonical materialized labels,
   classifications, approved Estimates, states, conditions, deadlines, actions,
   lineage, and relations applicable to that source type are readable.
3. **Given** live source data later changes or is archived, **When** Budget v1 is
   read again, **Then** its materialized content and total remain byte-for-byte
   unchanged and do not depend on current source selectability.
4. **Given** Actuals exist at approval time, **When** Budget v1 is inspected, **Then**
   Actuals, operational variance, residual, saving, overspend, and late corrections
   are absent from the approved baseline.
5. **Given** external approval evidence or attachments, **When** a live attachment
   is later detached, **Then** evidence retained by the approval remains immutable or
   versioned and readable by authorized users of the same company.

### Edge Cases

- A source has zero approved allocation but a state, condition, deadline, or other
  explicit decision that requires it to remain in Budget v1.
- Positive and negative Actual Lines net to zero while Actual presence remains true
  and read-only.
- A source is archived between Proposal initialization and approval.
- An automatically included source is created between readiness review and approval.
- A condition, lifecycle fact, classification, Expense Line, or Exercise revision
  changes after the displayed impact and before confirmation.
- A new Expense refers to a new Project that has not yet received a live identity.
- A copied Expense's original source is later changed or archived.
- Approval is retried after the client loses the successful response.
- Snapshot materialization fails after live actions have begun.
- An external approval has no attachment but identifies an optional external subject
  or venue.
- A user attempts to edit or physically delete Budget v1 or any materialized row.
- A Proposal attempts to create a manual Estimate inside a Contract, apply prorata,
  change an economically used Contract Supplier, or move Actuals.

## Requirements *(mandatory)*

### Functional Requirements

- **S6-FR-001**: Every Proposal, Proposal Item, Budget snapshot, materialized row,
  approval evidence, attachment reference, and Timeline/audit event MUST belong to
  exactly one company and MUST NOT be read or mutated through another company.
- **S6-FR-002**: Reading Proposals and Budgets MUST require `visualizza`; creating,
  changing, and reviewing a Proposal MUST require `gestisce_proposte`; approval MUST
  require `approva_budget`, all for the exact company and rechecked at submission.
- **S6-FR-003**: S6 MUST support only an initial Proposal whose main Exercise is Open
  and has no approved Budget; later Budget revisions belong to S7.
- **S6-FR-004**: At most one active Draft Proposal MAY exist per company and Exercise,
  including under concurrent creation attempts.
- **S6-FR-005**: Proposal states MUST be exactly `Bozza`, `Approvata`, and `Scartata`,
  and an Approvata or Scartata Proposal MUST be immutable.
- **S6-FR-006**: A Draft MUST be persistent and resumable without applying temporary
  changes to live operational objects.
- **S6-FR-007**: Initialization MUST include exactly every source satisfying
  `InclusaAutomaticamenteInProposta` for the Exercise, including qualifying archived
  sources in read-only form.
- **S6-FR-008**: Initialization MUST NOT automatically copy autonomous Expenses from
  another Exercise, Actual Lines, a previous Budget, or provisional carryover at its
  maximum available value.
- **S6-FR-009**: Existing Actuals MUST be presented as read-only reality and MUST NOT
  be stored as editable planning actions or Budget baseline values.
- **S6-FR-010**: Every Proposal Item MUST identify the main Exercise, source type,
  optional live `OriginKey`, `CopiedFromOriginKey` when applicable, source baseline
  revision, typed plan actions, resulting plan values, canonical readiness state,
  last alignment author/date when present, and proposed supported relations.
- **S6-FR-011**: Proposal actions MUST be typed domain actions and MUST NOT be stored
  or interpreted as a generic data patch.
- **S6-FR-012**: A new proposed object MUST exist only as a Proposal Item until
  approval and MUST NOT reserve or expose a live identity beforehand.
- **S6-FR-013**: A Proposal Item MAY refer to another Proposal Item in the same Draft;
  all such references MUST be same-company, type-compatible, acyclic where required,
  and resolvable atomically at approval.
- **S6-FR-014**: Copying a prior autonomous Expense MUST create a distinct Proposal
  Item, preserve `CopiedFromOriginKey`, omit Actuals, and create a new stable live
  identity only at approval.
- **S6-FR-015**: Expense planning MUST support new planned Expenses and Estimate-Line
  creation, change, annulment, restoration, and zeroing without editing Actuals.
- **S6-FR-016**: For an Expense without Actuals, actions MAY move it only between
  autonomous and Project ownership or between Projects, change Exercise only when
  it is autonomous and both Exercises are Open, change Supplier only when it is
  autonomous or Project-owned, change direct Cost Center only when it is autonomous,
  and reverse or restore it only when every canonical precondition is satisfied.
- **S6-FR-017**: For an Expense with Actuals, Proposal actions MUST be limited to its
  Estimates; repositioning residual plan MUST reduce or zero original Estimates and
  create a separate planned Expense without moving or matching the Actuals.
- **S6-FR-018**: Project planning MUST support a new Planned Project, continuation,
  supported Estimate changes, supported future lifecycle actions, and an annual Cost
  Center change only when no Actual would be reclassified.
- **S6-FR-019**: Contract planning MUST support a new Planned Contract, continuation,
  supported future conditions, economic changes, lifecycle, renewal, deadline, and
  annual Cost Center actions while preserving all canonical Contract rules.
- **S6-FR-020**: Proposal actions MUST NOT change the Supplier of an economically used
  Contract, create a manual Contract Estimate, overlap conditions, infer prorata, or
  silently choose a non-canonical effective date.
- **S6-FR-021**: S6 relation actions MUST support the already deterministic
  Project–Contract `Collegato a` relation, including links between new Proposal
  Items, without transferring economic values or states.
- **S6-FR-022**: Structured source-replacement actions MUST remain unavailable under
  canonical §32.
- **S6-FR-023**: Carryover, Reprogramming, and their cross-year plan actions MUST NOT
  be partially implemented in S6; their complete behavior remains in S8.
- **S6-FR-024**: Each item state MUST be exactly `Allineato`, `Da prendere in
  visione`, `Da riallineare`, or `Incoerente` according to the canonical closed
  predicates.
- **S6-FR-025**: Any canonical source change after the item's baseline MUST invalidate
  the entire source and mark it `Da riallineare`; field-level automatic merge MUST
  NOT be performed.
- **S6-FR-026**: A newly qualifying source after Proposal initialization MUST enter
  as `Da prendere in visione` and MUST block approval.
- **S6-FR-027**: S6 MUST detect and expose `Da prendere in visione`, `Da riallineare`,
  and `Incoerente` states, but the user workflows that resolve them belong to S7.
- **S6-FR-028**: Readiness MUST calculate a pre-save impact covering every affected
  Exercise and source, previous/new allocation, previous/new state, Budgets that
  remain unchanged, other Proposals made stale, warnings, and blocks.
- **S6-FR-029**: A Proposal MUST NOT be approvable while any item is `Da prendere in
  visione`, `Da riallineare`, or `Incoerente`, mandatory data is absent, an action is
  not completely representable, or an affected Exercise is not Open.
- **S6-FR-030**: Every approval block MUST state the canonical domain reason and MUST
  NOT be represented by an undefined generic inconsistency category.
- **S6-FR-031**: Approval MUST re-enumerate included sources, recalculate affected
  Exercises, lock and revalidate Exercise/source revisions and membership, and
  revalidate actions, states, conditions, and relations after confirmation.
- **S6-FR-032**: Successful approval MUST atomically apply every plan action, create
  new live objects, resolve Proposal references, materialize Budget v1, record all
  required Timeline/audit events, and mark the Proposal Approved.
- **S6-FR-033**: Approval MUST be all-or-nothing across every affected open Exercise;
  any validation, concurrency, persistence, or materialization failure MUST leave no
  partial live, snapshot, evidence, status, or Timeline effect.
- **S6-FR-034**: Approval MUST be idempotent for the same operation identity and MUST
  produce at most one Budget v1, one set of live objects, and one required set of
  events.
- **S6-FR-035**: The first successful approval for an Exercise MUST create version
  `v1`; version identifiers MUST be unique and monotonically increasing within the
  Exercise, and v1 MUST never be overwritten, renumbered, or deleted.
- **S6-FR-036**: Budget v1 MUST be a materialized, autonomous, immutable snapshot that
  remains readable without resolving current live names, classifications, states,
  conditions, amounts, archive state, or selectability.
- **S6-FR-037**: The Budget header MUST materialize the canonical company, Exercise,
  version, approval timestamp/author, initial-purpose designation, optional external
  approval subject or venue, Proposal, prior-version reference when applicable,
  total approved allocation, and open Exercises changed by approval.
- **S6-FR-038**: Budget v1 MUST contain exactly all effective and revalidated Proposal
  Items, including decision-bearing items with zero approved allocation.
- **S6-FR-039**: Every first-level Budget row MUST materialize its stable origin and
  proposal lineage, label, optional summary, applicable Supplier, annual Cost Center
  or Unclassified value, approved Estimates, approved carryover value/state when
  canonically applicable, approved allocation, start/end state, approved transitions,
  applicable conditions and contract events, approved actions/reasons, supported
  relations, and approval-event references.
- **S6-FR-040**: Expense detail MUST materialize canonical identity, description,
  Exercise, Manual/System origin, owner, Supplier, active/reversed state, approved
  Estimate total, active Estimate-Line data and notes, and approved annul/restore
  actions without counting annulled Lines.
- **S6-FR-041**: Project detail MUST additionally materialize annual start/end state,
  approved transitions, deferral mode and values only when produced by a fully
  implemented canonical operation, Estimates, supported relations, and approved
  state-action reasons.
- **S6-FR-042**: Contract detail MUST additionally materialize annual start/end
  state, contractual start, next expiry, automatic-renewal facts, renewal duration,
  notice and cancellation deadline, approved conditions, cycles, Estimate
  attribution dates/composition, and approved cessation/reactivation facts.
- **S6-FR-043**: Budget baseline values MUST NOT contain Actuals, operational
  variance, residual, saving, final overspend, late corrections, Forecast, or a
  Closing-snapshot schema.
- **S6-FR-044**: Budget totals MUST be derivable from its own materialized rows and
  MUST prevent silent divergence between header totals, source totals, and details.
- **S6-FR-045**: Approval MUST record timestamp, author, optional external approval
  subject or venue, produced version, affected Exercises, applied-impact summary,
  and optional evidence attachments; electronic signatures, votes, escalation, and
  maker-checker separation MUST NOT be required.
- **S6-FR-046**: Approval evidence MUST remain immutable or versioned; detaching an
  attachment from a live object MUST NOT remove evidence retained by Budget v1.
- **S6-FR-047**: Proposal initialization, each plan action and status transition,
  failed approval with a non-sensitive reason, successful v1 approval, and every
  applied domain action MUST create the canonical typed append-only Timeline/audit
  evidence exactly once.
- **S6-FR-048**: Timeline and audit MUST explain actor, company, source, operation,
  effective dates, before/after values, allocation/Actual impact by Exercise, reason
  when required, Proposal/Budget references, attachments, and operation identity.
- **S6-FR-049**: The application MUST NOT physically delete Proposals, Proposal
  Items, Budget snapshots, snapshot rows, approval evidence, or Timeline/audit
  events through ordinary workflows.
- **S6-FR-050**: S6 MUST NOT implement later Budget revisions, realignment resolution,
  carryover/reprogramming execution, Closing snapshots, late corrections, complete
  reporting comparisons/exports, Forecast, fuzzy source matching, parallel Proposal
  alternatives, or arbitrary-time reconstruction.

### Key Entities

- **Proposal**: The company's persistent isolated planning workspace for one main
  open Exercise, with purpose, state, operation identity, creator, and timestamps.
- **Proposal Item**: One existing or proposed first-level source, its lineage and
  baseline revision, typed plan actions, resulting values, readiness state, and
  supported relations.
- **Proposal Action**: A typed, ordered planning decision whose canonical effects are
  validated but not applied to live reality before approval.
- **Budget Snapshot**: The immutable materialized Budget v1 header created by one
  successful initial approval.
- **Budget Source Row**: A materialized first-level source included in Budget v1,
  retaining labels, classification, approved values, states, decisions, and lineage.
- **Budget Source Detail**: Source-type-specific immutable materialization for an
  Expense, Project, or Contract.
- **Approval Evidence**: The immutable/versioned external approval facts and optional
  attachments retained by the approved snapshot.
- **Source/Exercise Revision**: A monotonic concurrency fact used to establish
  whether the Proposal baseline and included-source set are still current.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every tested mix of Expenses, Projects, and Contracts, Proposal
  initialization includes 100% of sources satisfying the canonical predicate and 0
  sources that do not satisfy it.
- **SC-002**: Before approval, 100% of tested Proposal edits leave live allocations,
  Actuals, ownership, classifications, states, and source identities unchanged.
- **SC-003**: Users can identify every affected Exercise, allocation change, state
  change, warning, and approval block from one readiness review before confirmation.
- **SC-004**: Every tested stale source or changed included-source set blocks approval
  before any live or historical effect is persisted.
- **SC-005**: Every injected failure point during approval leaves zero partial live
  actions, new objects, snapshot records, evidence links, status changes, or events.
- **SC-006**: Repeating a successful approval operation any number of times produces
  exactly one Budget v1 and one canonical set of resulting objects and events.
- **SC-007**: After arbitrary supported changes or archive operations on live
  sources, 100% of Budget v1 fields and totals remain unchanged and readable.
- **SC-008**: Automated checks reject every tested attempt to place an Actual,
  Forecast, residual, operational variance, late correction, or Closing-only field
  into the Budget baseline.
- **SC-009**: A company user without the exact required capability, and every user
  of another company, completes 0 unauthorized Proposal, approval, evidence, or
  Budget read/write operations in the authorization test matrix.
- **SC-010**: The complete automated suite, static analysis, formatting, dependency
  validation/audit, application boot check, and an authenticated Proposal-to-Budget
  v1 demonstration all pass before S6 is marked verified.

## Assumptions

- S3, S4, and S5 remain the verified authority for live Expenses, Projects,
  Contracts, classifications, state-at-date calculations, company permissions,
  Timeline storage, and attachment storage.
- S6 delivers the initial Budget only. The data model may preserve version lineage,
  but creating and resolving a Revision is S7 behavior.
- S6 detects stale/new/inconsistent Proposal sources because approval cannot be safe
  without doing so; the canonical user choices for resolving them are delivered in
  S7.
- Carryover and Reprogramming fields are materialized only when supplied by a fully
  implemented canonical operation; S6 does not introduce their S8 behavior early.
- `Collegato a` is the only supported relation action in S6. Structured source
  replacement is permanently absent under canonical §32.
- Application language, money, calendar, precision, and company-local time follow
  the already verified product-wide rules.
