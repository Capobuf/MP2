# UI Contract: Projects

All pages are inside the selected Company tenant and use Italian text. `visualizza`
permits reading; `modifica_operativita` permits S4 mutations. Authorization and
company ownership are rechecked on submission and direct guessed URLs disclose no
cross-company data.

## Progetti Resource

Navigation `Progetti`; tenant URLs provide list, create, view, and descriptive edit.
There is no delete action.

The list shows Titolo, Stato alla data odierna aziendale, Data efficacia iniziale,
Archivio and last update. Active Projects are the default view, with an explicit
archived filter. A Project absent today shows `Assente alla data`.

Create asks for Titolo, optional Descrizione/Note, Stato iniziale, Data di efficacia,
one open Esercizio, and optional active same-company Centro di Costo for that
Exercise. It creates one Project and initial annual classification atomically, but no
Expense or economic value.

View shows stable OriginKey, current state and reference date, archive visibility,
annual situations, transitions, Project Expenses, and a filtered Timeline link.
Descriptive edit changes only Titolo, Descrizione, and Note.

## Situazioni annuali

Each row shows Esercizio, canonical reference date, state at date, Centro di Costo or
`Non classificato`, Allocato, Effettivo, and Scostamento. Past/current/future
reference rules are explicit in the row. Future Exercises separately list planned
transitions after 1 January.

`Riclassifica` is available only for an open Exercise. Its preview shows previous and
new Cost Center, all affected Project Expenses, and exact allocation/actual moved as
one annual whole. Confirmation rechecks revisions. Archived existing references are
labelled `Archiviato` but cannot be newly selected; Unclassified is explicit.

## Transizioni

The nested table shows Da, A, Data efficacia, Stato (`Pianificata`, `Efficace`,
`Annullata`), Motivo, and Autore.

`Pianifica transizione` shows the state immediately before the requested date and
the allowed destinations. Closure, cancellation, and reopening require Motivo.
Duplicate dates, incompatible source states, or a broken later sequence return a
concrete domain error and persist nothing.

Only a future active transition exposes `Annulla` and `Sostituisci`. Both retain the
old row and require confirmation/reason. Effective transitions have no edit,
annulment, or delete action; correction uses a later canonical transition.

## Spese di Progetto

Reuse the existing `Spese` Resource and Line relation manager. The Project view
contains a filtered child table and `Nuova spesa di progetto`, preselecting the
Project and one open Exercise.

Every Expense list/detail shows `Contenitore`: `Autonoma` or a link to its Project.
Supplier remains on the Expense. Direct Cost Center is shown and selectable only for
autonomous Expenses; a Project Expense displays the inherited annual classification.

Estimate activity requires a non-archived Planned/Open Project. Ordinary Actual
activity requires Open at the company-local submission date. A Planned Project
offers explicit atomic opening. Closed/Cancelled offers only declared `Tardivo`,
`Rimborso`, or `Correttivo` with mandatory Note and without state change. Archived
Projects accept no new activity until restored.

## Sposta o riclassifica Expense

Extend the S3 action with destination `Autonoma` or `Progetto` and active
same-company Project selection. No Contract option exists.

The preview shows preserved Expense/Line IDs, source/destination container,
direct/inherited Cost Center before and after, Project state eligibility, exact
allocation/actual deltas for every affected Project/Exercise, and unchanged Exercise
total for a same-year container move. Input changes invalidate the preview.

Entering a Project clears direct Cost Center. Leaving requires an explicit active
direct Cost Center or `Non classificata`; inherited classification is never retained
implicitly. Estimate-only moves require Planned/Open or an included reopening.
Moves with Actuals require a reason and follow ordinary/late/corrective rules. Stale,
closed-year, reversed, archived, cross-company, and unsupported destinations reject
the whole operation.

## Sovraspesa

Every relevant form/action displays `Sovraspesa creata` when variance crosses from
non-positive to positive, or `Sovraspesa aumentata` when an existing positive value
increases. Equal or decreasing positive variance produces no new notification.

When the Company setting is active, `Nota di sovraspesa` becomes mandatory before
confirmation. The UI warning reflects the server-side recomputation; missing or
stale data leaves live state and Timeline unchanged.

## Archivio and Timeline

`Archivia` is available only when the Project is Closed or Cancelled at the current
company-local date. `Ripristina` changes visibility only. Both are confirmed,
revision-safe, and audited without changing values or classifications.

`Timeline Azienda` remains newest-first and read-only. Project filtering includes
creation, details, transitions, annual classification, Expense ownership, child
Expense/Line economic changes, overspend, archive, and restore, even after an Expense
moves elsewhere. Details show effective date, before/after, affected Exercises,
exact impacts, reason, references, and operation identity.

## Explicit absences

No UI exposes physical deletion, state `Sospeso`, carryover, reprogramming,
Contracts or Project-Contract relations, Proposals, Budgets, Closing, closed-year
correction, attachments, Forecast, full reporting, percentage Cost Center splits, or
Project-level Supplier.

## Error behavior

Invalid, stale, unauthorized, cross-company, duplicate-date, incompatible-sequence,
closed-Exercise, archived-target, ineligible-state, missing-note, reused-operation,
or unsupported-owner requests show a concrete Italian domain reason and leave both
live state and Timeline unchanged.
