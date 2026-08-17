# Research: Master Data

**Date:** 2026-08-17  
**Status:** Complete — no unresolved technical clarification

## Canonical scope and category-E review

**Decision:** Implement only Supplier identity/details, Contact create/update, Cost
Center identity/rename, Supplier and Cost Center Archive/restore, company isolation,
and required Timeline events.

**Rationale:** FR-082/083 and §§20–24 fully determine these bounded operations. The
canonical document does not define deletion, Archive, restore, primary status, or a
state machine for Contacts. S2 therefore offers none of those operations instead of
choosing an unstated lifecycle.

**Alternatives considered:**

- Add Contact Archive/restore for consistency: rejected because consistency is not a
  domain rule and would invent observable behavior.
- Physically delete Contacts while retaining Suppliers and Cost Centers: rejected
  because §5.7 prohibits ordinary physical deletion of persisted domain objects and
  no Contact exception is defined.
- Treat the missing Contact removal journey as a blocking E gap: rejected for this
  slice because removal is not necessary to satisfy the supported S2 operations. It
  would become a genuine product decision only if removal were required.

## Native tenant-scoped Filament Resources

**Decision:** Use two native Filament 5 Resources for Suppliers and Cost Centers and
one Supplier Contact relation manager.

**Rationale:** Filament tenancy expects an ownership relationship on the resource
model and an inverse relationship on the tenant, and it applies tenant scoping to
resource lists and direct record URL resolution. Relation managers are the native
fit for Contacts that belong to one Supplier. Explicit Actions still own writes and
re-check authorization.

**Alternatives considered:**

- Custom pages and hand-built tables: rejected because native Resources already
  provide the bounded CRUD/list/filter shell.
- Independent Contact Resource: rejected because Contacts are subordinate to one
  Supplier and have no independent company-level lifecycle.
- A master-data plugin: rejected because no package is needed for two ordinary
  Resources and one relation manager.

**References:**

- `https://filamentphp.com/docs/5.x/users/tenancy`
- `https://filamentphp.com/docs/5.x/advanced/security`
- `https://filamentphp.com/docs/5.x/resources/overview`
- `https://filamentphp.com/docs/5.x/testing/testing-resources`

## Archive representation

**Decision:** Use nullable `archived_at` UTC timestamps and explicit active/archive
query scopes; do not use SoftDeletes or a global scope.

**Rationale:** Canonical Archive affects visibility and selectability but is not
deletion. A global scope could accidentally make a historical reference unresolvable.
An explicit timestamp records current state and its technical transition time while
the Timeline remains the authoritative explanation.

**Alternatives considered:**

- Laravel SoftDeletes: rejected because its delete/restore vocabulary misrepresents
  Archive and makes physical purge APIs unnecessarily available.
- Boolean `is_archived`: valid, but a timestamp adds useful technical timing without
  changing canonical behavior.
- Global active-only scope: rejected because FR-083 requires archived Supplier
  identities to remain resolvable for history.

## Contact role tags

**Decision:** Store zero or more optional free role tags as a JSON array on the
Contact.

**Rationale:** Canonical §21.2 uses optional plural tags and gives examples rather
than a closed enumeration. JSON preserves the simple bounded data without a role
entity or mandatory taxonomy.

**Alternatives considered:**

- Enum with Commerciale/Tecnico/Amministrativo/Altro: rejected because the examples
  are not a closed canonical set.
- Role and pivot tables: rejected as disproportionate and suggestive of a hierarchy
  the domain excludes.
- Single role string: rejected because the canonical wording permits multiple tags.

## Explicit Actions and policies

**Decision:** Use small entity-specific Actions for create/update/Archive/restore and
Laravel policies for model UI authorization.

**Rationale:** Every mutation must remain atomic with audit and re-authorized inside
the transaction. Entity-specific Actions keep validation and canonical outcomes
visible without a generic CRUD service. Policies make read and UI affordances
consistent, while the Actions remain the security boundary for writes.

**Alternatives considered:**

- Resource callbacks as the only mutation implementation: rejected because direct
  tests and non-UI callers must share the same atomic domain operation.
- Generic repository/service layer: rejected because three direct models and eight
  small operations do not justify it.

## Retry idempotency

**Decision:** Add a nullable unique UUID `operation_id` to the existing audit table
and require it for S2 mutation Actions.

**Rationale:** It provides a compact technical retry key without a new operation
service/table. Existing S1 rows remain valid. Because each S2 Action appends exactly
one event, the event also records the already-applied result that a retry returns.

**Alternatives considered:**

- No-op comparison only: insufficient for retried create requests, which have no
  pre-existing natural identity.
- Generic idempotency/command table: rejected as premature infrastructure.
- Queue serialization: rejected as unnecessary and outside the approved baseline.

## Roadmap invariant reconciliation

**Decision:** Move first primary ownership of FR-009/010 and invariants 28.44–28.46
from S3 to S2 while leaving annual Cost Center invariants in S4.

**Rationale:** S2 is the first slice that persists ordinary domain master data and
demonstrates Archive, rejection of physical deletion, and stable identities. Keeping
their first authoritative test in S3 would leave the S2 archive/restore promise
unmapped. Later slices extend rather than replace these rules.

**Alternatives considered:**

- Leave every cross-cutting Archive/no-delete row in S3: rejected because it obscures
  the first actual implementation and test.
- Move FR-079–081 to S2: rejected because annual classification and inherited Cost
  Center behavior are explicitly outside S2 and remain S4 work.
