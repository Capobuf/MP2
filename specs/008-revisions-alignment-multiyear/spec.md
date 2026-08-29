# Feature Specification: Revisions, Realignment, and Multi-Year Impact

**Feature Branch**: `main`

**Created**: 2026-08-23

**Status**: Implemented

**Roadmap ID**: S7

**Input**: Continue canonical delivery with roadmap slice S7 after verified initial
Proposal and Budget v1: allow Budget Revisions while an Exercise is Open, resolve
Proposal source-wide misalignment and newly discovered sources, discard Drafts
without rolling back reality, and apply supported future multi-Exercise changes
atomically without rewriting Closed Exercises.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prepare a Budget Revision (Priority: P1)

As a proposal manager, I can start the single active Proposal for an Open Exercise
that already has an approved Budget, so I can prepare a new version from current live
reality while retaining the previous approved version only as a comparison reference.

**Why this priority**: An Open Exercise must always have a corrective path that
preserves every prior approved version instead of editing it.

**Independent Test**: Approve Budget v1, change supported live plan facts, start a
Revision, and verify that its baseline equals current live reality, the prior Budget
is unchanged, Actuals remain read-only, and concurrent or unauthorized creation is
rejected.

**Acceptance Scenarios**:

1. **Given** an Open Exercise with at least one approved Budget and no active Draft,
   **When** an authorized user starts a Proposal, **Then** its purpose is Revision,
   its baseline is current live reality, and the latest Budget is shown only as the
   comparison reference.
2. **Given** multiple prior Budget versions, **When** the Revision is initialized,
   **Then** every version remains immutable and the next successful approval will use
   the next unique sequential version.
3. **Given** live Actuals in the Exercise, **When** the Revision is prepared, **Then**
   they remain visible and read-only and never become editable plan actions or Budget
   baseline values.
4. **Given** a Closed Exercise, another active Draft, another company, or a user
   without the required company capability, **When** Revision creation is attempted,
   **Then** it is rejected without any partial Proposal, item, or event.

---

### User Story 2 - Resolve a changed Proposal source (Priority: P1)

As a proposal manager, I can realign an entire stale source by reloading reality,
keeping and reapplying its proposal actions, or reviewing those actions manually, so
approval never merges individual fields or silently ignores concurrent live changes.

**Why this priority**: A Draft can safely survive live operational changes only when
the whole source is revalidated against one explicit new baseline.

**Independent Test**: Change every canonical source-invalidating fact in turn, then
exercise each of the three realignment choices and verify the resulting baseline,
actions, status, impact, audit, and approval readiness.

**Acceptance Scenarios**:

1. **Given** a source changed in any canonical invalidating dimension after the
   Proposal baseline, **When** readiness is reviewed, **Then** the entire source is
   `Da riallineare` and approval is blocked without field-level merge.
2. **Given** a `Da riallineare` item, **When** the user chooses `Ricarica realtà`,
   **Then** all proposed actions for that source are removed and current live reality
   becomes the new aligned baseline.
3. **Given** a `Da riallineare` item whose actions remain valid, **When** the user
   chooses `Mantieni proposta` with the required reason, **Then** current reality
   becomes the new baseline, all typed plan actions are reapplied, every affected
   Exercise is recalculated, and the item becomes aligned.
4. **Given** a `Da riallineare` item whose actions are no longer valid, **When** the
   user chooses `Mantieni proposta`, **Then** the item remains blocked with an exact
   canonical inconsistency and no live or Proposal partial effect is persisted.
5. **Given** a `Da riallineare` item, **When** the user chooses `Rivedi manualmente`,
   **Then** actions may be changed or removed and the new result must be explicitly
   confirmed against current reality before the item becomes aligned.
6. **Given** any accepted realignment choice, **When** it completes, **Then** author,
   date, previous baseline, new baseline, choice, reason when required, and annual
   impact are recorded once.

---

### User Story 3 - Acknowledge newly included sources and resolve inconsistencies (Priority: P1)

As a proposal manager, I can review sources that became relevant after Proposal
initialization and correct only the canonically enumerated inconsistencies, so every
approval candidate is complete and deterministically valid.

**Why this priority**: New live reality and invalid planned combinations must be
resolved explicitly before any Budget version can be approved.

**Independent Test**: Add a newly qualifying source, create every closed canonical
inconsistency representable by the currently supported actions, and verify the full
closed reason vocabulary, acknowledgement choices, exact blocks, recovery, and zero
undefined generic inconsistency states. Reasons tied to S8 operations are defined but
are not made reachable by introducing those operations early.

**Acceptance Scenarios**:

1. **Given** a new live source that now satisfies automatic inclusion, **When** the
   Draft is reviewed, **Then** it enters as `Da prendere in visione` and blocks
   approval.
2. **Given** a `Da prendere in visione` source, **When** the user keeps it in the
   baseline, **Then** it becomes aligned without creating an economic modification.
3. **Given** a `Da prendere in visione` source, **When** the user proposes a supported
   plan change or excludes only plan that the domain permits excluding, **Then** the
   resulting typed actions are validated while any Actual remains untouched.
4. **Given** an item with one or more currently representable conditions from the
   canonical closed inconsistency list, **When** readiness is reviewed, **Then** each
   exact reason is shown and approval remains blocked until all reasons are resolved;
   predicates belonging to S8 actions remain unavailable until those actions exist.
5. **Given** a requested condition outside that closed list, **When** it is evaluated,
   **Then** it is not invented as a new generic inconsistency; it must be represented
   by an existing rule or treated as a domain gap before implementation.

---

### User Story 4 - Apply supported multi-Exercise decisions atomically (Priority: P1)

As a proposal manager or operational editor, I can review and confirm the complete
impact of a supported change across every affected Open Exercise, while Closed
Exercises remain unchanged and any historical divergence is explicitly recorded.

**Why this priority**: Contract conditions, lifecycle changes, future Project
transitions, annual classifications, and supported Expense moves can affect several
years and must never leave a partial economic state.

**Independent Test**: Prepare representative supported changes affecting two Open
Exercises and one Closed Exercise, inject a stale revision and a persistence failure,
and verify the impact preview, all-or-nothing result, Proposal invalidation, unchanged
Budgets, and historical-divergence evidence.

**Acceptance Scenarios**:

1. **Given** a supported operation that would change several Open Exercises, **When**
   its impact is reviewed, **Then** every affected Exercise and source, prior/new
   allocation, reclassified Actual where applicable, prior/new state, unchanged
   Budget, affected Draft, warning, and block is shown before confirmation.
2. **Given** a confirmed valid impact, **When** the operation is applied, **Then** all
   affected Exercises and sources are locked, revalidated, and changed in one logical
   operation with one annualized Timeline explanation.
3. **Given** any affected revision or source set changed after preview, or any
   application step fails, **When** the operation is attempted, **Then** no partial
   change or event is retained.
4. **Given** another Draft uses an affected source, **When** the operation succeeds,
   **Then** that Draft does not block the live operation and its entire source becomes
   `Da riallineare`.
5. **Given** an affected Exercise already has an approved Budget, **When** live
   reality changes, **Then** the Budget remains immutable and current reality exposes
   the difference.
6. **Given** the operation would have produced a different value in a Closed
   Exercise, **When** the open-year effects are confirmed, **Then** the Closed
   Exercise and every historical snapshot remain unchanged, the divergence is shown
   and recorded, and only Open Exercises are changed.

---

### User Story 5 - Approve, retry, or discard the Revision safely (Priority: P1)

As an authorized user, I can approve a fully aligned Revision into the next immutable
Budget version, retry it safely, or discard a Draft without undoing live reality.

**Why this priority**: Revision completion must preserve history and be safe under
concurrency, failure, and retry; abandoning a Draft must not become a rollback.

**Independent Test**: Approve and retry a Revision, force failures across multiple
Open Exercises, then discard a separate Draft after live changes and verify version
lineage, atomicity, idempotency, immutability, and unchanged reality.

**Acceptance Scenarios**:

1. **Given** a fully aligned Revision and an authorized approver, **When** approval
   succeeds, **Then** all plan actions are applied atomically, version `vN+1` is
   materialized with its required reason and predecessor, prior versions remain
   unchanged, and the Proposal becomes immutable and Approved.
2. **Given** any stale source, unseen source, inconsistency, missing required value,
   unsupported action, Closed affected Exercise, or authorization failure, **When**
   approval is attempted, **Then** it is rejected before any effect is persisted.
3. **Given** the same successful approval operation is retried, **When** it runs
   again, **Then** it returns the same result without creating another Budget,
   action, live object, or event.
4. **Given** a Draft Proposal, **When** an authorized proposal manager discards it
   with a reason, **Then** its content is preserved immutably as `Scartata`, no live
   reality or prior Budget is changed, and one discard event is recorded.
5. **Given** an Approved or Discarded Proposal, **When** any further edit,
   realignment, acknowledgement, approval, or discard is attempted, **Then** it is
   rejected without effects.

### Edge Cases

- A source changes after realignment but before approval confirmation.
- A new qualifying source is created after readiness review but before approval.
- A source is archived or restored while its Proposal item is open.
- `Mantieni proposta` can reapply some but not all actions after the live change.
- One live change invalidates Drafts for the same source in several Open Exercises.
- An operation affects Open Exercises before and after a Closed Exercise.
- A Contract change would have changed a historical Estimate in a Closed Exercise.
- A Closed-year divergence is discovered but the user does not declare the historical
  value erroneous.
- An autonomous Expense copied from a Closed Exercise remains unchanged while the
  new planned Expense is created only in the Open destination Exercise.
- The same operation identity is retried after the client loses a successful response.
- A Revision is approved after several prior versions and concurrent attempts race
  for the same next version number.
- A copied autonomous Expense is approved into another Open Exercise after the
  original changes or is archived.
- A user attempts to use Revision realignment to change, move, or reclassify Actuals.
- A requested multi-year action is Carryover or Reprogramming, which belongs to S8.
- A requested structured source-replacement relation is rejected under canonical §32.

## Requirements *(mandatory)*

### Functional Requirements

- **S7-FR-001**: Every Revision, item, action, realignment, acknowledgement, impact,
  discard, Budget version, and Timeline/audit fact MUST belong to exactly one company
  and MUST NOT be read or mutated through another company.
- **S7-FR-002**: Reading MUST require `visualizza`; creating, changing, realigning,
  acknowledging, and discarding a Proposal MUST require `gestisce_proposte`;
  approval MUST require `approva_budget`; live operational changes MUST retain their
  existing capability requirements, all for the exact company and rechecked at
  submission.
- **S7-FR-003**: An authorized user MUST always be able to start a Revision while the
  main Exercise is Open and at least one approved Budget exists; no company setting
  may disable Revisions.
- **S7-FR-004**: At most one active Draft Proposal MAY exist per company and Exercise,
  including under concurrent creation attempts.
- **S7-FR-005**: A Revision MUST initialize from current live reality using the
  canonical automatic-inclusion predicates; the latest approved Budget MUST be only
  a comparison reference and MUST NOT be cloned or reapplied.
- **S7-FR-006**: A Revision MUST include all inherited and confirmed plan values in
  the next Budget snapshot, including decision-bearing sources with zero approved
  allocation, while Actuals remain read-only and excluded from the baseline.
- **S7-FR-007**: Revision approval MUST create the next unique monotonically
  increasing version, link it to the previous version, require a reason, and leave
  every earlier version immutable and readable.
- **S7-FR-008**: Any change to Estimates, Actuals, owner or Exercise, report-visible
  Supplier, annual Cost Center, state or transitions, Carryover, Contract conditions
  or lifecycle facts, reversal/restoration/archive state, or an associated
  informative relation MUST invalidate the baseline of the entire affected source.
- **S7-FR-009**: Source invalidation MUST produce `Da riallineare`; the system MUST
  NOT perform automatic field-level merge or treat an unchanged subset of fields as
  independently aligned.
- **S7-FR-010**: `Ricarica realtà` MUST remove all Proposal actions for the source,
  capture current live reality as the new baseline, recalculate the result, and align
  the item without changing live reality.
- **S7-FR-011**: `Mantieni proposta` MUST capture current live reality as the new
  baseline, reapply every typed plan action, recalculate all affected Exercises,
  require a reason, and remain blocked without partial Proposal updates if any action
  is no longer valid.
- **S7-FR-012**: `Rivedi manualmente` MUST let the user change or remove typed plan
  actions and MUST require explicit confirmation of the newly calculated result
  against current reality before alignment.
- **S7-FR-013**: Every successful realignment MUST record author, timestamp, previous
  baseline, new baseline, selected choice, required reason, and impact by Exercise
  exactly once.
- **S7-FR-014**: A newly qualifying source MUST enter an existing Draft as `Da
  prendere in visione`, must block approval, and MUST NOT be treated as absent or
  silently accepted.
- **S7-FR-015**: A user reviewing `Da prendere in visione` MUST be able to keep the
  source in the baseline, prepare a supported plan change, or exclude only plan that
  the canonical domain permits excluding; acknowledgement alone MUST NOT create an
  economic change or alter Actuals.
- **S7-FR-016**: Proposal inconsistency reasons MUST be limited to the complete list
  in canonical §12.14 and MUST expose the exact predicate that is false; no undefined
  generic inconsistency category may be added.
- **S7-FR-017**: Resolving an inconsistency MUST modify or remove the responsible
  typed action or required data and re-evaluate the whole item; it MUST NOT suppress
  a valid block or mutate live reality before approval.
- **S7-FR-018**: Proposal creation, resumption, readiness review, realignment,
  acknowledgement, manual review, approval, and discard MUST recalculate canonical
  item states from current reality rather than trust a stale displayed status.
- **S7-FR-019**: Before any supported operation that can affect multiple Exercises,
  the system MUST enumerate all affected Open Exercises and sources and present an
  impact plan containing previous/new allocation, reclassified Actual where
  applicable, previous/new state, immutable Budgets, Drafts made stale, warnings,
  and blocks.
- **S7-FR-020**: A confirmed multi-Exercise operation MUST lock and revalidate every
  affected Exercise, source revision, source set, and concurrent Draft, then apply
  all effects and annualized Timeline evidence in one logical transaction.
- **S7-FR-021**: A stale revision, changed source set, validation failure, or
  persistence failure in any affected Exercise MUST leave zero partial live,
  Proposal, Budget, or Timeline effects.
- **S7-FR-022**: Another Draft using an affected source MUST NOT block an ordinary
  live operation; after success, the whole source in that Draft MUST become `Da
  riallineare`.
- **S7-FR-023**: Approved Budgets in affected Exercises MUST remain immutable while
  current reality changes and the resulting difference remains observable.
- **S7-FR-024**: Closed Exercises MUST NOT be recalculated or rewritten by a current
  multi-Exercise operation; the system MUST show and always record the historical
  divergence and apply only the effects on Open Exercises. Creating a formal
  historical-error annotation remains in S10 and MUST NOT be partially introduced
  by this slice.
- **S7-FR-025**: Supported multi-Exercise behavior in S7 MUST cover the already
  canonical operations available from current Expenses, Projects, Contracts,
  classifications, transitions, conditions, renewals, cessations, reactivations, and
  Revision approval; no new economic operation may be inferred.
- **S7-FR-026**: Canonical copying of an autonomous Expense between Exercises MUST
  create a new identity, preserve `CopiedFromOriginKey`, copy no Actuals, and apply
  only to the Open destination Exercise after the source identity and facts are
  revalidated; reading from a Closed source Exercise MUST NOT alter that Exercise.
- **S7-FR-027**: A copied Expense MUST NOT share mutable state with its origin, be
  matched to it by similarity, or alter the origin when the copy later changes.
- **S7-FR-028**: Revision approval MUST repeat source enumeration, impact
  calculation, locking, revision and membership checks, and action validation after
  confirmation before applying any effect.
- **S7-FR-029**: Successful Revision approval MUST atomically apply all supported
  plan actions to Open Exercises, create and resolve supported new live identities,
  materialize exactly one autonomous `vN+1` Budget snapshot, record required events,
  and mark the Proposal Approved.
- **S7-FR-030**: Revision approval and every mutating realignment, acknowledgement,
  discard, copy, and multi-Exercise operation MUST be idempotent for the same
  operation identity and MUST NOT duplicate domain effects or events on retry.
- **S7-FR-031**: A Proposal MUST NOT be approved while any item is `Da prendere in
  visione`, `Da riallineare`, or `Incoerente`, mandatory data is absent, an action is
  not fully representable, or any affected Exercise is Closed.
- **S7-FR-032**: Discarding a Draft MUST require a reason, preserve the Proposal and
  its content immutably as `Scartata`, record one event, and MUST NOT revert or change
  live reality, an approved Budget, or an ordinary operation performed while the
  Draft existed.
- **S7-FR-033**: Approved and Discarded Proposals MUST reject every later content,
  status-resolution, approval, or discard mutation.
- **S7-FR-034**: Timeline and audit MUST explain each Revision, source invalidation,
  new-source acknowledgement, realignment choice, inconsistency resolution,
  multi-Exercise impact, historical divergence, approval, failure, and discard with
  actor, company, operation identity, effective facts, before/after values, affected
  Exercises, reason when required, and Proposal/Budget references.
- **S7-FR-035**: S7 MUST NOT implement Carryover or Reprogramming execution,
  Closing, late corrections, complete comparison/report/export semantics, Forecast,
  fuzzy matching, parallel Proposal alternatives, arbitrary-time reconstruction, or
  automatic rollback of live reality when a Draft is discarded.
- **S7-FR-036**: Structured source-replacement actions MUST remain unavailable under
  canonical §32; S7 MUST NOT introduce archive, movement, or historical-endpoint
  behavior for such a relation.

### Key Entities

- **Revision Proposal**: The single active Draft for an Open Exercise that already
  has an approved Budget, initialized from current live reality and linked to the
  latest approved version for comparison.
- **Source Baseline**: The whole-source revision and canonical source facts against
  which a Proposal Item and its typed actions were last aligned.
- **Realignment Record**: The immutable evidence of `Ricarica realtà`, `Mantieni
  proposta`, or `Rivedi manualmente`, including old/new baseline, actor, reason, and
  annual impact.
- **New-Source Acknowledgement**: The explicit decision applied to a source that
  became automatically includable after Proposal initialization.
- **Impact Plan**: The pre-confirmation set of affected Open and Closed Exercises,
  sources, before/after values and states, unchanged Budgets, stale Drafts, warnings,
  blocks, and historical divergences.
- **Budget Version**: The immutable materialized `vN+1` reference produced by one
  successful Revision approval and linked to its immutable predecessor.
- **Historical Divergence**: Append-only evidence that a current operation would
  have produced a different value in a Closed Exercise that remains unchanged.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tested Open Exercises with an approved Budget, an authorized
  user can start a Revision unless the single-Draft rule is already occupied; no
  setting can disable that path.
- **SC-002**: For every canonical source-invalidating change tested, 100% of the
  affected source is marked `Da riallineare` and 0 fields are merged automatically.
- **SC-003**: Each of the three realignment choices produces one complete,
  explainable outcome, and every invalid reapplication leaves 0 partial Proposal or
  live effects.
- **SC-004**: Every newly qualifying source tested appears as `Da prendere in
  visione` before approval and requires exactly one explicit supported decision.
- **SC-005**: Every tested inconsistency maps to one or more reasons from the
  canonical closed list, with 0 generic or invented inconsistency reasons.
- **SC-006**: Users can inspect every affected Exercise, source, allocation, state,
  immutable Budget, stale Draft, warning, block, and historical divergence before
  confirming a multi-Exercise operation.
- **SC-007**: At every injected stale-revision, validation, or persistence failure,
  the multi-Exercise operation and Revision approval retain 0 partial economic,
  Proposal, Budget, or Timeline effects.
- **SC-008**: Repeating any successful idempotent S7 operation produces exactly one
  domain result and one required event set.
- **SC-009**: After Revision approval, exactly one next sequential Budget version is
  readable and 100% of every earlier Budget field and total remains unchanged.
- **SC-010**: Discarding a Draft changes 0 live economic values, states,
  classifications, Actuals, prior Budgets, or ordinary-operation events.
- **SC-011**: For tested changes spanning Open and Closed Exercises, 100% of Closed
  Exercise values and snapshots remain unchanged while every required divergence is
  visible and recorded.
- **SC-012**: Focused automated checks, the complete test suite, static analysis,
  formatting, application boot, and an authenticated Revision-to-`vN+1`
  demonstration all pass before S7 is marked verified.

## Assumptions

- S6 remains the verified authority for isolated Proposals, typed plan actions,
  readiness detection, atomic initial approval, immutable Budget v1, company access,
  Timeline storage, and approval evidence.
- S3 through S5 remain the verified authority for live Expenses, Projects,
  Contracts, classifications, deterministic state-at-date calculations, and the
  existing impact-plan and transaction boundaries reused by S7.
- S7 extends the existing Proposal and Budget model; it does not introduce a second
  planning workspace, a generic merge engine, or a generic multi-year rules engine.
- Carryover and Reprogramming are intentionally deferred to S8 even when a Proposal
  displays existing values needed to explain an impact.
- Canonical §32 removes structured source replacement; endpoint movement follows only
  the ordinary Expense rules and introduces no relation-specific behavior.
- All requested behavior is directly specified by the canonical domain or composes
  already defined primitives; no new category-E case is introduced by this slice.
