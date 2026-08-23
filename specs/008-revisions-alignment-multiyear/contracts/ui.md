# UI Contract: Revisions, Realignment, and Multi-Year Impact

All pages remain inside the selected Company tenant and use Italian text. Direct
URLs, selectors and submissions recheck the exact company capability.

## Exercise integration and Revision creation

An Open Exercise without a Budget retains `Inizializza proposta`. An Open Exercise
with at least one Budget shows `Crea revisione` to `gestisce_proposte` users when no
Draft exists. The confirmation states that:

- the baseline is current live reality;
- the latest Budget is comparison-only and remains immutable;
- Actuals are visible but not editable in the Proposal;
- approval creates the next version and requires a reason.

A Closed Exercise, occupied Draft, foreign company, or missing capability shows an
exact disabled reason. Success opens the new Revision Draft.

## Proposal header and comparison

The Proposal view shows `Budget iniziale` or `Revisione`, main Exercise, Draft state,
reference Budget `vN`, planned allocation and read-only Actual notice. For a Revision,
the current live baseline and latest approved values are shown as distinct named
references; the previous Budget is never presented as editable or reapplied.

## Whole-source realignment

An Item marked `Da riallineare` exposes exactly:

- `Ricarica realtà`: confirmation states that all Proposal decisions touching the
  source are withdrawn and current reality replaces the plan result;
- `Mantieni proposta`: requires a reason and shows the fresh impact of replaying all
  active decisions;
- `Rivedi manualmente`: lists active touching decisions with checked `Mantieni`
  controls; unselected decisions are withdrawn, and replacements use the existing
  source-specific planning actions.

Every modal displays the current/stored source revision, affected Exercises,
before/after allocation/state, unchanged Budgets, stale Drafts, warnings and Closed
divergences. A stale confirmation refreshes the page and applies nothing. Invalid
replay retains `Da riallineare` or becomes `Incoerente` with exact reasons.

Action history shows active and withdrawn decisions, original actor/time, withdrawal
actor/time, reason and operation identity. Status uses text/icon in addition to color.

## New-source acknowledgement and inconsistencies

An Item marked `Da prendere in visione` exposes `Prendi visione`. The confirmation
shows current read-only Actual context and any already prepared plan action. Keeping
the source unchanged creates no economic action. To reduce or exclude permitted plan,
the user first uses the existing typed Estimate planning control and then confirms
acknowledgement.

An `Incoerente` Item lists every canonical reason code with an Italian explanation
and links the user to the relevant typed planning action. There is no generic
`Azione non valida`, dismiss/suppress control, field-level merge, Actual editor, or
free-form payload editor.

## Multi-Exercise impact and Closed divergences

Readiness and every confirmation group impact by Exercise. Open rows are labelled
`Verrà applicato`; Closed rows are labelled `Storico invariato` and show the computed
divergence. Existing Budgets are explicitly labelled `Resta invariato`.

S7 does not show a historical-error annotation form. It records the divergence and
directs formal historical correction/annotation to the later dedicated workflow.

## Revision approval

The approval modal displays `Approva revisione e crea Budget vN+1`, the predecessor,
all applied Open impacts, Closed divergences, evidence and immutable-history notice.
Revision reason is required; external subject/venue and eligible evidence attachments
remain optional. Submission rechecks authorization, latest predecessor, source
membership/revisions, active actions, readiness and all impact rows.

A successful retry opens the already-created Budget. The Budget list/view shows all
versions and predecessor/purpose without edit or delete actions.

## Discard

A Draft manager sees `Scarta proposta`. The modal requires a reason and states that
live reality and all Budgets remain unchanged. Success leaves the terminal Proposal
readable with content/action history and removes every mutation control. Retrying the
same operation returns the same Discarded Proposal.

## Explicitly unavailable controls

No S7 page exposes Carryover, Reprogramming, Closing, late correction, historical
annotation, full comparison/export, Forecast, directed `Sostituisce`, parallel Draft,
physical delete, or arbitrary as-of controls.

## Empty, error and accessibility behavior

Empty action/history/divergence collections use explanatory Italian zero states.
Buttons disable duplicate submission. Validation messages are attached to the
relevant field, and standard Filament focus, keyboard and responsive behavior is
retained.
