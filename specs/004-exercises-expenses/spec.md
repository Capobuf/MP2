# Feature Specification: Exercises, Expenses and Lines

**Feature Branch:** `004-exercises-expenses`
**Created:** 2026-08-17
**Status:** Implemented locally
**Roadmap ID:** S3

## User Scenarios & Testing

### User Story 1 — Manage open Exercises (Priority: P1)

As a user responsible for company operations, I can create and inspect more than one
open calendar-year Exercise so current work and advance planning remain separate.

**Why this priority:** Every Expense and Line belongs to an Exercise, and the domain
explicitly permits several open years at the same time.

**Independent Test:** Create two open Exercises for one company, reject a duplicate
year in that company, and verify a user in another company cannot see or reuse them.

**Acceptance Scenarios:**

1. **Given** a user with `modifica_operativita` for the selected company, **When** the
   user creates an Exercise for a calendar year not yet present, **Then** one open
   Exercise owned by that company is created without a Budget or copied Expenses.
2. **Given** an open Exercise already exists for a company and year, **When** another
   creation is attempted for the same company and year, **Then** it is rejected
   without creating a duplicate or a misleading Timeline event.
3. **Given** two distinct years, **When** both are created for the same company,
   **Then** both remain open and independently selectable.
4. **Given** a user lacks company-specific operational authority, **When** the user
   attempts creation or guesses another company's Exercise URL, **Then** the request
   is rejected without disclosure or mutation.

---

### User Story 2 — Register an autonomous Expense with Estimates and Actuals (Priority: P1)

As an operational user, I can register an autonomous Expense and its Estimate or
Actual Lines so the live yearly allocation, actual amount, and operational variance
are available without a Budget or Forecast.

**Why this priority:** This is the smallest independently useful economic flow and
establishes the canonical live reality used by every later slice.

**Independent Test:** In an open Exercise, create one autonomous Expense with
Estimate and Actual Lines, then verify the authoritative line amounts produce the
correct allocation, actual total, and operational variance.

**Acceptance Scenarios:**

1. **Given** an open Exercise, **When** an authorized user creates an Expense with a
   required description and at least one valid Estimate or Actual Line, **Then** the
   Expense is active, autonomous, company-owned, and has a stable OriginKey derived
   only from its type and stable identity.
2. **Given** active Estimate Lines of 1,000.00 EUR and 500.00 EUR and active Actual
   Lines of 900.00 EUR and 100.00 EUR, **When** the Expense is viewed, **Then** its
   current allocation is 1,500.00 EUR, its actual is 1,000.00 EUR, and its
   operational variance is -500.00 EUR.
3. **Given** quantity and unit amount whose product differs from the entered amount,
   **When** the Line is saved after an explicit warning, **Then** the entered amount
   remains authoritative.
4. **Given** two Expenses with the same description, supplier, or amounts, **When**
   both are saved, **Then** they remain distinct sources and are never matched or
   merged automatically.
5. **Given** an archived supplier, **When** a new ordinary Expense is created, **Then**
   that supplier is unavailable for selection; an existing historical reference to
   it remains readable.

---

### User Story 3 — Maintain Lines without erasing history (Priority: P1)

As an operational user, I can add, correct, annul, and restore Lines in an open
Exercise so the live values remain accurate while every persisted fact retains its
identity and audit history.

**Why this priority:** Line amount is the authoritative economic fact, and changes
must remain explainable, idempotent, and non-destructive.

**Independent Test:** Add and edit Estimate and Actual Lines, annul and restore each
type, retry each operation with the same operation identity, and verify totals and
Timeline events change exactly once.

**Acceptance Scenarios:**

1. **Given** an active Expense in an open Exercise, **When** an authorized user adds
   or changes a non-negative Estimate Line, **Then** the current allocation is
   recalculated from active Estimate amounts only.
2. **Given** an active Expense in an open Exercise, **When** an authorized user adds
   or changes an Actual Line, **Then** the actual total is recalculated from active
   Actual amounts only and no Estimate-to-Actual relationship is created.
3. **Given** a negative Actual representing a reimbursement, credit, or correction,
   **When** it is saved with a mandatory note, **Then** the signed amount contributes
   to the actual total; without the note the operation is rejected.
4. **Given** active Actual Lines of +100.00 EUR and -100.00 EUR, **When** presence of
   Actuals is evaluated, **Then** it remains true even though the net total is zero.
5. **Given** only a zero-valued Actual or only annulled Actual Lines, **When** presence
   of Actuals is evaluated, **Then** it is false.
6. **Given** a persisted Line, **When** it is annulled or restored, **Then** the same
   stable identity and history are preserved and only active Lines affect totals.
7. **Given** a mutation retry with the same operation identity, **When** it is
   processed again, **Then** no duplicate Line or Timeline event is created.

---

### User Story 4 — Reclassify or move an autonomous Expense safely (Priority: P2)

As an operational user, I can change an autonomous Expense's open Exercise,
supplier, or direct Cost Center while seeing the economic impact, so corrections to
the live operational reality are complete rather than partial.

**Why this priority:** Open-year correction is canonically allowed, but must preserve
the whole Expense, its Lines, company boundary, and annual Actual constraints.

**Independent Test:** Move a complete autonomous Expense between two open Exercises,
change its supplier and direct Cost Center, and verify all Lines and totals move
atomically while identity remains stable.

**Acceptance Scenarios:**

1. **Given** an autonomous Expense and two open Exercises in the same company,
   **When** an authorized user confirms the before/after impact, **Then** the whole
   Expense and all of its Lines move atomically to the destination Exercise.
2. **Given** an Expense containing Actuals, **When** its Exercise is changed, **Then**
   a reason is mandatory and the destination year cannot be later than the company's
   local current year.
3. **Given** a closed source or destination Exercise, **When** an Exercise change is
   attempted, **Then** it is rejected without partial movement.
4. **Given** an active supplier or Cost Center owned by the same company, **When** it
   is selected after the impact is shown, **Then** the entire autonomous Expense is
   reclassified while its identity and Lines remain unchanged.
5. **Given** a supplier, Cost Center, Exercise, or Expense belonging to another
   company, **When** it is submitted through a crafted request, **Then** the operation
   is rejected without disclosure or partial changes.

---

### User Story 5 — Storno and restore an Expense (Priority: P2)

As an operational user, I can reverse an Expense that has no Actuals and restore it
while the Exercise is open, so it leaves or rejoins live calculations without being
physically deleted.

**Why this priority:** Storno is the canonical economic lifecycle for a persisted
Expense and differs from non-economic Archive.

**Independent Test:** Reverse and restore an Estimate-only Expense, then attempt the
same on an Expense with net-zero non-zero Actual Lines and verify the latter is
rejected.

**Acceptance Scenarios:**

1. **Given** an active Expense with no non-zero active Actual Line in an open
   Exercise, **When** an authorized user provides a reason and confirms Storno,
   **Then** the Expense is excluded from current calculations but retains its ID,
   OriginKey, Lines, and history.
2. **Given** a reversed Expense in an open Exercise, **When** an authorized user
   provides a reason and restores it, **Then** it contributes again using its active
   Lines and retains the same identity.
3. **Given** an Expense with at least one non-zero active Actual Line, including
   offsetting positive and negative Lines, **When** Storno is attempted, **Then** it
   is rejected even if the net actual is zero.
4. **Given** an already reversed or already active Expense, **When** the same state
   request is repeated, **Then** no duplicate transition or misleading event is
   produced.

---

### User Story 6 — Explain economic changes in the Timeline (Priority: P2)

As an authorized viewer, I can inspect complete, append-only Timeline events for
Exercises, Expenses, and Lines so every current economic difference can be traced to
who changed what, when, why, and with which yearly impact.

**Why this priority:** S3 owns the first complete economic use of the shared audit
infrastructure and must reconcile canonical FR-084 with the envelope introduced in
S1 and S2.

**Independent Test:** Perform every S3 mutation, then verify the company Timeline
shows one immutable typed event per real transition with before/after values and
per-Exercise allocation and actual impacts.

**Acceptance Scenarios:**

1. **Given** a successful S3 mutation, **When** the Timeline is viewed, **Then** one
   typed event identifies actor, company, object, affected Exercises, operation,
   effective date, previous/new values, per-Exercise allocation and actual impact,
   reason when required, and operation identity.
2. **Given** an atomic move between Exercises, **When** its event is inspected,
   **Then** the single operation explains removal from the source year and addition
   to the destination year.
3. **Given** a failed, unauthorized, invalid, or no-op mutation, **When** the Timeline
   is inspected, **Then** no success event or partial economic state exists.
4. **Given** an existing Timeline event, **When** update or deletion is attempted,
   **Then** the event remains immutable.
5. **Given** a viewer authorized only for Company A, **When** the Timeline is viewed,
   **Then** no Company B event is disclosed.

### Edge Cases

- An Exercise year is duplicated inside one company but also exists independently in
  another company.
- A past, current, or future calendar-year Exercise is created; all start open.
- An Actual is entered into an Exercise after the company's local year has advanced,
  or into a future Exercise and must be rejected.
- An Estimate is negative, an Actual is negative without a note, or a new manual
  zero-valued Line has no explicit reason.
- Quantity or unit amount is absent, has up to six decimal places, or suggests an
  amount different from the authoritative amount.
- Active Actual Lines offset to net zero; Actual presence remains true.
- A Line is annulled twice, restored twice, or changed through a repeated operation.
- An Expense is moved between years while another request changes one of its Lines.
- An archived supplier or Cost Center is already referenced by an Expense.
- A reversed Expense receives a crafted Line mutation before restoration.
- A physical deletion is attempted for an Exercise, Expense, or Line.
- A user loses `modifica_operativita` between opening and submitting a form.

## Requirements

### Functional Requirements

- **S3-FR-001**: Every Exercise, Expense, Line, and S3 Timeline event MUST belong to
  exactly one company and MUST NOT be shared or exposed across companies.
- **S3-FR-002**: Reading S3 data MUST require `visualizza` for the affected company;
  creating or mutating it MUST require `modifica_operativita` for that exact company,
  rechecked when the operation is submitted.
- **S3-FR-003**: An Exercise MUST represent one calendar year, MUST start `Aperto`,
  and MUST be unique by company and year while allowing multiple different open
  Exercises for one company.
- **S3-FR-004**: S3 MUST NOT expose Exercise closing, reopening, Budget, Proposal,
  carryover, reprogramming, classification inheritance, or next-year creation
  behavior owned by later slices.
- **S3-FR-005**: Exercise creation MUST NOT create a Budget, copy autonomous Expenses
  or Actuals, create Project Estimates, or assign annual Project/Contract
  classifications.
- **S3-FR-006**: Every persisted Exercise, Expense, and Line MUST receive a stable,
  non-reused identity and MUST NOT be physically deleted through ordinary
  operations.
- **S3-FR-007**: An Expense MUST have a stable OriginKey composed only from its source
  type and stable source ID; title, description, supplier, Cost Center, and amount
  MUST NOT define identity.
- **S3-FR-008**: S3 MUST expose only autonomous manual Expenses; each MUST belong to
  one Exercise, MUST have a required description, MAY have notes, an optional active
  supplier, and an optional active direct Cost Center, and MUST contain one or more
  persisted Lines.
- **S3-FR-009**: The economic ownership model MUST preserve exactly one of autonomous,
  Project, or Contract membership and MUST reject simultaneous Project and Contract
  membership; S3 MUST NOT expose Project or Contract assignment before S4/S5.
- **S3-FR-010**: A Line MUST have exactly one type, `Stima` or `Effettivo`, an
  authoritative EUR net-of-VAT amount with two decimals, active/annulled state,
  optional descriptive quantity, unit amount, unit of measure, and note.
- **S3-FR-011**: Quantity and unit amount MUST remain optional and descriptive, MUST
  support at least six decimal places when present, and MUST NOT replace or silently
  overwrite the authoritative Line amount.
- **S3-FR-012**: When both descriptive quantity and unit amount are present, the user
  MUST be warned before saving if their half-up two-decimal product differs from the
  authoritative amount.
- **S3-FR-013**: An Estimate amount MUST be non-negative. An Actual amount MAY be
  negative only for a reimbursement, credit, or correction and MUST require a note.
  A new manual zero Line MUST require an explicit reason.
- **S3-FR-014**: An Actual MUST NOT belong to an Exercise later than the year of its
  technical registration as evaluated in the company's time zone; within the same
  year the user's annual declaration is authoritative.
- **S3-FR-015**: Active Lines alone MUST contribute to calculations. For an active
  autonomous Expense, current allocation MUST equal the decimal sum of active
  Estimate amounts, actual MUST equal the decimal sum of active Actual amounts, and
  operational variance MUST equal actual minus current allocation.
- **S3-FR-016**: Company Exercise totals MUST count every active autonomous Expense
  exactly once and MUST NOT count its Lines again as separate top-level sources.
- **S3-FR-017**: Actual presence MUST mean at least one non-zero active Actual Line;
  it MUST remain true when signed Actual Lines net to zero, and MUST be false for
  zero-valued or annulled Actual Lines alone.
- **S3-FR-018**: S3 MUST NOT create any Estimate-to-Actual, Actual-to-contract-cycle,
  or Actual-to-carryover matching, consumption state, FIFO/LIFO rule, or automatic
  reconciliation between similar Expenses.
- **S3-FR-019**: Authorized users MUST be able to add and modify Lines and to annul or
  restore persisted Lines in an open Exercise, preserving their identities and
  history; Line mutation on a reversed Expense MUST be rejected until restoration.
- **S3-FR-020**: An autonomous Expense MAY change year only between two open Exercises
  of the same company, as one atomic whole including every Line; an Expense with
  Actuals additionally requires a reason and MUST NOT move to a future year.
- **S3-FR-021**: Before changing an Expense's Exercise, supplier, or direct Cost
  Center, the system MUST present the affected old and new yearly allocation and
  actual values and then apply the confirmed change atomically.
- **S3-FR-022**: An active supplier or Cost Center MAY be selected only within the
  same company. An archived identity MUST remain readable on an existing Expense but
  MUST NOT be selectable for new ordinary assignment until restored.
- **S3-FR-023**: A persisted Expense with no non-zero active Actual Line MAY be
  reversed in an open Exercise with a mandatory reason, excluded from current
  calculations, and restored with a mandatory reason while the Exercise remains
  open; an Expense with Actual presence MUST NOT be reversed.
- **S3-FR-024**: Repeated no-op annul, restore, reverse, or restore-Expense requests
  MUST NOT append misleading duplicate transition events.
- **S3-FR-025**: Every mutating command MUST be idempotent by operation identity or an
  equivalent mechanism and MUST atomically persist both the complete domain change
  and its Timeline event; retry MUST NOT duplicate a Line, Expense, Exercise, or
  event.
- **S3-FR-026**: Every successful S3 mutation MUST append a typed event to the shared
  company Timeline with the complete canonical envelope: actor, company, object,
  affected Exercises, operation, effective date or interval, materialized previous
  and new values, allocation and actual impact per Exercise, reason when required,
  relevant references, and operation identity.
- **S3-FR-027**: S3 Timeline events MUST be append-only, newest-first when viewed,
  and readable only inside the current company; current state MUST continue to be
  read from live objects rather than reconstructed from Timeline replay.
- **S3-FR-028**: All money calculations MUST use decimal arithmetic in EUR, net of
  VAT, with authoritative values rounded to exactly two decimals; S3 MUST NOT expose
  VAT, multi-currency, exchange conversion, or fiscal-period Exercises.
- **S3-FR-029**: S3 MUST NOT introduce Forecast, Preventivo, Imprevista, Plafond, or
  Rettifica as Line types, Expense types, flags, states, or dedicated reports.
- **S3-FR-030**: S3 MUST NOT implement Projects, Contracts, Budgets, Proposals,
  Reviews, carryover, reprogramming, closing, late closed-year corrections,
  classifications of Project/Contract by year, or full reporting.

### Key Entities

- **Exercise**: A company-owned calendar year with stable identity and open/closed
  state; S3 creates and works only with open Exercises.
- **Expense**: A stable first-level economic source in S3, manually created,
  autonomous, assigned to one Exercise, optionally referencing a supplier and direct
  Cost Center, and active or reversed.
- **Line**: A stable Estimate or Actual fact belonging to one Expense, with an
  authoritative two-decimal amount, optional descriptive calculation fields, note,
  and active/annulled state.
- **OriginKey**: The comparison identity composed from source type and stable source
  ID; it is not derived from descriptive or economic values.
- **Impact Plan**: The before-save explanation of affected Exercises, sources,
  allocation, and actual values for a move or reclassification.
- **Timeline Event**: The existing immutable company audit record, now carrying real
  per-Exercise economic impacts for S3 mutations.

## Success Criteria

- **SC-001**: An authorized user can create an open Exercise and an autonomous
  Expense with at least one valid Line in under three minutes.
- **SC-002**: Across all calculation tests, allocation, actual, and operational
  variance equal the exact signed decimal sums of active authoritative Line amounts,
  with zero double counting.
- **SC-003**: Every tested net-zero pair of non-zero Actual Lines reports Actual
  presence, while every tested set containing only zero or annulled Actual Lines does
  not.
- **SC-004**: Every invalid future Actual, negative Estimate, negative Actual without
  a note, simultaneous Project/Contract membership, cross-company reference, and
  reversal with Actual presence is rejected with zero partial economic effect.
- **SC-005**: Every tested real mutation produces exactly one complete append-only
  company-scoped Timeline event; every tested retry and no-op produces no duplicate
  object or event.
- **SC-006**: Moving an Expense between two open Exercises changes both yearly totals
  as one complete operation in every test, and a forced failure leaves both years
  unchanged.
- **SC-007**: An authorized user can identify in under one minute why an Expense's
  allocation or actual total changed by following its Timeline events and per-year
  impacts.
- **SC-008**: The complete S3 demonstration works without a Budget, Project,
  Contract, carryover, closing, Forecast, or annual Project/Contract classification
  and exposes none of those future-slice controls.

## Assumptions

- S1 company tenancy, `visualizza`, `modifica_operativita`, time zone, and shared
  append-only Timeline remain the access and audit foundation.
- S2 suppliers and Cost Centers remain stable, company-owned identities; active
  scopes are used for new choices and archived identities remain resolvable for
  existing references.
- A past Exercise may be created open because the canonical domain restricts the
  timing of closing, not the timing of initial creation; S3 does not close it.
- Exercise, Expense, and initial Line creation are presented as one coherent journey,
  but an Exercise never automatically creates economic sources.
- A reason on a new zero-valued Line satisfies the canonical instruction that such a
  manual Line should not be created without a purpose; zero remains economically
  neutral.
- Optional Expense/Line attachments do not yet require uploaded evidence for the S3
  independent demonstration. Their storage lifecycle is introduced only when a
  bounded slice requires actual evidence, without inventing removal or historical
  versioning behavior here.

## Clarifications

No critical product ambiguity requires user input for this bounded slice. Canonical
rules determine year state, line types, signed amounts, calculations, matching,
Storno, move constraints, company permissions, and Timeline content. Cases owned by
Projects, Contracts, Budgets, Closing, and late corrections are excluded rather than
guessed.

## Domain Traceability

- Canonical FR-001–FR-008 — live reality, two Line types, authoritative amount, no
  matching, exclusive Expense membership, first-level sources, no double counting,
  and OriginKey (§§5.1–5.6, 7.2, 8.1, 8.6).
- Canonical FR-031–FR-033 — multiple open Exercises and authoritative annual Actuals
  with no future-year Actual (§§6.4, 11.1, 11.3).
- Canonical FR-046–FR-047 and FR-050–FR-054 — Expense structure, autonomous source
  identity, year/container rules, integral reclassification, Storno, and prohibited
  special types (§15).
- Canonical FR-084 — complete explanatory append-only Timeline (§22), extending the
  S1/S2 shared event envelope rather than creating a parallel audit mechanism.
- Canonical FR-098 and FR-101 — calendar year, EUR net of VAT, decimal precision, and
  Actual presence independent from net total (§§4.3, 6.4).
- Canonical invariants 28.1–28.7, 28.10, 28.52–28.54, and 28.61.
- Canonical implementation constraints for impact plans, consistency, atomicity,
  idempotency, company time zones, and domain messages (§§30.4, 30.10–30.14).

## Category A–E Reconciliation

- A narrative cause such as quote, emergency, reimbursement, or credit is represented
  by the existing Expense, Line, and Note primitives: categories A–C, with no new
  economic type.
- Similar Expenses, external-document duplication, VAT, multi-currency, accounting
  competence, monthly Actuals, and automatic reconciliation are explicitly outside
  the model: category D.
- Project and Contract containers are canonical but belong to S4/S5. S3 preserves
  their exclusive-membership boundary without exposing incomplete container
  workflows.
- Optional attachment lifecycle is not needed for the bounded S3 demonstration and
  is not invented; notes and Timeline provide the required explanations in S3.
- No category-E structural gap was found for the S3 operations exposed here.
