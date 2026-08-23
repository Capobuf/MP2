# UI Contract: Carryover and Reprogramming

All pages remain inside the selected Company tenant and use Italian user-facing text.
Authorization and validation are rechecked server-side; visibility alone is never an
authorization boundary.

S8 extends existing Filament surfaces. It does not introduce a separate frontend,
wizard framework, or generic workflow builder.

## Project Current Situation

The Project annual value area must distinguish:

- `Stime`;
- `Riporto ricevuto`;
- `Allocato corrente`;
- `Effettivo`;
- `Scostamento operativo`;
- `Residuo`;
- `Disponibilità massima riportabile`;
- current incoming deferral mode where a previous consecutive Exercise exists.

Do not label Carryover as an Estimate.

When an already-live provisional Carryover is above the source Project's current
maximum, show the exact warning:

`Riporto provvisorio superiore al massimo corrente`

The warning does not mutate the value.

## Proposal Project action: Rinvio

For an existing Project in a Proposal for Exercise `N+1`, when an immediate previous
Exercise `N` exists, the Project action group exposes a bounded `Rinvio` control.

The control shows:

- source Exercise and destination Exercise;
- source current allocation;
- source Actual;
- source residual;
- current maximum transferable availability;
- current live mode/value;
- proposed mode/value;
- annual impact before confirmation.

Mode options are exactly:

- `Nessuna`;
- `Riporto`;
- `Riprogrammazione`.

They are mutually exclusive; do not use independent checkboxes.

### Nessuna

Show that:

- no amount is transferred;
- Carryover is zero;
- Reprogramming is zero;
- independent new destination allocation remains separate.

If live mode is already `Nessuna`, no economic action needs to be appended merely to
reconfirm the default.

### Riporto

Show an amount input `Riporto provvisorio` and a required `Motivazione`/`Nota`
for the rinvio.

The UI displays the current maximum and validates:

```text
0 < amount <= current maximum
```

Do not prefill the maximum automatically for a new choice.

The confirmation explicitly states:

- source Estimates remain unchanged;
- destination allocation increases by the chosen Carryover;
- Carryover is provisional until Closing;
- later source changes can make it exceed the current maximum without automatic
  correction;
- existing Budgets remain unchanged.

If source maximum is zero, `Riporto` is unavailable and `Nessuna` remains valid.

### Riprogrammazione

Show:

- canonical pre-operation availability;
- total currently reducible active source Estimates;
- requested Reprogrammed amount derived from selected reductions;
- active Estimate lines of the Project's source-Exercise Expenses;
- for each line, current amount and a user-entered reduction amount from zero up to
  the line amount;
- generated destination preview grouped by source Expense;
- source allocation before/after;
- destination Estimate increase;
- zero Carryover;
- exact equality check.

Do not expose Actual lines as selectable.

Destination preview is generated from the selected reductions:

- one new destination Expense per affected source Expense;
- same description/notes;
- source supplier may be prefilled only when still selectable; if it is Archived, the
  normal optional Project-Expense supplier field requires an explicit choice between
  `Nessun Fornitore` and an active supplier;
- same Project ownership;
- new identity;
- visible `Copiata da <OriginKey>` lineage;
- one new Estimate line per selected source reduction.

The user does not manually match destination rows to source rows.

If received Carryover makes canonical availability higher than the total reducible
source Estimates, the UI must not imply that the entire availability can be
Reprogrammed. It shows both values and the selected reduction total remains the
Reprogrammed amount. Received Carryover is not editable from this control.

If the sum of reductions is zero, mode `Riprogrammazione` cannot be submitted.

`Riprogrammazione` requires a non-blank `Motivo`. A Proposal action that replaces or
removes an already-applied live mode also requires a reason; do not rely only on a
later generic approval note.

### Terminal/closed states

If the Project state at 31 December of `N` is `Chiuso` or `Cancellato`, show mode
`Nessuna` and explain that the terminal source-year state prevents Carryover and
Reprogramming. Inside a Proposal, this state is the result after applicable planned
Project transitions, not merely the current pre-Proposal state.

If either Exercise is Closed, the current deferral state remains readable but no S8
editing control is available.

## Proposal action: Nuova allocazione

For a new destination Expense attached to an existing live Project, expose
`Nuova allocazione` rather than an ambiguous generic Project Expense creation action.

It requires a `Nota`. The value belongs to the Proposal decision/audit and is not
silently inserted into the destination Expense's own Notes field.

The explanation states that:

- it increases destination Estimates independently;
- it is not Riporto;
- it is not Riprogrammazione;
- it will not be removed if a Reprogramming is later reversed.

The existing new-child-plan flow for a Project created inside the same Proposal may
remain unchanged because it has no prior live year passage.

## Proposal readiness

Project rows show exact S8 inconsistency messages already defined by S7:

- `Il Riporto supera il limite disponibile.`
- `La Riprogrammazione supera l’importo disponibile.`
- `La Riprogrammazione non è bilanciata.`
- `Le modalità di rinvio sono incompatibili.`

Do not add `Azione non valida`, `Errore rinvio`, or another generic fallback reason.

A source change still uses S7 `Da riallineare` and the existing whole-source choices.

## Approval summary

The existing Proposal approval modal groups S8 impact by Exercise.

For Carryover:

```text
N:   Allocato invariato
N+1: + € X Riporto provvisorio
Budget esistenti: invariati
```

For Reprogramming:

```text
N:   - € X Stime/Allocato
N+1: + € X nuove Stime
Riporto: € 0
Budget esistenti: invariati
```

The summary also shows:

- source line count;
- destination Expense/Estimate count;
- affected Drafts;
- warnings/blocks;
- immutable Budget notice.

Approval does not expose a second S8 confirmation after the normal Proposal approval
confirmation.

## Direct live Project mode change

On the existing Project detail page, an authorized `modifica_operativita` user sees a
single action such as `Gestisci rinvio` only when the Project passage already has an
applied live `Riporto` or `Riprogrammazione` and both Exercises are editable.

The modal exposes only the valid replacement/removal targets for the current mode:

- from `Riporto`: `Nessuna` or `Riprogrammazione`;
- from `Riprogrammazione`: `Nessuna` or `Riporto`.

It requires a reason for every successful live mode change. Creating a new transfer
from `Nessuna`, or changing the provisional Carryover amount while staying in
`Riporto`, is done through Proposal/Revision.

Before confirmation it shows exact impact and states:

- both Exercises must still be Open;
- if `Riporto -> Riprogrammazione` would require a Project reopen/open transition in
  the destination Exercise, the direct action is blocked and the change must be
  prepared atomically through Proposal/Revision;
- all facts are revalidated on submit;
- existing Budgets remain unchanged;
- affected Draft Proposals will require realignment.

### Leaving Riprogrammazione

The confirmation additionally lists:

- source Estimate lines that will be restored;
- destination Estimate lines created by the active Reprogramming that will be
  annulled;
- independent destination allocations explicitly labelled `Non verrà modificata`.

If any involved line no longer matches the expected post-Reprogramming state, the
modal must not offer an overwrite path. Show a blocking message explaining that the
plan was modified independently and must be realigned before the mode can change.

## Budget view

For each Project row, display:

- approved Estimate total;
- approved Carryover;
- Carryover state (`Provvisorio` when applicable);
- approved allocation;
- deferral mode;
- approved Reprogrammed amount when non-zero.

Do not add the Reprogrammed amount again to approved allocation.

Lineage/details may be progressively disclosed; the top-level numbers must remain
readable without opening raw JSON.

## Timeline

The Project Timeline renders the deferral event as a functional event, not
`oggetto modificato`.

At minimum show:

- previous -> new mode;
- Carryover before/after;
- Reprogrammed amount before/after;
- source/destination Exercise;
- allocation impact by Exercise;
- reason when required;
- actor/date;
- Proposal/Budget reference when applicable.

Resolved line IDs may remain in technical detail/drill-down rather than primary text.

## Empty/error/accessibility behavior

- No immediate previous Exercise: explain that there is no incoming deferral to
  configure; do not show a broken selector.
- Maximum zero: keep `Nessuna` available; disable invalid positive modes with reason.
- No source Estimate lines: Reprogramming is unavailable.
- Stale confirmation: apply nothing and request refresh/review.
- Duplicate submit: standard Filament duplicate-submit protection plus server-side
  idempotency.
- Use text/icon in addition to color for mode/warning states.
- Preserve keyboard, focus, validation association, responsive behavior, and existing
  MP2 object-page hierarchy.
