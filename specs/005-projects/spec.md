# Feature Specification: Projects

**Feature Branch**: `agent/projects`

**Created**: 2026-08-17

**Status**: Draft

**Roadmap ID**: S4

**Input**: Continue development with roadmap slice S4, Projects, after the verified
Exercises, Expenses, and Lines slice.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create and inspect Projects (Priority: P1)

As an operational user, I can create a company Project with a stable identity,
initial state, effective date, and yearly Cost Center classification so planned or
urgent work can be managed as one first-level economic source.

**Why this priority**: A Project must exist before its Expenses, state, annual
classification, totals, and history can be managed.

**Independent Test**: Create both a Planned and an Open Project, inspect their state
on past, current, and future reference dates, and verify another company cannot see
or reuse either identity.

**Acceptance Scenarios**:

1. **Given** an authorized user in a company with an open Exercise, **When** the user
   creates a Project with title, initial state, effective date, and optional Cost
   Center for that Exercise, **Then** the Project receives a stable identity and one
   complete Timeline event without requiring an Expense.
2. **Given** a Project whose initial state is effective in the future, **When** a date
   before that effective date is inspected, **Then** the Project is shown as absent
   at that date rather than being assigned an invented lifecycle state.
3. **Given** a Project owned by another company, **When** a user guesses its URL or
   submits its identity, **Then** no Project data is disclosed and no mutation occurs.

---

### User Story 2 - Manage dated Project transitions (Priority: P1)

As an operational user, I can schedule and record the canonical Project transitions
so the Project state is correct at any relevant date and historical decisions remain
explainable.

**Why this priority**: Project eligibility for Estimates, ordinary Actuals, annual
views, and later Closing decisions depends on state at the applicable date.

**Independent Test**: Exercise every allowed transition, including a future
transition that is annulled or replaced, and verify current, annual, and historical
views resolve the deterministic state for their required reference date.

**Acceptance Scenarios**:

1. **Given** a Planned Project, **When** an authorized user schedules a valid opening
   date, **Then** it remains Planned before that date and is Open from that date.
2. **Given** a future non-annulled transition, **When** it is annulled or replaced
   before becoming effective, **Then** the original event remains in history and the
   resulting state follows only the valid replacement sequence.
3. **Given** two transitions on the same effective date or a transition whose source
   state does not match the state immediately before that date, **When** confirmation
   is attempted, **Then** the request is rejected with no partial state or success
   event.
4. **Given** an already effective transition, **When** a user tries to erase it,
   **Then** deletion is unavailable and a later canonical transition is required.

---

### User Story 3 - Plan and record Project Expenses (Priority: P1)

As an operational user, I can create or associate Expenses with a Project so its
yearly allocation, Actual, variance, and Cost Center are calculated once at the
Project level.

**Why this priority**: The independently useful outcome of a Project is its economic
view across its child Expenses without double counting.

**Independent Test**: Add multiple Project Expenses and Lines in one Exercise,
including separate suppliers, and verify exact Project and Exercise totals count
each amount once while every child Expense inherits the Project's annual Cost Center.

**Acceptance Scenarios**:

1. **Given** a Planned or Open Project in an open Exercise, **When** an authorized
   user creates a Project Expense with valid Estimate Lines, **Then** its Estimates
   contribute to the Project allocation and do not appear again as an autonomous
   top-level source.
2. **Given** an Open Project, **When** an authorized user records ordinary Actual
   Lines, **Then** exact signed amounts contribute to Project Actual and variance
   under the same annual Project classification.
3. **Given** a Planned Project, **When** an ordinary Actual is submitted, **Then** it
   is rejected unless the user explicitly includes a valid opening transition in the
   same atomic operation.
4. **Given** a Closed or Cancelled Project, **When** a late, reimbursement, or
   corrective Actual is explicitly declared with a note, **Then** it may be recorded
   without changing Project state; an ordinary Actual remains rejected.
5. **Given** Project Expenses with different Suppliers, **When** the Project is
   inspected, **Then** Supplier remains a property of each Expense and no Project
   Supplier is invented.

---

### User Story 4 - Reclassify a Project for an Exercise (Priority: P2)

As an operational user, I can change a Project's Cost Center for an open Exercise
after seeing the complete impact so every child Expense in that year is reclassified
consistently.

**Why this priority**: Project classification is annual and inherited; partial or
mid-year classification would make economic aggregations contradictory.

**Independent Test**: Change a Project from one Cost Center to another and then to
Unclassified in one open Exercise, verifying all allocation and Actual values move
as a whole while another Exercise remains unchanged.

**Acceptance Scenarios**:

1. **Given** a Project with Expenses in an open Exercise, **When** the user confirms
   the old/new Cost Center and exact allocation/Actual impact, **Then** the complete
   Exercise is reclassified atomically, including existing Actuals.
2. **Given** the same Project in two Exercises, **When** one annual classification is
   changed, **Then** the other Exercise's classification and totals remain unchanged.
3. **Given** an archived or cross-company Cost Center, **When** it is submitted as a
   new classification, **Then** the operation is rejected; an existing historical
   archived reference remains readable.
4. **Given** a newly created Exercise, **When** Project classifications are
   initialized, **Then** each Project receives its latest known classification or
   remains Unclassified, and no Expense, Estimate, Actual, or Budget is created.

---

### User Story 5 - Move a whole Expense between supported containers (Priority: P2)

As an operational user, I can move a manual Expense between autonomous and Project
ownership, or between Projects, after a complete impact preview so all Lines and
economic meaning move together.

**Why this priority**: S4 introduces the first real container and must extend the S3
whole-Expense correction without creating duplicate or partially classified values.

**Independent Test**: Move an Estimate-only Expense autonomous to Project, Project
to Project, and Project to autonomous; then repeat with Actuals and verify state,
note, classification inheritance, identities, totals, and rollback rules.

**Acceptance Scenarios**:

1. **Given** an Estimate-only Expense and a Planned or Open destination Project,
   **When** the user confirms the impact, **Then** the whole Expense changes
   container atomically and retains its stable identity and Lines.
2. **Given** an Expense with Actuals, **When** it is moved to an Open Project with a
   required reason, **Then** all Estimates and Actuals are reclassified together.
3. **Given** an Expense with Actuals and a Planned Project, **When** the user includes
   a valid opening transition in the same confirmed operation, **Then** both changes
   succeed atomically; without the transition the move is rejected.
4. **Given** an Expense with Actuals and a Closed or Cancelled Project, **When** the
   user declares a permitted late or corrective attribution with a reason, **Then**
   the move may occur without changing Project state; an ordinary attribution is
   rejected.
5. **Given** an Expense leaving a Project, **When** it becomes autonomous, **Then**
   it does not retain the inherited Cost Center implicitly and the user may select an
   active direct Cost Center or leave it Unclassified.
6. **Given** a stale preview, cross-company object, closed Exercise, reversed Expense,
   or unsupported Contract destination, **When** confirmation is attempted, **Then**
   the entire operation is rejected without partial totals or Timeline events.

---

### User Story 6 - Explain overspend, archive, and Project history (Priority: P2)

As an authorized viewer, I can see when Project overspend begins or increases,
archive only terminal Projects, and follow every Project change in the company
Timeline.

**Why this priority**: Economic changes and lifecycle visibility must remain
explainable without deleting history or inventing a reporting subsystem early.

**Independent Test**: Create and increase positive variance with both overspend-note
settings, archive and restore eligible Projects, and inspect one ordered,
company-scoped Timeline containing exact before/after impacts.

**Acceptance Scenarios**:

1. **Given** a Project whose variance changes from non-positive to positive, **When**
   the mutation is confirmed, **Then** the user sees an overspend warning and the
   Timeline records the newly created overspend.
2. **Given** an already positive variance, **When** it increases, **Then** another
   overspend warning is produced; when it stays equal or decreases, no new warning is
   produced.
3. **Given** the company requires an overspend note, **When** an operation creates or
   increases overspend without a note, **Then** the entire operation is rejected.
4. **Given** a Closed or Cancelled Project, **When** it is archived or restored, **Then**
   its identity, values, classifications, and history remain unchanged; Planned and
   Open Projects cannot be archived.
5. **Given** a Project event, **When** an authorized viewer opens the Timeline, **Then**
   its effective date, state, affected Exercises, exact impacts, reason, and operation
   identity are readable and cannot be edited or deleted.

### Edge Cases

- Initial state becomes effective on a past, current, or future company-local date.
- A late Project is first entered after activity began, while closed Exercises must
  remain untouched.
- Multiple future transitions are individually valid but incompatible as a sequence.
- A future transition is replaced with another transition on the same effective date.
- An Actual submission crosses the company's local midnight between form display and
  confirmation.
- Positive and negative Actual Lines net to zero while Actual presence remains true.
- Moving or restoring a Line, Expense, or classification creates or increases
  Project overspend indirectly.
- A Project has no Expenses, or has only reversed Expenses and annulled Lines.
- The latest known annual Cost Center is archived before a new Exercise is created.
- An archived Project still has values in annual views and is targeted through a
  crafted new-activity request.
- A Project Expense is moved while another request changes one of its Lines or its
  Project transition sequence.
- A physical deletion is attempted for a Project, transition, or annual
  classification.

## Requirements *(mandatory)*

### Functional Requirements

- **S4-FR-001**: Every Project, transition, annual classification, Project Expense,
  and Project Timeline event MUST belong to exactly one company and MUST NOT be
  shared or disclosed across companies.
- **S4-FR-002**: Project reading MUST require `visualizza`; creation, transition,
  classification, Expense, archive, and restore operations MUST require
  `modifica_operativita` for the exact company, rechecked at submission.
- **S4-FR-003**: A Project MUST have a stable non-reused identity, title, optional
  description and notes, initial state, initial effective date, dated transitions,
  annual classifications, archive property, child Expenses, and append-only history.
- **S4-FR-004**: Project lifecycle states MUST be exactly Planned, Open, Closed, and
  Cancelled; Archived MUST remain a separate visibility property.
- **S4-FR-005**: The allowed transitions MUST be Planned to Open or Cancelled, Open
  to Closed or Cancelled, Closed to Open, and Cancelled to Planned or Open, with a
  reason wherever the canonical transition requires one.
- **S4-FR-006**: Project state at a date MUST be derived deterministically from the
  initial effective state and all non-annulled transitions effective on or before
  that date; before the initial date it MUST read `Absent at date`.
- **S4-FR-007**: A Project transition MUST retain its requested source state,
  destination state, effective date, planning/effective/annulled status, actor,
  technical timestamp, and reason.
- **S4-FR-008**: Two non-annulled transitions MUST NOT share an effective date, and a
  transition MUST be rejected when the state immediately before its effective date
  differs from its required source state or when it makes the remaining future
  sequence incompatible.
- **S4-FR-009**: A future transition MAY be annulled or replaced before it becomes
  effective, with history preserved; an effective transition MUST NOT be erased and
  requires a later allowed transition to change state.
- **S4-FR-010**: The global Project view MUST use the company's current local date;
  an annual view MUST use 31 December for past Exercises, the company-local current
  date for the current Exercise, and 1 January plus separately visible planned
  transitions for future Exercises.
- **S4-FR-011**: A Project MAY exist with zero Expenses and zero economic values.
- **S4-FR-012**: A Project Expense MUST remain one stable manual Expense containing
  Estimate and/or Actual Lines, MAY have its own optional Supplier, MUST inherit the
  Project's Cost Center for its Exercise, and MUST NOT have an independent direct
  Cost Center.
- **S4-FR-013**: For an Exercise, Project allocation MUST equal the exact sum of
  active Estimate Lines in active child Expenses plus any canonically available
  received carryover; S4 MUST treat carryover as unavailable rather than introduce
  its later-slice workflow or placeholder input.
- **S4-FR-014**: Project Actual MUST equal the exact signed sum of active Actual Lines
  in active child Expenses, variance MUST equal Actual minus allocation, and Actual
  presence MUST remain existential rather than depend on the net total.
- **S4-FR-015**: Exercise totals MUST count a Project once as a first-level source and
  MUST NOT also count its child Expenses as autonomous sources.
- **S4-FR-016**: Estimate activity MUST require the Project to be Planned or Open in
  the affected Exercise context; a Closed or Cancelled Project MUST receive no new
  planning until a valid reopening is included first.
- **S4-FR-017**: An ordinary Actual MUST require the Project to be Open at the
  company-local technical registration date; a Planned Project MAY receive it only
  when a valid opening is confirmed in the same atomic operation.
- **S4-FR-018**: A Closed or Cancelled Project MAY receive a late, reimbursement, or
  corrective Actual in an open Exercise only after explicit declaration and a
  mandatory note, without changing Project state.
- **S4-FR-019**: Every Project MUST have at most one Cost Center classification per
  Exercise; absence of a classification MUST be displayed as Unclassified.
- **S4-FR-020**: Changing a Project's Cost Center in an open Exercise MUST show and
  atomically reclassify all Project allocation and Actual for that Exercise,
  including existing Actuals, without changing any other Exercise.
- **S4-FR-021**: A new active classification MUST reference an active Cost Center in
  the same company; an archived existing identity MUST remain historically readable
  but MUST NOT be selected for new classification until restored.
- **S4-FR-022**: Creating an Exercise after S4 MUST initialize every existing
  Project's annual classification from its latest known classification, or
  Unclassified when none exists, while creating no Expense, Line, Budget, Estimate,
  Actual, or carryover.
- **S4-FR-023**: A manual Expense MUST have exactly one supported S4 ownership state:
  autonomous or associated with one Project; S4 MUST reject simultaneous or
  cross-company ownership and MUST NOT expose Contract ownership before S5.
- **S4-FR-024**: Moving an Expense between autonomous and Project ownership or between
  Projects MUST preserve the Expense and Line identities and move the complete
  Expense as one atomic whole after an exact impact preview.
- **S4-FR-025**: An Estimate-only Expense may enter only a Planned or Open Project,
  unless a valid reopening is included in the same operation; an Expense containing
  Actuals MUST follow the ordinary or late/corrective state rules and require a
  reason.
- **S4-FR-026**: Entering a Project MUST remove direct Cost Center ownership and apply
  the Project's annual classification; leaving a Project MUST require an explicit
  active direct Cost Center choice or Unclassified and MUST NOT retain the inherited
  classification implicitly.
- **S4-FR-027**: A stale revision, closed Exercise, reversed Expense, archived target,
  invalid transition sequence, or cross-company reference MUST reject the complete
  Project or Expense operation without partial state or audit.
- **S4-FR-028**: Project totals and preview calculations MUST use exact decimal EUR
  net-of-VAT amounts and MUST preserve all S3 Line validation, future-Actual,
  warning, annul/restore, Storno, and idempotency rules.
- **S4-FR-029**: The system MUST warn when Project variance changes from non-positive
  to positive or when an existing positive variance increases, and MUST NOT create a
  new overspend warning when positive variance stays equal or decreases.
- **S4-FR-030**: When the company's overspend-note setting is enabled, every operation
  that creates or increases Project overspend MUST require a note and reject the
  entire operation when it is absent.
- **S4-FR-031**: A Project MAY be archived only while Closed or Cancelled; archive and
  restore MUST preserve every economic value and historical identity, while an
  archived Project MUST be unavailable for new ordinary activity until restored.
- **S4-FR-032**: Project, transition, classification, Expense ownership, overspend,
  archive, and restore mutations MUST be idempotent, revision-safe, and atomically
  persist both the complete domain change and one typed Timeline event per real
  transition.
- **S4-FR-033**: Project Timeline events MUST remain company-scoped, append-only, and
  readable newest first with effective date, before/after state, affected Exercises,
  exact allocation and Actual impacts, reason, references, and operation identity.
- **S4-FR-034**: Project, transition, annual classification, Expense, and Line records
  MUST NOT be physically deleted through ordinary operations.
- **S4-FR-035**: S4 MUST NOT implement carryover, reprogramming, Proposal, Budget,
  Closing, late closed-year correction, Contract membership, informative
  Project-Contract relations, approved-budget reason rules, attachments, Forecast,
  or full reporting.

### Key Entities

- **Project**: A company-owned, multi-year first-level economic source with stable
  identity, descriptive fields, initial effective state, archive visibility, and
  yearly economic views.
- **Project Transition**: A dated, auditable lifecycle change whose source and
  destination states determine whether the sequence is valid.
- **Annual Project Classification**: The Project's optional Cost Center for exactly
  one Exercise, inherited by every child Expense in that year.
- **Project Expense**: The existing stable manual Expense aggregate associated with
  one Project instead of being autonomous; its Lines remain the authoritative facts.
- **Project Impact Plan**: The immutable pre-confirmation explanation of affected
  Projects, Exercises, classifications, allocation, Actual, state, and unchanged
  identities.
- **Overspend Event**: An explanatory Project event produced only when positive
  variance is created or increases.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorized user can create a Project, classify it for an Exercise,
  and add its first Estimate Expense in under four minutes.
- **SC-002**: Every tested current, past, future, annulled, replaced, and reopened
  transition sequence returns exactly the canonical Project state for the requested
  date.
- **SC-003**: Across all calculation tests, Project allocation, Actual, and variance
  equal the exact signed sums of eligible child Lines, and Exercise totals contain
  zero double counting.
- **SC-004**: Every tested ordinary Actual on an ineligible Project, invalid
  transition, cross-company reference, archived target, closed-Exercise mutation,
  and stale preview is rejected with zero partial economic effect.
- **SC-005**: Every annual Cost Center change moves 100% of that Project's allocation
  and Actual in the selected Exercise and 0% in every other Exercise.
- **SC-006**: Every tested Expense container move preserves all Expense and Line IDs,
  changes old/new first-level totals exactly once, and rolls back completely on a
  forced failure.
- **SC-007**: Every tested creation or increase of positive Project variance produces
  exactly one warning, while unchanged or reduced positive variance produces none;
  required-note mode rejects 100% of missing-note attempts.
- **SC-008**: An authorized viewer can determine in under one minute a Project's state
  at the relevant date and the Timeline cause of any tested state, classification,
  ownership, or overspend change.
- **SC-009**: The complete S4 demonstration exposes no carryover, reprogramming,
  Contract, Proposal, Budget, Closing, Forecast, attachment, or full-reporting
  control.

## Assumptions

- The merged S3 slice supplies company tenancy, open Exercises, exact Expense/Line
  behavior, revision guards, and the append-only Timeline foundation.
- Direct Project creation records an explicit initial state and effective date; later
  Proposal flows may create Projects through their own isolated approval path without
  changing this Project lifecycle model.
- The S4 economic demonstration uses open Exercises. Closed-year late corrections
  remain in S10, while late or corrective Actuals discussed here still belong to an
  open Exercise.
- Received carryover is zero and unavailable in S4 because S8 owns its decisions and
  persistence; Project allocation is extended there without changing child Expense
  semantics.
- Budget-dependent mandatory reasons are not triggered because Budget does not exist
  before S6. Reasons already required by state, Actual, move, archive, and overspend
  rules remain mandatory.
- Project-to-Contract links and Contract destinations are deferred until the real
  Contract identity and workflows exist in S5.
- Notes and Timeline provide S4 explanations; uploaded evidence remains deferred to
  the first bounded slice that requires an attachment lifecycle.

## Domain Traceability

- Canonical FR-005–FR-007 and FR-051–FR-052 for the real Project container,
  first-level aggregation, no double counting, and whole-Expense reclassification.
- Canonical FR-055–FR-058 for Project states, Actual eligibility, dated transitions,
  and overspend warnings.
- Canonical FR-079–FR-081 for annual Cost Center classification and inheritance.
- Canonical FR-084 for typed explanatory Timeline events produced by S4 operations.
- Canonical invariants 28.4, 28.5, 28.8, 28.24, 28.42–28.46, 28.52, 28.57.
- Canonical implementation constraints for revision-safe state-at-date calculation,
  impact plans, exact totals, atomicity, idempotency, time zones, and domain messages.

## Category A-E Reconciliation

- A temporary pause, external cause, Project narrative type, supplier mix, and reason
  for variance use existing states, Notes, child Expenses, and Timeline: categories
  A–C with no new economic concept.
- Project suspension, earned-value indicators, monthly Actuals, physical deletion,
  and inferred causes remain explicitly outside the model: category D.
- Carryover, reprogramming, Proposals, Budgets, Closing, and Contracts are canonical
  but intentionally assigned to later roadmap slices; S4 does not approximate them.
- The canonical transition model, annual classification, Expense movement, late
  Actual rules, and overspend predicates are deterministic for the bounded S4 scope.
  No category-E structural gap was found.
