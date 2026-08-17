# Data Model: Company Access and Settings

**Date:** 2026-08-17  
**Database:** MySQL 8.4 family

## User extension

Existing `users` gains:

| Field | Type | Rules |
|---|---|---|
| `is_platform_admin` | boolean | Required, default `false`; authorizes company creation only |

The existing S0 administrator provisioning sets this flag to `true`. The S1 general
user-provisioning command always creates users with it set to `false`.

## Company

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary, stable, never reused |
| `name` | string | Required, trimmed, maximum 255 characters |
| `timezone` | string | Required valid IANA identifier, maximum 64 characters, no implicit default |
| `overspend_note_required` | boolean | Required, default `false` |
| `unclassified_closing_policy` | string | Required closed value: `warning` or `blocking`; default `warning` |
| `created_at` | timestamp | Required technical creation time |
| `updated_at` | timestamp | Current live-state modification time |

Relationships:

- has many capability assignments;
- has many audit events;
- is accessible to users only through a `visualizza` assignment.

Company deletion and archival do not exist in S1.

## CompanyCapability

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary |
| `company_id` | foreign key | Required; cascade is not exposed as an ordinary operation |
| `user_id` | foreign key | Required beneficiary |
| `capability` | string | One canonical `Capability` enum value |
| `created_at` | timestamp | Assignment persistence time |

Database invariants:

- unique (`company_id`, `user_id`, `capability`);
- one row applies to exactly one company;
- absence means the capability is not granted;
- no `updated_at`: assignment changes are insert or delete and are independently
  represented in audit.

Canonical capability values:

1. `visualizza`
2. `modifica_operativita`
3. `gestisce_proposte`
4. `approva_budget`
5. `chiude_esercizio`
6. `corregge_esercizio_chiuso`
7. `gestisce_anagrafiche`
8. `gestisce_impostazioni`
9. `gestisce_permessi`

## AuditEvent

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned integer | Primary, append-only |
| `company_id` | foreign key | Required company scope |
| `actor_id` | foreign key to users | Required author |
| `event_type` | string | Closed S1 event type |
| `subject_type` | string | Required canonical affected-object type |
| `subject_id` | unsigned integer | Required canonical affected-object identity |
| `beneficiary_id` | nullable foreign key to users | Required for capability events |
| `capability` | nullable string | Required for capability events |
| `setting` | nullable string | Required for setting events |
| `affected_exercise_ids` | JSON array | Required; empty in S1 |
| `effective_from` | date | Required company-local operation date |
| `effective_to` | nullable date | End of effectiveness interval when applicable; null in S1 |
| `previous_value` | nullable JSON scalar/object | State immediately before the operation |
| `new_value` | nullable JSON scalar/object | State immediately after the operation |
| `allocated_impact_by_exercise` | JSON object | Required; empty in S1 |
| `actual_impact_by_exercise` | JSON object | Required; empty in S1 |
| `reason` | nullable text | Optional only for capability assignment/revocation in S1 |
| `reference_type` | nullable string | Canonical related-operation reference type when applicable |
| `reference_id` | nullable unsigned integer | Canonical related-operation reference identity when applicable |
| `created_at` | timestamp | Required technical timestamp |

There is no `updated_at` and the application exposes no update or delete operation.

S1 event types:

- `company_created`;
- `capability_assigned`;
- `capability_revoked`;
- `setting_changed`.

Validation by type:

| Event type | Subject | Beneficiary | Capability | Setting | Previous/new values |
|---|---|---|---|---|---|
| `company_created` | company | null | null | null | null / initial company values |
| `capability_assigned` | user | required | required | null | `false` / `true` |
| `capability_revoked` | user | required | required | null | `true` / `false` |
| `setting_changed` | company | null | null | required | required / required |

Every row has explicit empty `affected_exercise_ids`,
`allocated_impact_by_exercise`, and `actual_impact_by_exercise` values in S1. These
dimensions are not nullable because later slices must extend their content rather
than introduce a second event envelope.

## Closed domain values

### ClosingUnclassifiedPolicy

- `warning` — canonical `Avviso`;
- `blocking` — canonical `Blocco`.

### Setting

- `overspend_note_required`;
- `unclassified_closing_policy`;
- `timezone`.

## Mutation transitions

### Create company

```text
no company
  -> company with canonical defaults
  -> nine creator capability rows
  -> one company_created + nine capability_assigned events
```

All steps commit or roll back together.

### Synchronize a beneficiary's capabilities

```text
current set + requested set
  -> additions = requested - current
  -> removals = current - requested
  -> assignment inserts/deletes + one event per difference
```

An identical set is a no-op.

### Update settings

```text
locked current settings + requested settings
  -> validate closed values and IANA timezone
  -> if timezone differs, require preview confirmation
  -> update changed fields + one setting_changed event per field
```

An identical set is a no-op. Historical rows and audit events are never rewritten.
