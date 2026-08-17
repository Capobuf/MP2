# Data Model: Exercises, Expenses and Lines

## Exercise

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; never reused |
| `company_id` | Company reference | Required; restrict deletion |
| `year` | positive year number | Required; unique with Company |
| `status` | `open` or `closed` | S3 creates only `open`; no S3 close/reopen |
| `revision` | unsigned monotone integer | Incremented for affected S3 source/economic changes |
| timestamps | technical UTC | Economic dates use Company time zone |

Constraints: unique `(company_id, year)`, indexed `(company_id, status, year)`, closed
status vocabulary, no ordinary physical deletion. An Exercise belongs to Company and
has many Expenses. Creation copies or generates nothing.

## Expense

Every S3 Expense is manual and autonomous by construction.

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; never reused |
| `company_id` | Company reference | Required and equal to referenced objects |
| `exercise_id` | Exercise reference | Required, same Company |
| `supplier_id` | nullable Supplier reference | Same Company; active for new assignment |
| `direct_cost_center_id` | nullable Cost Center reference | Same Company; active for new assignment |
| `description` | string | Required/trimmed; technical max 255; not identity |
| `notes` | nullable text | Descriptive only |
| `reversed_at` | nullable technical timestamp | Null = Attiva; value = Stornata |
| `revision` | unsigned monotone integer | Incremented by each Expense/Line mutation |
| timestamps | technical UTC | Not an Actual date |

Derived identity:

```text
OriginKey = "expense:" + Expense.id
```

Only type and stable ID participate. The key is derived, not duplicated.

Constraints:

- composite same-company references to Exercise, Supplier and Cost Center;
- no Project/Contract fields or accepted input in S3;
- archived existing references remain readable but cannot be newly assigned;
- physical deletion is rejected by model, policy, FK and UI.

An Expense belongs to Company and Exercise, optionally Supplier and direct Cost
Center, and has one or more persisted Lines.

## Expense Line

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; never reused |
| `expense_id` | Expense reference | Required; restrict deletion |
| `type` | `estimate` or `actual` | Only canonical types |
| `amount` | decimal `(19,2)` | Authoritative EUR net-IVA amount |
| `quantity` | nullable decimal with 6 places | Descriptive only |
| `unit_amount` | nullable decimal with 6 places | Descriptive only |
| `unit_of_measure` | nullable string | Descriptive; technical max 64 |
| `note` | nullable text | Required for negative Actual/new manual zero |
| `annulled_at` | nullable technical timestamp | Null = Attiva; value = Annullata |
| timestamps | technical UTC | No structured economic Actual date |

Database checks close Line type and reject negative Estimates and negative Actuals
without non-blank Note. Action validation covers new zero reason, future Actual year,
warning acknowledgement, open Exercise and active Expense.

## Exact derived values

For an active Expense:

```text
Allocation = exact sum(active Estimate amounts)
Actual = exact sum(active Actual amounts)
OperationalVariance = Actual - Allocation
HasActuals = exists active Actual with amount != 0.00
```

A reversed Expense contributes zero while retaining Lines. Exercise totals sum each
active autonomous Expense exactly once; Lines are not added again at top level.

## State transitions

```text
Exercise: creation -> open

Expense:
active --Storno(reason, !HasActuals, Exercise open)--> reversed
reversed --restore(reason, Exercise open)-------------> active

Line:
active --annul--> annulled
annulled --restore and full revalidation--> active
```

Repeated current-state requests are no-ops without events.

## Impact plan

Before an Exercise, Supplier or Cost Center reference changes, the plan records:

- Expense ID, OriginKey and expected revision;
- affected Exercise IDs/years and expected revisions;
- old/requested references;
- exact allocation and actual removed/added per year/grouping;
- unchanged Expense/Line identities;
- required reason and any blocking domain message.

Confirmation locks and revalidates revisions, authorization, open state, active
same-company references and future-Actual rule before one atomic save.

## Timeline envelope reuse

S3 extends the existing Audit Event with typed Exercise, Expense and Line events.
Each has unique operation ID, Company/actor/subject, ordered affected Exercises,
Company-local effective date, complete before/after snapshots, exact allocation and
actual impact strings keyed by Exercise ID, reason when required, and null later-slice
references. Events remain append-only; live state is not reconstructed by replay.
