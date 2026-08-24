# UI Contract: Correzioni post-Chiusura

All pages remain inside the selected Company tenant and use Italian text. Direct URLs,
record selectors and submissions recheck the exact Company and capability.

## Closed Exercise integration

A Closed Exercise readable by the current tenant shows its immutable Closing summary
and two contextual actions:

- `Registra correzione tardiva` for `corregge_esercizio_chiuso`;
- `Annota errore storico` for `corregge_esercizio_chiuso`.

An Open Exercise exposes neither action. Missing capability, foreign Company or absent
canonical Closing Snapshot produces an exact disabled or rejection message; no
ordinary Manage Operations capability substitutes for the correction capability.

## Late correction form

The action states explicitly that:

- the amount belonged really to the Closed Exercise;
- the Exercise will not be reopened;
- Closing Snapshot, Budget, Carryover, state and historical attribution remain
  unchanged;
- the operation appends an Actual and cannot edit an existing line.

Required input:

- historical first-level source context;
- existing compatible manual Expense, or `Nuova Spesa tardiva`;
- description when a new Expense is required;
- Actual amount;
- reason;
- explicit `L'importo apparteneva realmente a questo Esercizio` confirmation.

Optional input:

- original ExpenseLine reference when known;
- Supplier for a new autonomous/Project late Expense, including historically relevant
  Archived Suppliers;
- note details beyond the required reason;
- evidence attachment, added through the retained Attachment interaction.

The form never offers Estimate type, owner/Exercise reclassification, ordinary
Supplier substitution, state change, reversal, annulment, Carryover change or
Closing edit. For Contract context, Supplier is inherited and no manual Estimate
control appears.

If the selected historical Expense is incompatible, confirmation explains that a new
manual late Expense will be created in the same historical owner context. The system
does not suggest another Expense by title, amount or Supplier similarity.

## Historical-error annotation form

Required input:

- one closed canonical error kind;
- data recorded;
- data believed correct;
- affected source references;
- reason.

The immutable Closing Snapshot is included automatically. Optional evidence uses the
existing Attachment interaction.

The confirmation states that the Annotation has zero economic effect. It does not
show controls to transfer amounts, reclassify history, reopen the Exercise, change
Carryover, create a future plan action or change Project/Contract state.

## Local evidence presentation

The Closed Exercise and Closing context show two separate collections:

### Correzioni tardive

Each row shows:

- new Actual amount and sign;
- generated Expense/ExpenseLine;
- original line when known;
- historical source/owner/Supplier context;
- reason and declaration;
- actor and timestamp;
- retained attachments.

### Annotazioni di errore storico

Each row shows:

- Italian error-kind label;
- data recorded and data believed correct;
- affected sources and Closing Snapshot;
- reason;
- actor and timestamp;
- retained attachments;
- `Nessun impatto economico`.

The immutable Closing values stay visually distinct. S10 does not add aggregate
comparison dashboards, Previsto/Non previsto categories, cross-year report filters or
exports.

## Terminal and error behavior

Persisted corrections and annotations have no edit or delete actions. A later mistake
requires a new operation. Duplicate submission with the same successful operation
identity opens the existing result.

A stale Exercise/source revision, changed selection, failed validation or persistence
failure applies nothing and refreshes the affected context. Validation messages attach
to the exact field. Buttons disable duplicate client submission.

## Empty and accessibility behavior

Empty collections show explanatory Italian zero states distinguishing `nessuna
correzione tardiva` from `nessuna annotazione storica`. Status and error meaning use
text/icon in addition to color. Standard Filament focus, keyboard and responsive
behavior is retained.