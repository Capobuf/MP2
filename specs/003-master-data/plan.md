# Implementation Plan: Master Data

**Branch:** `003-master-data` | **Date:** 2026-08-17 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/003-master-data/spec.md`

## Summary

S2 adds company-owned Supplier, Contact, and Cost Center master data. Authorized
users create and maintain records, archive/restore Suppliers and Cost Centers without
physical deletion, and inspect the resulting events in the existing company
Timeline. Archived records stay directly resolvable for future historical uses while
ordinary active selectors exclude them.

The implementation uses tenant-scoped Filament Resources, a Supplier relation
manager for Contacts, Laravel policies, direct Eloquent models, and explicit
transactional Actions for every mutation. It extends the existing append-only audit
envelope and adds no package, generic service layer, soft-delete semantics, annual
classification, or economic behavior.

## Technical Context

**Language/Version:** PHP 8.3  
**Primary Dependencies:** Laravel 13, Filament 5; no new package  
**Storage:** MySQL 8.4 family; persistent `mp2`, isolated `testing`  
**Testing:** Pest 4 feature/model tests and Filament/Livewire resource tests, using
the existing test-database guard  
**Target Platform:** Linux-hosted server-rendered web application through Laravel
Sail  
**Project Type:** Single Laravel/Filament tenant-aware web application  
**Performance Goals:** Tenant-scoped active/archive lists and ordinary master-data
mutations complete in one normal request with indexed company/archive lookups  
**Constraints:** Exact-company isolation, `gestisce_anagrafiche` mutation boundary,
append-only complete audit, atomic mutation plus audit, stable identities, no
physical deletion, no implicit uniqueness, no S3+ behavior, no frontend build  
**Scale/Scope:** Two top-level resource types, one nested Contact collection, four
resource journeys, and one existing Timeline surface; no production scale claim

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain is sole functional authority | PASS — the scope follows FR-082/083, §§20–22 and §§24.1–24.3; absent Contact removal/archive behavior is not invented. |
| Category A–E discipline | PASS — duplicate descriptive values are A/B, extra narrative data is C, excluded fiscal/hierarchy behavior is D, and no E gap blocks the bounded operations. |
| Smallest proportional design | PASS — two native Resources, one relation manager, direct models/policies, and explicit Actions; no plugin or generic service layer. |
| Slice boundary | PASS — Cost Centers receive identity and Archive only; annual classification and every economic source remain absent. |
| Dependency boundary | PASS — no new dependency and no modification of `vendor/`, `node_modules/`, or installed source. |
| Explicit domain operations | PASS — create/update/Archive/restore mutations and audit are owned by transactional Actions, not only Filament callbacks. |
| Historical and transactional integrity | PASS — Archive is non-economic, rows remain resolvable, audit is append-only, and retries/no-ops do not duplicate transitions. |
| Forward-only migrations | PASS — S2 adds new migrations and extends `audit_events`; no applied migration is rewritten. |
| Proportional tests | PASS — cross-company rejection, deletion rejection, atomic rollback, no-op/retry, Archive/restore identity, and Timeline coverage are planned. |
| Persistent development safety | PASS — tests use only `testing`; persistent validation uses forward migration and no destructive reset. |

## Project Structure

### Documentation (this feature)

```text
specs/003-master-data/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── ui.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/MasterData/
│   ├── CreateSupplier.php
│   ├── UpdateSupplier.php
│   ├── SetSupplierArchived.php
│   ├── CreateSupplierContact.php
│   ├── UpdateSupplierContact.php
│   ├── CreateCostCenter.php
│   ├── RenameCostCenter.php
│   └── SetCostCenterArchived.php
├── Filament/Resources/
│   ├── Suppliers/
│   │   ├── SupplierResource.php
│   │   ├── Pages/
│   │   ├── RelationManagers/ContactsRelationManager.php
│   │   ├── Schemas/SupplierForm.php
│   │   └── Tables/SuppliersTable.php
│   └── CostCenters/
│       ├── CostCenterResource.php
│       ├── Pages/
│       ├── Schemas/CostCenterForm.php
│       └── Tables/CostCentersTable.php
├── Models/
│   ├── Supplier.php
│   ├── SupplierContact.php
│   └── CostCenter.php
└── Policies/
    ├── SupplierPolicy.php
    ├── SupplierContactPolicy.php
    └── CostCenterPolicy.php

database/
├── factories/
│   ├── SupplierFactory.php
│   ├── SupplierContactFactory.php
│   └── CostCenterFactory.php
└── migrations/
    ├── *_create_suppliers_table.php
    ├── *_create_supplier_contacts_table.php
    ├── *_create_cost_centers_table.php
    └── *_add_operation_id_to_audit_events_table.php

tests/Feature/MasterData/
├── MasterDataBoundaryTest.php
├── SupplierTest.php
├── SupplierContactTest.php
├── CostCenterTest.php
├── ArchiveRestoreTest.php
├── MasterDataAuditTest.php
├── SupplierResourceTest.php
└── CostCenterResourceTest.php
```

**Structure Decision:** Keep the existing single application. Top-level master data
uses Filament's tenant-scoped Resources because the installed framework already
scopes resource queries and direct record URLs to the current Company. Contacts use a
Supplier relation manager because they have no independent company-level lifecycle.
Actions remain the mutation/transaction boundary and policies plus tenant scoping
form the read/UI boundary. No repository, generic CRUD service, plugin, or global
Archive scope is introduced.

## Design Decisions

### Tenant ownership and direct-route isolation

- `Supplier` and `CostCenter` each belong directly to `Company`; `Company` exposes
  the inverse relationships Filament tenancy requires.
- Filament tenant scoping remains enabled for both Resources, covering lists and
  direct record resolution.
- A Contact belongs only to its Supplier and is managed within that already-scoped
  parent; every Contact action verifies the locked parent Supplier belongs to the
  exact Company authorized for the actor.
- Resource visibility/read requires `visualizza`; create, edit, Archive, restore, and
  Contact mutation require `gestisce_anagrafiche` for the current Company.
- Actions re-check authorization after locking the affected record, so a stale open
  form cannot bypass a capability revocation.

### Archive rather than soft delete

- Suppliers and Cost Centers use nullable UTC `archived_at` properties, not Laravel
  SoftDeletes: Archive is a visible domain property, not delayed physical deletion.
- No global query scope hides archived rows. Explicit `active()` scopes support
  future ordinary selectors, while direct identity queries continue to resolve
  archived historical references.
- Resource lists default to active records and provide explicit active/archived/all
  filters. Archived records remain viewable and restorable.
- Model deletion hooks, absent delete UI actions, and restrictive foreign keys reject
  ordinary physical deletion. Contacts likewise expose no delete action.

### Contact representation

- Contacts remain subordinate records with optional scalar details and a JSON array
  of optional free role tags.
- The example roles in §21.2 are suggestions, not a closed enum. No tag is required or
  inferred, and no separate role/hierarchy model is created.
- S2 supports Contact create/update only. It does not silently define delete,
  Archive, restore, reorder, primary-contact, or hierarchy semantics.

### Transactional mutation, audit, and retry identity

- Each Action validates input, opens one database transaction, locks the current
  Company/record as applicable, re-authorizes, applies one real transition, and
  appends one complete `AuditEvent`.
- S2 extends `AuditEventType` with explicit Supplier, Contact, Cost Center, Archive,
  and restore event cases; the existing Company Timeline displays them.
- S2 events retain the complete §22.2 envelope with empty Exercise and economic
  impact collections and the Company-local effective date.
- A new nullable unique audit `operation_id` records the technical mutation token.
  Existing S1 events remain valid with null. Every S2 mutation supplies a UUID; a
  retry with the same UUID returns the already-recorded outcome without applying or
  logging the transition again.
- A different UUID that requests an already-effective update or Archive state is a
  successful no-op with no audit event.
- A persistence failure for the audit event rolls back the master-data mutation.

### Roadmap reconciliation

- FR-082 remains S2's primary canonical requirement. S2 supplies the archived-
  Supplier foundation of FR-083; S10 owns its first complete historical and
  late-correction verification.
- The first authoritative Archive/no-delete/stable-identity tests occur in S2 for
  Suppliers and Cost Centers. Roadmap rows FR-009/010 and invariants 28.44–28.46 are
  reassigned to S2 as the first implementing slice; later economic slices extend the
  same rules to their own entities.
- FR-079–081 and invariants 28.42–28.43 remain planned until S5 completes annual
  classification and inheritance for both Project and Contract sources.
- FR-084 remains owned by S3 for the first economic Timeline, while S2 incrementally
  adds the master-data events mandated by §22.6.

## Post-Design Constitution Check

PASS. The Phase 1 design preserves every pre-research gate. Native tenant scoping is
paired with application Actions that lock and re-authorize exact-company mutations;
Archive never becomes delete; historical lookup does not depend on active selection;
all migrations are forward-only; and no S3 entity or economic behavior is introduced.

## Complexity Tracking

No constitution violation requires justification.
