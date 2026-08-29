# UI Contract: Contracts

All pages are inside the selected Company tenant and use Italian user-facing text.
`visualizza` permits reading and authorized attachment download;
`modifica_operativita` permits S5 mutations. Authorization, tenant ownership,
revisions, and current domain state are rechecked on every submission. Guessed URLs
must not disclose another Company's Contract, Expense, Line, link, or attachment.

## Contratti Resource

Navigation label `Contratti`; tenant URLs provide list, create, view, and descriptive
edit. No delete action exists.

The list shows Titolo, Fornitore, Stato alla data odierna aziendale, Data inizio,
Prossima scadenza or `Scadenza non definita`, Rinnovo automatico, current annual
Centro di Costo, Archivio, and last update. Active records are the default with an
explicit archived filter.

Create collects:

- Titolo and optional Note;
- active same-company Fornitore;
- Data di inizio;
- optional Prossima scadenza;
- Rinnovo automatico, default yes;
- required Data efficacia configurazione rinnovo, including for a late census;
- required positive Durata rinnovo when automatic renewal and expiry exist;
- optional non-negative Preavviso;
- first economic condition: amount, cycle, attribution mode, valid-from, optional
  valid-to;
- optional annual Cost Center or `Non classificato` for every affected open
  Exercise shown by the creation preview.

The preview lists state/start facts, renewal terms, every affected open Exercise,
annual composition/Estimate, and classification. Confirmation creates the Contract,
activation fact, initial configuration, first condition, classifications, generated
Estimates where non-zero, and required Timeline events atomically. Missing condition,
archived Supplier, invalid dates, cross-company reference, or stale affected
Exercise rejects everything.

When the real contractual start predates registration, the same create flow uses the
explicit initial renewal-configuration effective date, shows the derived current
state, current deadline/renewal chain, and only the open Exercises that will receive
generated values. Confirmation retains the real dates, records the census and every
required typed event, and leaves all existing approved Budgets and Closing Snapshots
unchanged.

View shows stable OriginKey, Supplier, current state and reference date, Archive,
contractual dates, renewal terms, next expiry/notice limit, annual situations,
condition composition, manual Actual Expenses, links, attachments, and filtered
Timeline. Descriptive edit changes only Titolo and Note and never changes economics.

## Situazioni annuali

Each row shows Exercise, canonical reference date, state at date, annual Cost Center
or `Non classificato`, Allocato generated, Effettivo manual, Scostamento, generated
Estimate link, and cycle composition. Future Exercises show state at 1 January and
future facts separately.

`Riclassifica` is available only for an open Exercise. Preview shows old/new Cost
Center, generated allocation, all manual Actual Expenses, and exact amounts moved.
Confirmation updates the whole annual Contract classification or nothing. An active
same-company Cost Center or `Non classificato` is selectable; an archived existing
reference remains readable but cannot be newly selected.

Creating a new Exercise initializes the latest known Contract classification without
creating an Expense, Line, Estimate, or Actual solely because the Exercise exists.

## Condizioni economiche

The related table shows status, amount, cycle, attribution mode, inclusive validity,
anchor, author, and reason when present. There is no inline raw edit or delete.

`Modifica accordo` asks for requested date and new terms. Preview shows:

- requested date;
- first day of the following month;
- effective applicable cycle boundary;
- reason for any delay;
- `Prorata applicato: no`;
- old/new terms;
- exact annual composition and allocation impact for every open Exercise.

If requested and effective dates differ, explicit effective-date confirmation is
required. No applicable boundary before cessation/non-renewed expiry blocks the
operation. A future condition whose first cycle has not begun may be replaced while
keeping its future valid-from after preview.

`Correggi errore materiale` is a separate action. It requires the user's declarations
that the old value was wrong and no new agreement began, a reason, all affected
Exercises open, exact preview, and confirmation. It updates the original condition
with complete before/after audit rather than pretending a real economic change.

Annul/terminate operations preserve the condition identity and history. Generated
Estimate Expenses and Lines expose no manual create, edit, annul, reverse, move, or
Actual action.

## Ciclo di vita

The related table shows type, declared contractual date, state-change date, display
status (`Pianificato`, `Efficace`, `Annullato`), reason when required, author,
technical timestamp, and renewal/configuration reference.

Actions:

- `Cessa`: date + mandatory Note, cycle-preserving impact preview, atomic open-year
  recalculation;
- `Riattiva`: new Active start, applicable expiry, at least one new valid condition,
  annual impact preview, no reopening of prior conditions;
- `Annulla prima dell'attivazione`: only never-active Planned Contract, mandatory
  reason, all affected Exercises open;
- `Annulla fatto futuro` and `Sostituisci fatto futuro`: only before effectiveness,
  preserving the old row and validating the complete later sequence.

Effective facts are read-only and have no erase action. Planned/Active Contracts
cannot be archived.

## Rinnovo e scadenza

`Modifica rinnovo` creates a complete dated configuration after preview of current
and proposed expiry, duration, automatic behavior, notice limit, every affected open
Exercise, and any elapsed unmaterialized expiry. Elapsed expiries must be processed
with their historical configuration before the new configuration can apply.

The user sees `Rinnovo senza condizione economica` when the Contract stays Active but
no valid condition covers the renewed period. No Estimate is invented. Conditions
with explicit valid-to are not extended.

The page never labels Contract dates as invoice/payment dates and never claims that
notice was sent.

## Scadenze contratti page

Navigation label `Scadenze contratti`. This is a read-only tenant page and does not
materialize renewals merely because it is opened.

For each non-archived Contract it shows:

- Contract and Supplier links;
- current state and start;
- next expiry or `Scadenza non definita`;
- automatic renewal and duration;
- notice days and derived calendar-day notice limit;
- planned cessation;
- days until expiry and notice limit;
- current Cost Center;
- renewal-without-condition warning;
- Timeline link.

Filters cover expiry interval, notice-limit interval, automatic renewal on/off,
undefined expiry, lifecycle state, Supplier, and Cost Center. There is no reminder,
email, notification, invoice, instalment, or payment control.

## Spese Effettive di Contratto

The Contract view reuses the existing Expense list/detail and Line relation manager.
`Nuova Spesa Effettiva` preselects Contract, uses the globally selected open Exercise,
derives Supplier and annual Cost Center, and accepts only Actual Lines.

Ordinary Actual requires Active state on the company-local submission date. Planned
rejects it. Cessated/Cancelled offers only explicitly declared `Addebito tardivo`,
`Costo di cessazione`, `Rimborso`, or `Correzione`, with mandatory Note and no state
change. An existing Contract may continue with its archived Supplier.

Every Expense view shows owner `Autonoma`, linked Project, or linked Contract;
origin `Manuale` or `Sistema`; derived/direct Supplier; and direct/inherited annual
classification. A system Estimate is visibly read-only.

## Sposta o riclassifica Expense

Extend the existing action with destinations `Autonoma`, `Progetto`, and `Contratto`.
The preview shows preserved Expense/Line IDs, origin, source/destination owner,
Supplier warning/derivation, direct/inherited Cost Center before/after, state
eligibility, and exact allocation/Actual deltas for every affected source/Exercise.

- Entering Contract rejects every manual Estimate Line and every system Expense.
- Entering Contract replaces a different direct Supplier only after explicit warning.
- Leaving Contract never moves/copies the generated system Estimate and may retain
  the former Supplier.
- Autonomous destination requires an explicit active direct Cost Center selection or
  `Non classificata`; inherited Cost Center is not retained silently.
- Project/Contract destination inherits its nullable annual classification.
- An Expense with Actuals requires reason and exact destination state declaration.

Reversed, closed-Exercise, archived-target, unsupported state, simultaneous Project
and Contract, cross-company, stale preview, or changed Line/condition/configuration
rejects the complete move.

## Project-Contract links

Contract and Project views expose the same `Collegato a` table. Create selects one
active/readable same-company opposite source and optional Note. The link is symmetric,
many-to-many, unique while active, and used only for navigation/history.

`Archivia collegamento` and `Ripristina collegamento` preserve identity and change no
amount, state, ownership, classification, or carryover. There is no delete action.

No structured source-replacement action, field, type, route, or placeholder is
exposed, as required permanently by canonical §32.

## Attachments

Contract, Expense, and Line views expose a shared related attachment list after the
owner exists. Upload uses the private disk and records original filename, observed
media type, size, checksum, uploader, and time. It never accepts a client path as an
authorization source.

`Scarica` reauthorizes the selected Company and owner on every request and streams
through an authenticated route. No public storage URL is shown. `Rimuovi dall'oggetto`
requires confirmation and logically detaches the immutable version; it does not
delete or overwrite the stored file. Replacing evidence uploads a new version.

Cross-company attachment IDs and guessed download URLs disclose nothing. Later
approval/Snapshot evidence retention is not exposed in S5, but stable detached IDs
and blobs remain available to those workflows.

## Archive and Timeline

`Archivia` is available only for Cessated/Cancelled Contracts. `Ripristina` changes
visibility only. Both preserve every date, condition, configuration, classification,
Expense, value, link, attachment, and event. Archived Contracts accept no new
ordinary activity until restored.

Company Timeline remains newest-first and append-only. Contract filtering includes
creation and late census, details, lifecycle, renewals, renewal configuration,
conditions, generated Estimate recalculation, classification, manual Expenses/Lines,
ownership movement, links, attachments, Archive, and restore even after an Expense
moves. Details show
effective dates, before/after facts, affected Exercises, exact impacts, reason when
required, references, operation identity, and event sequence.

## Explicit absences

No UI exposes physical deletion, state `Sospeso`, prorata, cycle-to-Actual matching,
invoice/payment schedules, reminders, variable consumption, setup/one-time Contract
components, tiers, indexation, carryover, reprogramming, Proposal, Budget, Revision,
Closing, closed-year correction, Forecast, full reporting, percentage Cost Center
splits, or non-canonical informative relations.

## Error behavior

Invalid, stale, unauthorized, cross-company, duplicate-date, overlapping-condition,
incompatible-sequence, closed-Exercise, archived-target, missing-note,
unconfirmed-effective-date, invalid renewal, unsupported owner/origin, reused
operation, or storage authorization requests show a concrete Italian domain reason
and leave live state, files, and Timeline without partial effects.
