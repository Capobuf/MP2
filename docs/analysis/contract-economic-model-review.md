# Revisione del modello economico dei Contratti

Data: 3 ottobre 2026. Repository esaminato: commit `fe5839e38afb3c33cbe96e896bd4184038000e64`.

Questo è un rapporto di analisi e raccomandazione, non una specifica già approvata né un'implementazione. Le regole indicate come **proposte** richiedono il successivo recepimento nel dominio. L'attività ha prodotto esclusivamente questo documento: nessuna modifica a codice, test, database o Specifica Canonica.

Fonti: richiesta dell'utente, `AGENTS.md`, sezioni pertinenti di `docs/domain/Specifica_Canonica_Semplificata_v4.md`, implementazione, migrazioni e test correnti. Non sono stati consultati i piani storici delle slice. I percorsi riportati sono relativi alla radice del repository; i nomi dei metodi identificano i punti verificati.

## 1. Executive summary

MP2 distingue già la durata del Contratto dal ciclo economico, ma ammette soltanto Stime contrattuali generate da Condizioni ricorrenti. La specifica richiede una Condizione alla creazione e alla riattivazione e vieta le Stime manuali nelle Spese di Contratto. Il problema Sophos è quindi una lacuna del perimetro economico deliberatamente scelto, non soltanto un campo mancante nel form.

Per Sophos il requisito è: accordo dal 22/02/2025 al 22/02/2028, senza rinnovo, costo complessivo di **2.621,34 € nel 2025**, senza ripartizione negli anni successivi. La validità della licenza continua anche quando l'Allocato annuale è zero. Una Condizione annuale aperta genererebbe un costo anche nel 2026, 2027 e, con queste date inclusive, il 22/02/2028. Una Condizione artificialmente limitata a un solo ciclo può imitare il risultato numerico, ma falsifica la natura del costo e non risolve bene setup più canone.

**Raccomandazione: combinare B e D.** Consentire Contratti con zero o più Condizioni ricorrenti non sovrapposte; utilizzare le Spese manuali già esistenti, collegate al Contratto e a un Esercizio, anche per Stime non ricorrenti. Mantenere le Condizioni come unico meccanismo di generazione automatica e gli Effettivi come registrazioni indipendenti. Non introdurre un'entità Componente, un Piano economico persistente, una frequenza “una tantum”, fatture o pagamenti.

La formula proposta è:

```text
Allocato Contratto nell'anno = Stima automatica ricorrente + Stime manuali non ricorrenti
Effettivo Contratto nell'anno = Effettivi delle Spese manuali
Scostamento operativo = Effettivo − Allocato
```

La Spesa di sistema materializza la prima componente: non va sommata nuovamente al risultato del motore. Il totale di Esercizio conta il Contratto una sola volta, oppure somma le sue righe nel percorso equivalente già usato da `Exercise`, senza aggiungere anche il contenitore.

Lo schema relazionale possiede già gli elementi necessari. **Non serve una migrazione per rappresentare il nuovo costo.** Serve però un adeguamento coerente di applicazione e dominio: abilitare il form da solo farebbe fallire la quadratura del Budget e la conferma della Chiusura. Inoltre le Proposte conoscono le Spese del Contratto nella baseline, ma non dispongono di un percorso completo per pianificarle.

I rischi principali sono: doppio conteggio della Stima di sistema; differenze fra totale e dettaglio; perdita apparente della componente manuale nella proiezione di Chiusura; riutilizzo improprio della Stima come pagamento atteso; riallineamento incompleto delle Proposte; rappresentazione retroattiva di Stime in anni chiusi. È stata individuata anche una lacuna preesistente nel report a Conoscenza Corrente per sorgenti assenti dalla Snapshot: deve essere affrontata per coprire completamente il censimento tardivo.

La soluzione non automatizza il costo a ogni rinnovo pluriennale: quello è un problema di ricorrenza distinto. Gli eventi economici già noti possono essere pianificati esplicitamente nei rispettivi Esercizi, senza clonazioni automatiche. Le limitate decisioni di dominio ancora necessarie sono isolate nel §13.

## 2. Modello attuale

### 2.1 Ricostruzione prima della scelta della soluzione

Questo passaggio descrive il sistema esistente, senza attribuirgli il comportamento raccomandato più avanti.

Un **Contratto** è un'identità durevole con titolo, Fornitore obbligatorio, data di inizio, termini di rinnovo, scadenze, eventi di stato, allegati e classificazioni annuali. Una licenza valida per tre anni è, sotto il profilo temporale, rappresentabile. Il Contratto non è una fattura e non contiene uno stato di pagamento.

Una **Condizione** descrive un importo per ciclo, la frequenza, l'attribuzione all'inizio o alla fine del ciclo e un intervallo di validità. Le frequenze sono mensile, trimestrale, semestrale e annuale. Due Condizioni Valide dello stesso Contratto non possono sovrapporsi: oggi rappresentano una successione di tariffe, non componenti economiche parallele.

Una **Spesa** appartiene a un Esercizio e a un solo contenitore economico: autonoma, Progetto oppure Contratto. Le righe hanno due tipi: Stima ed Effettivo. Le Spese figlie alimentano il contenitore; non sono nuove sorgenti da aggiungere al totale generale.

Fonti: specifica §§5–8, 15 e 18; `app/Models/Contract.php`, `Expense.php`, `ExpenseLine.php`; migrazioni `2026_08_20_000500_create_contract_conditions_table.php` e `2026_08_20_000700_add_contract_ownership_to_expenses_table.php`.

### 2.2 Come nasce la Stima automatica

`app/Domain/Contracts/ContractAnnualAllocation.php::forYear()`:

1. ignora le Condizioni annullate;
2. enumera i cicli tramite `ContractCycle::enumerate()`;
3. ammette il ciclo se il Contratto è Attivo alla data di inizio del ciclo;
4. attribuisce l'intero importo all'anno della data di attribuzione;
5. conserva la composizione con ID Condizione, inizio ciclo, data di attribuzione e importo.

L'ancora è sempre il `valid_from` originario. L'aggiustamento a fine mese non si propaga ai mesi successivi. “Fine ciclo” significa **inizio del ciclo successivo**, non ultimo giorno del ciclo e non scadenza di pagamento. Un ciclo già iniziato non viene ridotto proporzionalmente; può produrre una Stima in un anno successivo o dopo la cessazione.

`app/Actions/Operations/RecalculateContractEstimates.php` materializza il risultato nell'unica Spesa `origin=system` per Contratto/Esercizio. Se il risultato positivo scompare, conserva Spesa e Riga a zero. Se la Spesa non è mai esistita e il risultato è zero, non la crea. Opera solo su Esercizi Aperti della stessa Azienda.

L'unicità è anche un vincolo DB, mediante colonne generate e indice `expenses_generated_contract_exercise_unique`. Non è un'unicità estesa alle Spese manuali.

### 2.3 Spese, Effettivi, quantità e importi

| Tipo di Spesa | Stime | Effettivi | Origine | Classificazione |
|---|---|---|---|---|
| Autonoma | Manuali | Manuali | `manual` | Centro di Costo diretto, Fornitore opzionale |
| Di Progetto | Manuali | Manuali | `manual` | Centro di Costo annuale del Progetto, Fornitore opzionale |
| Di Contratto, generata | Una Stima aggregata dalle Condizioni | Vietati | `system` | Fornitore e Centro di Costo annuale del Contratto |
| Di Contratto, manuale | Vietate | Manuali | `manual` | Fornitore e Centro di Costo annuale del Contratto |

L'Effettivo non consuma una specifica Stima e non viene abbinato a un ciclo. Può essere maggiore, minore o uguale alla previsione. Il saldo può essere negativo per accrediti, rimborsi o correzioni con Nota. `HaEffettivi` considera l'esistenza di una Riga Effettivo Attiva di importo non nullo, non soltanto il saldo: +100 e −100 non autorizzano lo Storno della Spesa.

Quantità e importo unitario supportano sei decimali, ma **l'importo della Riga a due decimali è autoritativo**. `ManualExpenseLine::suggestedAmount()` propone il prodotto; una differenza richiede presa visione. Per Sophos: quantità 18, importo unitario 145,63, importo 2.621,34, unità “licenze”. Non è necessario aggiungere questi campi al Contratto o alla Condizione.

Le operazioni `CreateExpense`, `CreateExpenseLine`, `UpdateExpenseLine`, `SetExpenseLineActive` e `SetExpenseReversed` applicano i vincoli all'ingresso. `ContractExpenseActivity::assertActualOnly()` vieta oggi qualsiasi Stima manuale; `validate()` richiede inoltre il Contratto Attivo alla registrazione per un Effettivo ordinario. Per Cessato/Annullato ammette gli specifici Effettivi terminali con Nota; Pianificato non può ricevere Effettivi ordinari. Non esiste una data strutturata dell'Effettivo distinta dalla registrazione tecnica.

Dopo un Budget approvato, le operazioni previste dalla specifica richiedono motivazione. Le righe possono essere modificate, annullate e ripristinate negli anni aperti; una Spesa può essere Stornata solo senza `HaEffettivi`. Spese e righe persistite non vengono eliminate fisicamente.

### 2.4 Ciclo di vita e classificazioni

- `CreateContract` richiede almeno una Condizione, crea configurazione di rinnovo, attivazione e classificazioni per gli Esercizi Aperti. Ammette censimento storico con data reale, ma ricalcola solo anni aperti.
- `SaveContractEdits`, `ChangeContractCondition` e `CorrectContractCondition` distinguono un nuovo accordo da una correzione materiale. La successione già avvenuta deve rispettare un confine di ciclo e non alterare anni chiusi. Le modifiche future ordinarie seguono la data minima e il confine del ciclo corrente.
- `ProcessContractRenewals` materializza rinnovi e scadenze; la durata del rinnovo è in mesi ed è distinta dalla frequenza delle Condizioni. Le Condizioni aperte proseguono; quelle finite non vengono prolungate implicitamente.
- `CeaseContract` chiude le Condizioni aperte applicabili e ricalcola. Il giorno dichiarato è l'ultimo giorno Attivo; la variazione di stato è il successivo. I cicli iniziati restano interi.
- `ReactivateContract` richiede oggi una nuova Condizione. Non prolunga automaticamente le precedenti.
- `CancelContract` annulla un Contratto Pianificato mai attivato, annulla le Condizioni e ricalcola. Non ha operazioni sulle Stime manuali, oggi vietate.
- `SetContractArchived` cambia la visibilità nei limiti dello stato terminale. L'Archivio non elimina valori. `Contract::booted()` vieta la cancellazione ordinaria.
- `UpdateContractClassification` usa `ContractClassificationImpactPlan` e i totali annuali. Una riclassificazione viva può mostrare l'impatto sugli Effettivi; la Proposta non può riclassificare Effettivi.
- `ContractEconomicUse` blocca il cambio di Fornitore dopo l'uso economico rilevabile; include Budget, Chiusura, Stime di sistema e righe Effettivo. È un punto da estendere se diventano lecite Stime manuali.

### 2.5 Le definizioni economiche realmente usate

| Grandezza/percorso | Implementazione attuale | Osservazione |
|---|---|---|
| Stima automatica annuale | `ContractAnnualAllocation::forYear()` | Solo Condizioni e stato alla data |
| Totali annuali persistiti del Contratto | `Contract::annualTotals()` | Somma tutte le Stime/Effettivi attivi di Spese non Stornate; non filtra l'origine per la Stima |
| Stima, Effettivo e scostamento di una Spesa | `Expense::allocation()`, `actual()`, `operationalVariance()` | Somma righe attive; Spesa Stornata vale zero |
| Totale annuale di Esercizio | `Exercise::allocation()`, `actual()` | Tutte le righe dell'anno, più Riporto dei Progetti per l'Allocato; non aggiunge di nuovo i contenitori |
| Situazione Corrente/report Contratti | `BuildReport::currentSources()`, `loadedExpenseTotals()` | Aggregazione delle Spese persistite |
| Elenco e dettaglio Filament dei Contratti | `ContractsTable::annualValues()`, `ContractInfolist::annualRow()` | Allocato ricalcolato dalle sole Condizioni; Effettivi dalle Spese manuali |
| Totale della riga Budget | `BudgetSnapshotPayload::allocation()` | Stime delle Spese persistite dell'anno |
| Dettaglio del Contratto nel Budget | `BudgetSnapshotPayload::contractDetail()` | `approved_estimate_total` dalle sole Condizioni |
| Anteprima Chiusura | `ContractClosingProjection::build()` | Prima: totale persistito; dopo: sole Condizioni proiettate |
| Snapshot di Chiusura | `ClosingSnapshotPayload::contractRow()` | Totali persistiti quando presenti, composizione ricorrente calcolata, dettaglio di tutte le Spese |
| Impatto della Proposta sul Contratto | `ProposalImpactPlan::contractImpact()` | Prima e dopo dalle Condizioni, senza componente manuale |
| Scostamenti/confronti | `Decimal`, `ComparisonEngine`, `ReportAggregator`, dashboard | Effettivo−Allocato; Allocato−Budget; Effettivo selezionato−Budget |

Questi percorsi sono coerenti finché vale l'invariante attuale “nessuna Stima manuale di Contratto”. Non rappresentano già una soluzione completa per rimuoverlo.

## 3. Perché il caso non funziona

### 3.1 Sophos: quattro ostacoli distinti

**Limite voluto della specifica.** §§15.4 e 18.22 escludono setup e una tantum pianificate nel Contratto; §§6.5 e 8.5 definiscono l'Allocato come derivato dalle Condizioni. §18.2 richiede almeno una Condizione. È necessario modificare queste regole, non aggirarle.

**Limite del codice applicativo.** `CreateContract` valida `conditions` con `required`, `array`, `min:1`; `ContractExpenseActivity` vieta le Stime nelle Spese manuali. Anche spostando una Spesa, `UpdateExpense::validateContext()` controlla tutte le righe in ingresso, comprese quelle annullate, tramite `assertActualOnly()`. Riattivazione e Proposte ripetono l'assunzione.

**Limite UI.** `ContractForm` impone almeno una Condizione alla creazione. `ExpenseForm` e `ExpenseLinesRelationManager` limitano i tipi disponibili per il Contratto. Il dettaglio annuale mostra la composizione dei cicli come spiegazione dell'intero Allocato.

**Decisione di dominio nuova.** MP2 non deve ripartire il costo lungo la validità della licenza. L'anno operativo del costo non si ricava dalla durata dell'accordo. Per la Stima non ricorrente deve essere una scelta esplicita dell'Esercizio; per l'Effettivo valgono le regole operative e tardive esistenti.

### 3.2 Verifica numerica eseguita sul motore reale

Sono state invocate in memoria le classi di dominio tramite l'autoload locale, senza avviare Laravel e senza connettersi al database.

| Input al motore corrente | 2025 | 2026 | 2027 | 2028 |
|---|---:|---:|---:|---:|
| Annuale 2.621,34 €, dal 22/02/2025; Contratto Attivo fino al 22/02/2028 incluso | 2.621,34 | 2.621,34 | 2.621,34 | 2.621,34 |
| Stessa Condizione con `valid_to=22/02/2025` | 2.621,34 | 0,00 | 0,00 | 0,00 |
| Requisito Sophos | 2.621,34 | 0,00 | 0,00 | 0,00 |

La seconda configurazione dimostra che il numero è imitabile, ma per mezzo di una falsa Condizione annuale limitata a un giorno. Non è una rappresentazione semanticamente corretta da raccomandare. Inoltre occupa un intervallo esclusivo rispetto a un eventuale canone nello stesso Contratto.

Il motore con elenco vuoto di Condizioni restituisce già `0.00`: il requisito “almeno una” è un vincolo di creazione/pianificazione, non una necessità matematica del calcolo.

### 3.3 Perché non basta separare la Spesa

Una Spesa autonoma nel 2025 può rappresentare 2.621,34 € correttamente nel totale aziendale. Non alimenta però il Contratto Sophos, non compare nella sua storia economica, non ne eredita la classificazione e diventa una diversa sorgente nei confronti con il Budget. Una nota con il nome del Contratto non è una relazione economica.

La relazione `ProjectContractLink` è informativa fra Progetto e Contratto: non trasporta il costo dal Progetto al Contratto. Inventare una relazione informativa Spesa–Contratto lascerebbe comunque irrisolta l'appartenenza economica e aggiungerebbe un concetto.

### 3.4 Altri problemi che Sophos rende visibili

Setup + canone non è modellabile con Condizioni contemporanee perché si sovrapporrebbero. Contratto gratuito, costo ancora sconosciuto e accordo quadro senza prezzo richiedono oggi una Condizione artificiale a zero, oppure non superano la creazione. Il dato zero da solo non può distinguere queste tre situazioni.

Un costo annuale anticipato **già ricorrente** è invece rappresentabile con attribuzione a inizio ciclo. “Anticipato” non basta a identificarlo come una tantum: conta se la previsione si ripete, non quando sia stato sostenuto un Effettivo.

## 4. Assunzioni e vincoli attuali

### 4.1 Matrice delle assunzioni

| Assunzione | Specifica | Codice | UI | Esito dell'analisi |
|---|---|---|---|---|
| Nuovo Contratto con almeno una Condizione | §18.2 | `CreateContract`, `ContractPlan::validateForApproval` | Repeater minimo 1 | Da rimuovere nella proposta |
| Riattivazione con nuova Condizione | §18.8 | `ReactivateContract`, `ContractPlan::lifecycle` | Form del ciclo di vita | Da rendere facoltativa, senza riaprire Condizioni vecchie |
| Manuale di Contratto = soli Effettivi | §15.4 | `ContractExpenseActivity`, ingressi e ripristini | Tipo Stima escluso | Da estendere |
| Allocato = ricorrenze | §§6.5, 8.5 | UI, Proposte, dettaglio Budget, proiezione Chiusura | Composizione solo cicli | Da distinguere in componente ricorrente e totale |
| Condizioni non sovrapposte | §18.11 | Regole, piani, validazione Chiusura | Successione di tariffe | Da mantenere per le Condizioni ricorrenti |
| Effettivi indipendenti dalle Stime | §§5.3, 18.19 | Righe senza matching | Registrazione separata | Da mantenere |
| Sistema non editabile | §15.4 | Actions di Spesa/Riga | Mutazioni nascoste/bloccate | Da mantenere |
| Nessun Riporto del Contratto | §18.20 | Chiusura e inizializzazione N+1 | Nessuna azione di Riporto | Da mantenere anche per le Stime manuali |
| Esercizi chiusi e Snapshot immutabili | §§14, 23–24 | Policy, actions, guard storici | Operazioni tardive distinte | Da mantenere |
| Sorgenti di primo livello distinte dalle figlie | §§5.6, 8.6, 25.3 | Cataloghi e aggregazioni | Contratto come sorgente | Da mantenere |

### 4.2 Problemi verificati e conseguenze della rimozione ingenua dei vincoli

**P1 — Budget: totale e dettaglio non quadrerebbero.** Con ricorrente 1.200 e setup manuale 300, `BudgetSnapshotPayload::allocation()` produce 1.500, mentre `contractDetail()` produce 1.200. `assertConsistent()` rileva la differenza e blocca l'approvazione. È una conseguenza del nuovo dato se si modificassero soltanto i form/actions; non un errore dei Budget oggi validi.

**P2 — Chiusura: anteprima e applicazione divergerebbero.** `ContractClosingProjection::build()` usa 1.500 come `allocation_before`, ma 1.200 come `allocation_after`, producendo un falso delta −300. Il ricalcolo effettivo aggiorna solo la Spesa `system` e lascia la manuale. `CloseExercise` confronta poi Snapshot e anteprima e rileva la differenza. Il rischio principale è un blocco/coerenza errata, non una cancellazione fisica già presente delle righe manuali.

**P3 — Proposte: dato nella baseline, ma nessuna pianificazione completa.** `ProposalSourceSnapshot::contract()` include già `expense_plan`. Tuttavia non registra `origin` nel sottopiano Spesa; il catalogo non crea elementi individuali per le figlie; i payload di `create_expense` e `set_expense_owner` non ammettono riferimenti al Contratto; `ApplyContractPlan` non applica modifiche alle sue Spese manuali. La capacità parziale di `ApplyExpensePlan` di leggere `contract_id` non rende il flusso supportato.

**P4 — Prima Condizione aggiunta dopo il censimento senza prezzo.** `ContractForm` rende aggiungibile una Condizione in modifica soltanto se esiste una precedente; `SaveContractEdits::changes()` rifiuta la nuova Condizione quando `$last === null`. Eliminare solo `minItems(1)` crea un Contratto che non può ricevere la prima Condizione attraverso il normale percorso di modifica. Va gestito questo ingresso esplicitamente.

**P5 — Stima manuale non rilevata come uso economico.** `ContractEconomicUse::exists()` considera Stime `system` e righe Effettivo, ma non Stime `manual`. Con la nuova funzionalità consentirebbe un cambio di Fornitore dopo una previsione manuale, salvo presenza di Budget/Chiusura. `UpdateContract` propagherebbe il Fornitore alle Spese: l'estensione del predicato è necessaria.

**P6 — Storico annuale UI ricostruito dalle regole vive.** `ContractInfolist::annualRow()` calcola dalle Condizioni anche gli anni chiusi; non legge la Snapshot di Chiusura. Può quindi mostrare una previsione ricostruita che non era materializzata alla Chiusura di un anno, soprattutto dopo censimento tardivo. La classe `ContractAnnualSituationsRelationManager` presenta la stessa assunzione, ma **non risulta registrata** in `ContractResource::getRelations()`; la vista annuale attiva è nell'Infolist. Non va descritta come un secondo tab già visibile.

**P7 — Correzione tardiva di sorgente assente dalla Snapshot.** `HistoricalCorrectionSource::contracts()` ammette, fra gli altri criteri, un Contratto la cui data reale precede la fine dell'anno; non richiede una riga nella Snapshot. `RecordLateCorrection` può quindi registrare un Effettivo tardivo del Contratto censito dopo la Chiusura. `BuildReport::closingSources()` costruisce però le sorgenti soltanto con `snapshot->rows->map(...)`, applicando correzioni alle sole chiavi presenti. `annualTotals()` somma invece tutte le correzioni dell'anno. Ne consegue una possibile omissione nel dettaglio/aggregato per sorgente, pur con importo incluso nel totale a Conoscenza Corrente. È una lacuna preesistente dedotta dal percorso completo, non riprodotta su DB in questa attività.

**P8 — Compatibilità storica troppo restrittiva per il nuovo modello.** `HistoricalExpenseCompatibility::accepts()` rifiuta una Spesa di Contratto con qualsiasi Riga Stima. Dopo l'estensione, una Spesa manuale con Stima è un contenitore legittimo di Effettivi; il divieto deve restare sulla Spesa di sistema, non su quella manuale. Oggi il rifiuto può provocare una nuova Spesa tardiva invece dell'aggiunta alla stessa Spesa storica.

**P9 — Avvisi e linguaggio non equivalgono al dominio desiderato.** La Chiusura usa composizione ricorrente vuota per avvisare “senza condizione applicabile”, anche quando una Condizione esiste ma attribuisce altrove. L'assenza di costo nell'anno può essere corretta. Inoltre `RegisterContractPayment` espone già “Registra Pagamento”, pur creando esclusivamente una Riga Effettivo: è un'incongruenza terminologica esistente da non propagare nella nuova esperienza.

**P10 — Il lettore dei report Budget non filtra le righe figlie.** `BudgetSnapshotPayload::build()` materializza una riga per elemento incluso, ma somma nell'header soltanto contenitori e Spese autonome. `BuildReport::budgetSources()` converte invece tutte le righe in sorgenti; `applyFilters()` non esclude le contenute e `ReportAggregator::executive()` somma tutte le sorgenti ricevute. Se la Snapshot contiene padre e figlia, un report generale può quindi contarli entrambi. Il rischio è già presente per i piani che materializzano figli e diventerebbe rilevante per le nuove manuali contrattuali. Il filtro deve usare l'owner materializzato nel riferimento, senza modificare Snapshot o inferirlo dal contenitore vivo. È una deduzione statica, non una riproduzione su DB.

### 4.3 Limiti che restano invarianti

Importi EUR netti IVA, calcolo decimale, niente prorata automatico, nessun matching, nessun consumo di Stima, nessuna generazione di Effettivi, nessuna riscrittura di Budget e Chiusure, appartenenza a una sola Azienda e a un solo contenitore, nessuna cancellazione fisica ordinaria. La richiesta non giustifica un cambiamento di questi principi.

## 5. Alternative analizzate

### 5.1 A — Mantenere il modello, costi non ricorrenti fuori dal Contratto

**Cambiamento:** nessuno; Spesa autonoma o di Progetto con Stima/Effettivo e una descrizione esplicativa.

**Vantaggi:** perimetro già definito e implementato; nessuna migrazione, nuovo formato o regressione introdotta. Quantità e importi già disponibili.

**Svantaggi e copertura:** copre il totale aziendale del costo una tantum, ma perde l'appartenenza economica al Contratto, la sua storia unitaria, la classificazione ereditata e il confronto a pari sorgente. Setup + canone diventa due sorgenti diverse. Non consente un Contratto senza Condizioni e contraddice il requisito esplicito di non scollegare artificialmente il costo.

**Dominio/codice/DB:** invariati, salvo eventuali spiegazioni UI. Budget, Snapshot e backup invariati. Il doppio conteggio nasce se il costo resta fuori e viene anche aggiunto al canone o al totale contrattuale “per completezza”. **Non raccomandata.**

### 5.2 B — Consentire Contratti senza Condizioni

**Cambiamento:** eliminare il minimo alla creazione, nella Proposta e nella riattivazione, gestire l'aggiunta della prima Condizione e rivedere gli avvisi.

**Vantaggi:** separa davvero esistenza dell'accordo e ricorrenza; copre gratuito, quadro, prezzo ignoto e Contratto con soli Effettivi. Il motore accetta già zero Condizioni.

**Svantaggi e copertura:** da sola non pianifica una tantum interne. Sophos può avere un Effettivo 2025 e Allocato zero, ma non una Stima 2025 di 2.621,34. Non risolve setup pianificato + canone.

**Impatto:** modifica §§18.2 e 18.8, `CreateContract`, `ReactivateContract`, `ContractPlan`, form/modifica e avvisi. Nessuna nuova tabella o modifica strutturale alle Snapshot/backup; verificare presenza delle sorgenti a zero. Rischio basso sul calcolo dei ricorrenti, concreto sui percorsi che si aspettano una prima Condizione. Nessun doppio conteggio aggiuntivo. **Necessaria, insufficiente da sola.**

### 5.3 C — Aggiungere una Condizione “una tantum”

**Cambiamento corretto sul piano semantico:** non un quinto valore della frequenza; servirebbe distinguere natura ricorrente/non ricorrente, con attribuzione singola per la seconda e campi coerenti. La Condizione cesserebbe di significare soltanto tariffa valida nel tempo.

**Vantaggi:** centralizza la pianificazione del Contratto in un insieme di elementi; il generatore potrebbe materializzare in una sola Spesa di sistema sia canoni sia costi singoli. Budget e Proposte continuerebbero a trattare un piano contrattuale.

**Problemi verificati contro il codice:**

- una tantum e canone devono poter coesistere nella stessa data: il divieto globale di sovrapposizione dovrebbe diventare selettivo;
- `ContractCycle`, `ContractCycleType`, `ContractEconomicChangePlan`, creazione, correzione, annullamento, Chiusura e `ContractPlan` assumono mesi, ancora e confini di ciclo;
- `valid_from/valid_to` perderebbero uniformità: per il costo singolo occorre scegliere data o anno di attribuzione, separandoli dalla validità della licenza;
- cessazione, annullamento e riattivazione non possono limitare automaticamente tutti gli elementi nello stesso modo: un setup già sostenuto o un costo di cessazione non è un ciclo da sopprimere;
- rinnovo non deve rigenerare l'evento singolo; “una volta” e “una volta a ogni rinnovo” sono diversi;
- quantità e importo unitario stanno già sulle righe, non sulle Condizioni: riportarli su una Condizione richiederebbe altri campi o una spiegazione descrittiva separata;
- modifiche a importo/data del costo singolo non devono usare la regola “primo confine di ciclo dal mese successivo”.

**Copertura:** può coprire singoli e misti solo completando queste regole; non basta aggiungere un enum. Contratti privi di prezzo richiedono comunque B. Il costo a ogni rinnovo resta una categoria ulteriore.

**DB/migrazioni:** almeno estensione/correzione in avanti della struttura delle Condizioni, enum/vincoli e semantica dei campi; eventuali nuovi campi per natura e attribuzione. I vecchi record devono restare ricorrenti. Composizione e dettagli Snapshot devono distinguere gli elementi; backup deve supportarne campi/valori e importazione. Nessuna riscrittura degli snapshot storici.

**Rischio/complessità:** maggiore invasività sul motore oggi deterministico. Doppio conteggio evitabile mantenendo un solo generatore, ma alto se lo stesso evento fosse ammesso anche come Stima manuale. Selezionando C occorrerebbe vietare D per la stessa funzione. **Alternativa credibile ma sproporzionata rispetto al riuso delle Spese.**

### 5.4 D — Consentire Stime manuali nelle Spese di Contratto

**Cambiamento:** estendere la Spesa manuale a Stima, Effettivo o entrambi; conservare il vincolo esclusivo e protetto della Spesa `system`. L'Esercizio della Spesa attribuisce il costo singolo; la durata del Contratto non lo distribuisce.

**Vantaggi:** riusa importi, quantità, righe, Fornitore, classificazione, Storno, audit e identità già presenti. Gestisce più costi nello stesso anno o in anni diversi; costo solo pianificato; sola realtà senza previsione; scostamenti positivi e negativi. La separazione dal generatore è già espressa da `origin`.

**Svantaggi:** richiede adeguamenti trasversali P1–P10; la pianificazione in Proposta non è pronta. Gli utenti devono capire che la Stima manuale è un costo **aggiuntivo** alla ricorrenza, non una copia del canone o del totale contrattuale.

**Copertura:** con B copre Sophos, setup + ricorrente, gratuito/ignoto e costi annuali espliciti. Non genera automaticamente costi ai rinnovi pluriennali. Non rende lecite Stime retroattive negli anni chiusi.

**DB/migrazioni:** nessun nuovo campo necessario per rappresentare i costi; i vincoli DB esistenti ammettono una Riga Stima in Spesa `manual` con `contract_id`. Nessuna conversione dei ricorrenti o degli Effettivi. Il nuovo dettaglio Budget deve includere le Spese manuali; la Snapshot di Chiusura già conserva le Spese. I payload nuovi vanno versionati se cambia il contratto del dettaglio, mantenendo lettori dei vecchi. Il workbook di backup ha già Spese, righe, origine e riferimenti: non serve un nuovo formato solo per il nuovo uso.

**Doppio conteggio:** controllabile, ma non automaticamente risolto da `origin`. Servono formule univoche, esclusione delle figlie dai totali e un'unica rappresentazione della pianificazione nelle Proposte (§7). Nessun riconoscimento per descrizione/importo.

**Esito:** miglior compromesso con B, a condizione di implementare l'intero percorso e non solo la UI.

### 5.5 E — Introdurre una Componente economica del Contratto

**Cambiamento:** nuova entità per elementi ricorrenti e singoli, con generazione delle Stime e collegamento al Contratto.

**Vantaggi:** può modellare distintamente diversi servizi, condizioni contemporanee, attribuzioni singole e ricorrenti, se diventassero requisiti reali.

**Svantaggi:** duplica le responsabilità oggi distribuite fra Condizione e Spesa. Richiede decidere se sostituire le Condizioni, contenerle o affiancarle. Se le affianca, crea due modi di modellare il costo singolo o la ricorrenza; se le sostituisce, impone migrazione e compatibilità di storia e identità.

**Copertura:** tutti i casi singoli/misti sono esprimibili, ma gratuito/ignoto richiedono ancora consentire l'insieme vuoto. Il rinnovo economico richiederebbe comunque regole proprie.

**Impatto:** nuove tabelle/relazioni, actions, autorizzazioni, cataloghi, riferimenti nelle Proposte, Snapshot e backup; migrazione in avanti delle Condizioni o mantenimento di due percorsi. Rischio di doppio conteggio durante la coesistenza e rischio regressioni su tutto il motore. Complessità non giustificata dal requisito. **Non raccomandata.**

### 5.6 F — Separare Contratto, Piano economico ed Effettivi

Il modello già separa implicitamente questi concetti: `Contract` conserva l'accordo; Condizioni e Stime sono piano/previsione; Effettivi sono realtà; Proposta è un insieme di azioni sul piano; Budget e Chiusura sono riferimenti immutabili.

**Variante minima:** rendere esplicita questa separazione nelle regole rimuovendo i vincoli che la comprimono. Coincide con B+D, senza introdurre un oggetto Piano.

**Variante strutturale:** aggiungere un Piano economico persistente. Renderebbe necessario sincronizzare Piano, Condizioni, Spese e Proposte, oppure migrare a un nuovo modello. Nessun caso richiesto dimostra la necessità di questa ulteriore identità/versione. Costi DB, migrazioni, Snapshot e backup simili o superiori a E; rischio di doppio conteggio fra piano e righe.

**Esito:** valida come lettura concettuale del modello esistente; non come nuova architettura.

### 5.7 G — Altre soluzioni verificate

**G1 — Condizione annuale limitata a un solo ciclo.** Nessuna modifica DB, ma semantica falsa per Sophos, conflitto di sovrapposizione con setup + mensile, avvisi negli anni successivi e tariffe apparentemente scadute. Non scelta, pur avendo dimostrato il risultato numerico.

**G2 — Condizione ricorrente a zero + soli Effettivi.** Registra la realtà ma manca la previsione; confonde gratuità e costo sconosciuto e introduce una ricorrenza priva di significato. Nessun formato nuovo, ma peggiora la qualità dei dati. Non scelta.

**G3 — Importo una tantum direttamente sul Contratto.** Semplice soltanto per un costo: servirebbero anno, quantità e storico; un secondo costo richiede subito un elenco, cioè una nuova entità. Duplica le Spese e complica Budget/Chiusura. Non scelta.

**G4 — Usare un Progetto per ogni Contratto pluriennale.** Il costo può essere associato al Progetto e collegato informativamente al Contratto, ma eredita un diverso ciclo di vita e potenziali decisioni di Riporto/Riprogrammazione. Il Contratto continua a non avere il proprio costo. Non soddisfa il requisito.

**G5 — Introdurre un ciclo pluriennale o “al rinnovo”.** Una frequenza fissa ogni 36 mesi è una ricorrenza; un importo a ogni rinnovo dipende dagli eventi di rinnovo e dalle loro modifiche. Sono due semantiche distinte, nessuna delle quali serve a rappresentare un costo singolo senza rinnovo. Potenziale estensione mirata solo se l'automazione del caso 25 diventa richiesta: modifiche a enum/calcolo, condizioni, Proposte, backup e composizioni, non un generatore generalizzato.

## 6. Stress test delle alternative

La candidata iniziale al termine del confronto era **B+D**, confrontata con **B+C semanticamente completa**. La colonna C non presuppone che basti aggiungere un valore a `cycle`. Gli esiti sono verifiche progettuali contro le regole e i percorsi letti; non test di una funzionalità già implementata.

### 6.1 Le 25 casistiche richieste

| # | Caso | B+D | B+C | Controesempio o vincolo da rispettare |
|---|---|---|---|---|
| 1 | Mensile puro | Condizione invariata | Condizione ricorrente invariata | 100 × 12 = 1.200 per anno pieno, attribuzione inizio |
| 2 | Trimestrale/semestrale/annuale | Motore invariato | Motore da preservare nel ramo ricorrente | 100 per ciclo: 400/200/100 nell'anno pieno |
| 3 | Pluriennale interamente all'inizio | Stima manuale nell'anno iniziale; Effettivo separato | Elemento singolo con attribuzione iniziale | Mai suddividere il costo per durata |
| 4 | Annuale anticipato | Condizione annuale a inizio ciclo se ricorrente; Spesa singola se costo non ripetibile | Distinzione ricorrente/singolo | La modalità di registrazione dell'Effettivo non determina la frequenza |
| 5 | Setup + mensile | Setup manuale + Stima `system` mensile | Richiede deroga selettiva alle sovrapposizioni | 300 + 1.200 = 1.500, non 2.700 |
| 6 | Setup + annuale | 300 manuali + 1.000 ricorrenti | Come sopra | Dettaglio Budget deve spiegare tutti i 1.300 |
| 7 | Più una tantum nello stesso Contratto | Più Spese o righe descrittive | Più elementi singoli | Nessuna deduzione di duplicati da importi uguali |
| 8 | Una tantum in anni diversi | Una Spesa per Esercizio | Attribuzione singola per elemento | La stessa Spesa non attraversa due anni |
| 9 | Costo ancora sconosciuto | Nessuna Condizione/Stima; note esplicative | Insieme di elementi vuoto | Allocato zero non significa gratuito |
| 10 | Gratuito | Nessuna riga artificiale; descrizione della gratuità | Insieme vuoto | Un eventuale costo futuro richiede registrazione esplicita |
| 11 | Accordo quadro temporaneamente senza componenti | Contratto senza Condizioni | Insieme vuoto | Nessuna Stima inventata e nessun Effettivo automatico |
| 12 | Stima e successivo Effettivo diverso | Due tipi di Riga; nessun aggiornamento automatico della Stima | Stima generata + Effettivo manuale | Nessun matching o consumo |
| 13 | Effettivo sopra Stima | Stima 2.621,34; Effettivo 2.800; scostamento +178,66 | Stesso risultato | Non gonfiare retroattivamente Budget/Stima |
| 14 | Effettivo sotto Stima | Effettivo 2.500; scostamento −121,34 | Stesso risultato | Non chiamarlo automaticamente risparmio |
| 15 | Cessazione anticipata | Taglio delle ricorrenze future; una tantum preservate | Regole terminali differenziate per natura | Setup 300 + sei canoni da 100 = 900 dopo cessazione 15 giugno |
| 16 | Annullamento prima dell'attivazione | Ricorrenze a zero; sorte delle manuali da decidere esplicitamente (§13) | Stessa decisione per gli elementi singoli | Lo stato da solo non prova che nessun costo resti dovuto |
| 17 | Rinnovo | Prosegue solo la ricorrenza; nessuna copia manuale | Nessuna ripetizione dell'elemento singolo | Setup originario non si ripete |
| 18 | Nuova tariffa ricorrente | Confini di ciclo esistenti; componente manuale invariata | Preservare il ramo ricorrente | L'anteprima deve distinguere delta della tariffa e totale completo |
| 19 | Censimento dopo l'inizio reale | Date reali; Stime solo negli anni aperti | Stessa regola | Nessuna falsa decorrenza per far entrare il dato |
| 20 | Inizio in anno già chiuso | Nessuna Stima retroattiva; eventuale Effettivo tardivo | Non genera l'elemento singolo nel chiuso | Correggere P7; lo storico approvato resta immutato |
| 21 | Costo noto dopo Budget | Nuova Stima viva con motivo; eventuale Revisione | Nuovo elemento con impatto e Revisione | Budget precedente resta identico |
| 22 | Non ricorrente pianificato, non sostenuto | Stima senza Effettivo | Elemento singolo senza Effettivo | Warning di allocato senza Effettivi; nessun pagamento dedotto |
| 23 | Solo Effettivi | Nessuna previsione obbligatoria | Nessun elemento economico obbligatorio | Allocato 0 e Effettivo positivo sono leciti |
| 24 | Ricorrente con anni senza costo | Intervalli scoperti/attribuzione corretta; valore 0 | Come sopra | Non riempire i vuoti; zero non significa Contratto assente |
| 25 | Costo a ogni rinnovo pluriennale | Eventi noti pianificabili a mano; automazione non coperta | “Una tantum” da sola non lo automatizza | Ricorrenza economica distinta, mai copia implicita al rinnovo |

### 6.2 Controesempi aggiuntivi emersi dal repository

| Caso limite | Rottura della soluzione ingenua | Requisito della soluzione completa |
|---|---|---|
| Ricalcolo ripetuto dopo setup manuale | Totale ricalcolato può perdere il setup o duplicarlo | Ricalcolo modifica solo `system`; totale somma ogni Riga una volta |
| N+1 già Aperto con costo manuale proprio | Proiezione può sottrarre il costo manuale di N+1 | Conservare le manuali già in N+1; non copiarvi quelle di N |
| N+1 da creare | Copia generica del piano ripete Sophos | Creare classificazioni e ricorrenze, nessuna nuova manuale |
| Condizione con attribuzione a fine ciclo oltre la cessazione | Rimozione di tutto ciò che cade dopo la cessazione | Preservare il ciclo intero iniziato mentre Attivo |
| Condizione a zero e nessuna Spesa `system` | UI può presumere un record obbligatorio | Zero deterministico senza creare righe fittizie |
| Stima manuale annullata, poi ripristinata | Vecchio controllo richiede Effettivo ordinario e Contratto Attivo | Validazione distinta per piano e realtà |
| Setup con Effettivi + rimborso a saldo zero | Storno basato sul saldo cancella la storia | Usare `HaEffettivi`, conservare le righe |
| Variazione viva mentre la Proposta è in Bozza | Il replay riscrive importi o una figlia ormai spostata | Fingerprint, revisioni, appartenenza attuale e rivalidazione |
| Nuovo Contratto e nuova Spesa nella stessa Proposta | `contract_item_id` non risolto | Identità prima del costo; applicazione e Snapshot atomiche |
| Spesa figlia contemporaneamente nel piano del padre e in un elemento | Delta/Allocato sommato due volte | Una sola rappresentazione applicabile per Spesa; aggregazione finale per contenitore |
| Anno chiuso, Spesa manuale con Stima storica | Correzione tardiva la considera incompatibile | Consentire solo append di Effettivo; non toccare Stima |
| Importazione e ricalcolo successivo | Duplicazione di una tantum trattata come automatica | Importare `origin`, relazioni e righe, ricalcolare solo `system` |
| Spostamento di una Stima autonoma nel Contratto | Vecchio Budget resta autonomo, corrente è aggregato | Conservare entrambe le identità storiche; spiegare la riclassificazione |
| Costo prima dell'attivazione o dopo cessazione | Il controllo dello stato degli Effettivi viene applicato anche al piano | Definire regole annuali di pianificazione e contesto terminale (§7.4) |
| Contratto censito nel 2026 con costo reale omesso nel 2025 chiuso | Totale tardivo e dettaglio per sorgente divergono | Includere la sorgente tardiva nella vista di conoscenza, senza inserirla nella Snapshot |

### 6.3 Seconda review indipendente della candidata

Dopo aver individuato B+D ho ripreso l'analisi dalla prospettiva di una proposta altrui. Questa è una seconda revisione critica svolta dallo stesso autore, non un'approvazione attribuita a un revisore esterno.

**Assunzione nascosta 1: “le Spese ci sono già, quindi le Proposte funzionano”.** Smentita da payload, catalogo, applicazione e replay. La raccomandazione ora include un percorso di pianificazione completo, circoscritto al Contratto, con una sola rappresentazione per ciascuna Spesa.

**Assunzione nascosta 2: “ricalcolare l'Allocato ricalcola solo la ricorrenza”.** Falso per chi legge i campi `allocation_before/after` come totale, in particolare Chiusura. Il generatore rimane ricorrente; i consumatori che mostrano totali devono aggiungere la manuale esattamente una volta.

**Assunzione nascosta 3: “zero Condizioni significa zero costi futuri certi”.** Falso: può significare licenza già sostenuta, gratuità, importo ignoto o accordo quadro. Non si introduce un nuovo stato economico dedotto; la UI descrive ciò che è registrato e usa le note per la motivazione.

**Assunzione nascosta 4: “annullamento può azzerare ogni previsione senza conseguenze”.** Non determinabile: possono esistere costi non rimborsabili o di cessazione. La componente manuale non contiene date economiche infra-annuali per decidere automaticamente. La scelta deve essere esplicita e il conflitto con §18.9 va risolto prima di implementare.

**Assunzione nascosta 5: “storico del Contratto equivale a ricalcolare le Condizioni negli anni passati”.** Smentita da censimento tardivo e Snapshot immutabili. Occorre distinguere storico materializzato, conoscenza successiva e previsione delle regole vive.

**Assunzione nascosta 6: “backup conserva tutto, compreso ogni fatto di uso economico”.** Il business backup esporta dati e riferimenti economici ma non le Bozze e l'audit completo. Il controllo di uso economico oggi usa anche audit: la continuità del blocco Fornitore dopo azzeramenti e spostamenti non può essere garantita solo dicendo che le righe vengono esportate (§8.9).

**Esiste qualcosa di più semplice?** B soltanto copre realtà e gratuità ma non il piano; G1 falsifica la semantica; C invade calendario e intervalli; E/F aggiungono persistenza non necessaria. La conclusione resta B+D. Cambia il giudizio sull'intervento: piccolo nel modello dati, significativo nei punti di integrazione, da completare come comportamento unico.

## 7. Soluzione raccomandata

Le regole di questa sezione sono **proposte**, non descrizioni del comportamento già disponibile. La loro convergenza è B+D: estendere il modello esistente senza creare un secondo piano economico persistente.

### 7.1 Significato degli oggetti

1. **Contratto:** identità dell'accordo, durata, controparte, eventi e classificazioni. Può esistere senza Condizioni e senza Spese. La presenza di un costo non determina il suo stato.
2. **Condizione economica:** esclusivamente una tariffa ricorrente generata automaticamente. Restano le quattro frequenze, l'attribuzione inizio/fine, l'ancora, il divieto di sovrapposizione fra Condizioni e l'assenza di prorata.
3. **Spesa `system` del Contratto:** sola materializzazione della Stima ricorrente nell'anno; unica per Contratto/Esercizio, protetta, senza Effettivi.
4. **Spesa `manual` del Contratto:** costo esplicito dell'Esercizio, con Stime non ricorrenti, Effettivi o entrambi. Può contenere soltanto Effettivi relativi ai canoni: `manual` identifica l'origine della registrazione, non afferma che ogni Effettivo sia un costo una tantum.
5. **Riga Stima manuale:** previsione aggiuntiva non già rappresentata da una Condizione. Non sostituisce, corregge o duplica implicitamente la ricorrenza. La revisione di una tariffa ricorrente usa le operazioni sulle Condizioni.
6. **Riga Effettivo:** realtà operativa, senza corrispondenza obbligatoria a una specifica Riga Stima. Stima ed Effettivo possono stare nella stessa Spesa manuale; non esiste una relazione di consumo fra le righe.

Non serve un flag `one_off` sulla Spesa: per le **Stime**, la distinzione già sufficiente è `system` generata / `manual` non ricorrente. Non introdurre un tipo economico ulteriore oltre a Stima ed Effettivo.

### 7.2 Formule e sorgente dei valori

Per Contratto C ed Esercizio Y:

```text
R(C,Y) = risultato di ContractAnnualAllocation per le sole Condizioni
S(C,Y) = somma Stime attive della Spesa system materializzata, oppure 0 se assente
M(C,Y) = somma Stime attive delle Spese manual non Stornate nell'Esercizio
E(C,Y) = somma Effettivi attivi delle Spese manual non Stornate nell'Esercizio

Dopo un ricalcolo valido in un Esercizio Aperto: S(C,Y) = R(C,Y)
Allocato corrente persistito A(C,Y) = S(C,Y) + M(C,Y)
Allocato proiettato A'(C,Y) = R'(C,Y) + M'(C,Y)
Scostamento operativo = E(C,Y) − A(C,Y)
```

Una proiezione ricalcola R; non somma contemporaneamente R, S e M. Una lettura corrente usa le righe persistite, come `Contract::annualTotals()` e i report. Se un ricalcolo autorizzato è necessario, lo compie l'operazione prevista, non una lettura che genera silenziosamente record mancanti.

Per una modifica della sola ricorrenza M non cambia; il delta economico è `R'−R`. Un riepilogo denominato “Allocato del Contratto prima/dopo” deve però mostrare `R+M` e `R'+M`. Se si mostrano soltanto R e R', chiamarli “Stima ricorrente”, non “Allocato totale”.

I valori di un anno chiuso provengono dal riferimento storico appropriato. Il motore vivo può spiegare regole correnti, ma non riscrive S, M, Budget o Snapshot storici.

**Esempio anti-duplicazione:** mensile 100 per dodici cicli e setup 300 producono `R=1.200`, `S=1.200`, `M=300`, `A=1.500`. `R+S+M=2.700` è sbagliato. Aggiungere anche il Contratto da 1.500 alla somma delle sue Spese da 1.500 produrrebbe 3.000 ed è altrettanto sbagliato.

### 7.3 Attribuzione e dati del costo singolo

- L'Esercizio della Spesa manuale è l'anno operativo di previsione/registrazione, scelto esplicitamente. Nessun calcolo dalla durata del Contratto.
- Non introdurre una data di pagamento, fattura o competenza. Una data eventualmente scritta in nota resta descrittiva e non governa aggregazioni.
- La Stima deve essere non negativa. L'Effettivo negativo mantiene la Nota obbligatoria. L'importo totale della Riga resta autoritativo.
- Una Spesa appartiene a un solo anno: due costi in anni diversi richiedono due Spese, non una Riga che venga divisa automaticamente.
- Più costi singoli nello stesso anno possono usare Spese distinte per descrizione e gestione autonoma; più righe nella stessa Spesa rimangono ammesse. Non creare una tabella “componenti” per distinguerli.
- Fornitore derivato dal Contratto e Centro di Costo ereditato dalla classificazione annuale, anche per la manuale. Un costo realmente verso un'altra controparte non può essere forzato nel Contratto esistente.
- Il Contratto può avere Allocato zero senza righe. Gratuito, prezzo ignoto e accordo quadro vengono spiegati nei campi descrittivi disponibili; il sistema non li deduce né li etichetta come equivalenti.

### 7.4 Inserimento, stato, modifiche e spostamenti

**Pianificazione ordinaria proposta:** una Stima manuale può essere creata o modificata in un Esercizio Aperto della stessa Azienda, per un Contratto non Archiviato Pianificato o Attivo. La validazione della previsione non richiede che l'attivazione sia già efficace oggi. L'anno è esplicito; il software non pretende di conoscere il giorno economico di una Riga che non lo possiede.

**Contesto terminale proposto:** per un Contratto Cessato/Annullato, una previsione manuale di costo residuo, di cessazione o conosciuto tardivamente deve poter essere dichiarata esplicitamente con Nota, senza riattivazione implicita. Non riutilizzare la dichiarazione “Effettivo ordinario” per autorizzare una Stima. Si tratta di una regola da recepire, coerente con il fatto che il costo può sopravvivere all'accordo.

Gli Effettivi mantengono tutte le attuali restrizioni: nessuno negli anni futuri, niente Effettivo ordinario su Pianificato, modalità terminali dichiarate con Nota. Una richiesta con Stima ed Effettivo deve soddisfare entrambe le regole; non deve superare i controlli perché contiene almeno una Stima.

**Prima Condizione tardivamente conosciuta:** consentire la prima Condizione anche su un Contratto creato senza piano ricorrente, verificando stato alla decorrenza e storia chiusa. Se si registra una ricorrenza reale già esistente in un anno aperto, conservarne la decorrenza reale con motivo; non applicare automaticamente una “sostituzione della precedente” inesistente. Una nuova modifica futura di accordo resta soggetta alle regole canoniche pertinenti. Nessuna nuova Condizione ordinaria può alterare anni chiusi.

**Modifiche e Storno:** gli Effettivi non riscrivono la Stima; le Stime manuali si modificano/annullano con audit. Una Spesa con `HaEffettivi` non viene Stornata, anche a saldo netto zero; possono essere annullate soltanto le sue Stime. Le richieste di motivo dopo Budget restano applicabili. I percorsi di ripristino devono distinguere piano e Effettivi.

**Spostamento vivo:** rimuovere il divieto assoluto delle Stime in ingresso al Contratto, conservando anteprima, motivo quando richiesto, atomicità, eredità del Fornitore e Centro di Costo, anni aperti e tenant isolation. Tutte le righe della Spesa seguono il cambio di contenitore/anno; nessun abbinamento con la Stima di sistema. Gli anni chiusi restano esclusi. Le regole specifiche della Riprogrammazione del Progetto non vengono aggirate passando per un Contratto.

**Proposte e spostamenti:** per questa estensione è sufficiente aggiungere creazione e modifica del piano interno al Contratto. Non è necessario estendere contestualmente la Proposta a ogni spostamento contrattuale supportato nella realtà viva. I cambi di appartenenza contrattuale non rappresentati dal payload devono continuare a fallire esplicitamente. Con Effettivi, nella Proposta sono modificabili solo le Stime, mai identità, anno, contenitore, Fornitore, classificazione o Effettivi.

### 7.5 Cessazione, annullamento, riattivazione e rinnovo

**Cessazione:** ricalcola solo la ricorrenza secondo le regole attuali; non elimina, ripartisce o restituisce automaticamente le Stime manuali. Un setup rimane nell'anno originario. Un rimborso reale è un Effettivo negativo, non una Stima negativa e non un ricalcolo del setup. Se una previsione manuale non è più valida, l'utente la riduce o annulla esplicitamente.

**Annullamento prima dell'attivazione:** la raccomandazione è azzerare le sole ricorrenze annullate e mostrare le Stime manuali da riesaminare. Non cancellarle implicitamente. L'utente può annullare esplicitamente quelle non più valide, usando le operazioni esistenti; se stato e piano vengono confermati insieme, l'applicazione deve essere atomica. Questa scelta modifica l'affermazione generale di §18.9 “Allocato a zero” e richiede convalida di dominio (§13.1). Non è lecito implementarla silenziosamente come se fosse già canonica.

**Riattivazione:** la nuova Condizione diventa facoltativa. Se necessaria, viene creata esplicitamente e non si estendono le precedenti. Nessuna Spesa manuale viene copiata o ripristinata per effetto della riattivazione.

**Rinnovo:** proseguono le Condizioni ricorrenti secondo calendario, validità e stato; i costi manuali rimangono nei propri Esercizi. Un rinnovo dell'accordo non implica un costo uguale a quello iniziale.

**Costo a ogni rinnovo pluriennale:** può essere pianificato esplicitamente per i rinnovi noti. Non è “una tantum” in senso economico se deve ripetersi automaticamente. Se serve l'automatismo, occorre definire se il costo dipenda dall'ancora economica fissa o dall'evento di rinnovo, soprattutto quando cambia scadenza. Non introdurre quell'automatismo in questa estensione né presentarlo come già coperto.

### 7.6 Proposte: il percorso minimo completo

Il meccanismo corrente dei Progetti è un riferimento concreto, non una prova che il Contratto sia già supportato:

- le Spese esistenti del Progetto sono nella baseline del padre;
- `PlanProjectChildExpenses` modifica le Stime delle esistenti;
- nuove Spese sono elementi distinti della Proposta, risolti tramite `project_id/project_item_id`;
- `ApproveProposal` materializza prima i contenitori, poi le nuove Spese.

**Scelta tecnica raccomandata per il Contratto:** seguire questa distinzione, senza introdurre un generatore di piani comune a tutte le entità.

1. L'elemento Contratto conserva nella baseline le Spese dell'anno; il sottopiano distingue esplicitamente `origin`. La Stima `system` è contesto calcolato in sola lettura, mai bersaglio di `set_estimates`.
2. Per modificare Stime di Spese manuali già vive, introdurre un'azione tipizzata sul padre, analoga a `PlanProjectChildExpenses` (nome possibile `PlanContractChildExpenses`). I bersagli sono identificati per ID, non per titolo/importo. Non creare anche un secondo elemento modificabile della stessa Spesa.
3. Per nuove Spese pianificate, estendere il payload esistente di creazione con **uno solo** fra `contract_id` e `contract_item_id`, esclusivo rispetto ai riferimenti al Progetto. `contract_item_id` indica un Contratto della stessa Proposta. Il padre deve essere incluso, anche se inizialmente a zero.
4. La risoluzione di Fornitore e classificazione è derivata dal Contratto. Non accettare un Fornitore concorrente nel payload. Il piano crea solo Stime.
5. L'approvazione materializza il Contratto, applica le modifiche delle Stime esistenti una sola volta, materializza le nuove Spese risolvendo gli ID, poi costruisce Budget e dettaglio aggiornati. Il fallimento di una figlia comporta rollback dell'intera approvazione.
6. Nuove Spese future in altri Esercizi Aperti sono effetti espliciti: vengono applicate negli anni indicati, partecipano all'anteprima multi-Esercizio e al riallineamento, ma non al totale/dettaglio del Budget dell'anno principale. I Budget di quegli altri anni restano invariati.

**Aggregazione nella Proposta:** per ciascun Contratto/anno, l'Allocato previsto è ricorrenza proiettata + piano delle manuali esistenti + nuove manuali previste per quell'anno. Gli elementi figli contribuiscono come dettaglio del Contratto, non come sorgenti aggiuntive da sommare alla riga del padre. Negli anni non modificati il valore manuale vivo rimane; un elenco vuoto relativo all'anno principale non azzera le manuali degli altri anni.

**Baseline, fingerprint e replay:** `ProposalSourceSnapshot` include già il piano e il contesto degli Effettivi; aggiungere l'origine modifica il fingerprint e richiede il normale riallineamento delle Bozze esistenti, non una reinterpretazione dei Budget approvati. Ogni mutazione manuale deve incrementare le revisioni pertinenti. `Ricarica realtà` elimina le azioni interessate; `Mantieni proposta` riapplica azioni tipizzate sulla realtà nuova; se la Spesa è stata spostata, Stornata o è cambiata in modo incompatibile, la Proposta rimane bloccata. Non effettuare merge per importo.

I riferimenti fra elementi nuovi devono essere considerati anche da `ProposalActionReplay::touchingActions()`: oggi la selezione avviene per appartenenza dell'azione o valori diretti nel payload. Non basta inserire un UUID in una lista e presumere che la dipendenza venga riconosciuta. Il riallineamento del padre non deve far sparire o rendere orfane le nuove figlie; né deve riapplicarne la creazione come se fosse un'azione sulle Condizioni.

### 7.7 Budget e Chiusura

**Budget:** mantiene una sola riga di primo livello per Contratto con Allocato `S+M`. Il dettaglio deve contenere:

- Stima ricorrente approvata e sua composizione per cicli;
- Spese manuali dell'anno, ID, descrizione, origine, stato e Stime approvate;
- importi autoritativi ed eventuali quantità/importi unitari già disponibili;
- totale approvato pari alla somma delle due componenti.

Le Spese manuali non diventano righe da aggiungere nuovamente al totale aziendale. Eventuali righe figlie materializzate dalla Proposta sono marcate come contenute e trattate come dettaglio. Nessun Effettivo entra nel payload del Budget; non copiare integralmente il dettaglio di Chiusura, che li contiene.

**Chiusura di N:** la proiezione conserva `M(N)` e varia soltanto R per gli eventi/ricalcoli previsti. Il delta è confrontato con `S(N)+M(N)`, non con R soltanto. Il ricalcolo reale aggiorna la sola `system`. La Snapshot conserva tutte le Spese e i relativi valori finali. Devono quadrare anteprima, valore vivo, righe della Snapshot e header.

**N+1 esistente:** usare le manuali già registrate in N+1, senza riscriverle o copiarvi quelle di N. **N+1 assente:** creazione delle classificazioni e della sola Stima ricorrente applicabile; manuali iniziali zero. Nessun Riporto, nessun residuo contrattuale da trasferire, nessuna Riprogrammazione automatica.

**Warning:** conservare “Allocato presente, nessun Effettivo registrato” sull'intero `S+M`. Un Contratto intenzionalmente senza Condizioni non è invalido. Mostrare “Nessuna ricorrenza configurata”, senza dedurre gratuità o mancanze. Per un Contratto con storia di Condizioni mantenere il controllo di fine copertura/rinnovo senza Condizione, distinguendo l'intervallo coperto dall'anno di attribuzione. Uno zero annuale non dimostra una Condizione mancante. Le policy di classificazione della Chiusura restano applicabili anche alle sorgenti incluse a zero; non promettere che “zero” esenti automaticamente dalla classificazione.

### 7.8 Storico, chiusi e UI essenziale

Aprendo Sophos nell'Esercizio 2026 l'utente deve vedere:

```text
Sophos — StudioRS
Inizio contrattuale: 22/02/2025
Scadenza: 22/02/2028 — rinnovo automatico: no
Stato alla data di riferimento 2026: Attivo

Esercizio 2026
Stima ricorrente:      0,00 €
Stime non ricorrenti:  0,00 €
Allocato:             0,00 €
Effettivo:            valore effettivamente registrato nel 2026

Storia economica
2025: licenze — 18 × 145,63 € — importo 2.621,34 €
      Stima ed Effettivo esposti separatamente, secondo i dati realmente presenti
```

Non affermare che l'Effettivo 2026 sia zero senza verificarne le righe. Non esporre 2.621,34 come Effettivo solo perché esiste una Stima. La validità al 2028 non trasforma il costo del 2025 in un costo corrente.

La storia annuale già presente nell'Infolist va completata: totale e scomposizione ricorrente/manuale, accesso alle Spese per anno e indicazione del riferimento. Non serve un nuovo cruscotto o una pagina autonoma del Piano economico.

Per il 2025 chiuso:

- se costo e piano erano presenti alla Chiusura, mostrarne i valori dalla Snapshot;
- se il Contratto è censito dopo, mostrare “non presente nella Snapshot di Chiusura”, non una riga storica inventata;
- se l'Effettivo era realmente del 2025 ed è stato omesso, usare la correzione tardiva e distinguere Effettivo alla Chiusura e a Conoscenza Corrente;
- non inserire una Stima 2025 per ricostruire ciò che si sarebbe potuto prevedere;
- se l'importo è realmente sostenuto nel 2026, l'Effettivo appartiene al 2026 secondo la convenzione operativa, pur essendo commercialmente riferito al Contratto.

Usare “Stima”, “Effettivo”, “costo registrato” e “validità”. La label esistente “Registra Pagamento” va sostituita nel flusso coinvolto con “Registra Effettivo”; il software non prova l'avvenuto pagamento. Il valore annuale precompilato deve essere presentato soltanto come suggerimento modificabile, non come importo dovuto, residuo o importo da abbinare a una Stima.

## 8. Impatto sul repository

### 8.1 Dominio e specifica

Recepire, in un'attività successiva, le regole proposte nei §§6.5, 7.1/7.6, 8.5, 12.7/12.9/12.14, 14.4, 15.4/15.8/15.9, 18.1/18.2/18.8/18.9/18.12/18.22 e nelle invarianti/esempi che ripetono il divieto. Non cambiare §§5.3, 5.6, 18.14–18.16, 18.20 o 24 per introdurre matching, prorata, Riporto o Stime tardive.

`app/Domain/Contracts/ContractExpenseActivity.php` deve separare la validazione delle Stime dalla validazione degli Effettivi. `ContractAnnualAllocation`, `ContractCycle`, `ContractCycleType` e `ContractAttributionMode` restano il motore ricorrente. Il nome attuale della classe non deve indurre nuovi chiamanti a leggerla come totale completo.

È sufficiente riusare `Contract::annualTotals()` per i totali persistiti e applicare la formula ricorrente + manuale nelle proiezioni. Un eventuale piccolo metodo per leggere il subtotale manuale nell'anno è proporzionato; un servizio generale di calcolo economico, cache o engine nuovo non lo è.

### 8.2 Modelli e schema

| File | Intervento/garanzia |
|---|---|
| `app/Models/Contract.php` | Totali già compatibili con righe manuali; verificare uso dei riferimenti storici nella presentazione |
| `app/Models/Expense.php` | Riutilizzare `allocation`, `actual`, `hasActuals`, eredità e Storno; nessun nuovo tipo |
| `app/Models/ExpenseLine.php` | Quantità, importo unitario e importo totale già presenti |
| `app/Models/Exercise.php` | Mantenere somma delle righe una sola volta più Riporti dei soli Progetti |
| `app/Models/ContractCondition.php` e `ContractClosedHistoryGuard.php` | Preservare guardia degli intervalli storici e censimento autorizzato |
| `database/migrations/2026_08_20_000700_add_contract_ownership_to_expenses_table.php` | Vincoli già adatti: un solo contenitore, `system` contrattuale, unicità generata |
| `database/migrations/2026_08_17_001200_create_expense_lines_table.php` | Tipi e precisioni già adatti; nessun vincolo SQL “manuale contrattuale = solo actual” |

Non riscrivere migrazioni committate. Per il nucleo B+D non sono necessarie nuove migrazioni né trasformazioni dati.

### 8.3 Actions operative e ciclo di vita

- `app/Actions/Operations/CreateContract.php`: normalizzare correttamente anche `conditions=[]` e assenza esplicita di Condizioni; oggi l'assenza del campo può diventare `[[]]`. Conservare validazioni di ogni Condizione quando presente, audit, configurazioni e classificazioni.
- `ReactivateContract.php`: rendere facoltativa la nuova Condizione e preservare eventi/date/guardie.
- `SaveContractEdits.php`, `CreateContractCondition.php`: gestire la prima Condizione aggiunta successivamente senza richiedere `$last`; verificare Contratto Pianificato alla decorrenza, non imporre indiscriminatamente Attivo oggi per la sola pianificazione.
- `CreateExpense.php`, `CreateExpenseLine.php`, `UpdateExpenseLine.php`, `SetExpenseLineActive.php`, `SetExpenseReversed.php`: accettare Stime manuali nei limiti proposti; preservare protezioni `origin=system`, Effettivi, motivi, revisioni e audit.
- `UpdateExpense.php` e `app/Domain/Expenses/ExpenseImpactPlan.php`: rivedere ingresso e ripristino delle Stime contrattuali; mantenere anteprima dei due contenitori e dei due anni, divieto sui chiusi e sostituzione del Fornitore dichiarata.
- `RecalculateContractEstimates.php` e `ProcessContractRenewals.php`: lasciare la materializzazione sulla sola `system`. Identità e idempotenza già necessarie al dominio non vanno indebolite.
- `CeaseContract.php`, `CancelContract.php`, `AnnulContractLifecycleFact.php`, `ReplaceContractLifecycleFact.php`: verificare che modifiche/annullamenti degli eventi non tocchino implicitamente manuali; l'annullamento iniziale segue la decisione del §13.1.
- `UpdateContractClassification.php`/`ContractClassificationImpactPlan.php`: il totale già somma manuali; assicurare inclusione nelle anteprime e impossibilità di riclassificare anni chiusi.
- `UpdateContract.php`/`ContractEconomicUse.php`: considerare le nuove Stime manuali nel blocco Fornitore e i casi di continuità discussi al §8.9.

### 8.4 Inventario completo degli usi di `ContractAnnualAllocation`

La ricerca dei riferimenti in `app/` ha individuato questi **undici file chiamanti**, oltre alla classe stessa. Le ricerche su `ContractCycle`, somme delle Condizioni e filtri di origine non hanno evidenziato un secondo enumeratore economico indipendente. I totali SQL e le somme di Spese restano comunque percorsi distinti, già censiti al §2.5.

| File chiamante | Uso | Trattamento raccomandato |
|---|---|---|
| `app/Actions/Operations/RecalculateContractEstimates.php` | Genera/aggiorna `system` | Resta solo R; non passarvi R+M |
| `app/Actions/Proposals/ApplyContractPlan.php` | Seconda implementazione della materializzazione `system` | Preservare R e allineare le garanzie con l'action di ricalcolo; non duplicare le manuali |
| `app/Domain/Contracts/ContractEconomicChangePlan.php` | Impatto della modifica Condizioni | Delta solo ricorrente; totale e label devono includere/dichiarare M |
| `app/Actions/Operations/UpdateContractRenewal.php` | Anteprima del rinnovo | Stessa distinzione fra componente e totale |
| `app/Domain/Proposals/ProposalImpactPlan.php` | Prima/dopo del Contratto | Sommare piano manuale e ricorrente per anno; nuove figlie una volta |
| `app/Domain/Proposals/BudgetSnapshotPayload.php` | Dettaglio Contratto | Aggiungere il dettaglio manuale e far quadrare il totale |
| `app/Domain/Closing/ContractClosingProjection.php` | Proiezione e delta di Chiusura | Confrontare totali omogenei, conservare M |
| `app/Domain/Closing/ClosingSnapshotPayload.php` | Composizione e fallback dell'Allocato | Cicli solo per R; totale dalla realtà materializzata; dettaglio manuale preservato |
| `app/Filament/Resources/Contracts/Schemas/ContractInfolist.php` | Riepilogo annuale e composizione | Totale S+M, dettaglio separato; storico dai riferimenti appropriati |
| `app/Filament/Resources/Contracts/Tables/ContractsTable.php` | Elenco annuale | Totale coerente con report e Budget |
| `app/Filament/Resources/Contracts/RelationManagers/ContractAnnualSituationsRelationManager.php` | Vecchio percorso di situazione annuale, non registrato nella Resource | Da allineare se mantenuto/utilizzato; non aggiungerlo come nuova UI per questa modifica |

La duplicazione di materializzazione fra `RecalculateContractEstimates` e `ApplyContractPlan` è concreta: la prima richiede una sola Riga Stima con `sole()`, la seconda usa `first()` e può crearne una se assente. Non serve un refactor generale; occorre almeno un comportamento verificato identico per i dati validi e un solo trattamento delle manuali. Il riuso del percorso già esistente è preferibile se compatibile con la transazione e l'audit di approvazione.

### 8.5 Filament

File principali:

- `app/Filament/Resources/Contracts/Schemas/ContractForm.php`;
- `app/Filament/Resources/Contracts/Pages/CreateContract.php`, `EditContract.php`, `ViewContract.php`;
- `app/Filament/Resources/Contracts/RelationManagers/ContractLifecycleRelationManager.php`, `ContractExpensesRelationManager.php`, `ContractConditionsRelationManager.php`;
- `app/Filament/Resources/Expenses/Schemas/ExpenseForm.php`, `RelationManagers/ExpenseLinesRelationManager.php`;
- `app/Filament/Resources/Expenses/Actions/RegisterContractPayment.php`;
- `resources/views/filament/resources/contracts/components/overview.blade.php` e `allocation-detail.blade.php`.

Conseguenze necessarie: Condizioni opzionali, campi contrattuali compilabili anche senza suggerimenti provenienti da una Condizione, aggiunta della prima Condizione successiva, Stima disponibile sulle manuali, sistema sempre protetto, composizione economica completa e storico annuale leggibile. I selettori di anno, i motivi dopo Budget e le restrizioni sugli Effettivi devono restare espliciti.

Non mostrare un errore “manca una Condizione” sul Contratto senza ricorrenze. Non chiamare “nessun costo” l'assenza di un piano. Stato temporale, costo annuale e durata devono essere leggibili nello stesso contesto.

Non è stata effettuata verifica browser: non ci sono modifiche visive implementate. La futura implementazione richiede controllo reale dei flussi principale, vuoto, validazione, sola lettura di sistema e anno chiuso, oltre ai test Livewire.

### 8.6 Proposte e Budget

| File/area | Adeguamento necessario |
|---|---|
| `app/Domain/Proposals/ContractPlan.php` | Zero Condizioni, azione sulle figlie manuali, controlli di stato e validità del piano |
| `ProposalActionType.php`, `ProposalActionPayload.php` | Azione tipizzata per Stime delle figlie esistenti; riferimenti al Contratto per nuove Spese; nessun Effettivo |
| `ExpensePlan.php`, `ProposalItemReference.php` | Esclusività dell'owner, stessa Proposta/Azienda, stato e riferimenti risolti; blocchi espliciti sui cambi non supportati |
| `ProposalSourceSnapshot.php` | Origine esplicita; baseline manuale distinguibile da `system`; contesto Effettivi separato |
| `ProposalSourceCatalog.php` | Includere il padre con piano manuale anche se non attivo; non promuovere automaticamente le figlie a sorgenti autonome |
| `ProposalImpactPlan.php` | Totali ricorrenti + manuali per Esercizio, figlie non duplicate, perimetro esplicito degli anni modificati |
| `ProposalReadiness.php`, `ProposalReadinessReason.php` | Il predicato “manual_contract_estimate” non è più vietato in assoluto; restano blocchi su sistema, chiusi, Effettivi, riferimenti e concorrenza |
| `ProposalActionReplay.php`, `RealignProposalItem.php` | Replay e dipendenze padre/figlie, preservazione identità, nuove realtà ed Effettivi |
| `app/Actions/Proposals/PlanExpense.php`, `PlanContract.php` | Creazione/pianificazione autorizzate e audit coerente |
| `ApplyExpensePlan.php`, `ApplyContractPlan.php`, `ApproveProposal.php` | Risoluzione `contract_item_id`, applicazione una volta, Fornitore derivato, rollback, revisioni e anni aperti |
| `MarkProposalItemsToRealign.php` | Includere Contratti toccati da nuove o modificate manuali, anche in Proposte di altri anni |
| `BudgetSnapshotPayload.php`, `MaterializeBudgetSnapshot.php`, `BudgetPayloadGuard.php` | Dettaglio contrattuale completo, quadrature, versioni e nessun Effettivo nel Budget |
| `app/Filament/Resources/Proposals/` | Esporre le stesse azioni complete e i loro impatti; non un campo che aggiri readiness/replay |

Le righe pianificate in `ProposalActionPayload::validateEstimateLines()` ammettono oggi importo, nota, ID e annullamento, **non quantità/importo unitario**. Per pianificare 2.621,34 € basta l'importo con nota esplicativa. Se si richiede la stessa immissione strutturata “18 × 145,63” anche in Proposta, l'estensione dei campi descrittivi va portata fino ad applicazione, Snapshot e replay; non dichiarare già supportata quella UX. Le righe vive già dotate dei campi devono conservarli quando si modifica soltanto importo/nota.

Non replicare il piano manuale contemporaneamente in `ContractPlan` e in una nuova entità. Il pattern delle figlie richiede un'azione e riferimenti nuovi, non un nuovo dominio economico.

### 8.7 Chiusura

`app/Domain/Closing/ContractClosingProjection.php` è il punto principale: `allocationForYear()` restituisce ricorrenza, mentre `build()` la usa come totale dopo. Conservare il primo significato e comporre il totale nel chiamante evita che `PrepareExerciseClosing` copi manuali di N nel nuovo N+1.

`app/Actions/Closing/ReviewExerciseClosing.php` deve valutare avvisi e totali sulla proiezione completa, con fingerprint aggiornato delle fonti. La validazione delle Condizioni già accetta l'insieme vuoto per costruzione; non aggiungere un nuovo blocco “nessuna Condizione”. Distinguere copertura e composizione annuale negli avvisi.

`PrepareExerciseClosing.php` deve distinguere N+1 nuovo, per cui genera solo R, da N+1 già esistente, per cui conserva M di quell'anno. `CloseExercise.php` mantiene sequenza atomica, ricalcolo, verifica rispetto al riepilogo confermato e Snapshot; nessuna modifica alle manuali salvo un'azione esplicita dell'utente autorizzata in altro percorso.

`ClosingSnapshotPayload.php` conserva già `expenses` e i totali; arricchire la spiegazione della componente manuale senza chiamarla ciclo. Non rigenerare dettagli storici dalla realtà successiva. `CreateExercise.php` continua a copiare classificazioni note e generare solo ricorrenze.

### 8.8 Reporting e dashboard

`app/Actions/Reporting/BuildReport.php::currentSources()` e `loadedExpenseTotals()` sommano già tutte le Spese del Contratto: il valore corrente manuale sarebbe incluso senza un nuovo aggregatore. L'inclusione del Contratto Attivo/Pianificato a zero esiste già. `ReportAggregator` e `ComparisonEngine` mantengono il Contratto come sorgente; il dettaglio delle figlie non riceve autonomamente etichette “Previsto/Non previsto”.

Nel percorso Budget correggere P10: `budgetSources()` deve fornire alle aggregazioni e ai confronti solo le sorgenti di primo livello, usando `detail.expense.owner` per le versioni che lo materializzano e il relativo contratto di lettura per quelle precedenti. Le figlie restano accessibili come dettaglio; la loro presenza non aumenta il totale né il numero delle sorgenti autonome.

Verificare `ReportAggregator::suppliers()`: nel percorso corrente usa le Spese di dettaglio e il Fornitore del Contratto. Se si aggiunge un subtotale ricorrente nel dettaglio, non aggiungerlo anche come seconda Spesa. Per Budget/Chiusura, struttura e origine del dettaglio devono essere interpretate secondo il riferimento; non presumere che ogni payload usi gli stessi nomi del dettaglio corrente.

`app/Support/Reporting/EconomicDashboardReadModel.php` usa i risultati dei report per confronti e sorgenti; `app/Filament/Widgets/` li presenta. `RegisterContractPayment::annualEstimate()` legge già `annualTotals()`: il suggerimento diventerebbe comprensivo delle manuali, mentre Infolist ed elenco senza correzioni resterebbero sul solo ricorrente. Va eliminata questa divergenza.

Per P7, la vista a Conoscenza Corrente deve unire le chiavi presenti nella Snapshot alle chiavi delle correzioni tardive. Per una chiave assente: nessuna falsa riga di Snapshot, base alla Chiusura esplicitamente assente, importo aggiuntivo dalla sola correzione, etichetta e contesto dal record tardivo, nessuna classificazione storica inventata. `ReportSource` ammette stato nullo, ma `contractRows()` oggi usa `ContractState::from($source->state)`: anche la presentazione deve gestire “non presente alla Chiusura” senza fabbricare uno stato. Il dettaglio e gli aggregati devono riconciliare le correzioni anche a saldo netto zero.

Non riscrivere un report storico usando automaticamente Fornitore, Centro di Costo o condizioni correnti al posto dei contesti materializzati. Lo storico economico nel Contratto deve esporre chiaramente se il dato proviene da Budget, Chiusura o conoscenza successiva.

### 8.9 Backup/restore e continuità

Il namespace è `app/BusinessBackup/V1/`, ma `BusinessBackupContract::FORMAT_VERSION` corrente vale **2**, con lettura legacy 1. Non confondere il nome della directory con la versione effettiva.

I fogli macchina possiedono già:

- `_MP2_contracts`, `_MP2_contract_conditions`, rinnovi, eventi e classificazioni;
- `_MP2_expenses` con `contract_ref`, `exercise_ref`, `origin`;
- `_MP2_expense_lines` con tipo, importo e campi descrittivi;
- righe Budget/Chiusura con `detail_version` e `detail_json`;
- correzioni tardive e annotazioni.

`BusinessBackupCollector` esporta le righe esistenti. `BusinessBackupValidator::assertExpenses()` controlla contenitore esclusivo, Centro di Costo diretto e unicità della `system`, senza imporre il divieto applicativo di Stima manuale contrattuale. `ImportBusinessBackup` ripristina le tabelle e rimappa gli ID; non ricrea la Stima come nuovo costo durante l'importazione. `portableJson()`/`restoreJson()` trasformano già riferimenti di Spese, righe, Contratti e Condizioni nei dettagli.

**Conclusione per B+D:** i dati vivi non richiedono nuovi fogli/colonne o un cambio del formato XLSX. Occorre verificare la portabilità del nuovo dettaglio Budget e i totali. Un'eventuale nuova `detail_version` non implica di per sé un nuovo formato globale. Conservare la lettura dei dettagli vecchi, senza aggiungervi retroattivamente componenti inesistenti.

Dopo il restore, il primo ricalcolo deve trovare la `system` importata e aggiornare soltanto quella; le Stime manuali rimangono identiche. Il test esistente `tests/Feature/BusinessBackup/RestoredContractContinuityTest.php` protegge la `system`; va esteso al Contratto misto e senza Condizioni.

**Limiti reali del backup:** il collector non esporta le Bozze di Proposta né l'audit completo; il payload portabile esclude anche i riferimenti tecnici ad azioni/eventi. Gli allegati sono inventariati, non ripristinati come file originali. Non promettere una continuità della Timeline completa o delle Bozze che il formato attuale non offre.

**Uso economico e Fornitore:** l'estensione deve almeno considerare una Stima manuale positiva e conservarne il blocco dopo annullamento/Storno, senza permettere di cambiare controparte tramite azzeramento. Attenzione al caso limite: un precedente valore positivo corretto a zero o una Spesa spostata fuori dal Contratto può lasciare evidenza soltanto nell'audit, non esportato. Il problema di ricostruzione esiste già per le Stime di sistema ricalcolate a zero prima di qualsiasi Snapshot. Non introdurre una migrazione generalizzata per supposizione; prima verificare il controesempio nel test di continuità. Se il blocco permanente del Fornitore deve valere anche in questi restore, serve preservare esplicitamente quel fatto storico nel modello/backup: è una decisione di compatibilità circoscritta (§13.3), non un motivo per introdurre Componenti economiche.

**Altro rischio preesistente da verificare:** `BusinessBackupValidator::assertBudgets()` distingue le righe Spesa autonome/figlie usando il contenitore **vivo esportato**. Se una Spesa era autonoma nel Budget ed è stata poi spostata nel Contratto, il Budget storico non deve cambiare classificazione ai fini della quadratura. Il riferimento corretto è l'appartenenza materializzata nel dettaglio Budget. Questo caso diventa particolarmente rilevante quando diventano leciti gli ingressi di Spese con Stima nel Contratto; deve entrare nel test di round trip.

### 8.10 Correzioni tardive

`app/Actions/LateCorrections/RecordLateCorrection.php` rimane append-only per Effettivi. `HistoricalCorrectionSource.php` consente già il censimento tardivo quando la data reale rende la sorgente pertinente all'anno. `HistoricalExpenseCompatibility.php` deve ammettere una manuale contrattuale con Stima storica, mantenendo invariati origine manuale, anno, contenitore, Fornitore storico e stato non Stornato.

Non aggiungere una nuova Stima in anno chiuso. Se il costo non è stato sostenuto e manca soltanto una vecchia previsione, non esiste un Effettivo tardivo da inventare per “sistemare” il Budget: si documenta l'omissione e si pianifica soltanto negli anni aperti in cui ciò corrisponde alla realtà. Se era presente sotto il contenitore sbagliato nel chiuso, resta una Annotazione di errore storico, non un trasferimento compensativo.

### 8.11 Autorizzazioni, isolamento e test

Mantenere `ContractPolicy`, `ExpensePolicy`, `ExpenseLinePolicy`, `ProposalPolicy`, policy Budget/Chiusura e `LateCorrectionPolicy`. I controlli sulle Spese `system` restano anche nelle actions: le sole policy non distinguono tutti i casi economici. Le nuove figlie devono appartenere alla stessa Azienda del Contratto e allo stesso contesto di Proposta; i riferimenti non autorizzati vanno respinti prima di persistere.

La matrice minima è nel §12. I test che esprimono il divieto assoluto di Stima manuale devono essere aggiornati con il nuovo confine, conservando il divieto di modifica manuale della `system`. Non eliminare indiscriminatamente i test di comportamento escluso.

## 9. Rischi e regressioni

| Rischio concreto | Manifestazione | Prevenzione necessaria |
|---|---|---|
| R + S + M | La ricorrenza è contata due volte | Generatore restituisce solo R; lettura persistita S+M; proiezione R'+M' |
| Padre + figlia | Totale Proposta/report superiore al costo reale | Aggregare sorgenti di primo livello usando l'appartenenza del riferimento, non quella viva |
| Totale e dettaglio Budget diversi | Approvazione bloccata da `assertConsistent()` | Dettaglio completo e stessa attribuzione annuale della riga |
| Costo di altro anno nel Budget principale | Anticipazione artificiale del costo | Filtrare per Esercizio anche nuove figlie, dettagli e relativi impatti |
| Manuale persa nella proiezione | Falso delta negativo e Chiusura non confermabile | Conservare M prima/dopo; verificare quadratura con materializzazione |
| Una tantum copiata in N+1 | Sophos o setup ripetuti | Niente copia, Riporto o Riprogrammazione automatica delle manuali |
| Regole Effettivo applicate alle Stime | Impossibile pianificare un Contratto futuro | Validazioni separate; richieste miste soddisfano entrambi i vincoli |
| Allentamento dei controlli sulla `system` | Modifica manuale o Effettivi nella Stima generata | Protezioni nelle actions, non soltanto nei form |
| Proposta obsoleta | Ripristino di dati vecchi, applicazione doppia o figlie orfane | Revisioni, fingerprint, replay delle dipendenze e rivalidazione atomica |
| Azzeramento dopo cessazione/annullamento | Perdita di costi che sopravvivono all'accordo | Nessuna deduzione automatica sulla manuale; decisione §13.1 |
| Rinnovo interpretato come nuova una tantum | Costo duplicato all'evento contrattuale | Nessuna generazione manuale al rinnovo; automazione distinta |
| Storico ricostruito dalle Condizioni vive | Stima mostrata in un anno chiuso dove non esisteva | Riferimenti storici materializzati, senza rigenerazione retroattiva |
| Sorgente tardiva assente dal dettaglio | Totale a Conoscenza Corrente diverso dalle sue sorgenti | Unione Snapshot/correzioni senza mutare la Snapshot |
| Cambio Fornitore dopo uso manuale | Storia attribuita alla controparte sbagliata | Estendere `ContractEconomicUse`; verificare persistenza dopo azzeramento/restore |
| Restore di Budget dopo spostamento | Validazione con owner corrente interpreta male il vecchio totale | Usare owner materializzato nel Budget |
| Zero interpretato come gratuito/assente | Contratto valido nascosto o falsa informazione | Inclusione e stato separati dal costo; note e contesto annuale |
| Saldo Effettivi zero interpretato come assenza | Storno non lecito o perdita di realtà | `HaEffettivi` basato sulle righe non nulle attive, non sul saldo |

Il doppio conteggio **semantico** rimane diverso da quello algoritmico: un utente potrebbe inserire manualmente il canone già previsto dalle Condizioni. Il software deve rendere esplicito “costo aggiuntivo non ricorrente”, mostrare la composizione e proteggere la `system`; non può riconoscere automaticamente l'errore da importo o testo senza introdurre falsi positivi e un matching fuori dominio.

Non tutti i rischi sopra sono bug dei dati attuali. P1–P5 e P8 derivano dall'estensione; P6, P7 e P10 riguardano percorsi già esistenti che diventano determinanti per i casi richiesti. Le ipotesi di errore di continuità del backup sono identificate separatamente e devono essere riprodotte con test dedicati prima di scegliere una modifica strutturale.

## 10. Compatibilità con i dati esistenti

**Contratti ricorrenti già presenti:** Condizioni, ID, date, importi, ancora, attribuzione, rinnovi ed eventi restano identici. Poiché oggi M=0, la formula nuova si riduce alla precedente. I quattro cicli devono generare esattamente gli stessi importi e la stessa composizione; non basta una quadratura solo sul totale. Nessuna migrazione dei Contratti verso un tipo nuovo.

**Spese `system` esistenti:** restano nello stesso anno e con gli stessi ID. Il ricalcolo aggiorna la Riga esistente, compreso il valore zero. Non convertire una `system` in manuale né ricrearla per distinguere i costi.

**Spese manuali contrattuali con soli Effettivi:** restano tali. Non inferire Stime dai loro Effettivi, non rinominarle tutte “una tantum” e non modificare l'attribuzione storica. Le nuove Stime sono facoltative.

**Contratti a zero o con una Condizione artificiale:** non rimuovere automaticamente Condizioni a zero né trasformare quelle annuali limitate. Il repository non può stabilire se siano espedienti o veri accordi. Eventuali rettifiche vive devono seguire le operazioni autorizzate, con motivazione e tutela degli anni chiusi.

**Spese autonome/di Progetto già usate per un costo contrattuale:** nessun collegamento automatico per descrizione, Fornitore o importo. Negli anni aperti un eventuale spostamento è esplicito, con anteprima e ragioni richieste. Nei chiusi l'errore di contenitore si annota, non si corregge riscrivendo le righe o spostando artificialmente il costo in un altro anno.

**Budget e Chiusure approvati:** rimangono byte per byte invariati nel contenuto persistito. I lettori continuano a supportare le versioni dei dettagli già materializzate. Le righe figlie già contenute non vanno sommate una seconda volta nei report: correggere la lettura non equivale a riscrivere il riferimento storico.

**Bozze di Proposta:** l'aggiunta di `origin` alla baseline può far cambiare il fingerprint anche senza variazione economica. Usare il normale riallineamento e verificare che azioni precedenti valide possano essere mantenute; niente aggiornamento silenzioso dei fingerprint. Una Proposta già approvata non viene rigiocata. Azioni nuove richiedono i relativi lettori/applicatori; non promettere compatibilità verso una versione software precedente che non le conosce.

**Contratti importati:** un vecchio backup deve importare con gli stessi risultati; un nuovo backup misto deve preservare origine, anno, owner e ID rimappati. Nessuna deduzione di ricorrenza dalle Stime manuali. Il restore non deve essere seguito da un ricalcolo indiscriminato degli anni chiusi. Le limitazioni su audit e Bozze restano quelle dichiarate nel §8.9.

**Effetto DB:** il nucleo della soluzione è un cambiamento delle regole applicative e dei payload, non una migrazione dati. L'eventuale persistenza aggiuntiva di un fatto storico di primo uso economico dipende dal problema specifico del §13.3; se necessaria, richiederà una migrazione in avanti e una decisione di portabilità limitata a quel fatto. Non è necessaria per rappresentare Sophos e non va nascosta dentro un'affermazione generale “nessuna migrazione possibile”.

## 11. Piano di implementazione consigliato

Sequenza proposta per un'attività successiva; nessuno di questi interventi è stato eseguito qui.

1. **Recepire il dominio proposto.** Definire Allocato S+M, Condizioni facoltative, pianificazione manuale e comportamento terminale; risolvere §13.1 e circoscrivere §13.3. Confermare che l'automazione al rinnovo pluriennale resta esclusa salvo richiesta esplicita. Aggiornare soltanto le regole canoniche pertinenti.
2. **Abilitare il modello operativo.** Creazione/riattivazione senza Condizioni, prima Condizione successiva, Stime manuali e relativi ripristini/spostamenti. Proteggere `system`, Effettivi, anni chiusi e uso del Fornitore. Verificare subito ricorrenti invariati e caso misto.
3. **Allineare letture e Chiusura.** Totali annuali S+M, proiezioni R'+M', N+1 senza copie manuali, avvisi e Snapshot. Correggere i consumatori diretti delle Condizioni senza cambiare il significato del generatore.
4. **Completare Proposte e Budget come unico percorso.** Azione per manuali esistenti, nuove figlie, baseline/origine, replay, multi-Esercizio, approvazione atomica e dettaglio Budget. Verificare aggregazioni di primo livello anche nei report del Budget.
5. **Completare storia, correzioni e portabilità.** Sorgenti tardive nei report, manuali storiche compatibili con append di Effettivi, versioni dei dettagli, round trip e primo ricalcolo dopo restore. Adeguare la UI esistente alla distinzione fra durata e costo annuale.
6. **Eseguire le regressioni mirate e il quality gate.** Matrice §12, controllo browser dei flussi coinvolti, revisione complessiva del diff e gate corrente di `.github/workflows/ci.yml`. Rilasciare il comportamento completo: non rendere disponibili Stime manuali mentre Budget/Chiusura le interpretano ancora come illecite.

Nessuna nuova dipendenza, repository layer, coda, cache, feature flag o motore generale è giustificato da questa soluzione. Non occorre riorganizzare tutte le aggregazioni del prodotto per riusare una formula: riutilizzare i totali già corretti e correggere soltanto i chiamanti che confondono ricorrenza e Allocato.

## 12. Matrice di test

Questi sono **test da realizzare o estendere**, non test eseguiti sull'implementazione proposta. Sono raggruppati per invariante per evitare una suite duplicata per ciascuna schermata.

| ID | Livello/percorso | Scenario minimo e risultato atteso |
|---|---|---|
| T1 | Unit, motore ricorrente esistente | Dataset mensile/trimestrale/semestrale/annuale, anno intero e parziale, ancore 28–31 e bisestile, attribuzione fine ciclo dopo cessazione: identici importi/composizioni precedenti; zero Condizioni → zero |
| T2 | Feature, Contratto senza ricorrenza | Creazione Pianificato/Attivo senza Condizioni; gratuito/ignoto senza righe artificiali; aggiunta successiva della prima Condizione valida; riattivazione senza riapertura automatica delle vecchie |
| T3 | Feature, Sophos e attribuzione | Inizio 2025, fine 2028, nessun rinnovo; Stima manuale 2025 di 2.621,34, quantità 18 e unitario 145,63; Allocato successivo zero; nessuna ripartizione o generazione di Effettivi |
| T4 | Feature, Effettivi indipendenti | Stima 2.621,34 e Effettivo 2.800/2.500 → scostamenti +178,66/−121,34; Stima immutata. Sola Stima e soli Effettivi ammessi. Righe Effettivo opposte a saldo zero mantengono `HaEffettivi` |
| T5 | Feature, ricorrente + singoli | Canone annuo 1.200 + setup 300 + altro costo 50 → 1.550 in Contratto, Esercizio e report; ripetere ricalcolo conserva ID/righe manuali e unica `system`. Variazione tariffa cambia solo la ricorrenza |
| T6 | Feature, mutazioni e confini | Modifica/annullamento/ripristino di Stima manuale; importo negativo respinto; Effettivo negativo senza Nota respinto; Storno con `HaEffettivi` respinto; tutte le mutazioni manuali della `system` respinte, incluso ingresso Effettivo |
| T7 | Feature, stato e rinnovo | Setup 300 + mensile100, cessazione 15 giugno → 900; costo manuale successivo non copiato/azzerato; rinnovo/riattivazione non duplicano setup; annullamento rispetta la decisione §13.1; richieste Stima+Effettivo non aggirano lo stato |
| T8 | Feature, spostamento e Fornitore | Spesa con Stima entra/esce dal Contratto in anno aperto con owner esclusivo, Fornitore derivato e totali quadrati; motivi obbligatori rispettati; cambio Fornitore dopo uso manuale respinto anche dopo annullamento/Storno |
| T9 | Feature, Proposta completa | Nuovo Contratto senza Condizioni + nuova figlia; Contratto esistente con modifica Stime e nuova figlia; approvazione applica una volta, Budget totale=dettaglio=1.550, nessun Effettivo nel payload; tentativo sulla `system` respinto |
| T10 | Feature, baseline/replay/atomicità | Variazione viva di Stima/Effettivo/owner mentre Bozza; readiness blocca; Ricarica/Mantieni tratta padre e figlie coerentemente; figlia non più valida respinta; errore dopo applicazione parziale provoca rollback di Contratto, Spese, eventi e Budget |
| T11 | Feature, multi-Esercizio | Ricorrenza modificata su più anni, manuali diverse per anno; nuova figlia in altro anno aperto presente nell'impatto e nella realtà di quell'anno ma non nel Budget principale; nessuna manuale fuori piano azzerata; altre Bozze da riallineare |
| T12 | Feature, Budget/report | Snapshot con padre e righe figlie contenute: header, dettaglio, aggregato aziendale, Fornitore e confronti contano ogni costo una volta; dettaglio vecchio e nuovo leggibili; costo aggiunto dopo Budget modifica soltanto il corrente, non il Budget |
| T13 | Feature, Chiusura | Contratto misto con anteprima=valori ricalcolati=Snapshot=header; ricalcolo mantiene manuale; warning su totale completo; modifica dopo conferma del riepilogo invalida la conferma; errore lascia rollback completo |
| T14 | Feature, N+1 | Varianti N+1 assente/esistente: nel nuovo nessuna manuale copiata; nell'esistente resta il suo costo manuale proprio. Nessun Riporto contrattuale. Ricorrenze attribuite a fine ciclo conservate |
| T15 | Feature, chiusi e tardivo | Tentativi di Stima/modifica/spostamento in anno chiuso respinti; censimento con date reali non genera Stima retroattiva. Effettivo tardivo su sorgente assente dalla Snapshot visibile a Conoscenza Corrente e riconciliato col totale; Snapshot/Budget immutati |
| T16 | Feature, storia e compatibilità tardiva | Append di Effettivo su manuale storica con Stima ammesso nei limiti esistenti; `system` respinta; vista Alla Chiusura invariata; saldo netto correzioni zero mantiene le registrazioni e la sorgente pertinente |
| T17 | Feature, backup/restore | Round trip vecchio ricorrente, nuovo misto e senza Condizioni; stessi totali/report, legami e versioni; ricalcolo successivo senza duplicati. Budget precedente a spostamento mantiene owner storico. Primo uso poi azzerato/spostato esercita §13.3 |
| T18 | Feature/Livewire, permessi | Ruolo senza modifica respinto; riferimenti a Contratto/Spesa/Esercizio/elemento di Proposta di altra Azienda respinti; nessuna scrittura parziale; campi nascosti non bastano a eludere la policy/action |
| T19 | Livewire + browser | Creazione senza Condizioni, pianificazione singola/mista, sola lettura `system`, errori/anno chiuso; Sophos 2026 mostra validità 2025–2028, Allocato0 e costo2025 distinto. Elenco, dettaglio, suggerimento Effettivo e report mostrano lo stesso totale |

**Punti di estensione concreti:** `tests/Unit/Domain/Contracts/ContractAnnualAllocationTest.php`; `tests/Feature/Contracts/CreateContractTest.php`, `CreateContractActualTest.php`, `RecalculateContractEstimatesTest.php`, `ManageContractLifecycleTest.php`, `ChangeContractSupplierTest.php`, `ContractAnnualSituationUiTest.php`; `tests/Feature/Proposals/PlanContractTest.php`, `PlanExpenseTest.php`, `ApproveProposalSnapshotTest.php`, `ProposalMultiExerciseImpactTest.php`, `RealignProposalItemTest.php`; `tests/Feature/Closing/ContractClosingTest.php`, `ClosingAtomicityTest.php`, `ClosingSnapshotTest.php`; `tests/Feature/Reporting/ClosedKnowledgeReportTest.php`, `CurrentExpenseAndContractInclusionTest.php`; `tests/Feature/LateCorrections/RecordLateCorrectionTest.php`; `tests/Feature/BusinessBackup/RestoredContractContinuityTest.php`, `BusinessBackupRoundTripTest.php`, `BusinessBackupReportingEquivalenceTest.php`.

Riutilizzare fixture e dataset esistenti; estendere i test che già proteggono la stessa invariante. Non riscrivere una seconda suite dei cicli e non testare CRUD generico del framework. Gli attuali test che vietano Stime manuali di Contratto devono cambiare aspettativa soltanto per la manuale, mantenendo i MUST NOT pertinenti.

**Gate della futura implementazione:** ambiente isolato secondo `docs/testing-policy.md`, mai reset del database persistente di sviluppo. Il job `quality` corrente richiede validazione Composer, installazione lock PHP/Node, build frontend, audit dipendenze, migrazioni sull'ambiente testing, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pest` e smoke della pagina login. Gli strumenti PDF previsti dalla CI restano necessari per i test di reporting. Non eseguire deploy come parte della verifica locale.

## 13. Decisioni ancora necessarie

### 13.1 Annullamento prima dell'attivazione con Stime manuali residue

**Conflitto concreto:** §18.9 richiede Allocato zero negli anni aperti dopo annullamento; il nuovo piano manuale può contenere un costo che non viene meno con l'annullamento. Non esiste oggi una regola o un dato che stabilisca automaticamente quali righe sopprimere.

**Decisione raccomandata:** azzerare soltanto la ricorrenza tramite gli eventi/Condizioni; preservare le manuali e richiedere una revisione esplicita quando l'utente vuole annullarle. Una manuale residua deve essere spiegabile nel contesto terminale. L'alternativa è imporre l'annullamento esplicito di tutte le Stime manuali prima di confermare lo stato, ma escluderebbe costi residui pianificati finché non fossero registrati altrove.

**Impatto:** regola canonica, `CancelContract`, Proposta di annullamento, riepilogo e T7. È il punto di dominio che impedisce di considerare tutta la proposta già approvata. Non riguarda il ricalcolo dei normali Contratti ricorrenti.

### 13.2 Automazione del costo a ogni rinnovo pluriennale

La richiesta impone di analizzare il caso 25, ma non specifica un accordo reale con regola di automazione. Il modello raccomandato può registrare previsioni esplicite nei rispettivi anni; **non** automatizza un costo ogni 36 mesi o a ogni evento di rinnovo.

Non occorre una decisione aggiuntiva per B+D. Solo se l'automazione entra nel requisito, va stabilito se il costo segue un calendario economico fisso o il rinnovo effettivo dell'accordo, e cosa accade a variazione/annullamento del rinnovo. Sono le sole informazioni necessarie per scegliere un'estensione mirata; non giustificano oggi una Componente generica.

### 13.3 Continuità del fatto storico di primo uso economico nel backup

Il divieto di cambio Fornitore dopo primo uso è già canonico: **non è da rimettere in discussione**. La tensione è fra quel fatto storico, a volte riconoscibile soltanto dall'audit, e il perimetro del backup corrente che non esporta l'audit.

Prima di concludere che tutta la continuità è ottenibile senza modifiche di schema/formato, riprodurre T17: Stima positiva, poi zero o spostamento fuori, nessun Budget/Chiusura, export/import, tentativo di cambio Fornitore. Il codice letto non offre una prova della conservazione del blocco in quel caso.

Se confermato, la scelta necessaria è **dove preservare il fatto minimo di uso economico**, mantenendo il vincolo anche dopo restore: un dato persistente del Contratto oppure un'evidenza economica portabile circoscritta. Non esportare tutto l'audit per questo singolo scopo e non ricostruire date/importi che non sono più conoscibili. Per i backup legacy privi del fatto, va dichiarato il limite e concordato il trattamento degli oggetti non determinabili; né sbloccarli né bloccare indiscriminatamente ogni Contratto sono decisioni deducibili dai dati.

Questa decisione tecnica di compatibilità è separata dalla rappresentazione dei costi. Il modello B+D resta valido, ma una promessa assoluta di conservazione di ogni invariante dopo qualsiasi backup storico sarebbe priva di evidenza.

## 14. Verifica finale

La checklist conferma il **riesame dell'analisi**. Non certifica una funzionalità implementata né l'esecuzione dei test proposti.

- [x] **Doppio conteggio:** distinti R, S e M, padre/figlie, piano della Proposta, lettore Budget e totale di Esercizio; individuato anche P10.
- [x] **Budget:** verificati costruzione, dettaglio, quadratura, esclusione Effettivi, primo livello, anni e immutabilità.
- [x] **Proposte:** verificati catalogo, baseline, fingerprint, payload, readiness, replay, approvazione, nuove figlie, riallineamento e multi-Esercizio; supporto attuale distinto da quello da costruire.
- [x] **Chiusura:** verificati anteprima, proiezione, ricalcolo, warning, Snapshot, confronto confermato, N+1 nuovo/esistente e nessuna copia manuale.
- [x] **Rinnovi:** separate durata dell'accordo e ricorrenza economica; nessuna ripetizione automatica dei singoli; limite del caso25 dichiarato.
- [x] **Cessazioni:** preservati cicli iniziati e costi manuali; annullamento/riattivazione trattati separatamente, conflitto residuo esplicitato.
- [x] **Storico:** distinti dati vivi, Budget, Alla Chiusura e Conoscenza Corrente; nessuna conversione automatica dei vecchi Contratti.
- [x] **Esercizi chiusi:** nessuna Stima retroattiva, spostamento o riscrittura; censimento reale e soli Effettivi tardivi tramite i percorsi autorizzati.
- [x] **Report:** analizzati totali, sorgenti, aggregazioni, confronti, dashboard, visibilità a zero e sorgenti tardive assenti dalla Snapshot.
- [x] **Backup/restore:** verificati schema, relazioni, remapping, dettagli, ricalcolo successivo e limiti del formato; rischio di uso economico storico non occultato.
- [x] **UI:** individuati form e viste effettivamente attivi, prima Condizione, storia annuale e terminologia; nessun prototipo o nuovo flusso implementato.
- [x] **Contratti ricorrenti esistenti:** motore lasciato semanticamente invariato; verifiche numeriche locali e regressioni per le quattro frequenze identificate.
- [x] **Alternative e controesempi:** A–G analizzate, tutte le 25 casistiche coperte dalla matrice, seconda review critica completata prima della convergenza.
- [x] **Perimetro dell'attività:** prodotto solo questo rapporto; nessun intervento dell'analisi su codice, test, migrazioni, dati, Specifica Canonica, issue o PR.

**Verifiche effettivamente eseguite:** lettura mirata di dominio, codice, migrazioni, test e CI; ricerca dei chiamanti del motore e delle aggregazioni; invocazioni PHP in memoria delle classi esistenti, senza boot dell'applicazione/DB, per Sophos, zero Condizioni, quattro frequenze e cessazione. I risultati numerici sono riportati nei §§3.2 e 6; la moltiplicazione decimale 18 × 145,63 restituisce 2.621,34. Eseguito inoltre il controllo documentale di struttura, riferimenti e diff.

**Non eseguito:** nessuna suite Pest, migrazione, integrazione su DB, verifica browser o quality gate applicativo. L'analisi non modifica il software e i nuovi percorsi non esistono ancora. P7, P10 e i controesempi di backup derivano dall'ispezione statica e richiedono le riproduzioni indicate nella matrice.

**Nota sul workspace:** durante l'attività è comparsa una modifica esterna a `composer.lock`; non è stata prodotta né modificata da questa analisi. Il risultato attribuibile a questo lavoro resta esclusivamente il presente file.
