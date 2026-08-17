# Data Model: Master Data

**Date:** 2026-08-17  
**Database:** MySQL 8.4 family

## Supplier

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary, stable, never reused by ordinary operations |
| `company_id` | foreign key | Required owner Company; restrictive deletion |
| `legal_name` | string | Required, trimmed, maximum 255 characters; not unique |
| `vat_number` | nullable string | Optional informative value, maximum 64 characters; not unique |
| `notes` | nullable text | Optional narrative information |
| `archived_at` | nullable timestamp | Null means active; UTC timestamp means archived |
| `created_at` | timestamp | Required technical creation time |
| `updated_at` | timestamp | Current live-state modification time |

Relationships:

- belongs to exactly one Company;
- has zero or more Contacts;
- is referenced by zero or more audit events through the event subject envelope.

Database/index rules:

- index (`company_id`, `archived_at`);
- index (`company_id`, `legal_name`);
- no unique constraint on legal name or VAT number;
- no ordinary delete path; the Company foreign key is restrictive.

Queryable states:

- `active`: `archived_at IS NULL`;
- `archived`: `archived_at IS NOT NULL`;
- historical/direct resolution: no active-only global scope.

## SupplierContact

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary, stable, not deleted by S2 operations |
| `supplier_id` | foreign key | Required owner Supplier; restrictive deletion |
| `first_name` | nullable string | Optional, maximum 255 characters |
| `last_name` | nullable string | Optional, maximum 255 characters |
| `phone` | nullable string | Optional descriptive telephone, maximum 64 characters |
| `email` | nullable string | Optional syntactically valid email, maximum 255 characters |
| `notes` | nullable text | Optional narrative information |
| `role_tags` | JSON array | Required storage value; zero or more optional strings |
| `created_at` | timestamp | Required technical creation time |
| `updated_at` | timestamp | Current live-state modification time |

Relationships:

- belongs to exactly one Supplier;
- derives Company ownership only through that Supplier;
- has no independent Archive, restore, delete, primary, or hierarchy relation in S2.

Database/index rules:

- index `supplier_id` through the foreign key;
- no unique constraint on names, email, telephone, or role tags;
- Supplier deletion is restrictive and S2 exposes no Contact deletion.

## CostCenter

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary, stable, never reused by ordinary operations |
| `company_id` | foreign key | Required owner Company; restrictive deletion |
| `name` | string | Required denomination, trimmed, maximum 255 characters; not unique |
| `archived_at` | nullable timestamp | Null means active; UTC timestamp means archived |
| `created_at` | timestamp | Required technical creation time |
| `updated_at` | timestamp | Current live-state modification time |

Database/index rules:

- index (`company_id`, `archived_at`);
- index (`company_id`, `name`);
- no unique constraint on denomination;
- no annual-classification or economic foreign key is introduced in S2.

## AuditEvent extension

The existing append-only `audit_events` table gains:

| Field | Type | Rules |
|---|---|---|
| `operation_id` | nullable UUID string | Null for existing S1 rows; required and unique for every S2 Action event |

The existing model gains S2 event values:

- `supplier_created`;
- `supplier_updated`;
- `supplier_archived`;
- `supplier_restored`;
- `supplier_contact_created`;
- `supplier_contact_updated`;
- `cost_center_created`;
- `cost_center_renamed`;
- `cost_center_archived`;
- `cost_center_restored`.

For each event:

- `company_id` is the exact Company owner;
- `subject_type` and `subject_id` identify Supplier, Contact, or Cost Center;
- `affected_exercise_ids`, `allocated_impact_by_exercise`, and
  `actual_impact_by_exercise` are explicit empty collections;
- `effective_from` is the operation date in the Company's current time zone;
- `previous_value` and `new_value` materialize the relevant descriptive/archive
  state;
- `reason` and canonical reference fields remain null because S2 defines no mandatory
  reason or related Proposal/Budget/Closing reference.

## Validation rules

### Supplier input

- legal name: required non-blank string, maximum 255;
- VAT number: nullable string, maximum 64, no format/deduplication inference;
- notes: nullable string;
- company is selected from the current authorized tenant, never from form input.

### Contact input

- all descriptive fields are optional;
- email, when present, uses ordinary email syntax validation;
- role tags accept zero or more optional strings and no mandatory/closed role list;
- supplier is the already-authorized parent, never a free company/supplier selector.

### Cost Center input

- denomination: required non-blank string, maximum 255;
- company is selected from the current authorized tenant, never from form input;
- no uniqueness, hierarchy, split, Exercise, or monetary input.

## Mutation transitions

### Create Supplier or Cost Center

```text
authorized Company + validated input + new operation_id
  -> create stable company-owned row
  -> append one complete creation event
```

The row and event commit or roll back together. Retrying the same `operation_id`
returns the existing subject.

### Update Supplier, Contact, or Cost Center

```text
locked current row + exact-company authorization + validated requested values
  -> no differences: no-op, no event
  -> real differences: update row + append one complete event
```

Retrying the same successful `operation_id` does not apply or log again.

### Archive/restore Supplier or Cost Center

```text
active --Archive--> archived
archived --restore--> active
```

- requested current state already effective: no-op, no event;
- transition preserves identity and all descriptive/related data;
- transition and event commit or roll back together;
- physical deletion is rejected independently of state.

## Ownership invariants

- Supplier and Cost Center each carry exactly one `company_id`.
- Contact company ownership is `contact.supplier.company_id`; no second potentially
  divergent `company_id` is stored.
- Every action derives and locks the Company through the affected record before
  authorization and audit.
- Filament direct record resolution is tenant scoped; Actions remain protected even
  when called without Filament.
