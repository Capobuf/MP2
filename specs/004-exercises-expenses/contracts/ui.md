# UI Contract: Exercises, Expenses and Lines

All pages are inside the selected Company tenant and use Italian text. `visualizza`
permits reading; `modifica_operativita` permits S3 mutations. Direct URLs remain
tenant scoped.

## Esercizi Resource

Navigation `Esercizi`; tenant URLs use list, create and view only.

The table shows Anno, Stato, Allocato Corrente, Effettivo, Scostamento Operativo and
number of Spese, ordered by newest year. Create asks only for Anno and always creates
`Aperto`. View shows exact totals and that year's autonomous Expenses, with a
`Nuova spesa` link that uses the globally selected Exercise.

There is no Exercise edit, delete, close, reopen, Budget, next-year, carryover,
Project, Contract or classification action.

## Spese Resource

Navigation `Spese`. The table shows Descrizione, Esercizio, Fornitore, Centro di
Costo or `Non classificata`, Stato, Allocato Corrente, Effettivo and Scostamento.
Filters cover Exercise, active/reversed state, Supplier and direct Cost Center.

Create uses the globally selected Exercise without exposing a second Exercise field;
the selected Exercise must be open. It asks for description, notes, optional active
same-company Supplier/Cost Center and at least one initial Line in a repeater.
Expense, Lines and one complete creation event persist atomically.

View shows OriginKey, state, references and totals; archived referenced master data
has an `Archiviato` indication. It contains the Righe relation manager and authorized
actions for descriptive edit, `Sposta o riclassifica`, `Storna` or `Ripristina`.

Descriptive edit changes only description/notes. Exercise/Supplier/Cost Center are
changed only through the impact action.

### Sposta o riclassifica

A modal or wizard collects requested open Exercise and active same-company
Supplier/Cost Center, including `Nessuno`. `Calcola anteprima` displays old/new
references and per-year allocation/actual before, after and delta. Input changes
invalidate the preview.

`Conferma modifica` re-authorizes and revalidates the preview revisions under locks.
Stale previews request recalculation. Moving Actuals requires a reason and cannot
target a future year. Identity and all Lines remain unchanged.

### Storno and restore

Both require reason and confirmation with exact amounts removed/added. Storno is
blocked whenever `HaEffettivi` is true, including signed Actuals netting to zero.
Restore requires an open Exercise. Actions are mutually visible by current state.

## Righe relation manager

The nested table shows Tipo, Importo EUR, optional descriptive factors, Nota, Stato
and last update. Actions are add, edit, `Annulla`, and `Ripristina`; there is no delete
or bulk destructive action.

The form permits only Stima/Effettivo; amount is authoritative with two decimals;
quantity/unit amount accept six decimals. Negative Estimate is rejected. Negative
Negative Actual requires Note. A zero-valued Line is accepted only to preserve an
already materialized identity or an explicit decision; a new manual zero Line should
normally include a documented reason. Future-year Actual is rejected in Company time.

When both descriptive factors exist, show the exact half-up `Importo suggerito`. If
different, the first submit does not persist and requires explicit `Salva comunque`;
changing input invalidates confirmation. Amount is never overwritten.

Line mutations are hidden/disabled for reversed Expense and always rejected server
side until restoration.

## Timeline extension

`Timeline Azienda` stays read-only, newest-first and tenant-scoped. S3 adds Italian
subject/event labels plus an expandable detail for affected Exercises, before/after,
allocation/actual impact, reason and operation identity. Expense view links to its
filtered Timeline. No event mutation exists.

## Explicit absences

No UI exposes physical deletion, matching/consumption, Project/Contract assignment,
Budget/Proposal/Revision, Closing, carryover/reprogramming, annual container
classification, late corrections, full reports, Forecast, Preventivo, Imprevista,
Plafond, Rettifica, VAT, currency conversion or attachments.

## Error behavior

Invalid, stale, unauthorized, cross-company, reused-operation, closed-year and
future-Actual requests show a concrete Italian domain reason and leave both live
state and Timeline unchanged.
