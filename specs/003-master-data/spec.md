# Feature Specification: Master Data

**Feature Branch:** `003-master-data`  
**Created:** 2026-08-17  
**Status:** Draft  
**Roadmap ID:** S2

## User Scenarios & Testing

### User Story 1 — Manage suppliers (Priority: P1)

As a user responsible for company master data, I can register and maintain suppliers
so that later expenses and contracts can refer to stable, company-owned identities.

**Why this priority:** Suppliers are a direct prerequisite for the expense and
contract slices, and their identity must already respect company isolation and
historical continuity.

**Independent Test:** In one company, create a supplier with only its required legal
name, then add an optional VAT number and notes, rename it, and verify that the same
stable supplier identity remains available.

**Acceptance Scenarios:**

1. **Given** a user with `gestisce_anagrafiche` for the selected company, **When** the
   user enters a legal name and optionally a VAT number and notes, **Then** one
   supplier belonging only to that company is created.
2. **Given** two suppliers in the same company, **When** they have the same legal
   name or the same optional VAT number, **Then** both records are accepted because
   neither value is a canonical unique identity.
3. **Given** an existing supplier, **When** its legal name, VAT number, or notes are
   changed, **Then** its stable identity remains unchanged and the change is audited.
4. **Given** a user lacking `gestisce_anagrafiche` in the selected company, **When**
   the user attempts to create or modify a supplier, **Then** the operation is
   rejected without changing the supplier or its history.

---

### User Story 2 — Maintain supplier contacts (Priority: P1)

As a master-data manager, I can add and update optional contacts for a supplier,
including truthful optional role tags, so that useful contact information is
available without forcing invented roles.

**Why this priority:** Contacts are part of canonical supplier master data and must
remain subordinate to the correct supplier and company.

**Independent Test:** Add two contacts to one supplier, leave all role tags absent on
one contact, assign several descriptive role tags to the other, update their details,
and verify each change appears in the supplier history.

**Acceptance Scenarios:**

1. **Given** an existing supplier, **When** an authorized user adds a contact, **Then**
   the contact belongs to exactly that supplier and can contain optional name,
   surname, telephone, email, notes, and zero or more optional role tags.
2. **Given** a contact for which no role is known, **When** it is saved without role
   tags, **Then** the system accepts it and does not assign a default or inferred
   role.
3. **Given** an existing contact, **When** its details or role tags change, **Then**
   the new values are saved and the change is audited without replacing the supplier
   identity.
4. **Given** a supplier belonging to another company, **When** a user attempts to add
   or modify one of its contacts through a direct URL or crafted request, **Then** the
   operation is rejected and no cross-company data is disclosed or changed.

---

### User Story 3 — Manage cost-center identities (Priority: P2)

As a master-data manager, I can register and rename cost centers so that later annual
classifications can refer to stable company-owned identities.

**Why this priority:** Cost centers are needed before annual classification is built,
but S2 only establishes their master-data identity and lifecycle.

**Independent Test:** Create and rename a cost center, create another with the same
name, and verify both retain distinct stable identities within the selected company.

**Acceptance Scenarios:**

1. **Given** a user with `gestisce_anagrafiche` for the selected company, **When** the
   user supplies a denomination, **Then** one cost center belonging only to that
   company is created.
2. **Given** two cost centers with the same denomination, **When** both are saved,
   **Then** both remain valid distinct identities because the canonical domain does
   not make the denomination unique.
3. **Given** an existing cost center, **When** it is renamed, **Then** its stable
   identity remains unchanged and the rename is audited.
4. **Given** S2 has no Exercises or economic sources, **When** a cost center is viewed
   or edited, **Then** no annual classification, amount, allocation, split, hierarchy,
   or economic behavior is offered.

---

### User Story 4 — Archive and restore master data (Priority: P2)

As a master-data manager, I can archive and restore suppliers and cost centers while
preserving their identities and history, so obsolete choices do not appear as
ordinary new selections and past references remain representable.

**Why this priority:** Archive is the canonical non-destructive lifecycle for these
master-data records; physical deletion would break future historical behavior.

**Independent Test:** Archive and restore one supplier and one cost center, verify
their stable identities never change, verify archived records remain inspectable in
an explicit archive view, and prove no delete operation is available.

**Acceptance Scenarios:**

1. **Given** an active supplier or cost center, **When** an authorized user archives
   it, **Then** it is excluded from ordinary active lists and future ordinary
   selections but remains inspectable with the same identity and complete history.
2. **Given** an archived supplier or cost center, **When** an authorized user restores
   it, **Then** it becomes selectable again with the same identity and no historical
   value is rewritten.
3. **Given** a persisted supplier, contact, or cost center, **When** a user accesses
   the ordinary UI or sends a crafted deletion request, **Then** physical deletion is
   unavailable or rejected.
4. **Given** an archived supplier, **When** a later slice needs to display a historical
   reference or preserve it for a late correction, **Then** the archived identity is
   still resolvable; S2 does not create the later economic object itself.
5. **Given** any archive or restore operation, **When** it succeeds, **Then** exactly
   one append-only company-scoped audit event records the real transition.

---

### User Story 5 — Inspect master-data history (Priority: P2)

As an authorized company viewer, I can inspect supplier, contact, cost-center,
archive, and restore events in the existing company Timeline so master-data changes
are explainable.

**Why this priority:** Canonical section 22.6 requires these changes to be auditable,
and S1 already established the shared append-only event envelope.

**Independent Test:** Create, rename, archive, and restore master data and modify a
contact, then verify the company Timeline shows distinct immutable events scoped only
to that company.

**Acceptance Scenarios:**

1. **Given** a successful create, rename, archive, restore, contact, or role-tag
   mutation, **When** the company Timeline is opened, **Then** it contains a typed
   event with actor, company, affected object, operation, effective date, previous
   and new values, and explicit empty Exercise and economic-impact collections.
2. **Given** a no-op submission, **When** no stored master-data value changes, **Then**
   no misleading change event is appended.
3. **Given** a viewer authorized only for Company A, **When** the Timeline is viewed,
   **Then** no Company B master-data event is disclosed.

### Edge Cases

- A supplier is created without a legal name, or a cost center without a
  denomination.
- Supplier legal names, VAT numbers, or cost-center denominations are duplicated.
- A VAT number, contact email, telephone, note, or role tag is absent.
- A contact has no role tag; no role is inferred.
- An archive or restore request is repeated after the desired state is already
  effective.
- A user loses `gestisce_anagrafiche` between opening and submitting a form.
- A user guesses a supplier, contact, or cost-center URL belonging to another
  company.
- Two authorized users concurrently rename or archive the same record.
- An archived record is requested explicitly for historical display.
- A crafted request attempts physical deletion.

## Requirements

### Functional Requirements

- **S2-FR-001**: Every supplier, contact, and cost center MUST belong to exactly one
  company and MUST NOT be shared with another company.
- **S2-FR-002**: Only a user with `gestisce_anagrafiche` for the affected company MUST
  be able to create or mutate that company's suppliers, contacts, and cost centers.
- **S2-FR-003**: A supplier MUST have a stable, non-reused identity and a required
  legal name, and MAY have an optional VAT number and notes.
- **S2-FR-004**: Supplier legal names MUST NOT be treated as implicit identities or
  made unique unless a future canonical decision explicitly requires it.
- **S2-FR-005**: A supplier VAT number MUST remain optional, informative, and not
  obligatorily unique.
- **S2-FR-006**: A supplier MUST support zero or more contacts belonging to that
  supplier.
- **S2-FR-007**: A contact MUST support optional name, surname, telephone, email,
  notes, and zero or more optional role tags.
- **S2-FR-008**: Contact role tags MUST NOT be required, defaulted, or inferred; S2
  MUST NOT force the example labels from canonical §21.2 into a closed mandatory
  role taxonomy.
- **S2-FR-009**: S2 MUST support adding and modifying contacts, but MUST NOT invent a
  contact archive, restore, removal, or deletion lifecycle absent from the canonical
  domain.
- **S2-FR-010**: A cost center MUST have a stable, non-reused identity and a required
  denomination belonging to one company.
- **S2-FR-011**: Cost-center denominations MUST NOT be made implicitly unique.
- **S2-FR-012**: S2 cost centers MUST NOT yet classify Exercises, Expenses, Projects,
  or Contracts and MUST NOT generate, split, aggregate, or otherwise change economic
  values.
- **S2-FR-013**: Persisted suppliers and cost centers MUST use Archive rather than
  physical deletion; persisted contacts MUST NOT expose physical deletion in S2.
- **S2-FR-014**: Archiving MUST change visibility and future ordinary selectability
  only and MUST NOT change economic state, history, identity, or any materialized
  historical value.
- **S2-FR-015**: An archived supplier or cost center MUST remain explicitly
  inspectable and resolvable for historical contexts and MUST NOT be offered for new
  ordinary selections until restored.
- **S2-FR-016**: Restoring a supplier or cost center MUST preserve its identity and
  history and make it available again for ordinary selection.
- **S2-FR-017**: Create, rename, Archive, restore, contact-detail, and role-tag changes
  MUST append typed audit events to the existing company Timeline as required by
  canonical §22.6.
- **S2-FR-018**: Every S2 audit event MUST use the complete §22.2 envelope established
  in S1, including explicit empty affected-Exercise and per-Exercise Allocato and
  Effettivo impact collections because S2 has no economic impact.
- **S2-FR-019**: Master-data mutations MUST re-check exact-company authorization at
  submission time and MUST be atomic with their audit events.
- **S2-FR-020**: A no-op mutation or repeated Archive/restore request MUST NOT append
  a misleading duplicate transition event.
- **S2-FR-021**: Read and mutation interfaces MUST prevent disclosure or mutation of
  supplier, contact, cost-center, and audit data through guessed cross-company URLs
  or crafted requests.
- **S2-FR-022**: S2 MUST NOT implement annual cost-center classification, expenses,
  projects, contracts, historical corrections, proposals, budgets, closings,
  reporting, or any S3+ economic behavior.
- **S2-FR-023**: S2 MUST NOT introduce physical deletion, supplier deduplication,
  contact hierarchies, mandatory contact roles, additional supplier fiscal fields,
  cost-center hierarchies, or percentage allocations.

### Key Entities

- **Supplier**: A company-owned stable identity with required legal name, optional
  informative VAT number and notes, zero or more contacts, and active/archived
  visibility.
- **Contact**: Optional descriptive contact information belonging to exactly one
  supplier, with zero or more truthful optional role tags and no S2 deletion or
  archive lifecycle.
- **Cost Center**: A company-owned stable classification identity with a denomination
  and active/archived visibility; annual classification begins in a later slice.
- **Audit Event**: The existing immutable company Timeline record, extended with
  typed master-data operations without creating a parallel history mechanism.

## Success Criteria

- **SC-001**: An authorized user can create a supplier and a cost center, each with
  only its canonical required data, in under two minutes per record.
- **SC-002**: Across at least two companies, all supplier, contact, cost-center, and
  Timeline read/mutation tests show zero cross-company disclosure or authorization
  leakage.
- **SC-003**: Duplicate supplier names, duplicate optional VAT numbers, and duplicate
  cost-center denominations remain representable as distinct stable identities in
  every tested case.
- **SC-004**: Every tested real master-data transition produces exactly one complete
  company-scoped audit event, while every tested no-op produces zero change events.
- **SC-005**: An authorized user can archive and restore a supplier or cost center in
  under one minute while preserving the same identity and full history.
- **SC-006**: All tested physical-deletion attempts for persisted S2 entities are
  unavailable or rejected, with zero lost records and zero lost audit events.
- **SC-007**: The complete S2 demonstration can be performed without creating an
  Exercise or any economic source and without exposing functionality owned by S3 or
  later slices.

## Assumptions

- S1 authentication, company tenancy, exact-company capabilities, settings, and
  append-only Timeline remain the authorization and audit foundation.
- `visualizza` remains required to enter and inspect a company; management capability
  does not silently grant tenant visibility.
- Names and descriptive values are trimmed for ordinary input quality, but no
  canonical uniqueness or deduplication rule is inferred.
- Archived master data is hidden from ordinary active lists by default and available
  through an explicit archived/all filter for inspection and restoration.
- S2 establishes queryable active-versus-archived semantics that later slices can use;
  it does not create the later economic references used to demonstrate FR-083 fully
  end to end.
- Contact removal, contact Archive, and contact restore are deliberately absent from
  S2 because the canonical domain defines no such lifecycle. Existing contacts can
  be corrected by editing their optional descriptive fields and role tags.

## Clarifications

No critical ambiguity requires a product decision for the bounded S2 scope. The
absence of a canonical contact deletion/archive lifecycle is handled by not exposing
one, rather than inventing behavior.

## Domain Traceability

- Canonical FR-082 — Supplier and Contacts (§21).
- Canonical FR-083 — archived Supplier remains usable in historical contexts
  (§§21.4, 24.6).
- Canonical company ownership boundary — §7.4.
- Canonical cost-center identity, rename, Archive, and restore behavior —
  §§20.1, 20.7–20.8, 24.3.
- Canonical master-data Timeline events and append-only semantics — §§22.2, 22.6,
  22.10.
- Canonical no-physical-deletion and Archive behavior — §§5.7–5.8, 24.1–24.3 and
  invariants 28.44–28.46.
- Canonical authorization capability — §§26.5–26.6.
- Canonical exclusions for supplier/contact and cost-center complexity — §§20.10,
  21.5 and 30.15.

## Category A–E Reconciliation

- Duplicate legal names, VAT numbers, and cost-center denominations are supported by
  stable IDs without deduplication: category A/B, no structural change.
- Optional contact role descriptions use the canonical optional role-tag primitive:
  category B, with no mandatory taxonomy.
- Additional narrative supplier/contact data belongs in Notes: category C.
- Fiscal, payment, credential, hierarchy, and percentage-allocation additions are
  outside this slice or canonically excluded: category D.
- No category-E structural gap was found for the operations S2 actually exposes.
  Contact removal/archive would require a new lifecycle decision and is therefore not
  implemented or silently inferred.
