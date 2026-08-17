# Feature Specification: Company Access and Settings

**Feature Branch:** `002-company-access-settings`  
**Created:** 2026-08-17  
**Status:** Implemented  
**Roadmap ID:** S1

## User Scenarios & Testing

### User Story 1 — Create a company with explicit settings (Priority: P1)

As an authorized operator, I can create a company with a stable identity, a name,
an explicit IANA time zone, and the required initial settings so that all later MP2
data has one unambiguous company context.

**Why this priority:** Company ownership is the boundary for every later domain
object, permission, date interpretation, and setting.

**Independent Test:** Create one company by supplying its name and time zone, then
open it and verify the three canonical settings have their specified initial values.

**Acceptance Scenarios:**

1. **Given** an authorized operator, **When** the operator
   supplies a name and valid IANA time zone, **Then** one company is created with a
   stable identity, overspend-note requirement disabled, unclassified-at-closing
   policy set to warning, and the selected time zone.
2. **Given** a company creation attempt without a time zone or with an invalid IANA
   identifier, **When** the operator submits it, **Then** creation is rejected with a
   clear validation message and no partial company remains.
3. **Given** a created company, **When** its details are viewed, **Then** no fixed
   domain behavior is presented as a configurable setting.

---

### User Story 2 — Assign capabilities within one company (Priority: P1)

As a user with `gestisce_permessi` for a company, I can assign and revoke the nine
canonical capabilities for beneficiaries in that company, without granting access
to any other company.

**Why this priority:** Per-company authorization is the primary S1 invariant and the
required protection for all later slices.

**Independent Test:** Use two companies and two users, assign one capability in only
one company, prove it is effective there and absent in the other company, then revoke
it and verify the corresponding audit history.

**Acceptance Scenarios:**

1. **Given** a permission manager for Company A and an eligible beneficiary, **When**
   the manager assigns `visualizza` for Company A, **Then** the beneficiary can view
   Company A but receives no capability for Company B.
2. **Given** a beneficiary with a capability for Company A, **When** an authorized
   manager revokes it, **Then** the capability ceases to authorize subsequent actions
   in Company A.
3. **Given** a user without `gestisce_permessi` for Company A, **When** that user tries
   to change Company A capabilities, **Then** the operation is rejected and no
   assignment changes.
4. **Given** a permission manager only for Company A, **When** that user attempts to
   manage Company B permissions, **Then** the operation is rejected even if the same
   beneficiary exists in both companies.
5. **Given** one person has several capabilities, **When** that person prepares or
   later approves an authorized operation, **Then** the system does not impose a
   maker-checker separation.
6. **Given** a permission manager holds the last `gestisce_permessi` assignment in a
   company, **When** that user revokes it from themself, **Then** the revocation is
   allowed and audited and subsequent permission-management attempts are rejected.

---

### User Story 3 — Change company settings prospectively (Priority: P2)

As a user with `gestisce_impostazioni` for a company, I can change its canonical
settings and see the impact before confirming a time-zone change.

**Why this priority:** Later economic workflows depend on explicit, auditable company
settings, but setting changes must never rewrite historical facts.

**Independent Test:** Change each setting, verify its new value applies to subsequent
operations, inspect the audit event, and prove a user authorized only in another
company cannot make the same change.

**Acceptance Scenarios:**

1. **Given** an authorized settings manager, **When** the overspend-note requirement
   changes, **Then** the new boolean value is stored for subsequent operations and an
   audit event records the previous and new values.
2. **Given** an authorized settings manager, **When** the unclassified-at-closing
   policy changes between warning and blocking, **Then** the new value is stored for
   subsequent closing checks and the change is audited.
3. **Given** an authorized settings manager selecting a different valid IANA time
   zone, **When** the change is reviewed, **Then** the system previews its impact on
   events expected on the current local date before confirmation.
4. **Given** the user confirms the reviewed time-zone change, **When** the operation
   completes, **Then** subsequent technical timestamp conversions and local-current-
   date decisions use the new zone while existing economic dates and snapshots remain
   unchanged.
5. **Given** no event-bearing feature exists yet in S1, **When** a time-zone change is
   reviewed, **Then** the preview explicitly reports that no currently representable
   event is affected rather than inventing future event data.
6. **Given** a user without `gestisce_impostazioni` for the selected company, **When**
   that user attempts any setting change, **Then** the operation is rejected and no
   setting or audit state changes.

---

### User Story 4 — Inspect authorization and settings history (Priority: P2)

As an authorized company viewer, I can inspect the append-only history of capability
and setting changes for that company so that access and configuration decisions are
explainable.

**Why this priority:** FR-093 requires the resulting state to be supported by an
auditable record, not merely by current values.

**Independent Test:** Assign and revoke a capability and change a setting, then verify
the company history contains distinct immutable events with all required facts.

**Acceptance Scenarios:**

1. **Given** a capability is assigned or revoked, **When** the company history is
   viewed, **Then** it shows author, beneficiary, company, timestamp, optional reason,
   previous value, and new value.
2. **Given** a setting changes, **When** the company history is viewed, **Then** it
   shows the setting, previous value, new value, author, timestamp, and company.
3. **Given** an existing audit event, **When** a later correction is needed, **Then**
   the original event remains unchanged and a new event represents the later action.
4. **Given** a viewer authorized only for Company A, **When** the viewer inspects
   history, **Then** no Company B event is disclosed.

## Edge Cases

- A company is submitted with a blank, unknown, or malformed IANA time-zone identifier.
- The same capability is assigned twice or revoked when already absent.
- The acting user grants `gestisce_permessi` to themself or revokes their own last
  permission-management capability; the requested change is allowed when the user is
  authorized at submission time and affects subsequent requests.
- A beneficiary has different capabilities in two companies.
- An acting user loses the required capability between opening and submitting a form.
- Two authorized users change the same setting or assignment concurrently.
- A setting is submitted with its current value.
- A time-zone change crosses midnight for the company's local date.
- Audit data contains an optional reason that is blank.
- A user who can manage settings or permissions lacks `visualizza` explicitly.

## Requirements

### Functional Requirements

- **S1-FR-001**: Every company MUST have a stable identity, denomination, mandatory
  valid IANA time zone, the canonical settings, per-company capability assignments,
  and audit history.
- **S1-FR-002**: The S0 platform administrator MUST be able to create the first and
  subsequent companies and MUST receive all nine canonical capabilities for each
  company created.
- **S1-FR-003**: Company creation MUST initialize `nota_sovraspesa_obbligatoria` to
  `false` and `policy_non_classificato_alla_chiusura` to `Avviso`.
- **S1-FR-004**: Company creation MUST require an explicit valid IANA time zone and
  MUST NOT infer a default.
- **S1-FR-005**: The only configurable company settings in S1 MUST be the required
  overspend note, the unclassified-at-closing policy, and the company time zone.
- **S1-FR-006**: The fixed behaviors listed in canonical section 26.4 MUST NOT be
  exposed as settings.
- **S1-FR-007**: The system MUST support, independently for each company, at least
  `visualizza`, `modifica_operativita`, `gestisce_proposte`, `approva_budget`,
  `chiude_esercizio`, `corregge_esercizio_chiuso`, `gestisce_anagrafiche`,
  `gestisce_impostazioni`, and `gestisce_permessi`.
- **S1-FR-008**: A capability assigned for one company MUST NOT imply or propagate
  that capability for any other company.
- **S1-FR-009**: Capability assignment beneficiaries MUST be existing authenticated
  users provisioned through an explicit administrative command; S1 MUST NOT add user
  creation or invitation screens.
- **S1-FR-010**: Only a user with `gestisce_permessi` for the affected company MUST be
  able to assign or revoke that company's capabilities.
- **S1-FR-011**: Only a user with `gestisce_impostazioni` for the affected company MUST
  be able to change that company's settings.
- **S1-FR-012**: A single user MAY hold any combination of capabilities; the system
  MUST NOT require maker-checker separation.
- **S1-FR-013**: A setting change MUST affect subsequent operations only and MUST NOT
  rewrite prior budgets, closings, economic dates, snapshots, or audit events.
- **S1-FR-014**: Before confirming a company time-zone change, the system MUST preview
  the impact on events expected on the current local date.
- **S1-FR-015**: Assigning or revoking a capability MUST create an append-only audit
  event containing author, beneficiary, company, timestamp, optional reason, previous
  value, and new value.
- **S1-FR-016**: Changing a setting MUST create an append-only audit event containing
  the setting, previous value, new value, author, timestamp, and company.
- **S1-FR-017**: Audit visibility MUST be limited to users authorized to view the
  corresponding company.
- **S1-FR-018**: Permission and setting changes MUST re-check authorization when the
  change is submitted and MUST be atomic with their audit event.
- **S1-FR-019**: Repeating an already-effective assignment, revocation, or setting
  value MUST NOT create a misleading state transition or duplicate change event.
- **S1-FR-020**: S1 MUST NOT implement exercises, master data, expenses, projects,
  contracts, proposals, budgets, closings, or reporting behavior owned by later
  roadmap slices.
- **S1-FR-021**: An authorized user MUST be allowed to assign or revoke their own
  capabilities, including their last `gestisce_permessi`; authorization is evaluated
  before the mutation and the new state governs subsequent requests.
- **S1-FR-022**: Every S1 audit event MUST also carry the canonical event dimensions
  from §22.2: affected object type and identity, affected Exercises, operation type,
  effective date or interval, per-Exercise Allocato and Effettivo impact, and any
  applicable reference. S1 events MUST explicitly record empty Exercise and economic
  impact collections rather than omit those dimensions.

### Key Entities

- **Company**: One isolated operating context with stable identity, denomination,
  explicit IANA time zone, settings, permission assignments, and audit history.
- **Company Setting**: One of the three canonical prospective controls belonging to
  exactly one company.
- **Capability Assignment**: The fact that one beneficiary has one canonical
  capability for exactly one company.
- **Audit Event**: An immutable record of a company setting or authorization change,
  including the facts required to explain that change.
- **User**: An authenticated person who may hold different capability assignments in
  different companies; the S1 provisioning boundary awaits clarification.

## Success Criteria

- **SC-001**: An authorized operator can create a correctly configured company in
  under three minutes without relying on an implicit time zone.
- **SC-002**: Across a test set of at least two users and two companies, every one of
  the nine canonical capabilities can be assigned independently with zero observed
  cross-company authorization leakage.
- **SC-003**: Unauthorized permission and setting changes are rejected in every
  tested cross-company and missing-capability case, leaving both current state and
  audit history unchanged.
- **SC-004**: Every successful capability assignment, capability revocation, and
  setting change produces exactly one complete, company-scoped audit event.
- **SC-005**: A settings manager can review and confirm any canonical setting change
  in under two minutes, including the required impact preview for a time-zone change.
- **SC-006**: Existing company history remains unchanged after later setting and
  permission changes, with corrections represented only by later events.
- **SC-007**: A user can demonstrate company creation, isolated permission assignment,
  a company setting change, and the resulting audit history without encountering any
  object or workflow owned by S2 or later.

## Assumptions

- Authentication continues to use the working S0 login; S1 changes authorization,
  company access, and settings rather than replacing authentication.
- The S0 development administrator is the initial platform administrator. Platform
  administration authorizes company creation only; all access inside a company still
  depends on that company's capability assignments.
- Creating a company and granting its creator all nine capabilities is one complete
  operation; a partial company without its initial assignments is not acceptable.
- Additional login users are provisioned by an explicit administrative command with
  their name, unique email, and password before a company permission manager can
  select them as beneficiaries.
- `visualizza` is required to inspect company data and company audit history; holding
  a management capability does not silently grant unrelated capabilities.
- A no-op assignment, revocation, or setting submission is reported as already
  effective and does not create a change event.
- In S1, the time-zone impact preview can truthfully contain no affected planned
  events because later event-bearing domain features do not yet exist.
- Company deletion, company archiving, user-management screens, user deactivation,
  password recovery, MFA, invitations, and external identity providers are outside
  S1.

## Clarifications

### Session 2026-08-17

- Q: Who may create the first and subsequent companies? → A: The S0 platform
  administrator; that administrator receives all nine per-company capabilities on
  each newly created company.
- Q: How are permission beneficiaries provisioned? → A: By a dedicated
  administrative command; S1 assigns capabilities only to existing users and does
  not add user creation or invitation screens.

## Domain Traceability

- Canonical FR-091 — Impostazioni minime per Azienda (§26).
- Canonical FR-092 — Permessi assegnati per Azienda (§§26.5–26.6).
- Canonical FR-093 — Audit di permessi e Impostazioni (§§26.8–26.10).
- Canonical invariant 28.57 — every capability is assigned per company and never
  propagates automatically to another company.
- Company identity and ownership boundary — §7.4.
- Append-only audit semantics — §§22.1–22.2 and §22.10.
