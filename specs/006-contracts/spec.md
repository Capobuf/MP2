# Feature Specification: Contracts

**Feature Branch**: `006-contracts`

**Created**: 2026-08-20

**Status**: Draft

**Roadmap ID**: S5

**Input**: Continue development with roadmap slice S5, Contracts, after the verified
master-data, Exercise/Expense, and Project foundations.

## Clarifications

### Session 2026-08-20

- Q: Quando un comando sul Contratto produce più conseguenze di dominio, quanti
  eventi Timeline registra? → A: Tutti gli eventi tipizzati richiesti, una volta.
- Q: Come incide sugli anni chiusi il censimento tardivo di un Contratto già
  esistente? → A: Conserva le date reali e aggiorna soltanto gli Esercizi Aperti.
- Q: L'elaborazione dei rinnovi automatici può dipendere dall'apertura della pagina
  Scadenze? → A: No, deve essere indipendente dalla pagina.
- Q: Una Riga Effettivo Attiva a importo zero costituisce primo utilizzo economico
  per il cambio Fornitore del Contratto? → A: Sì, blocca il cambio Fornitore.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create and inspect Contracts (Priority: P1)

As an operational user, I can register a company Contract with its Supplier, start,
renewal configuration, first valid economic condition, and annual classification so
the agreement has one stable identity and immediately explains its planned cost.

**Why this priority**: A real Contract and its first condition are prerequisites for
state, deadlines, recurring Estimates, Actuals, and later Budget workflows.

**Independent Test**: Create one currently active Contract and one future planned
Contract, inspect their state on different dates, and verify their initial annual
Estimates and company isolation without creating any Actual.

**Acceptance Scenarios**:

1. **Given** an authorized user, an active Supplier, and an open Exercise, **When**
   the user creates a Contract with all required dates and one valid condition,
   **Then** one stable Contract, its initial lifecycle facts, annual classification,
   generated Estimate composition, and the complete set of required typed Timeline
   events are available exactly once.
2. **Given** a Contract whose start is in the future, **When** a date before or on
   its start is inspected, **Then** it is Planned before the start and Active from
   the start without depending on the date the screen is opened.
3. **Given** a missing first condition, an archived Supplier, invalid renewal data,
   or a cross-company reference, **When** creation is submitted, **Then** the entire
   request is rejected and no Contract, Expense, event, or partial Estimate exists.
4. **Given** a Contract with no defined next expiry, **When** it is inspected,
   **Then** it explicitly shows `Scadenza non definita` and no renewal or notice date
   is inferred.
5. **Given** an existing Contract whose real start predates its registration and may
   fall in a closed Exercise, **When** it is registered, **Then** its real dates,
   current state, deadlines, and renewals are retained, only open Exercises are
   recalculated, existing approved Budgets and Closing Snapshots remain unchanged,
   and the census is recorded in the Timeline.

---

### User Story 2 - Generate exact annual Estimates from conditions (Priority: P1)

As an operational user, I can define supported recurring economic conditions and see
their exact annual Estimates so the Contract contributes once to each affected
Exercise without becoming an invoicing system.

**Why this priority**: The Contract is economically useful only when its supported
conditions produce deterministic annual allocation.

**Independent Test**: Exercise every supported cycle and both attribution modes
across month ends and year boundaries, then verify exact cycle composition, annual
totals, one system Estimate source per Contract/Exercise, and no prorata.

**Acceptance Scenarios**:

1. **Given** a valid condition, **When** its eligible cycles are calculated, **Then**
   Monthly, Quarterly, Semiannual, and Annual recurrences remain anchored to the
   original valid-from date and use the last valid day only when the anchored day is
   absent from a month.
2. **Given** attribution at cycle start or cycle end, **When** a cycle crosses an
   Exercise boundary, **Then** the complete amount belongs only to the Exercise
   containing the canonical attribution date.
3. **Given** several eligible cycles in one Exercise, **When** the annual situation
   is inspected, **Then** their exact EUR net-of-VAT amounts are summed into at most
   one system Estimate Expense for that Contract and Exercise.
4. **Given** an already materialized system Estimate whose recalculation becomes
   zero, **When** recalculation completes, **Then** its stable identity remains at
   zero; a never-materialized zero Estimate is not created.
5. **Given** a user attempts to edit a generated Estimate, add a manual Estimate to
   a Contract, overlap valid conditions, or request prorata, **When** confirmation is
   attempted, **Then** the request is rejected with a domain reason.

---

### User Story 3 - Manage lifecycle, expiry, and renewal (Priority: P1)

As an operational user, I can schedule and record activation, cessation,
reactivation, cancellation, and automatic renewal so Contract state and next expiry
are correct at every relevant date.

**Why this priority**: State determines which cycles and ordinary Actuals are valid,
while expiry and renewal are central operational information.

**Independent Test**: Follow Planned to Active, cessation, reactivation, cancellation
before activation, renewal with multiple elapsed expiries, and non-renewal; verify
state, conditions, Estimates, expiry advancement, idempotency, and historical facts.

**Acceptance Scenarios**:

1. **Given** a Planned Contract, **When** its start becomes effective, **Then** the
   state at and after that date is Active and the prior Planned history is preserved.
2. **Given** an Active Contract with automatic renewal and a defined expiry, **When**
   the expiry is processed without a cessation, **Then** it remains Active, records
   exactly one renewal for that expiry, advances the next expiry from the approved
   anchor, and recalculates affected open Exercises.
3. **Given** several elapsed renewals not yet materialized, **When** processing is
   retried, **Then** every missing renewal appears once in chronological order and
   the next expiry becomes the first future expiry without duplicate events.
4. **Given** automatic renewal is disabled, **When** the expiry is reached, **Then**
   the Contract is Active through that date and Cessated from the following day,
   with no new economic cycle beginning afterward.
5. **Given** a cessation, reactivation, or cancellation, **When** its canonical
   preconditions are not met, **Then** the operation is rejected without changing
   state, conditions, Estimates, or Timeline.
6. **Given** a Cessated or Cancelled Contract, **When** reactivation is confirmed
   with a new start and at least one new valid condition, **Then** the inactive
   interval remains without Estimates and earlier conditions are not silently
   reopened.

---

### User Story 4 - Change Contract economics without silent prorata (Priority: P1)

As an operational user, I can change amount, cycle, or attribution mode from the
first permitted cycle boundary after seeing the requested, minimum, and effective
dates and the complete impact on every open Exercise.

**Why this priority**: A silent mid-cycle change would create overlapping or partial
costs and leave multiple plausible economic results.

**Independent Test**: Change Monthly and Annual conditions before and after a cycle
starts, including multi-Exercise impacts and blocked boundaries, then verify exact
dates, explicit confirmation, atomicity, and unchanged historical references.

**Acceptance Scenarios**:

1. **Given** an existing current condition, **When** a real economic change is
   requested, **Then** the user sees requested date, first day of the following
   month, first applicable cycle boundary, reason for any delay, `Prorata applicato:
   no`, old/new terms, and exact impact per open Exercise before confirmation.
2. **Given** the effective date differs from the requested date, **When** the user
   does not explicitly confirm the effective date, **Then** no change is applied.
3. **Given** no applicable boundary exists before cessation or non-renewed expiry,
   **When** the change is submitted, **Then** it is blocked rather than inventing a
   partial cycle.
4. **Given** a correction of an input error rather than a real agreement change,
   **When** all economically recalculated Exercises are open and the user declares
   the error with a reason, **Then** the original condition may be corrected with a
   complete impact and audit, without changing approved historical references.
5. **Given** one operation affects several open Exercises, **When** any reference,
   revision, condition, or Exercise has changed since preview, **Then** every effect
   is rejected; otherwise all annual Estimates and all required explanatory
   Timeline events are applied together.
6. **Given** a Contract has already been economically used, **When** a Supplier
   change is attempted, **Then** it is rejected and a new Contract is required for a
   different counterparty.

---

### User Story 5 - Record and move Contract Actual Expenses (Priority: P2)

As an operational user, I can record manual Actual Expenses for a Contract and move
whole compatible Expenses between autonomous, Project, and Contract ownership so
their stable Lines are counted exactly once.

**Why this priority**: Contracts must compare generated allocation with declared
Actuals while completing the canonical exclusive Expense-container model.

**Independent Test**: Create Contract Actual Expenses and move stable Expenses in
every supported direction, verifying Supplier derivation, state declarations,
identity preservation, ownership exclusivity, exact totals, and rollback.

**Acceptance Scenarios**:

1. **Given** an Active Contract, **When** an authorized user records an ordinary
   Contract Expense, **Then** it contains only Actual Lines, derives the Contract
   Supplier and annual Cost Center, and contributes once to Contract and Exercise
   Actual totals.
2. **Given** a Planned Contract, **When** an ordinary Actual is submitted, **Then**
   it is rejected; no Estimate, activation, or Actual is inferred.
3. **Given** a Cessated or Cancelled Contract, **When** a late charge, cessation
   cost, reimbursement, or correction is explicitly declared with a note in an open
   Exercise, **Then** it may be recorded without reactivating the Contract.
4. **Given** a manual Expense eligible for a destination container, **When** the
   whole-Expense impact is confirmed, **Then** the same Expense and Line identities
   move atomically and direct/inherited Supplier and Cost Center rules are applied.
5. **Given** an Expense with manual Estimates, **When** movement into a Contract is
   requested, **Then** the operation is rejected; movement out of a Contract does
   not create or retain a generated Contract Estimate.
6. **Given** simultaneous Project and Contract ownership, a cross-company target,
   archived target, closed Exercise, reversed Expense, invalid state declaration,
   or stale preview, **When** confirmation is attempted, **Then** the complete move
   is rejected without partial totals or Timeline events.

---

### User Story 6 - Classify, relate, attach, archive, and explain Contracts (Priority: P2)

As an authorized user, I can classify Contracts annually, inspect actionable expiry
information, add non-economic source relations and optional evidence, archive
terminal Contracts, and follow their complete Timeline.

**Why this priority**: Operational governance requires findable deadlines and
explainable history without changing economics or introducing reporting and
notification systems early.

**Independent Test**: Reclassify a Contract with Actuals, filter expiry and notice
dates, create/archive valid relations, add and inspect optional attachments,
archive/restore terminal Contracts, and inspect the immutable company-scoped history.

**Acceptance Scenarios**:

1. **Given** a Contract in an open Exercise, **When** its Cost Center is changed
   after exact preview, **Then** all generated allocation and manual Actuals for that
   Exercise are reclassified together and no other Exercise changes.
2. **Given** known expiry and notice data, **When** the user filters a selected date
   interval, **Then** the Contract appears with exact days remaining, renewal state,
   current classification, planned cessation, and any renewal-without-condition
   warning.
3. **Given** no expiry is defined, **When** deadline filters are used, **Then** the
   Contract appears only under `Scadenza non definita`; no payment deadline or
   reminder is invented.
4. **Given** a Project and Contract, **When** a valid same-company `Collegato a`
   relation is confirmed, **Then** it supports navigation and history without
   transferring amount, state, classification, ownership, or carryover, and a
   duplicate active link is rejected.
5. **Given** a Cessated or Cancelled Contract, **When** it is archived or restored,
   **Then** identity, conditions, deadlines, classifications, values, relations, and
   history remain unchanged; Planned and Active Contracts cannot be archived.
6. **Given** any Contract mutation, **When** an authorized viewer inspects the
   Timeline, **Then** effective dates, before/after facts, affected Exercises, exact
   allocation/Actual impacts, reason when required, references, and operation identity are
   readable and cannot be edited or deleted.
7. **Given** an authorized user adds an optional attachment to a Contract, Expense,
   or Line, **When** that attachment is inspected, **Then** it is readable only in
   the owning company and remains associated with the stable domain object.

### Edge Cases

- Start, expiry, notice limit, condition boundary, or renewal falls on 28/29
  February or on day 30/31 of a shorter month.
- A cycle starts while the Contract is Active but its end-attribution date falls
  after cessation or in the following Exercise.
- Automatic renewal remains active while no valid economic condition covers the
  renewed period.
- Several automatic renewals elapsed while no user opened the deadlines page.
- A renewal or condition modification affects more than one open Exercise.
- A condition has a future first cycle that has not started and is replaced while
  keeping the same valid-from date.
- A Contract is entered after its real start while earlier Exercises would already
  be closed in a later slice.
- Positive and negative Actual Lines net to zero while Actual presence remains true.
- The Contract Supplier or Cost Center becomes archived after historical use.
- An Expense changes ownership while another request changes a Line, condition,
  lifecycle event, classification, or Exercise revision.
- A physical deletion is attempted for a Contract, condition, lifecycle fact,
  system Estimate, classification, relation, or Timeline event, or for attachment
  evidence already retained by a historical decision.

## Requirements *(mandatory)*

### Functional Requirements

- **S5-FR-001**: Every Contract, condition, lifecycle fact, renewal configuration,
  annual classification, generated Estimate, manual Actual Expense, relation, and
  Timeline event MUST belong to exactly one company and MUST NOT be shared or
  disclosed across companies.
- **S5-FR-002**: Contract reading MUST require `visualizza`; ordinary Contract,
  condition, lifecycle, classification, Expense, relation, archive, and restore
  operations MUST require `modifica_operativita` for the exact company, rechecked at
  submission.
- **S5-FR-003**: A Contract MUST have a stable non-reused identity, title, mandatory
  Supplier, notes, contractual start, optional next expiry, renewal configuration,
  lifecycle facts, conditions, annual classifications, relations, archive property,
  and append-only history.
- **S5-FR-004**: A new Contract MUST include at least one valid economic condition,
  and its first valid-from date MUST NOT precede contractual start.
- **S5-FR-005**: Automatic renewal MUST default to enabled. The initial complete
  renewal configuration MUST have an explicit company-local effective date. When
  next expiry is defined it MUST NOT precede contractual start; automatic renewal
  with a defined expiry MUST require a positive whole-month renewal duration, while
  notice days, when present, MUST be a non-negative integer.
- **S5-FR-006**: Contract lifecycle states MUST be exactly Planned, Active, Cessated,
  and Cancelled; Archived MUST remain a separate visibility property and Suspended
  MUST NOT exist.
- **S5-FR-007**: Contract state at a date MUST be deterministic from contractual
  start, activation, cessation, reactivation, cancellation, renewal, and the renewal
  configuration effective at each expiry. Global, annual, future, and explicitly
  dated views MUST use the canonical company-local reference dates and separately
  expose projected future events.
- **S5-FR-008**: Contractual start and reactivation dates MUST be the first Active
  day; cessation and non-renewed expiry MUST be the last Active day, with Cessated
  state beginning the following day.
- **S5-FR-009**: Lifecycle facts MUST retain declared contractual date, state-change
  date when different, author, technical timestamp, status, and reason when the
  canonical operation requires one; effective history MUST NOT be erased.
- **S5-FR-010**: Duplicate non-annulled state changes on one effective date or a
  lifecycle sequence incompatible with the state immediately before the event MUST
  be rejected atomically.
- **S5-FR-011**: A future lifecycle fact MAY be annulled or replaced before it is
  effective with history preserved; an effective fact MUST remain historical and be
  followed by another canonical fact when change is needed.
- **S5-FR-012**: A valid condition MUST contain exactly one supported cycle, one
  supported Estimate attribution mode, a non-negative authoritative amount, an
  inclusive valid-from date, an optional inclusive valid-to date, and active or
  annulled status.
- **S5-FR-013**: Supported cycles MUST be Monthly, Quarterly, Semiannual, and Annual;
  supported attribution modes MUST be cycle start and cycle end.
- **S5-FR-014**: Valid conditions for the same Contract MUST NOT overlap and MUST be
  economically applicable only while the Contract is Active, without silently
  filling uncovered intervals.
- **S5-FR-015**: Recurrences MUST remain anchored to each condition's original
  valid-from date and MUST use the last day only for an anchored day absent from a
  target month, without propagating that adjustment.
- **S5-FR-016**: A cycle MUST be eligible only when its start lies within the valid
  condition interval and the Contract is Active on that start; a cycle started while
  Active MUST retain its full amount even when end attribution follows cessation.
- **S5-FR-017**: Estimate attribution date MUST be the cycle start for start mode and
  the next cycle start for end mode, and MUST determine only the Exercise receiving
  the Estimate rather than any invoice or payment deadline.
- **S5-FR-018**: Annual Contract allocation MUST equal the exact sum of eligible cycle
  amounts attributed to the Exercise and MUST be represented by at most one stable
  generated system Estimate Expense per Contract and Exercise.
- **S5-FR-019**: A previously materialized generated Estimate MUST remain at zero
  when recalculation becomes zero; a never-materialized zero Estimate MUST NOT be
  created.
- **S5-FR-020**: Generated system Estimates MUST NOT be manually created, edited,
  moved, reversed, or receive Actual Lines; manual Contract Expenses MUST contain
  only Actual Lines.
- **S5-FR-021**: The Contract engine MUST NOT apply prorata, generate Actuals or
  invoices, match Actuals to cycles, produce or receive carryover, or represent
  setup, variable consumption, thresholds, tiers, indexation, payment terms, or
  invoice instalments.
- **S5-FR-022**: A real change to amount, cycle, or attribution mode MUST create a new
  condition effective no earlier than the first day of the month after confirmation
  and, when a current cycle has started, at the first applicable cycle boundary.
- **S5-FR-023**: Before a real economic change, the system MUST show requested,
  minimum, and effective dates; reason for delay; explicit absence of prorata; old
  and new terms; and exact impact for every affected open Exercise, and MUST require
  confirmation of the effective date.
- **S5-FR-024**: A real economic change MUST be blocked when no applicable boundary
  exists before cessation or a non-renewed expiry, and MUST NOT be silently shifted.
- **S5-FR-025**: A declared material input correction MAY update the original
  condition only when all recalculated Exercises are open, after exact impact,
  reason, and audit distinguish it from a real agreement change.
- **S5-FR-026**: Every operation affecting multiple open Exercises MUST enumerate and
  preview all affected Exercises and sources, reauthorize and revalidate them, apply
  every change atomically, preserve approved references, and record one per-Exercise
  impact history.
- **S5-FR-027**: Automatic renewal with a defined expiry MUST keep the Contract
  Active in the absence of cessation, materialize exactly one event per elapsed
  expiry, and advance from the most recently approved manual expiry anchor by the
  renewal duration.
- **S5-FR-028**: Missing elapsed renewals MUST be processed chronologically and
  idempotently until the next expiry is future; calculation for a future open
  Exercise MUST consider projected renewals without prematurely turning them into
  historical events. Renewal processing MUST NOT depend on a user opening the
  deadlines section.
- **S5-FR-029**: Automatic renewal MUST continue open-ended conditions but MUST NOT
  extend an explicit valid-to date; renewal without a condition covering the later
  period MUST keep the Contract Active with a warning and zero invented Estimate.
- **S5-FR-030**: With automatic renewal disabled, a defined expiry MUST end activity
  at that date and prevent cycles starting later; without a defined expiry the
  Contract MUST remain indefinite until explicit cessation.
- **S5-FR-031**: Renewal, renewal duration, expiry, and notice changes MUST preserve
  historically effective configurations, preview all affected deadlines and open
  Exercises, apply atomically, and MUST NOT rewrite already materialized renewals.
  Elapsed unmaterialized expiries MUST first be processed using the configuration
  historically effective at each expiry before a new configuration can apply.
- **S5-FR-032**: Cessation MUST require date and note, end open-ended conditions on
  the cessation date without removing an already started cycle, prevent later cycle
  starts, and recalculate affected open Exercises without prorata.
- **S5-FR-033**: Reactivation MUST require a new Active start, new expiry when
  applicable, and at least one new valid condition, while leaving the inactive
  interval and previous conditions unchanged.
- **S5-FR-034**: Cancellation before activation MUST require a reason, apply only to
  a never-activated Planned Contract, annul future conditions, and reduce current
  allocation to zero only in affected open Exercises without rewriting historical
  references.
- **S5-FR-035**: Supplier MAY change only before the Contract's first economic use;
  after a non-zero live Estimate, any active Actual Line, approved Budget presence,
  or Closing Snapshot presence, a different counterparty MUST require a new
  Contract. An existing Contract MAY continue using its historically assigned
  archived Supplier and receiving valid Actuals, but an archived Supplier MUST NOT
  be selected for a new Contract.
- **S5-FR-036**: An ordinary Contract Actual MUST require Active state on the
  company-local technical registration date; a Planned Contract MUST NOT receive
  ordinary Actuals.
- **S5-FR-037**: A Cessated or Cancelled Contract MAY receive only explicitly
  declared late charges, cessation costs, reimbursements, or corrections in an open
  Exercise with a mandatory note, without reactivation.
- **S5-FR-038**: A manual Expense MUST belong to exactly one first-level owner:
  autonomous, one Project, or one Contract; simultaneous, unsupported, or
  cross-company ownership MUST be rejected.
- **S5-FR-039**: Moving a compatible manual Expense among autonomous, Project, and
  Contract ownership MUST preserve Expense and Line identities, move the complete
  Expense atomically after exact preview, and count every amount exactly once.
- **S5-FR-040**: Entering a Contract MUST reject manual Estimate Lines, replace a
  different direct Supplier after warning, and derive Supplier and annual Cost
  Center from the Contract. Leaving a Contract MAY retain the former Contract
  Supplier as the Expense's optional direct Supplier and MUST NOT copy the generated
  system Estimate. When it becomes autonomous, the Expense MUST NOT implicitly keep
  the inherited Cost Center: the user MAY select an active same-company direct Cost
  Center in the same operation, otherwise the Expense becomes Unclassified. When it
  enters another container, it MUST inherit that container's annual classification,
  including an absent classification.
- **S5-FR-041**: Every Contract MUST have at most one Cost Center classification per
  Exercise; changing it in an open Exercise MUST preview and atomically reclassify
  the full annual generated allocation and Actual. Manual selection MUST accept only
  active same-company Cost Centers, an existing archived reference MUST remain
  readable, and a new Exercise MUST inherit the latest known classification without
  creating Expenses or Actuals.
- **S5-FR-042**: The deadlines section MUST show the canonical Contract, Supplier,
  state, start, next expiry or undefined marker, renewal, duration, notice, planned
  cessation, days remaining, annual Cost Center, renewal-without-condition warning,
  and links to Contract and Timeline. The notice limit MUST equal next expiry minus
  the configured number of calendar days.
- **S5-FR-043**: The deadlines section MUST filter by expiry interval, notice-limit
  interval, automatic renewal on/off, undefined expiry, lifecycle state, Supplier,
  and Cost Center.
- **S5-FR-044**: Deadline dates MUST remain informational and MUST NOT be presented as
  invoice/payment deadlines, automatically send cancellation, infer that notice was
  sent, or promise reminders or notifications in S5.
- **S5-FR-045**: Active `Collegato a` relations MUST be unique symmetric
  Project-Contract links belonging to one company.
- **S5-FR-046**: Informative relations MUST NOT transfer or aggregate amounts,
  ownership, state, classification, carryover, or lifecycle and MUST be archived or
  restored rather than physically deleted.
- **S5-FR-047**: A Contract MAY be archived only while Cessated or Cancelled; archive
  and restore MUST preserve every value and historical identity, and an archived
  Contract MUST be unavailable for new ordinary activity until restored.
- **S5-FR-048**: Contract, condition, lifecycle, renewal, classification, generated
  Estimate, manual Actual ownership, relation, archive, and restore mutations MUST
  be idempotent, revision-safe, and atomically persist the complete domain change
  with every required typed Timeline event recorded exactly once. One command MAY
  record multiple distinct domain events; in particular, each elapsed renewal MUST
  have its own event.
- **S5-FR-049**: Contract Timeline events MUST remain company-scoped, append-only,
  newest-first, and include effective dates, before/after facts, affected Exercises,
  exact allocation and Actual impacts, reason when required, references, and
  operation identity.
- **S5-FR-050**: Contract, condition, lifecycle fact, renewal configuration, annual
  classification, Expense, Line, relation, and Timeline records MUST NOT be
  physically deleted through ordinary operations.
- **S5-FR-051**: S5 MUST NOT implement Proposals, Budgets, Revisions, carryover,
  reprogramming, Closing, late closed-year correction, Forecast, invoicing, payment
  schedules, reminders, full reporting, or non-canonical informative relations.
- **S5-FR-052**: Contracts, Expenses, and Lines MUST support optional attachments
  owned by the same company as the attached object. Attachments used as evidence for
  a later approval, Revision, Closing, late correction, or historical-error
  annotation MUST be retained immutably or by version, and removing an attachment
  from the live object MUST NOT remove that retained historical evidence.
- **S5-FR-053**: Registering an existing Contract after its real start MUST preserve
  its real contractual dates, derive current state, deadlines, and renewals, and
  recalculate only affected open Exercises. It MUST NOT retroactively insert or
  recalculate the Contract in closed Budgets or Closing Snapshots, and MUST record
  the census date in the Timeline.

### Key Entities

- **Contract**: A company-owned, multi-year first-level economic source with stable
  identity, mandatory Supplier, contractual dates, renewal configuration, state at
  date, annual classification, generated allocation, manual Actuals, archive, and
  history.
- **Economic Condition**: A dated, non-overlapping recurring agreement that defines
  amount, supported cycle, Estimate attribution mode, validity, and lifecycle.
- **Contract Lifecycle Fact**: A dated activation, cessation, reactivation,
  cancellation, or renewal fact used to determine state at a requested date.
- **Renewal Configuration**: The renewal, expiry, duration, and notice terms
  effective from a declared date and used at each applicable expiry.
- **Contract Cycle**: A derived occurrence anchored to a condition and retained in
  the composition explaining an annual generated Estimate.
- **Generated Contract Estimate**: The single stable system-planned source for one
  Contract and Exercise, composed from eligible attributed cycles.
- **Contract Actual Expense**: A manual Expense owned by one Contract, containing
  only Actual Lines and inheriting Contract Supplier and annual Cost Center.
- **Annual Contract Classification**: The optional Cost Center for exactly one
  Contract and Exercise.
- **Contract Deadline**: The informational next expiry and optional derived notice
  limit, explicitly distinct from invoice or payment dates.
- **Informative Source Relation**: An archived/restored non-economic `Collegato a`
  link between one Project and one Contract.
- **Attachment**: Optional company-owned evidence associated with a Contract,
  Expense, or Line; historical decision evidence is retained immutably or by
  version.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorized user can create a valid Contract with its first condition
  and inspect its first annual Estimate composition in under five minutes.
- **SC-002**: Across all supported cycles, attribution modes, month-end anchors, leap
  years, state intervals, and year boundaries, 100% of tested annual Estimate totals
  equal the exact sum of canonically eligible cycle amounts.
- **SC-003**: Every tested past, current, future, cancelled, cessated, reactivated,
  renewed, and non-renewed case returns exactly the canonical Contract state for the
  requested date.
- **SC-004**: Every tested multi-Exercise condition, renewal, cessation, expiry, or
  classification operation applies 100% of its confirmed impacts or none, including
  forced-failure and stale-preview cases.
- **SC-005**: Every tested automatic-renewal retry creates zero duplicate renewal
  facts, advances every missing expiry exactly once, and leaves the first future
  expiry visible.
- **SC-006**: Every tested Contract Actual and ownership move preserves all Expense
  and Line identities, rejects manual Contract Estimates, and changes first-level
  totals exactly once with no partial effect.
- **SC-007**: An authorized user can identify in under one minute the next expiry,
  notice limit, renewal behavior, applicable warning, and Timeline cause for any
  demonstrated Contract.
- **SC-008**: Every tested unsupported prorata, overlap, Supplier change after use,
  invalid lifecycle sequence, cross-company reference, archived target, physical
  deletion, and stale confirmation is rejected with zero partial economic effect.
- **SC-009**: The complete S5 demonstration exposes no Proposal, Budget, carryover,
  reprogramming, Closing, closed-year correction, Forecast, invoice, payment
  schedule, reminder, full-reporting control, or non-canonical informative relation.
- **SC-010**: Every tested Contract, Expense, and Line attachment is readable only
  within its company; any attachment retained as historical decision evidence is
  immutable or versioned and survives removal from the live object.

## Assumptions

- The merged S4 baseline supplies company tenancy, Suppliers, Cost Centers, multiple
  open Exercises, exact Expense/Line behavior, Project ownership, revision guards,
  and the append-only Timeline foundation.
- S5 operates only on open Exercises because Closing is introduced in S9. Its
  behavior and data must remain compatible with the canonical rule that later
  operations never recalculate a closed Exercise; S5 does not add placeholder
  Closing or historical-annotation controls.
- Budget-dependent reasons, immutable Budget references, and Proposal realignment
  are not triggered because Budget and Proposal do not exist before S6/S7. Reasons
  already required for Contract cessation, reactivation, cancellation, late Actual,
  and material input correction remain mandatory.
- Approval or confirmation date is interpreted in the company's local timezone;
  effective contractual dates remain local economic dates.
- Automatic renewal must be correct independently of a user opening the deadlines
  page; the mechanism used to invoke due processing remains a technical decision.
- Components not representable by the canonical recurring-condition engine remain
  separate autonomous or Project Expenses. An informative relation does not include
  them economically in the Contract.
- S5 introduces the shared optional attachment baseline for Contracts, Expenses,
  and Lines. Later approval, Revision, Closing, late-correction, and historical-error
  workflows reuse it and retain their decision evidence immutably or by version.

## Domain Traceability

- Canonical FR-048–FR-049 for Contract Expense types and the unique generated annual
  Estimate.
- Canonical attachment fields in §§8.1, 15.1, and 18.2, plus the historical
  evidence rules in §§23.12 and 30.9.
- Canonical FR-062–FR-078 for Contract data, lifecycle, renewals, conditions,
  recurrence, attribution, no prorata, and manual Actuals.
- Canonical FR-090 and FR-095 for informative deadlines and non-economic
  Project-Contract `Collegato a` navigation.
- Contract completion of canonical FR-005–FR-007, FR-051–FR-052, FR-079–FR-081 and
  invariants 28.4, 28.5, 28.42, 28.43 and 28.52 across every first-level source.
- Canonical invariants 28.9, 28.32–28.41, 28.56 and 28.60; S5 exercises the complete
  residual informative-relation invariant through `Collegato a`.
- First complete verification of canonical FR-094 for atomic operations across
  multiple open Exercises.
- Cross-cutting reuse of canonical FR-084 and invariants 28.24, 28.44–28.46, and
  28.57.
- Canonical implementation constraints for revisions, impact plans, anchored dates,
  idempotent renewal and generated Estimates, atomicity, exact totals, company time
  zones, and domain messages.

## Category A-E Reconciliation

- Billing frequency narratives already represented by the four cycles, commercial
  causes, temporary service pauses, and explanatory deadline context are categories
  A–C and use existing conditions, lifecycle facts, Notes, relations, and Timeline.
- Invoices, payments, instalments, tax, procurement, prorata, variable consumption,
  tiering, indexation, reminders, and Forecast remain category D or explicitly
  excluded canonical behavior.
- Proposals, Budgets, carryover, Closing, late closed-year corrections, and full
  reporting are canonical but assigned to later roadmap slices; S5 does not
  approximate them. S5 supplies only their shared attachment prerequisite, without
  introducing those later workflows.
- Canonical §32 permanently excludes structured source-replacement relations. No
  automatic relation archive, movement block, or historical endpoint rule is
  introduced when an autonomous Expense changes owner.
- Contract state at date, renewal, recurrence, annual attribution, economic-change
  boundary, ownership, classification, deadlines, and Project-Contract links are
  deterministic in the canonical domain. No other category-E structural gap was
  found for the bounded S5 scope.
