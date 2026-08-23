# UI Contract: Closing

## Entry point

Existing Exercise detail page:

- Open Exercise eligible for Closing: header action `Chiudi esercizio`.
- Closed Exercise with Snapshot: action `Apri Chiusura`.
- User without `chiude_esercizio`: no Closing mutation action.

Use a dedicated Closing page rather than a small confirmation modal because the
canonical confirmation can contain multiple Project decisions and warnings.

## Closing page

Keep one page with progressive sections; do not create a persistent workflow engine.

### 1. Header / status

Show:

- Exercise year;
- state;
- Company-local Closing reference `31/12/N`;
- latest Budget reference or `Budget Approvato assente`;
- finalizable Allocation / Actual / operational variance;
- whether `N+1` already exists;
- compact per-Exercise impact for every Open Exercise affected by Closing.

### 2. Blocking checks

If blocks exist:

- show a concise blocking summary;
- list each concrete affected source/condition;
- disable final confirmation;
- link to the existing screen that can resolve the issue when such a canonical
  existing path exists.

Do not invent a "Fix automatically" action.

### 3. Non-blocking warnings

Show canonical warnings distinctly from blocks.

Warnings never use invoice/payment language that the domain does not know.

If warnings exist, require one explicit final acknowledgement:

`Ho preso visione degli avvisi di Chiusura`

The Snapshot stores the warning codes/messages accepted at confirmation.

### 4. Project decisions

One compact card/row per Project Planned/Open at 31 December.

Show:

- Project;
- state at 31 December before the Closing decision;
- Allocation;
- Actual;
- Residual;
- maximum transferable;
- current provisional/live mode/value where present.

Require:

- final 31-December state choice from the canonical allowed set;
- if continuing, one explicit mode:
  - Nessuna
  - Riporto
  - Riprogrammazione
- final Carryover amount only for Riporto;
- explicit source Estimate reductions only for a not-yet-executed Reprogramming;
- required Note only in canonical required cases.

For an already executed Reprogramming, show that it is already applied and will be
verified, not executed again.

For terminal final state:

- force/lock mode to `Nessuna`;
- show why.

### 5. N+1

If `N+1` exists:

- show `Esercizio N+1 già esistente`;
- no delete/offboarding toggle.

If absent:

- explicit radio:
  - `Gestione continuata`
  - `Gestione terminata`

For continued management, explain that N+1 will be created Open without Budget,
autonomous Expense copies, Actual copies or Project Estimates.

For terminated management, show the canonical preconditions and disable confirmation
if incompatible.

### 6. Final confirmation

Show at minimum:

- Exercise;
- total Allocation;
- total Actual;
- operational variance;
- Project final states;
- consolidated Carryovers;
- accepted warnings;
- N+1 disposition;
- explicit statement:
  `L'Esercizio non potrà essere riaperto.`

Require an explicit confirmation checkbox.

Do not require a generic Closing Note unless a specific underlying canonical decision
requires a Note.

## Closing Snapshot view

Read-only.

Header:

- Company / Exercise;
- closed timestamp and actor;
- Budget v1/current references or absent;
- final Allocation;
- Actual at Closing;
- operational variance;
- total consolidated Carryover;
- accepted warnings;
- settings applied;
- N+1 disposition.

Rows:

- type;
- materialized label;
- Cost Center;
- final Allocation;
- Actual at Closing;
- variance;
- state at 31 December where applicable.

Drill into row detail for:

- Expense lines;
- Project decisions/transitions/deferral;
- Contract conditions/cycles/lifecycle.

Do not implement S11 comparison/reporting controls on this page.

## Closed Exercise page

After Closing:

- status visibly `Chiuso`;
- ordinary create/edit actions that would mutate historical plan/state are removed or
  disabled;
- `Apri Chiusura` is prominent;
- no `Riapri` action exists;
- Proposal/Revision initialization is unavailable.

S10 later adds its separate correction actions.
