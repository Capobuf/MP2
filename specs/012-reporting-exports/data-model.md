# Data Model: Reportistica ed esportazione

S11 non aggiunge entità persistenti. Usa modelli esistenti e strutture di sola lettura costruite per ogni richiesta.

## Fonti persistenti esistenti

### BudgetSnapshot / BudgetSourceRow

- Header: Azienda, Esercizio, versione, approvazione, finalità e totale approvato.
- Riga: tipo, OriginKey, CopiedFromOriginKey, etichette materializzate, Allocato approvato, stati e dettaglio piano-only.
- Vincolo: immutabili; nessun Effettivo nella baseline.

### ClosingSnapshot / ClosingSourceRow

- Header: Azienda/denominazione, Esercizio, timestamp, Budget v1/corrente, totali finali e decisioni.
- Riga: tipo, OriginKey, etichette materializzate, stato al 31 dicembre, Allocato finale, Effettivo alla Chiusura, Riporto e dettaglio.
- Vincolo: immutabili; esattamente una per Esercizio Chiuso.

### Oggetti vivi

- Exercise, Expense/ExpenseLine, Project/Transition/Deferral, Contract/Condition/Lifecycle/Renewal, Supplier, CostCenter e AuditEvent.
- Uso: Situazione Corrente, stato alla data canonica, aggregazioni e drill-down vivo.

### LateCorrection / HistoricalErrorAnnotation

- LateCorrection collega un Effettivo append-only alla Closing Snapshot e materializza `source_origin_key`, etichetta e contesto storico.
- HistoricalErrorAnnotation collega fatti registrati/corretti a sorgenti materializzate senza impatto economico.

## Strutture di richiesta

### ReportDefinition

| Field | Type | Rules |
|---|---|---|
| company_id | positive integer | deve essere il tenant corrente e autorizzato con `visualizza` |
| kind | ReportKind | valore chiuso supportato |
| exercise_id | positive integer | appartiene all'Azienda |
| initial_reference | ReportReference/null | obbligatorio quando il report è un confronto |
| final_reference | ReportReference/null | obbligatorio quando il report è un confronto |
| actual_reference | ActualReference/null | obbligatorio per Budget vs Actual e viste che usano Effettivo |
| comparison_exercise_id | positive integer/null | obbligatorio solo per confronto fra Esercizi |
| date_from/date_to | date/null | coppia completa, stesso ordine e dichiarata nel header |
| filters | map | solo filtri supportati, valori tenant-scoped; nessun valore sconosciuto |
| generated_at | company-local datetime | assegnato server-side al momento della generazione |

### ReportKind

Vocabolario chiuso:

- annual_executive;
- budget_actual;
- budget_current_allocation;
- operational_variance;
- budget_versions;
- exercises;
- carryovers;
- contracts;
- projects;
- suppliers.

### ReportReference

| Field | Type | Rules |
|---|---|---|
| type | budget/current/closing/current_knowledge | nessun altro riferimento |
| exercise_id | positive integer | stessa Azienda |
| budget_snapshot_id | integer/null | obbligatorio solo per `budget`; versione esplicita |
| reference_date | date/null | derivata secondo §9.2 oppure esplicitamente selezionata quando il report lo ammette |

### ActualReference

- current;
- closing;
- current_knowledge.

Ogni valore resta nominato anche quando due importi coincidono.

## Strutture normalizzate

### ReportHeader

- company_id e company_name;
- exercise_id e exercise_year;
- kind e titolo;
- initial/final reference labels e date economiche;
- budget id/versione/finalità quando presente;
- actual reference quando presente;
- generated_at nel timezone Azienda;
- filtri e intervallo applicati;
- currency `EUR` e `amount_basis` `net_of_vat`;
- definizioni di categorie/etichette effettivamente esposte.

### ReportSource

| Field | Meaning |
|---|---|
| source_type | expense/project/contract; solo primo livello |
| origin_id/origin_key | identità esatta del riferimento |
| copied_from_origin_key | derivazione esplicita senza fusione |
| label/summary | valori correnti o materializzati secondo riferimento |
| supplier/cost_center | identità ed etichette del riferimento |
| state | stato alla data dichiarata o `absent` |
| allocation | Allocato della misura selezionata |
| actual | Effettivo della misura selezionata |
| has_actuals | predicato basato su Righe attive non zero, non sul saldo |
| carryover/reprogrammed/residual/saving/unused | valori applicabili, con quelli di Chiusura mai ricalcolati |
| detail | Spese figlie, Righe, condizioni, cicli, transizioni, relazioni e riferimenti evento |
| corrections | positive, negative, net, individual rows |
| annotations | Annotazioni collegate senza impatto economico |

### ComparisonResultRow

- initial_source e final_source nullable;
- correlation: `same_origin`, `copied_from` o `single_reference`;
- initial_value, final_value e delta per la misura del report;
- exactly one primary_category: unchanged/added/removed/modified;
- zero o più modification_dimensions;
- zero o più secondary_labels, senza `replaced`;
- explanation events/reasons/attachments;
- `insufficiently_explained` boolean che produce la frase canonica;
- drill_down neutrale per Spese figlie.

## Vocaboli chiusi

### ComparisonCategory

- unchanged;
- added;
- removed;
- modified.

### ModificationDimension

- allocation_or_estimate;
- actual;
- carryover;
- cost_center;
- supplier;
- container;
- state_or_transitions;
- contract_economics;
- deadline_renewal_termination;
- archive_or_reversal;
- informative_relations.

### SecondaryLabel

- unplanned;
- planned_not_occurred;
- without_actuals;
- reversed;
- cancelled;
- deferred;
- late_correction;
- carryover_changed;
- historical_attribution_disputed;
- contract_expiry_in_selected_interval;
- undefined_expiry.

`replaced` non appartiene al vocabolario S11 perché la §32 ha rimosso permanentemente l'etichetta corrispondente dal dominio.

## Regole di costruzione per riferimento

### Budget

- Usa esclusivamente header/righe materializzate della versione selezionata.
- `allocation = approved_allocation`.
- Non popola actual/residual/operational variance come baseline.

### Current

- Usa oggetti vivi della stessa Azienda/Esercizio.
- Aggrega una volta Spese autonome, Progetti e Contratti.
- Stato a `DataRiferimentoEsercizio` salvo data esplicita ammessa.

### Closing

- Usa esclusivamente ClosingSnapshot e ClosingSourceRow materializzate.
- Stato al 31 dicembre e valori finali immutabili.

### Current knowledge

- Per Esercizio Chiuso: copia logica della riga Closing più correzioni tardive raggruppate per `source_origin_key`; `actual = closing_actual + net_corrections`.
- Mantiene label, classificazione, supplier, stato, Residuo/Risparmio/Allocato non utilizzato e Riporto della Chiusura.
- Aggiunge Annotazioni collegate senza cambiare importi.
- Per Esercizio Aperto, non esistono correzioni tardive canoniche; il riferimento coincide numericamente con l'Effettivo corrente ma resta nominato solo dove il report lo ammette.

## Regole di categoria

1. solo finale → added;
2. solo iniziale → removed;
3. entrambi, tutte le dimensioni uguali → unchanged;
4. entrambi, almeno una dimensione diversa → modified.

Storno o azzeramento materializzato nel finale resta `modified`.

## Regole etichette previsionali

- Valutate solo sulle sorgenti di primo livello.
- `unplanned`: Budget selezionato assente o Allocato approvato zero, e finale con Allocato non zero o HaEffettivi vero.
- `planned_not_occurred`: Allocato approvato positivo, HaEffettivi finale falso e almeno una condizione terminale del §25.9.
- `without_actuals`: sorgente ancora operativa in Esercizio Aperto con Allocato approvato positivo e HaEffettivi falso.

## Aggregazioni

- Executive/category totals: una voce per ReportSource primaria.
- Project/Contract: importi delle Spese figlie inclusi nel proprietario, mai sommati di nuovo.
- Supplier: aggregazione dalle Spese; Stima/Spese Contratto al Fornitore Contratto; Riporto Progetto in bucket dedicato.
- Ogni aggregatore espone componenti che devono riconciliarsi esattamente col totale.

## Persistenza e lifecycle

- Nessuna tabella S11.
- Nessun lifecycle di report o export.
- Il PDF esiste solo nella risposta HTTP; nessun file permanente o job.
- Qualunque riferimento incoerente interrompe la costruzione prima di produrre output.
