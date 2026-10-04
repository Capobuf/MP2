# Revisione indipendente del modello economico dei Contratti

Aggiornamento: 4 ottobre 2026. Prima revisione: 3 ottobre 2026. Repository: MP2. Oggetto: revisione critica di [contract-economic-model-review.md](/root/MP2/docs/analysis/contract-economic-model-review.md), non approvazione né implementazione della candidata B+D.

## 1. Verdetto

**Soluzione adeguata solo con le decisioni e le correzioni indicate; ciclo completo B+D non ancora eseguibile.**

Separare durata contrattuale, ricorrenza automatica e previsione manuale è proporzionato al problema. B+D rappresenta Sophos senza alterarne la sostanza: Contratto dal 22/02/2025 al 22/02/2028, nessun rinnovo, una Stima manuale di 2.621,34 € nel 2025, nessuna Stima nel 2026–2028, Effettivi registrati indipendentemente. Lo schema delle Spese e delle Righe può già contenere questi dati. Non occorrono un motore di componenti economiche, matching, ripartizione sulla durata o nuove frequenze per questo caso.

**Il rapporto originale non è però una specifica eseguibile senza ulteriori decisioni e correzioni.** La Canonica vigente vieta le Stime manuali di Contratto e richiede Condizioni alla creazione e alla riattivazione. La proposta cambia quindi il dominio, non soltanto i form. Restano da approvare il trattamento dei residui dopo annullamento e la regola della prima Condizione aggiunta a un Contratto già esistente senza Condizioni. Il blocco del Fornitore dopo primo uso economico è invece già obbligatorio: non è una decisione da riaprire.

La verifica del ciclo completo conferma con database i difetti di annualità Budget, doppio conteggio padre/figlia, aggregazioni storiche per Fornitore e Conoscenza Corrente (IR06–IR10). Rafforza IR05: Reload può ritirare la creazione della figlia ma lasciarla approvabile. Corregge il perimetro di IR12: la permanenza del primo uso si perde già nelle operazioni vive, senza restore, quando l'unico Effettivo esce dal Contratto. **Backup/restore, V3 e issue #33 sono sospesi:** IR13/IR14 e la parte storica di IR12 restano conservati, rinviati e non nuovamente verificati.

Per attraversare l'intero Esercizio, B+D richiede D1/D2 approvate, piano delle figlie coerente, writer e reader annuali completi, proiezioni di Chiusura R′+M′, storico e primo uso permanente. L'ordine minimo è nel §8.4: non esporre le nuove Stime prima che questi percorsi siano pronti. I 466 test attuali superati e le riproduzioni DB non certificano la futura feature, che oggi è fermata dai divieti vigenti. Non è dimostrata l'assenza di regressioni. Il caso 25 è rappresentabile con previsioni esplicite; **l'automazione di un costo a ogni rinnovo pluriennale non è coperta** e richiederebbe una decisione separata qualora diventasse requisito.

## 2. Perimetro ed evidenze

### 2.1 Stato osservato e vincoli rispettati

- Branch **main**, HEAD **fe5839e38afb3c33cbe96e896bd4184038000e64** («feat: Enhance proposal management and navigation»): coincide con entrambi i rapporti precedenti. Nessuna differenza di commit da riesaminare; implementazioni pertinenti ricontrollate sul checkout, senza dedurne automaticamente la correttezza.
- Stato iniziale: ` M composer.lock`, `?? docs/analysis/`. Preservati il lock modificato e gli altri documenti non tracciati. Aggiornato soltanto questo file già esistente; nessun terzo rapporto, commit, push, issue/PR o deploy.
- Letti integralmente AGENTS.md, richiesta allegata, Canonica vigente (compresi gli emendamenti finali), rapporto originale, review precedente, testing-policy.md e CI. Nessun vecchio piano Spec Kit usato come norma. Il §31.18 corregge «gestione terminata» in scelta Crea/Non creare N+1, senza offboarding; il §32 mantiene soltanto il collegamento informativo Progetto–Contratto.
- Seguiti creazione Esercizi, cataloghi, Proposte iniziali/Revisioni, mutazioni vive, conferma e Snapshot di Chiusura, N+1, correzioni tardive, annotazioni, lettori e UI registrata. Le prove e i limiti sono distinti qui sotto. Nessuna analisi nuova di package, binari o backup.
- Ambiente verificato **prima** delle esecuzioni: Laravel `testing`, connessione MySQL, schema configurato e `select database()` entrambi `testing`, host `127.0.0.1:3306`; TestEnvironmentGuard superato. PHP 8.3.6, MySQL 8.4.10. L'alias host `mysql` non risolve in questo ambiente: usato l'endpoint locale verificato. Nessuna fixture o reset sul database persistente di sviluppo.
- Pest ha usato il database testing con il normale isolamento previsto dai test; le riproduzioni temporanee con bootstrap Laravel hanno usato transazioni esterne annullate a fine scenario. Nessuna modifica a codice, test versionati, migrazioni, dipendenze, Canonica o rapporto originale. Script e log diagnostici soltanto in `/tmp`.

Impronte rilevate prima della scrittura del presente documento, da usare per il controllo di conservazione:

| File | SHA-256 |
|---|---|
| composer.lock | f564a54419d011244a62d86cacb0353cabf6aa46923b6a6a6a6d1b4669274c25 |
| Rapporto originale | 986bbd792410259be5c082ba9979b9e21628a206616fca8dd738839f2799d34c |
| Specifica Canonica v4 | d8a09bdc9349fe406dc8476fb295c2fb51c2099436ed72afaf8f9e8d1881bf15 |

### 2.2 Classificazione delle prove

**C — codice:** implementazione corrente letta. **E — esperimento:** distinguere **E isolato** (componenti in memoria, senza DB) ed **E DB** (bootstrap Laravel, Actions reali e persistenza isolata). **P — percorso dedotto:** conseguenza fra componenti non eseguita integralmente. **N — non verificato:** flusso B+D non implementato, browser, gara concorrente o altro limite esplicitato. **D — decisione di dominio:** risposta non determinata dalle fonti.

E1–E9 sono prove del **3 ottobre**, mantenute con la loro provenienza; non sono tutte rieseguite. E7/E8 riguardano esclusivamente il lavoro backup ora rinviato. E10–E27 e la suite sono nuove prove del **4 ottobre**. Una fixture costruita tramite factory non prova la sua creazione attraverso UI; una riproduzione negativa non equivale a feature superata. Gli attesi B+D nei §§6–11 sono regressioni future.

### 2.3 Esperimenti effettivamente eseguiti

**Provenienza storica E1–E9, 3 ottobre:** esecuzione tramite PHP da standard input, autoload del repository, senza bootstrap Laravel e senza connessione DB. Dove necessaria, è stata istanziata soltanto una Factory di validazione con traduttore in memoria. I metodi privati di trasformazione sono stati invocati tramite Reflection; i modelli usati erano oggetti non salvati. Gli importi sono stringhe decimali.

| ID | Input e componente reale | Risultato osservato | Portata della prova |
|---|---|---|---|
| E1 | ContractAnnualAllocation::forYear: annuale 2621.34 dal 22/02/2025, validità aperta, stato Attivo fino al 22/02/2028 incluso | 2621.34 in ciascuno degli anni 2025, 2026, 2027 e 2028; senza Condizioni: 0.00 | Dimostra perché annualizzare Sophos è errato; nessuna creazione di Contratto eseguita |
| E2 | ManualExpenseLine::suggestedAmount con quantità 18 e unitario 145.63 | 2621.34 | Prodotto suggerito, non cambio dell'autorità dell'importo della Riga |
| E3 | ReportAggregator::executive con padre Progetto 300 e figlia 300, entrambe come ReportSource | allocation 600.00 e source_count 2 | Il filtro di primo livello deve precedere l'aggregazione; il lettore Budget non lo applica |
| E4 | ReportAggregator::suppliers con Contratto Budget 1200 e dettaglio annidato contract; poi Contratto Chiusura 1200/900 e expenses con final_estimate_total/closing_actual_total | Budget: elenco vuoto; Chiusura: bucket del Fornitore con allocation 0.00 e actual 0.00 | Mismatch reale fra forme dei dettagli e aggregatore; non report HTTP completo |
| E5 | ComparisonEngine con Budget 300, Chiusura senza Effettivi, riferimento corrente a conoscenza con +100 e −100, actual 0 e hasActuals false come nel lettore | Etichetta planned_not_occurred presente insieme a late_correction | Conferma la conseguenza del flag storico non aggiornato; costruzione del riferimento dedotta dal codice |
| E6 | BudgetSnapshotPayload::allocation con Spesa dell'Esercizio identificato da 2027, Stima attiva 300, richiesta per Esercizio identificato da 2026 | 300.00 | Il helper Expense ignora l'anno richiesto; autorizzazione della nuova Spesa in altro anno ricostruita da ExpensePlan |
| E7 | portableJson → hydrateJson: owner con type=contract, origin_id=7, origin_key=contract:7; rimappatura a ID 70 | Export conserva source_ref=CTR-0000000001; import restituisce type e label, perdendo origin_id e origin_key | Perdita riprodotta nei trasformatori reali, senza workbook né import DB |
| E8 | BusinessBackupValidator::assertBudgets: Budget storico 300 per Spesa allora autonoma, attualmente collegata a Contratto; dettaglio storico owner.type=standalone | ValidationException: «Totale Budget non riconciliato con le righe di primo livello.» | Difetto del controllo reale su fogli minimi sintetici; non validazione integrale di un pacchetto |
| E9 | touchingActions del Progetto padre e azione create_expense della figlia con project_item_id del padre; quindi ProjectPlan::apply con quel tipo | L'azione della figlia viene selezionata; il dispatcher del padre risponde «Azione Progetto non valida.» | Due componenti eseguiti separatamente; composizione attraverso replay dedotta dal codice |

Un tentativo di invocare replay integralmente senza bootstrap si è arrestato in Eloquent loadMissing per assenza del connection resolver, prima di raggiungere il replay. Non è una prova di errore applicativo: per E9 sono state isolate selezione e applicazione. Questo limite riguarda il tentativo storico; il 4 ottobre E13 ha eseguito il percorso con DB senza modificare l’applicazione.

### 2.4 Nuove esecuzioni applicative e limiti

Comando della suite, con credenziali esclusivamente del servizio testing:

```bash
env APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=testing DB_USERNAME=sail DB_PASSWORD=password DB_URL='' \
  vendor/bin/pest tests/Feature/Expenses/CreateExerciseTest.php \
  tests/Feature/Closing tests/Feature/Proposals tests/Feature/LateCorrections \
  tests/Feature/Reporting --compact
```

**Esito: 466 passed, 3.647 assertions, 473,81 s, exit 0.** Log della sessione: `/tmp/mp2-review-pest.log`. Non è il quality gate completo e non contiene un'implementazione B+D. I test letti e usati come prova puntuale sono richiamati nel §10.1: il risultato dell'intera selezione non attribuisce automaticamente copertura a ogni scenario della richiesta.

Riproduzioni: `/tmp/mp2-cycle-review.php`, stesso ambiente e in aggiunta `CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`, eseguito con `php /tmp/mp2-cycle-review.php`; filtro opzionale per ID. Data operativa fissata **04/10/2026, Europe/Rome**; N=2025, N+1=2026, salvo indicazione. Actor della stessa Azienda con permessi necessari; R=1.200 da mensile 100, inizio 01/01/2025, attribuzione a inizio ciclo, nessun rinnovo automatico. Fixture separate e rollback finale. Output: `/tmp/mp2-cycle-results.jsonl`, `/tmp/mp2-cycle-corrected.jsonl`, `/tmp/mp2-cycle-final.jsonl`, `/tmp/mp2-cycle-terminal.jsonl`; corrected/final sostituiscono i primi tentativi dei rispettivi ID. Questi file sono diagnostici temporanei, non un nuovo rapporto né una suite versionata.

| ID / tipo | Scenario e percorso | Osservato; limite |
|---|---|---|
| E10 / DB | CreateContract; CreateExpense mista M=300/E=900; CreateExpenseLine su system | Contratto senza Condizioni rifiutato; richiesta mista rifiutata interamente, nessuna Spesa creata; solo E=900 accettato, A=1.200; system protetta. Divieti conformi oggi, non bug B+D |
| E11 / DB | PlanProject padre nuovo + PlanExpense figlia 300 → ApproveProposal → BuildReport Budget | Bozza: zero nuovi oggetti vivi; dopo approvazione A vivo/header=300, due righe padre/figlia=300 ciascuna, report=600 e due sorgenti. IR07 |
| E12 / DB | Proposta N, nuova autonoma 200 in N+1 → approvazione | Impatto N=0/N+1=+200 e realtà corretti; Budget N=200 errato, dettaglio exercise_id di N+1 ma exercise_year=2025. IR06 |
| E13 / DB | Progetto esistente, nuova figlia 300 referenzia padre; cambio titolo/revisione del padre in fixture; Keep poi Reload | Keep rifiutato «Azione Progetto non valida»; Reload ritira create_expense, conserva il risultato della figlia; readiness true, approvazione crea 300. IR05. Mutazione del padre predisposta via modello; riallineamento/approvazione reali |
| E14 / DB | Autonoma con Stima 300 → Budget → vera Chiusura → tardive +100/−100 → confronto | Snapshot header invariato; E=0, H vivo=true, H report=false; etichette planned_not_occurred e late_correction. IR10 |
| E15 / DB | Autonoma a zero omessa dalla Chiusura → +100/−100 | Header Conoscenza Corrente 100 poi 0; zero sorgenti e aggregati vuoti. Il saldo finale nasconde l'omissione. IR09 |
| E16 / DB + query log | Prepare con N+1 da creare; dopo preview nuovo E=900; conferma vecchio fingerprint | Preparazione senza query di scrittura nella fixture e N+1 non creato; conferma rifiutata, N Aperto; restano solo audit started/failed. Nessuna prova di concorrenza simultanea |
| E17 / DB, stato anomalo esplicito | Audit di sovraspesa con Nota obbligatoria assente predisposto in fixture; Prepare e chiamata diretta CloseExercise | Prepare bloccata; la chiamata diretta viene rifiutata dal guard **ClosingSnapshot::creating**, rollback, zero Snapshot e N Aperto; solo started/failed. Smentita l'ipotesi di bypass basata sulla sola differenza Prepare/Review |
| E18 / DB | Prima chiudere 2025 senza 2024; poi creare 2024 e chiuderlo senza rinvii | Operazioni consentite; 2025 resta Chiuso, disposizione already_existed. Nessuna riapertura; §11.5 vieta precedenti aperti già esistenti, non prescrive un blocco di creazione retrograda |
| E19 / DB | Contratto con Condizione reale a zero; unico E=100; UpdateExpense preview/confirm verso autonoma con motivo; UpdateContract Fornitore | Primo uso true→false; cambio Fornitore accettato, Effettivo 100 conservato fuori Contratto, nessun Budget/Chiusura. Difetto vivo IR12; nessuna manuale Stima introdotta o guard disabilitato |
| E20 / DB | Contratto 1.200/900 + Progetto 300 + autonoma 50 → Budget → Chiusura → report | Attesi 1.550/900. Corrente e CdC quadrano; Budget totale 1.550 ma Fornitori 50; Chiusura totale 1.550/900 ma Fornitori 50/0. Tutti i CdC sono Non classificato: non prova un albero multilivello. IR08 |
| E21 / DB, fault injection | Due nuove autonome 100/200; checkpoint after_expense fallisce sulla seconda | Zero Spese/Budget, revisione Esercizio preservata, Proposta draft; solo proposal_approval_failed. Prova transazione attuale, non nuove figlie contrattuali |
| E22 / DB, controllo condiviso | Contratto S=1.200/E=900 + **autonoma** 300 → Budget → Revisione in Bozza → modifica viva 400 → Reload → approvazione → Chiusura con N+1 nuovo | v1=1.500 immutata; Bozza obsoleta; v2=1.600, Chiusura=1.600/900, link v1/v2 corretti; N+1=1.200 e nessuna Spesa non system. Non è il percorso misto B+D |
| E23 / DB | Proposta principale 2026 pianifica 200 nel precedente 2025 aperto; chiudere 2025; tentare approvazione direttamente | Prima ready=true; approvazione rifiutata per anno destinazione chiuso, nessun costo nel chiuso né Budget principale. Variante cronologicamente lecita del cambio stato intermedio |
| E24 / DB | Anni passati/correnti/futuri, permessi, tenant, duplicate e retry | Effettivo 2027 rifiutato nel 2026; anno altra Azienda rifiutato; actor senza permesso 403; anno duplicato rifiutato; stesso operation_id restituisce stesso Esercizio; creazione 2024 ammessa |
| E25 / DB | Vera Chiusura vuota 2025; nel 2026 censire Contratto con inizio 2025; tardive +100/−100 | Nessuna Stima retroattiva; header 100 poi 0, zero sorgenti report, H contrattuale vivo=true. IR09 con Contratto realmente censito tramite CreateContract |
| E26 / DB, controllo condiviso | N+1 già aperto: S=1.200 e autonoma propria 450, Budget e Revisione draft; chiudere N senza cambi ricorrenti | N+1=1.650; 450 e ID Righe preservati, Budget immutato, Bozza resta ready perché la sorgente non è mutata. Non prova M contrattuale; nessuna invalidazione indiscriminata richiesta |
| E27 / DB | Draft iniziale N con Stima autonoma 300 → tentativo Close → scarto → Close senza Budget → chiamate dirette nel chiuso | Draft blocca Close; dopo scarto Snapshot=300 e Budget null; approvazione della scartata e modifica Riga 403, nuova Proposta/Stima rifiutate per anno chiuso. Nessuna riapertura |

I primi tentativi E15/E17/E22 hanno incontrato errori dello script di prova (Nota per importo zero, data audit richiesta, revisione del modello non ricaricata): corretti solo nello script temporaneo e rieseguiti. E14 aveva confrontato attributi del modello appena creato con quelli ricaricati: il confronto omogeneo conferma l'immutabilità. Questi errori non sono rilievi applicativi.

## 3. Verifica delle affermazioni del rapporto

| Affermazione originale | Esito indipendente |
|---|---|
| Il problema è di dominio, non soltanto di UI | **Confermata C.** §§12.14, 15.4, 18.2 e 18.8 della Canonica; CreateContract, ReactivateContract, ContractPlan e ContractExpenseActivity applicano i vincoli |
| Una Condizione annuale aperta ripete Sophos | **Confermata E1.** Anche il 2028 riceve un ciclo iniziato il 22 febbraio, con la convenzione inclusiva indicata |
| Quantità e unitario esistono già sulle Righe | **Confermata C/E2.** Non vanno duplicati su Contratto e Condizione; importo a due decimali autoritativo, fattori a sei decimali |
| Schema sufficiente per Stime manuali contrattuali | **Confermata per la rappresentazione.** Non dimostra sufficienza delle regole né della persistenza del primo uso economico |
| AnnualTotals ed Esercizio sanno già sommare le manuali | **Confermata C.** Sommano Righe attive non Stornate. Non significa che UI, Proposta e Snapshot usino tutti quelle somme |
| P1: dettaglio Budget ancora ricorrente | **Confermata C/P.** Riga calcolata da Spese, dettaglio Contratto da motore; con M positivo il controllo di coerenza può rifiutare l'approvazione |
| P2: proiezione Chiusura perde M | **Confermata, con precisazione.** L'anteprima omette M; il ricalcolo muta solo system. Il confronto finale può causare rollback. Non è dimostrata una cancellazione fisica delle manuali |
| P3/P4: piano figlie e prima Condizione richiedono nuovi percorsi | **Confermata C.** Non basta aprire CreateExpense o togliere minItems |
| P5: primo uso non riconosce nuove Stime manuali | **Confermata C/P.** E19 dimostra anche la perdita del vincolo dopo spostamento vivo; il restore resta rinviato |
| P6: dettaglio annuale storico ricostruito dal vivo | **Confermata C.** Il componente attivo è ContractInfolist; ContractAnnualSituationsRelationManager non è registrato in ContractResource |
| P7/P8: sorgente tardiva assente e incompatibilità delle manuali storiche | **Confermata E15/E25 DB per sorgente assente, C/P per futura manuale con Stima.** Inoltre il flag HaEffettivi non viene aggiornato dalle rettifiche: IR10 |
| P9: warning da composizione vuota e lessico Pagamento | **Confermata C.** Composizione annuale vuota non prova assenza di copertura di una Condizione |
| P10: doppio conteggio Budget padre/figlie | **Confermata C/E11 DB.** Il writer esclude figlie dal totale, il reader le rimette fra le sorgenti economiche |
| Riusare il pattern Progetti risolve la pianificazione delle figlie | **Solo in parte.** Riutilizzabili responsabilità e ordine di creazione; replay, rappresentazione delle righe e filtri non sono automaticamente corretti: IR04–IR06 |
| ID e relazioni sono rimappati nel backup | **Rilievo storico rinviato, non riverificato.** Le FK principali sono rimappate, ma l'owner annidato usa type dove i trasformatori richiedono source_type: E7 |
| Appartenenza storica Budget nel validator da verificare | **Evidenza storica E8; rinviata, non rieseguita.** Non è più soltanto un sospetto statico |
| Tutti i 25 casi sono coperti | **Da qualificare.** Caso 16 subordinato a decisione; caso 25 coperto soltanto manualmente. La matrice non equivale a verifica end-to-end |
| Intervento su una «Proposta di annullamento» | **Indicazione impropria di scope.** ContractPlan gestisce cessazione/riattivazione, non un'azione generale di annullamento contrattuale; §12.9 non impone di aggiungerla |
| Assenza di nuova tabella economica come prova di semplicità | **Ragionevole ma insufficiente.** La semplicità deriva anche da un solo autore per le Righe system, un solo piano modificabile per figlia e lettori storici coerenti |

Un'altra omissione significativa riguarda le quantità nelle Proposte: ProposalSourceSnapshot conserva dati delle Righe con id, mentre i payload modificabili accettano line_id e pochi campi economici. Il futuro supporto non può inoltrare meccanicamente le Righe dello snapshot né eliminare quantità/unitario durante un aggiornamento del solo importo.

## 4. Problemi trovati

Gravità: **alta** quando il problema altera totali, storia, invarianti o blocca un flusso necessario; **media** quando rende il percorso incompleto o fuorviante senza provare una perdita economica. «Attuale» descrive codice esistente; «estensione» descrive un problema che diventa raggiungibile introducendo B+D. Non sono segnalazioni di vulnerabilità sfruttate o prove su dati reali.

### IR01 — Contrasto con la Canonica e residui terminali

**Alta; estensione; C/D.** Contratto Pianificato con M=300 per un costo iniziale ancora dovuto, poi annullato. La candidata conserva 300; §18.9 prescrive Allocato zero negli anni aperti. §§12.14/15.4 vietano inoltre quella Stima; §§18.2/18.8 richiedono Condizioni. Non si può dichiarare entrambi i comportamenti conformi.

Riferimenti: [CancelContract::execute](/root/MP2/app/Actions/Operations/CancelContract.php:55), [ReactivateContract::execute](/root/MP2/app/Actions/Operations/ReactivateContract.php:29), Canonica §§18.8–18.9 e rapporto originale §§7.4–7.5, 13.1. Il ricalcolo corrente annulla le ricorrenze, non decide quali futuri costi manuali siano ancora dovuti.

**Correzione minima raccomandata:** approvare esplicitamente il nuovo perimetro; preservare le manuali dopo cessazione/annullamento e farne modificare le Stime con le operazioni esistenti, con motivo ove richiesto. Non inventare una cancellazione collettiva o un nuovo workflow di Proposta. **Regressione:** annullamento con M=300 lascia il piano visibile, oppure applica la diversa decisione approvata; eventuale annullamento esplicito delle Righe è tracciato e atomico con lo stato se confermato nella stessa operazione. D1 nel §9 è bloccante.

### IR02 — Prima Condizione, richieste miste e riattivazione non risolte togliendo un divieto

**Alta; estensione; C/P/D.** Si crea un Contratto senza Condizioni, poi si aggiunge il primo canone; oppure si pianifica M su un Contratto non ancora Attivo. [SaveContractEdits::changes](/root/MP2/app/Actions/Operations/SaveContractEdits.php:67) rifiuta la nuova Condizione quando manca la precedente; [CreateContractCondition::execute](/root/MP2/app/Actions/Operations/CreateContractCondition.php:64) richiede Attivo oggi; [ContractForm](/root/MP2/app/Filament/Resources/Contracts/Schemas/ContractForm.php:138) limita l'aggiunta alla presenza dell'ultima Condizione. ReactivateContract richiede dati della nuova Condizione. [ContractPlan::validateForApproval](/root/MP2/app/Domain/Proposals/ContractPlan.php:20) richiede Condizioni per un nuovo Contratto.

[ContractExpenseActivity::validate](/root/MP2/app/Domain/Contracts/ContractExpenseActivity.php:21), dopo assertActualOnly, continua a trattare l'intera richiesta come Effettivo ordinario/terminale. Eliminare soltanto assertActualOnly mantiene blocchi impropri sulle Stime oppure, saltando tutta la validazione, indebolisce gli Effettivi. [ExpenseForm](/root/MP2/app/Filament/Resources/Expenses/Schemas/ExpenseForm.php:74) converte persino le Righe a actual al cambio di contenitore.

**Correzione minima:** percorsi espliciti per prima Condizione e riattivazione facoltativa; validazione delle Stime distinta da quella degli Effettivi, con entrambe applicate alle richieste miste e ai ripristini. Effettivi su Pianificato restano vietati. Nessuna conversione automatica del tipo Riga. **Regressione:** matrice stato × sole Stime/soli Effettivi/miste, incluse mutazioni e ripristini; primo canone posticipato e storico aperto secondo D2.

### IR03 — Totali completi omessi da Budget e proiezione di Chiusura

**Alta; estensione; C/P.** S=1.200, M=300, E=900. Attesi A=1.500 e scostamento −600. [BudgetSnapshotPayload::build](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:43) legge le Spese, ma [contractDetail](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:196) ricostruisce soltanto R e assegna R al totale di dettaglio alla riga 240. assertConsistent alla riga 350 confronta 1.500 con 1.200 e può impedire l'approvazione.

[ContractClosingProjection::build](/root/MP2/app/Domain/Closing/ContractClosingProjection.php:131) confronta prima S+M con dopo R′: a ricorrenza invariata espone delta −300. [RecalculateContractEstimates::recalculateWithinTransaction](/root/MP2/app/Actions/Operations/RecalculateContractEstimates.php:80) modifica solo system. [CloseExercise::execute](/root/MP2/app/Actions/Closing/CloseExercise.php:267) confronta Snapshot e anteprima e può fare rollback. La manuale non viene per questo cancellata.

**Correzione minima:** A=S+M nelle letture; A′=R′+M′ nelle proiezioni; composizione ricorrente tenuta distinta. Snapshot con dettaglio di tutte le Stime conteggiate. Per N+1 già esistente usare M di N+1; per N+1 appena creato M=0. Non cambiare forYear in un totale comprensivo delle manuali. **Regressione:** Budget 1.500, Chiusura 1.500 e N+1 con manuale preesistente 450, incluse conferma obsoleta e rollback.

### IR04 — Piano delle figlie contrattuali senza autorità e identità sufficienti

**Alta; estensione; C/P.** Contratto con system 1.200 e manuale 300: il piano del padre contiene entrambe senza origin; non esistono payload per creare una figlia di Contratto. [ProposalSourceSnapshot::expense/contract](/root/MP2/app/Domain/Proposals/ProposalSourceSnapshot.php:16), [ProposalActionPayload::ALLOWED](/root/MP2/app/Domain/Proposals/ProposalActionPayload.php:13), [ExpensePlan::apply](/root/MP2/app/Domain/Proposals/ExpensePlan.php:67) e [ApplyContractPlan::execute](/root/MP2/app/Actions/Proposals/ApplyContractPlan.php:22) confermano il gap. Il cambio owner attuale azzera contract_id; non è un'implementazione riusabile senza adattamento.

Il pattern [ApplyProjectPlan::execute](/root/MP2/app/Actions/Proposals/ApplyProjectPlan.php:33) aggiorna solo Stime, ma annulla le Righe omesse e usa line_id. Copiato senza un filtro manual/system potrebbe rendere modificabile la generata; copiato con una trasformazione incompleta id→line_id potrebbe sostituire Righe anziché aggiornarle. Quantità/unitario non sono ammessi dal payload Stima corrente. Non è stata eseguita una Proposta B+D, oggi non supportata.

**Correzione minima:** per figlie esistenti un solo piano nel padre, con identità, origin, anno, stato, revisioni e Righe identificate; system visibile ma non modificabile. Per nuove figlie un solo elemento nuovo, riferimento al Contratto vivo o ProposalItemID, senza duplicare la Stima nel padre. Preservare quantità, unitario e unità delle Righe esistenti, e supportarli coerentemente dove vengono raccolti per nuove Stime. Validare l'intero riferimento nella stessa Azienda/Proposta. **Regressione:** nuova figlia, modifica parziale di figlia con Effettivi, tentativo di edit system, Riga estranea, Spesa Stornata, nessuna perdita dei metadati e nessun doppio conteggio. Non aggiungere spostamenti contrattuali in Proposta fuori scope.

### IR05 — Replay del padre errato e risultato applicabile dopo ritiro della figlia

**Alta; attuale e trasferibile all'estensione; C/E9 isolato storico/E13 DB.** Un Progetto esistente incluso in Bozza è riferito da una nuova Spesa tramite project_item_id. Dopo un cambiamento vivo del padre, il mantenimento delle decisioni seleziona anche create_expense della figlia. [ProposalActionReplay::touchingActions](/root/MP2/app/Domain/Proposals/ProposalActionReplay.php:62) seleziona qualunque stringa diretta uguale all'ID del padre, ma [replay](/root/MP2/app/Domain/Proposals/ProposalActionReplay.php:40) applica ogni azione con il dispatcher del padre. ProjectPlan rifiuta create_expense. Il matcher inoltre non percorre gli array di riferimenti.

[RealignProposalItem::execute](/root/MP2/app/Actions/Proposals/RealignProposalItem.php:94) ritira le azioni coinvolte ma aggiorna soltanto il risultato dell'elemento riallineato. **E13 conferma il secondo difetto:** create_expense diventa withdrawn, il risultato della figlia rimane 300, readiness la considera pronta e ApproveProposal materializza la Spesa. Quindi non basta correggere il dispatcher Keep: Reload deve eliminare/rivalidare anche il risultato dipendente, senza lasciare una decisione ritirata ancora applicabile.

**Atteso:** dipendenza dal padre rivalidata, decisione della figlia mantenuta o ritirata coerentemente sul suo elemento. **Correzione minima:** distinguere azioni possedute dall'elemento e azioni che lo referenziano; riconoscere i riferimenti previsti dai payload, senza un motore generico di grafi. Riallineare/rivalidare i risultati effettivamente coinvolti. **Regressione:** Keep/Reload/Manual con padre vivo cambiato, figlia nuova e riferimenti annidati; nessuna azione ritirata lascia un risultato applicabile incoerente. I test esistenti di RealignProposalItem letti usano una Spesa autonoma, non questo grafo.

### IR06 — Spesa di altro Esercizio conteggiata nel Budget principale

**Alta; attuale e trasferibile; C/E6 isolato storico/E12 DB.** Proposta principale 2026; nuova Spesa autonoma di 300 nell'aperto 2027. [ExpensePlan::validateResult](/root/MP2/app/Domain/Proposals/ExpensePlan.php:106) richiede anno aperto della stessa Azienda, non necessariamente principale. [BudgetSnapshotPayload::build](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:38) percorre tutti gli elementi; [allocation](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:259) ignora exerciseId per una Spesa. Il Budget 2026 riceve 300. [expenseDetail](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:147) combina exercise_id della Spesa con exercise_year principale: identità annuale contraddittoria. Le nuove figlie possono analogamente apparire con importi di altro anno nel dettaglio, anche se escluse dall'header.

**Atteso:** impatto vivo 2027=300, contributo economico Budget 2026=0. §13.3 distingue Snapshot principale e aggiornamenti degli altri anni. **Correzione minima:** applicare il perimetro annuale sia ai valori di Riga sia ai dettagli, conservando la tracciabilità degli elementi efficaci richiesta da §7.6.4. Non risolvere sommando tutti gli anni e neppure eliminando indiscriminatamente elementi obbligatori: l'elemento fuori anno può restare a contributo zero con l'azione multi-Esercizio esplicitata. **Regressione:** nuova autonoma e nuova figlia in N+1; header, dettagli, report, export e restore non attribuiscono il costo a N.

### IR07 — Lettore Budget riconta figlie già comprese nei padri

**Alta; attuale; C/E3 isolato storico/E11 DB.** Progetto 300 e sua nuova figlia 300 materializzati in due BudgetSourceRow. Il writer totalizza 300, ma [BuildReport::budgetSources](/root/MP2/app/Actions/Reporting/BuildReport.php:163) restituisce entrambe e [ReportAggregator::executive](/root/MP2/app/Domain/Reporting/ReportAggregator.php:13) somma 600. Lo stesso errore contaminerebbe le nuove figlie contrattuali, i confronti, i conteggi e i roll-up.

**Correzione minima:** costruire le sorgenti economiche di primo livello dal contenitore materializzato nel riferimento storico; figlie consultabili nel dettaglio e nella tracciabilità, non nuovamente sommate. Non usare l'owner vivo. **Regressione:** Progetto e Contratto con nuove figlie, più una autonoma: header = somma sorgenti economiche del report = somma per CdC, senza eliminare il dettaglio delle figlie.

### IR08 — Aggregazioni per Fornitore incompatibili con Budget e Chiusure

**Alta; attuale; C/E4 isolato storico/E20 DB.** Contratto Budget 1.200 o Chiusura 1.200/900. [ReportAggregator::suppliers](/root/MP2/app/Domain/Reporting/ReportAggregator.php:35) legge detail.expenses e i campi allocation/actual/supplier_id. Il Budget colloca i dati sotto detail.project o detail.contract; la Chiusura usa final_estimate_total/closing_actual_total e supplier annidato nelle Spese: [ClosingSnapshotPayload::expenseDetail](/root/MP2/app/Domain/Closing/ClosingSnapshotPayload.php:471). Conseguenza osservata: contributo assente o zero, sebbene il totale generale sia positivo. Per i Progetti può anche andare perso il Fornitore della figlia.

Le rettifiche tardive modificano ReportSource.actual, non detail.expenses: una correzione della sola forma delle Snapshot non basta a farle apparire nel totale per Fornitore.

**Correzione minima:** interpretare esplicitamente le forme/versioni storiche nel percorso che prepara i contributi per Fornitore. Un Contratto ha un Fornitore e può contribuire con il proprio totale; i Progetti richiedono i contributi delle figlie e il Riporto separato. Comporre le rettifiche mediante i loro contesti, senza riscrivere lo storico. Non aggiungere un'infrastruttura universale di normalizzazione. **Regressione:** stesso insieme numerico nei riferimenti Corrente, Budget, Alla Chiusura, Conoscenza Corrente; quadratura per Fornitore incluso «senza Fornitore» e Riporto.

### IR09 — Sorgente tardiva assente dalla Snapshot: totale senza dettaglio

**Alta; attuale; C/E15/E25 DB.** Nel 2026 si censisce un Contratto iniziato nel 2025 chiuso, assente dalla Snapshot; si registra un Effettivo tardivo 100. [HistoricalCorrectionSource](/root/MP2/app/Domain/LateCorrections/HistoricalCorrectionSource.php:29) ammette la sorgente storicamente pertinente; [RecordLateCorrection::execute](/root/MP2/app/Actions/LateCorrections/RecordLateCorrection.php:197) memorizza correzione e contesti. [BuildReport::closingSources](/root/MP2/app/Actions/Reporting/BuildReport.php:212) itera solo le righe della Snapshot, mentre [annualTotals](/root/MP2/app/Actions/Reporting/BuildReport.php:590) somma tutte le correzioni. Atteso: Alla Chiusura immutata; Conoscenza Corrente mostra +100 e la sorgente che lo spiega. Problematico: +100 nel totale, nessuna sorgente corrispondente.

**Correzione minima:** unione fra identità della Snapshot e identità delle rettifiche per la sola Conoscenza Corrente. Non inserire una nuova Riga nella Snapshot originale. Non inventare stato o CdC storico dal vivo: ownerContext conserva contenitore e CdC diretto, non necessariamente la lineage annuale contrattuale mancante. Il lettore di righe Contratto deve accettare l'assenza di un fatto storico anziché trasformarla in uno stato corrente. **Regressione:** sorgente non presente con +100, poi −100; totale/dettaglio/Fornitore quadrano, storia originaria invariata, classificazione non documentata dichiarata tale.

### IR10 — HaEffettivi non aggiornato dalle correzioni tardive

**Alta; attuale; C/E5 isolato storico/E14 DB.** Alla Chiusura: A=300, E=0, HaEffettivi=false. Rettifiche +100 e −100: E resta zero, ma esistono Effettivi non nulli. [BuildReport::closingSources](/root/MP2/app/Actions/Reporting/BuildReport.php:250) conserva il flag della Snapshot. [ComparisonEngine](/root/MP2/app/Domain/Reporting/ComparisonEngine.php:126) usa quel flag per «Previsto non avvenuto»; [ReportSource::comparisonValue](/root/MP2/app/Domain/Reporting/ReportSource.php:41) con E=0 e flag false ripiega sull'Allocato. E14 conferma l'intero percorso con database: H vivo=true, H nel report=false e l’etichetta errata planned_not_occurred convive con quella corretta late_correction, mentre la Chiusura resta invariata.

**Correzione minima:** nel riferimento a Conoscenza Corrente, flag = HaEffettivi storico oppure presenza di almeno una Riga tardiva attiva non zero; mai confronto del solo saldo netto. Alla Chiusura conserva il flag originario. **Regressione:** +100/−100, singolo +100 e sola Riga zero; nessun «Previsto non avvenuto» nei primi due casi, senza cambiare la semantica canonica della Riga zero.

### IR11 — Manuale storica con Stima non riutilizzata dal percorso tardivo

**Media; estensione; C/P.** La futura Spesa manuale Sophos contiene la Stima 2025 e viene chiusa; un Effettivo tardivo deve potersi aggiungere senza riscrivere la previsione. [HistoricalExpenseCompatibility::accepts](/root/MP2/app/Domain/LateCorrections/HistoricalExpenseCompatibility.php:21) rifiuta una Spesa contrattuale con qualsiasi Riga estimate, anche annullata. RecordLateCorrection non rifiuta necessariamente l’intera operazione: se la Spesa selezionata è incompatibile crea una nuova manuale nel medesimo contesto. Il problema B+D è il mancato riuso di quella esistente, non un divieto generale di registrare il costo tardivo.

**Correzione minima:** ammettere la Stima preesistente su manual; mantenere stesso anno, Azienda, owner, non Stornata, origine manual e append-only degli Effettivi. Non consentire una nuova Stima nel chiuso. **Regressione:** append a manuale mista; rifiuto system, anno/owner estraneo e modifica della Stima storica.

### IR12 — Primo uso economico non permanente, già nel ciclo operativo vivo

**Alta; difetto attuale verificato E19 DB; incompatibilità aggiuntiva B+D C/P.** Regola §18.12: il primo uso economico blocca permanentemente il cambio Fornitore. Scenario senza backup: creare tramite CreateContract un Contratto con Condizione a zero (dato legittimo del controllo, non sostituto artificiale di Sophos), registrare l'unico Effettivo manuale 100 nel 2025 aperto, senza Budget/Chiusura; spostare la Spesa fuori Contratto tramite UpdateExpense::preview/confirm con motivo. Atteso: Effettivo conservato e Fornitore ancora bloccato. **Osservato:** Effettivo 100 conservato, ContractEconomicUse true→false e UpdateContract accetta un diverso Fornitore.

Percorso: [ContractEconomicUse::exists](/root/MP2/app/Domain/Contracts/ContractEconomicUse.php:15) considera Budget/Chiusura, audit di ricalcolo positivo e Righe ancora appartenenti al Contratto; non conserva il fatto dell'Effettivo poi spostato. [UpdateContract::execute](/root/MP2/app/Actions/Operations/UpdateContract.php:64) si affida a quel predicato. Inoltre le nuove Stime manuali non sono fra le prove consultate: estensione futura non eseguita.

**Correzione minima:** rendere permanente il fatto di primo uso nelle operazioni vive che lo producono, nello stesso confine transazionale del costo/approvazione, e usarlo nel guard del Fornitore. Un fatto monotono circoscritto è giustificato; una query sulle sole manuali correnti non basta dopo spostamento. Non bloccare lo spostamento autorizzato per compensare questa perdita, non inventare date storiche e non esportare l'intero audit. Una Riga actual attiva anche zero conta per §18.12, ma H richiede non zero (§6.4): non unificare i predicati.

**Regressioni vive T13/T16:** unico Effettivo spostato o annullato; Stima positiva poi azzerata/spostata; actual zero; inclusione Budget zero; Contratto mai usato ancora modificabile; concorrenza primo uso/cambio Fornitore. E19 verifica solo il primo percorso, non l'intera matrice.

**Parte backup conservata dalla revisione del 3 ottobre, rinviata e non riverificata:** system positiva→zero senza Budget/Chiusura/Effettivi lasciava prova solo nell'audit; il collector non la esportava e il restore non la ricostruiva. Resta necessaria una futura verifica della portabilità; D3 disciplina il passato non ricostruibile. V3/#33 non sono analizzati qui. Il rinvio non sospende la correzione viva appena dimostrata.

### IR13 — Owner storico perde identità nel round-trip del JSON

**Rinviato — evidenza storica del 3 ottobre, non riverificata né risolta il 4 ottobre. Alta; C/E7 isolato storico.** Un dettaglio Budget di figlia usa owner={type: contract, origin_id: 7, origin_key: contract:7}. [BusinessBackupCollector::portableJson](/root/MP2/app/BusinessBackup/V1/BusinessBackupCollector.php:408) tratta origin_id solo quando esiste source_type; origin_key diventa source_ref. [ImportBusinessBackup::hydrateJson](/root/MP2/app/Actions/BusinessBackup/ImportBusinessBackup.php:426) cerca nuovamente source_type e scarta il riferimento se il discriminator è type. La FK della Spesa viva può essere corretta mentre l'identità storica nel dettaglio è persa.

**Correzione minima:** supportare la forma owner effettivamente prodotta, risolvendo il riferimento portabile in modo contestuale e deterministico. Non serve cambiare formato globale per questo errore; source_ref conserva già l'identità nel pacchetto esportato. **Regressione:** import con ID diversi conserva owner storico di Progetto e Contratto, anche se l'owner vivo è cambiato; verificare anche i collegamenti informativi annidati, senza assumere che ogni chiave id sia già gestita. Riparazioni di dettagli già importati non sono automaticamente deducibili dal vivo.

### IR14 — Validatore backup ricalcola la struttura del vecchio Budget dall'owner vivo

**Rinviato — evidenza storica del 3 ottobre, non riverificata né risolta il 4 ottobre. Alta; C/E8 isolato storico.** Budget approvato quando una Spesa da 300 era autonoma; successivo spostamento vivo in un Progetto o, con B+D, in un Contratto. Il Budget storico resta corretto. [BusinessBackupValidator::assertBudgets](/root/MP2/app/BusinessBackup/V1/BusinessBackupValidator.php:453) legge l'owner da _MP2_expenses e alla riga 470 esclude la vecchia Riga autonoma: il totale ricostruito diventa 0 invece di 300. Il validator rifiuta un pacchetto storicamente coerente. Il caso inverso può duplicare una figlia storica diventata autonoma.

**Correzione minima:** somma di primo livello basata sull'owner materializzato nel dettaglio della specifica versione Budget. Trattare esplicitamente i dettagli legacy; non recuperarli dalla relazione viva quando è incompatibile con l'immutabilità. **Regressione:** autonoma→Progetto/Contratto e figlia→autonoma dopo Budget; round-trip e quadratura dei riferimenti precedenti e successivi allo spostamento. Non è giustificata una nuova versione di workbook per correggere questa lettura.

### IR15 — Viste annuali e warning confondono ricorrenza, copertura e costo totale

**Media; attuale e aggravata dall'estensione; C/P.** [ContractInfolist::annualRow](/root/MP2/app/Filament/Resources/Contracts/Schemas/ContractInfolist.php:82) usa Condizioni, stato e classificazione vivi anche per anni chiusi. [ContractsTable](/root/MP2/app/Filament/Resources/Contracts/Tables/ContractsTable.php:155) espone R come Allocato. Sophos 2025 manuale apparirebbe zero in questi punti, mentre annualTotals restituirebbe 2.621,34. Modifiche vive possono inoltre cambiare la spiegazione mostrata per il chiuso.

[ReviewExerciseClosing::addContractWarnings](/root/MP2/app/Actions/Closing/ReviewExerciseClosing.php:710) deduce assenza di Condizione applicabile dalla composizione economica vuota. Una Condizione con ciclo a fine periodo può coprire l'anno senza attribuirvi costi. Con B+D, nessuna Condizione può essere anche intenzionale. [RegisterContractPayment::make](/root/MP2/app/Filament/Resources/Expenses/Actions/RegisterContractPayment.php:34) chiama «Pagamento» una registrazione di Effettivo; il suggerimento annuale usa già il totale persistito e non deve diventare un residuo da pagare.

**Correzione minima:** UI attiva e storico leggono i rispettivi riferimenti; evidenziare S e M dove spiegano A. Warning di allocato senza Effettivi basato su A completo; eventuale avviso di copertura basato su intervalli, non su composizione zero. Assenza intenzionale di Condizioni non è errore. Terminologia Effettivo, senza nuova semantica finanziaria. **Regressione:** Sophos 2026 presente e valido con A=0 e costo 2025 separato; anno chiuso invariato; ciclo coperto ma senza attribuzione; browser sui form realmente registrati. Browser N in questa revisione.

## 5. Coerenza con il resto dell'applicazione

### 5.1 Modello ricostruito e invarianti condivise

Il Contratto identifica accordo, controparte, date, rinnovi e fatti di ciclo di vita; le classificazioni sono annuali. Una Condizione alimenta cicli automatici, senza prorata. Una Spesa identifica un aggregato annuale e appartiene alternativamente a Contratto, Progetto o nessuno dei due. Una Riga esprime una Stima oppure un Effettivo, con annullamento separato dallo Storno della Spesa. Lo schema impone esclusività del contenitore e unicità della system per Contratto/Esercizio; la system contrattuale non è una seconda sorgente aziendale indipendente dal padre.

[Contract::annualTotals](/root/MP2/app/Models/Contract.php:154), [Expense](/root/MP2/app/Models/Expense.php:193) e [Exercise::allocation/actual](/root/MP2/app/Models/Exercise.php:155) aggregano le Righe persistite. HaEffettivi deriva dall'esistenza di Righe attive non zero, non dal saldo. L'Esercizio somma le Spese e il Riporto dei soli Progetti; non somma poi anche il Contratto. [ExpenseImpactPlan](/root/MP2/app/Domain/Expenses/ExpenseImpactPlan.php:224), [ContractClassificationImpactPlan](/root/MP2/app/Domain/Contracts/ContractClassificationImpactPlan.php:29) e [CostCenterMoveImpactPlan](/root/MP2/app/Domain/CostCenters/CostCenterMoveImpactPlan.php:105) usano i totali dei contenitori: sono consumatori pertinenti anche se non richiamano ContractAnnualAllocation.

Le Actions condivise CreateExpense, CreateExpenseLine, UpdateExpenseLine, SetExpenseLineActive, SetExpenseReversed e UpdateExpense chiamano ContractExpenseActivity. Una sua modifica interessa creazione, modifica, ingresso in Contratto e ripristino, non un solo pulsante. [UpdateExpense::validateExercisesAndReferences](/root/MP2/app/Actions/Operations/UpdateExpense.php:395) conserva vincoli di anni aperti, motivo post-Budget, passaggio con Effettivi, divieto di anno futuro per Effettivi, CdC diretto soltanto per autonome e riconoscimento dell'eredità del Fornitore contrattuale. Lo spostamento trasporta tutte le Righe: non consuma o duplica la generata.

Il Progetto mantiene le proprie regole di stato, Riporto e Riprogrammazione. La restrizione per uno spostamento futuro di pianificazione di Progetto è nella stessa validazione (righe 431–434): non va rimossa insieme al divieto di Stime contrattuali. La frase «non aggirare la Riprogrammazione passando per un Contratto» non autorizza a inventare una lineage permanente o nuovi blocchi multihop: il singolo spostamento deve rispettare le regole esistenti; eventuali ulteriori divieti richiederebbero un requisito dimostrato.

Fornitore ereditato dal Contratto anche sulle manuali; CdC dalla classificazione dell'anno, non dalla Condizione o dalla Riga. Negli anni chiusi valgono i contesti materializzati. Il collegamento informativo Progetto–Contratto non trasferisce ownership economica né costi. Archivio e ripristino non ricalcolano costi: [SetContractArchived::execute](/root/MP2/app/Actions/Operations/SetContractArchived.php:28) limita l'archiviazione a stati terminali, conserva i dati e registra impatti zero.

### 5.2 Inventario dei chiamanti del motore e semantica richiesta

La ricerca corrente trova **11 file chiamanti diretti** oltre alla definizione del motore. Non emerge un secondo enumeratore monetario equivalente: ContractCycle viene usato anche per date di confine, suggerimenti e rinnovi, che non devono trasformarsi in totali economici.

| Chiamante e riga | Responsabilità | Uso corretto dopo B+D |
|---|---|---|
| [RecalculateContractEstimates:69](/root/MP2/app/Actions/Operations/RecalculateContractEstimates.php:69) | Materializzazione ordinaria | Solo R → S; manuali intatte |
| [ApplyContractPlan:76](/root/MP2/app/Actions/Proposals/ApplyContractPlan.php:76) | Materializzazione all'approvazione | Stesso significato R → S; secondo produttore da mantenere coerente |
| [ContractEconomicChangePlan:261](/root/MP2/app/Domain/Contracts/ContractEconomicChangePlan.php:261) | Cambio tariffa | R/R′ per componente; S+M e R′+M se chiamati totali |
| [UpdateContractRenewal:130](/root/MP2/app/Actions/Operations/UpdateContractRenewal.php:130) | Impatto rinnovo | M invariato nell'anno; nessuna copia |
| [ProposalImpactPlan:243](/root/MP2/app/Domain/Proposals/ProposalImpactPlan.php:243) | Piano per anno | Prima persistito; dopo R′+M′, figlie nuove una sola volta |
| [BudgetSnapshotPayload:196](/root/MP2/app/Domain/Proposals/BudgetSnapshotPayload.php:196) | Dettaglio Budget | Ricorrenza come spiegazione, totale approvato completo |
| [ContractClosingProjection:172](/root/MP2/app/Domain/Closing/ContractClosingProjection.php:172) | Proiezione pura | Helper ricorrente; chiamante aggiunge M dell'anno destinazione |
| [ClosingSnapshotPayload:333](/root/MP2/app/Domain/Closing/ClosingSnapshotPayload.php:333) | Storico Chiusura | Totale materializzato e composizione separata |
| [ContractInfolist:85](/root/MP2/app/Filament/Resources/Contracts/Schemas/ContractInfolist.php:85) | Annualità realmente visibili | Corrente da Spese, chiuso da Snapshot/rettifiche |
| [ContractsTable:155](/root/MP2/app/Filament/Resources/Contracts/Tables/ContractsTable.php:155) | Elenco | A completo e riferimento annuale coerente |
| [ContractAnnualSituationsRelationManager:75](/root/MP2/app/Filament/Resources/Contracts/RelationManagers/ContractAnnualSituationsRelationManager.php:75) | Componente non registrato | Non scambiarlo per la UI attiva né registrarlo solo per questa estensione |

Non esiste, nei percorsi letti, una somma corrente esplicita R+S+M già necessaria al prodotto. È il rischio di una correzione ingenua del motore condiviso. Il doppio conteggio padre/figlie, invece, è attuale nel lettore Budget. La perdita di M riguarda i consumatori ricorrenti; l'anno errato riguarda il ramo Expense del writer.

### 5.3 Catena completa delle Proposte

| Passaggio | Implementazione osservata | Vincolo della modifica |
|---|---|---|
| Catalogo | ProposalSourceCatalog include autonome, Progetti, Contratti; non ogni figlia come sorgente autonoma | Conservare appartenenza e inclusione degli oggetti a zero; aggiornare la regola canonica per manuali contrattuali |
| Inizializzazione | InitializeProposal costruisce snapshot della realtà viva | Nessun cloning di Budget come nuova realtà; nessuna mutazione provvisoria |
| Baseline | ProposalSourceSnapshot separa plan_baseline e actual_context | Origin e identità manual/system; anno e metadati preservati; actual_context mai modificabile |
| Fingerprint/readiness | [ProposalReadiness::assessItem](/root/MP2/app/Domain/Proposals/ProposalReadiness.php:48) confronta revisione e hash; assessProposal rienumera membership | Ogni modifica viva delle figlie coinvolte deve invalidare il padre/piano appropriato; non riempire silenziosamente una vecchia baseline dal vivo |
| Azioni/piano | ContractPlan e ExpensePlan non supportano il nuovo piano | Una sola rappresentazione modificabile; validazione dei riferimenti e del tipo di Riga |
| Impatto | ProposalImpactPlan somma gli impatti degli elementi; il ramo Contract usa R | Non inserire la stessa nuova figlia sia in M′ del padre sia nel totale di un elemento separato; per riepilogo padre comporre la relazione senza ricontare l'impatto |
| Riallineamento | ProposalActionReplay + RealignProposalItem | Correggere IR05 prima del riuso; rispettare proprietà e dipendenze delle azioni |
| Approvazione | [ApproveProposal::execute](/root/MP2/app/Actions/Proposals/ApproveProposal.php:55) blocca Azienda, anni, sorgenti; readiness dentro transazione | Conservare atomicità su tutti gli anni e la verifica sotto lock; nuove figlie rientrano nel grafo verificato |
| Risoluzione | Approvazione applica Progetti, Contratti, poi Spese (righe 147–155) | Riutilizzare questo ordine per risolvere Contract ProposalItemID; nessun ID temporaneo nel risultato persistito |
| Snapshot | BudgetSnapshotPayload + BudgetPayloadGuard | Piano soltanto; niente Effettivi o contesto Effettivi nei dettagli/azioni serializzati; totali e anno coerenti |
| Lettura | BuildReport, dashboard e PDF | Primo livello storico, dettagli versionati, nessuna dipendenza dall'owner vivo |

ApplyProjectPlan conserva gli Effettivi perché modifica solo estimate, ma questo non prova che ogni sua assunzione sia adatta ai Contratti. Per esempio, non contiene una protezione origin=system, non necessaria per le attuali figlie di Progetto; quella protezione diventa indispensabile nel piano contrattuale. Non è necessaria una riscrittura generale del sottosistema Proposte.

### 5.4 Chiusura, storico e consumatori esterni alla pagina Contratto

PrepareExerciseClosing → ReviewExerciseClosing → ContractClosingProjection → conferma con fingerprint → CloseExercise → ricalcolo → ClosingSnapshotPayload è un unico percorso economico. [ReviewExerciseClosing::sourceState](/root/MP2/app/Actions/Closing/ReviewExerciseClosing.php:886) include revisioni dell'Esercizio, N+1 e Contratti/proiezioni. Le mutazioni delle figlie devono continuare a incrementare le revisioni dei padri/anni; non servono lock duplicati in ogni reader. CloseExercise rivalida e confronta i totali materializzati: questa protezione va conservata, non rimossa per far passare IR03.

BuildReport compone riferimenti correnti e storici, ReportAggregator produce aggregazioni, ComparisonEngine decide identità ed etichette. [EconomicDashboardReadModel::load](/root/MP2/app/Support/Reporting/EconomicDashboardReadModel.php:41) usa BuildReport; alcune card leggono l'header Budget, mentre confronti e CdC dipendono dalle sorgenti del report. Un header corretto non prova quindi tutta la dashboard corretta. [Reports](/root/MP2/app/Filament/Pages/Reports.php:422) e [ReportPdfController::__invoke](/root/MP2/app/Http/Controllers/ReportPdfController.php:25) riusano BuildReport: gli errori si propagano anche all'esportazione PDF. Il rendering reale è stato esercitato dai test ReportPdfTest della suite corrente; nessuna ispezione visiva browser/PDF e nessun rendering del futuro dataset B+D sono stati eseguiti.

### 5.5 Sicurezza e concorrenza

Le policy Contract, Expense ed ExpenseLine controllano permesso e accesso al tenant; le ultime limitano modifiche agli anni aperti. Le Actions operative esaminate invocano Gate, verificano le relazioni della stessa Azienda e usano transazioni con lock di Azienda e oggetti pertinenti. UpdateExpense conferma revisioni/fingerprint; approvazione e Chiusura rivalidano sotto lock. Il fatto che ApplyContractPlan e ApplyProjectPlan siano applicatori interni non autorizza a esporli direttamente saltando il controllo dell'operazione superiore.

La modifica deve mantenere i controlli server-side su system, tenant, anni, Effettivi, motivi e revisioni; la sola invisibilità di un controllo Filament non è sufficiente. I riferimenti nuovi di figlie vanno validati rispetto alla stessa Proposta/Azienda. Per il primo uso, registrazione della manuale e blocco Fornitore devono essere atomici rispetto al cambio Fornitore, riusando la serializzazione già esistente sull'Azienda/Contratto. Non sono dimostrati bisogni di nuove code, cache, retry o locking generalizzato. Le proprietà concorrenti sono C/P, non testate in esecuzione parallela.

### 5.6 Mappa delle transizioni dell'Esercizio

Gli unici stati persistiti dell'Esercizio sono **Aperto/Chiuso**. «Senza Budget», «con v1/v2», «obsoleto», «pronto» e «anteprima confermabile» sono condizioni, non nuovi stati dell'Esercizio. La Proposta ha draft/approved/discarded, purpose initial_budget/revision e readiness degli elementi; approvare non chiude l'anno. Le schermate di preparazione non sono una transizione persistita. Nessuna riapertura è prevista.

Nella tabella, ogni autorizzazione comprende la stessa Azienda/tenant; i permessi sono quelli delle policy effettivamente chiamate. «Rifiuto» significa validazione/autorizzazione server-side, non soltanto pulsante nascosto. Le scritture delle Actions mutanti sono transazionali salvo gli audit diagnostici esplicitamente indicati.

| Transizione / ingresso e precondizioni | Dati, anni e riferimento economico | Invalidazioni, postcondizioni ed errore |
|---|---|---|
| **A — inesistente→Aperto**, CreateExercise::execute; Create:Exercise; anno intero 1–9999, unico per Azienda | Legge Progetti/Contratti e classificazioni; scrive Esercizio, classificazioni, revisioni, audit e sole system via RecalculateContractEstimates. Primo anno, passato, corrente, futuro ammessi; nessun requisito di contiguità. Nessun Budget, autonoma, Effettivo o Stima Progetto copiato | Incrementa revisioni dei contenitori: le Bozze che li includono possono diventare obsolete. Stesso operation_id restituisce il risultato; duplicato anno diverso fallisce. Non muta economicamente altri anni nella creazione diretta. C/E24; classificazioni discusse sotto |
| **B — Aperto senza Budget→Bozza iniziale**, InitializeProposal; Create:Proposal; nessun'altra draft dell'anno | Catalogo dalla realtà viva, baseline/revisioni/hash, result di piano, actual_context separato; scrive Proposta, elementi e audit a impatto zero. Nessuna materializzazione di oggetti/costi nuovi | Catalogo mutato o sorgente modificata richiedono review/riallineamento; ReviewProposalReadiness può scrivere nuovi elementi/readiness, non costi. Errore annulla scritture tecniche. Nuova Proposta nel chiuso rifiutata (E27) |
| **B — piano→ready→approvazione**, PlanExpense/PlanProject/PlanContract, ProposalReadiness e ApproveProposal; Update:Proposal/Approve:Proposal, draft e anni coinvolti aperti | Baseline e azioni tipizzate; risolve padri prima di figlie, applica il piano vivo, crea Budget principale immutabile e prove/audit. BudgetPayloadGuard esclude anche chiavi di Effettivi annidate. E invariato; per B+D distinguere system/manual, ID Riga e metadati | Readiness sotto lock, revisione attesa e membership; rollback economico completo e failure audit fuori transazione. Retry restituisce Budget esistente senza riapplicazione; nuovo invio su terminale rifiutato. Difetti effettivi IR04–IR08, non sanati dall'esistenza di una transazione |
| **C — vivo dopo Budget**, Create/UpdateExpense(Line), SetExpenseLineActive, UpdateExpense e Actions contrattuali; permessi di update/create pertinenti, anno aperto, riferimenti validi | Scrive solo realtà, revisioni e audit; motivo post-Budget/riclassificazione/spostamento con Effettivi dove richiesto. Calcola impatto corrente, non riscrive v1. Effettivo futuro vietato; tipo misto oggi vietato per Contratto | Invalida sorgenti/anni pertinenti tramite revisioni e MarkProposalItemsToRealign; E22 dimostra stale dopo modifica viva. System resta esclusiva del ricalcolo; D1/D2 definiscono solo i nuovi casi. Primo uso permanente oggi violato da E19 |
| **C — Budget corrente→Revisione draft→nuova versione**, InitializeProposal/ApproveProposal | Parte dalla realtà attuale, conserva reference_budget_id; all'approvazione richiede motivo e predecessore ancora corrente, incrementa versione, lega previous_budget_id. Il selettore report può usare v1 o v2, non confonderle con il corrente | v1 invariata; predecessore superato o baseline obsoleta bloccano l'applicazione. E22 v1 1.500→v2 1.600 con E 900. Test ApproveRevision verifica retry e rollback, non B+D |
| **C — riallineare/ritirare/scartare**, RealignProposalItem (Keep/Reload/Manual), DiscardProposal; Update:Proposal e draft | Keep rigioca, Reload ricarica e ritira decisioni coinvolte, Manual rigioca solo le azioni selezionate; scrive piano/baseline/readiness/audit. Scarto con motivo terminalizza Proposta senza ripristinare il vivo | Terminale non riapplicabile; dati vivi post-Bozza non annullati dallo scarto. IR05 mostra che ritiro azione e result della figlia divergono: asserire entrambi. Nessuna nuova azione «annullamento contrattuale in Proposta» |
| **D — approvazione multi-Esercizio**, stesse Actions; tutti gli anni di destinazione ancora aperti | Impatti annuali distinti, effetti vivi atomici anche fuori anno; una sola Snapshot Budget per l'anno principale. Conservare elementi efficaci/tracciabilità multianno, contributo principale zero fuori anno | Budget degli altri anni invariati; Bozze delle sorgenti cambiate devono riallinearsi. E12: realtà corretta ma Budget errato IR06. E23: chiusura dell'anno interessato dopo preview impedisce approvazione senza scritture |
| **E — preparazione/verifica**, PrepareExerciseClosing→NormalizeClosingInput→ReviewExerciseClosing e proiezioni; Close:Exercise | Rilegge realtà, Budget, draft, classificazioni, impostazioni, note/audit e anni; costruisce input normalizzato, proiezioni, warning, blocchi e fingerprint. Prepare aggiunge impatto ricorrente del N+1 nuovo e blocchi note alla review economica | **Nessuna scrittura nel percorso letto; E16 lo conferma nella fixture tramite query log.** Abbandono non crea N+1, transizioni o costi. Il fingerprint esecutivo è quello della review economica, non quello arricchito di Prepare; non scambiarli |
| **E — Aperto→Chiuso**, CloseExercise; Close:Exercise, anno finito, precedenti esistenti chiusi, nessuna draft principale, blocchi risolti, conferma e presa visione | Lock e nuova Review autorevole; rinnovi fino al 31/12, ricalcolo system degli anni aperti, creazione eventuale N+1, transizioni/rinvii Progetti, invalidazioni, bilanci Progetti, Snapshot e status. Confronta header materializzato con preview; riferimenti v1/corrente, entrambi null se nessun Budget | **Budget non obbligatorio.** Draft iniziale o Revisione pendente bloccano; occorre approvarla o scartarla (E27 verifica scarto→Chiusura senza Budget e rifiuti diretti successivi). Snapshot e N+1 nella stessa transazione. Started prima e Failed dopo rollback sono intenzionali; non pretendere zero audit complessivi. Retry stessa operazione restituisce Snapshot; nessuna seconda Chiusura |
| **F — passaggio a N+1**, CreateExercise::createWithinTransaction oppure N+1 esistente | Nuovo: stesso inizializzatore diretto, ma dopo rinnovi fino a fine N; classificazioni e R coerenti col contesto aggiornato. Esistente: nessuna reinizializzazione o copia di M; può ricevere ricalcolo R/rinvii Progetti, non riscrittura Budget | Non creare N+1 richiede trasferimenti zero; non significa cessare la gestione tenant (§31.18). E22 nuovo=1.200, E26 esistente=1.650 controllo; Bozza N+1 resta valida se nulla pertinente cambia. N+1 chiuso non riaperto; trasferimento non nullo verso chiuso bloccato |
| **G — Chiuso→lettura/correzione/annotazione**, BuildReport, RecordLateCorrection, RecordHistoricalErrorAnnotation; View pertinenti, CorrectClosed:Exercise oppure AnnotateHistoricalError:Exercise, verificati anche dalle policy di creazione delle rispettive registrazioni | Operazioni ordinarie rifiutate; Budget e Alla Chiusura da Snapshot; Conoscenza Corrente aggiunge Effettivi append-only con contesto storico. Revisioni e operation_id rivalidati; annotazioni conservano fatti registrati/corretti con impatto zero | Nessuna Stima retroattiva o nuova classificazione storica dal vivo. Tardiva aumenta revisioni sorgente/Esercizio, annotazione non riscrive valori. Rollback se audit fallisce. IR09–IR11 distinguono assenza sorgente, saldo zero e futura manuale con Stima |

**Creazione e inclusione a zero.** CreateExercise::createWithinTransaction copia la classificazione del più recente anno **precedente** e crea comunque una classificazione nullable per ogni contenitore. Non filtra per costo positivo. ProposalSourceCatalog include Contratti Pianificati/Attivi nell'anno anche senza importi; inoltre considera Righe, Condizioni, eventi e scadenza. Per B+D una manuale residua deve continuare a far qualificare il Contratto; zero non significa gratuito o escluso. La creazione pubblica senza Condizioni resta bloccata E10: l'inclusione futura è C/P, non un flusso B+D superato.

**Limite normativo concreto sulle classificazioni retrograde:** §11.8 dice «ultima classificazione nota», mentre il codice cerca solo anni precedenti. Creare 2024 quando esiste solo una classificazione 2025 produce null, non quella nota nel 2025. La creazione retrograda è ammessa (E18/E24). È un punto da chiarire rispetto al significato temporale della regola prima di estendere l'inizializzazione; non autorizza a copiare una classificazione futura nella storia né a vietare anni passati. Nessuna differenza tra inizializzatore diretto e quello usato dalla Chiusura: condividono il metodo. C/P; regressione T19.

**Controlli di Chiusura e falsi allarmi esclusi.** Il mancato Budget è esplicitamente rappresentato, non un blocco. Classificazione mancante segue l'impostazione Avviso/Blocco (warning/blocking); i Progetti possono richiedere classificazione per Riporto secondo il proprio dominio. Contratto a zero può restare una sorgente rilevante: non sostituire membership con A>0. Gli avvisi richiedono presa visione, non conversione in blocchi universali. E17 dimostra che il controllo della Nota mancante, aggiunto da Prepare, è ripetuto in ClosingSnapshot::creating: la chiamata diretta non lo elude. Non è necessario introdurre un nuovo validatore generale.

**Cronologia:** alla data di prova non si può chiudere 2026, né 2025 se esiste 2024 Aperto. Per provare «destinazione chiusa durante Bozza» senza violare §11.5, E23 usa principale 2026 e destinazione 2025. N+1 già Chiuso è raggiungibile creando N in seguito (E18), e senza trasferimenti la Chiusura N riesce. Non è un difetto dimostrato: nessuna fonte impone la riapertura o un blocco indiscriminato di questa sequenza.

**Progetti, separatamente:** PlanProjectDeferral e ApplyProjectDeferral mantengono Riporto come valore aggiuntivo e Riprogrammazione come riduzione esplicita di Stime sorgenti più nuove Stime destinazione, senza copiare Effettivi. ProjectClosingReprogrammingTest verifica 100/20, riduzione 30 → N=70, N+1=30/E=0 e copied_from_origin_key, seconda applicazione senza duplicati; transizione terminale ripristina N=100, azzera la copia e conserva la Spesa indipendente N+1=7. I legami informativi Progetto–Contratto non sono letti per trasferire costi o stati. La regressione B+D deve conservarlo, non estendere queste modalità al Contratto.

## 6. Stress test

Questa è una matrice di revisione: gli esiti economici sono attesi della soluzione consolidata, salvo le osservazioni E esplicitate. I riferimenti T rinviano alle regressioni future del §10. Anni indicati come aperti e date operative sono condizioni della fixture; non implicano modifica dell'orologio o dei dati reali. Nei casi di ciclo mensile «anno pieno» significa ancora 1 gennaio, attribuzione a inizio ciclo e Contratto Attivo in tutti i dodici inizi.

### 6.1 Tutte le 25 casistiche del rapporto

| # | Dati iniziali e operazione | Risultato atteso | Componenti, possibile rottura e verifica |
|---|---|---|---|
| 1 | Mensile 100, anno pieno; ricalcolare due volte | R=S=1.200, M=0; una sola system e una Stima generata stabile | Motore e Recalculate/ApplyContractPlan; proteggere cicli e idempotenza della materializzazione, T1/T6 |
| 2 | Trimestrale, semestrale, annuale: 100 per ciclo, ancora 1 gennaio | 400, 200, 100 rispettivamente con attribuzione inizio; nessuna manuale necessaria | ContractCycle/AnnualAllocation; non introdurre frequenze o prorata, T1 |
| 3 | Sophos 22/02/2025–22/02/2028, nessun rinnovo; registrare 18×145,63 nel 2025 aperto della fixture | A2025=2.621,34; A2026=A2027=A2028=0; diritto valido fino alla scadenza; Effettivo non generato | CreateContract, manuale, UI, Budget, Chiusura; E1/E2 provano l'errore della ricorrenza artificiale e il prodotto, T2/T5/T10 |
| 4 | Annuale anticipato 1.000: accordo ricorrente oppure costo contrattualmente singolo | Nel primo caso R=1.000 per ciclo; nel secondo M=1.000 una volta nell'anno scelto | La data dell'Effettivo non seleziona il modello; Motore/CreateExpense, T1/T2 |
| 5 | Setup 300 e mensile 100×12 | A=1.500, non 2.700; E indipendente; due origini leggibili | IR03/IR04/IR07, T5/T6/T9 |
| 6 | Setup 300 e ricorrenza annuale 1.000 | A=1.300 e dettaglio Budget 1.300 spiegabile integralmente | BudgetSnapshotPayload e report; niente dettaglio fermo a 1.000, T9 |
| 7 | Due costi singoli nello stesso anno, 300 e 300, descrizioni diverse | M=600; nessuna deduplicazione per uguaglianza d'importo; una o più Spese secondo gestione desiderata | ManualExpenseLine e piano figlie; entrambe le identità conservate, T2/T6 |
| 8 | Costi singoli 300 nel 2026 e 450 nel 2027, entrambi aperti; pianificare e approvare | A2026=300, A2027=450; due Spese, nessuna Riga multianno; Budget principale 2026 non contiene 750 come totale | IR06; impatti annuali e Snapshot, T8 |
| 9 | Prezzo ignoto, Contratto valido senza Condizioni e senza Stime | A=0, non classificato automaticamente come gratuito; futura conoscenza richiede una registrazione esplicita | Creazione, inclusione a zero e note; nessuna cifra fittizia, T3/T5 |
| 10 | Contratto dichiarato gratuito nelle informazioni descrittive, nessuna Riga | A=0; non inventare Riga zero o Effettivo; eventuali costi futuri aggiunti esplicitamente | Stessi meccanismi del caso 9; il significato non si deduce dallo zero, T3/T18 |
| 11 | Accordo quadro inizialmente senza componenti; aggiungere poi un primo canone | Prima A=0; poi R soltanto dalla prima decorrenza reale approvata secondo D2 | IR02; SaveContractEdits, Condizioni, T3 |
| 12 | Stima manuale 1.000, successivo Effettivo 920 | A=1.000, E=920, scostamento −80; nessun consumo/abbinamento della Stima | Righe e annualTotals, T2/T4 |
| 13 | Sophos stimato 2.621,34, Effettivo 2.800 | Scostamento +178,66; Stima e Budget precedente intatti | Motivo/soglia ove previsti, dettaglio e report, T2/T5 |
| 14 | Sophos stimato 2.621,34, Effettivo 2.500 | Scostamento −121,34; nessun risparmio o Riporto contrattuale dedotto | Report/Chiusura; semantica scostamento distinta, T5/T10 |
| 15 | Setup 300 più mensile 100 dal 1 gennaio; cessazione 15 giugno | Sei cicli iniziati restano interi: R=600, M=300, A=900. Nessun rimborso dedotto | CeaseContract, ricalcolo, proiezione; M preservato, T1/T3/T10 |
| 16 | Pianificato, M=300, annullamento prima dell'attivazione | Secondo raccomandazione D1: R=0, M=300 finché annullato esplicitamente; secondo Canonica attuale A deve essere zero | Non determinabile senza D1; CancelContract, UI, T3. Nessuna copertura incondizionata dichiarabile |
| 17 | Annuale ricorrente 1.000 e setup 300 nel primo anno; rinnovo | Primo anno 1.300; anno successivo 1.000, senza nuova manuale 300 | ProcessContractRenewals/Recalculate, T1/T10 |
| 18 | Mensile 100 gennaio–giugno, 120 da 1 luglio su confine valido; setup 300 | R′=6×100+6×120=1.320, A′=1.620; delta rispetto a 1.500 = +120 | EconomicChangePlan, impatto vivo/Proposta; mostrare totale completo, T1/T5/T6 |
| 19 | Contratto iniziato febbraio, censito a ottobre dello stesso anno aperto; costo singolo 500 noto | Date reali preservate; M=500 nell'anno aperto, nessuna falsa decorrenza né frazionamento | CreateContract/CreateExpense, condizioni storiche solo se realmente ricorrenti, T2/T3 |
| 20 | Inizio 2025 chiuso, censimento 2026; costo 2025 100 conosciuto tardi | Nessuna Stima retroattiva; Effettivo tardivo +100 a Conoscenza Corrente, Alla Chiusura invariata | IR09/IR11, HistoricalCorrectionSource/RecordLateCorrection/BuildReport, T11 |
| 21 | Budget approvato A=0; si conosce M=300 | Corrente A=300 con motivo; Budget originale 0; eventuale Revisione approva 300 | Mutazione viva, readiness e nuova versione; non riscrivere v1, T5/T7/T9 |
| 22 | M=300, nessun Effettivo fino a Chiusura | A finale=300, E=0, avviso allocato senza Effettivi; «Previsto non avvenuto» solo rispetto a Budget positivo | ReviewClosing/ComparisonEngine; nessun pagamento generato, T10/T11 |
| 23 | Nessuna Condizione/Stima; registrare Effettivo 500 su Attivo oggi in anno non futuro aperto | A=0, E=500, HaEffettivi=true; costo non previsto se Budget assente o zero | ContractExpenseActivity/ManualExpenseLine/etichette, T4/T5 |
| 24 | Ricorrenza 100 nel 2025, nessuna Condizione applicabile al 2026, nuova nel 2027 | 100/0/100 se queste sono le attribuzioni; Contratto 2026 può essere valido e visibile con A=0 | Non colmare gap; non estendere tacitamente Condizioni né perdere sorgente a zero, T1/T5 |
| 25 | Accordo rinnovabile ogni 36 mesi; costo 900 per ciascun rinnovo noto 2025 e 2028 | Pianificazione esplicita: M2025=900, M2028=900; nessun automatismo negli anni intermedi o al solo evento di rinnovo | B+D rappresenta il piano manuale. Ripetizione automatica non implementabile come «una tantum» senza nuova regola; D4/T3 |

### 6.2 Controesempi aggiuntivi e combinazioni

| ID | Dati iniziali / operazione | Atteso e componenti coinvolti | Rottura o verifica |
|---|---|---|---|
| X1 | Contratto Cessato con vecchia manuale 300; riattivare senza Condizione e senza nuovi costi | Torna Attivo, ricorrenza nuova 0; la manuale resta nel suo anno e non viene duplicata | ReactivateContract/Form/ContractPlan; T3, oggi percorso non disponibile |
| X2 | Pianificato, richiesta con Stima 300 ed Effettivo 50 | Richiesta rifiutata per l'Effettivo ordinario; nessuna Stima parzialmente salvata | IR02; T4/T16 |
| X3 | M2026=300, M2027=450 già presente, R2027′=1.200; chiudere 2026 | N+1 dopo conferma 1.650, non 1.200 né 1.950; M2026 non copiata | Projection/Prepare/Close; T10 |
| X4 | N+1 non esiste; Sophos manuale in N senza ricorrenza | Se si crea N+1: zero nuova manuale/system non necessaria; classificazione secondo flusso esistente | T10; il helper di proiezione ricorrente non deve importare M di N |
| X5 | Bozza baseline M=300; nel vivo M cambia a 350; Bozza propone 400 | Da riallineare; approvazione senza conferma vietata; Keep/Reload/Manual producono risultati espliciti | Fingerprint, revisioni, replay; T7 |
| X6 | Contratto nuovo in Proposta e due figlie nuove 300/450; ricorrenza 1.200 | Padre creato prima, entrambe le FK risolte; A=1.950 una sola volta; fallimento seconda figlia annulla tutto | ApplyContractPlan/ApplyExpensePlan/Approve; T6/T16 |
| X7 | Progetto padre cambiato, figlia nuova con riferimento al suo ProposalItemID; riallineare | Non eseguire create_expense su ProjectPlan, non lasciare risultati di azioni ritirate | E13 DB (E9 storico), IR05; T7 |
| X8 | Budget N, nuova autonoma o figlia 300 in N+1 aperto | Impatto N+1=300; contributo annuale N=0, tracciabilità multianno e anno dettaglio corretto | E12 DB (E6 storico), IR06; T8 |
| X9 | Spesa M=300, Effettivi +100 e −100 attivi; tentare Storno | E=0 ma HaEffettivi=true; Storno vietato, annullamento delle sole Stime eventualmente ammesso | Expense/SetExpenseReversed, distinto dal caso tardivo; T4/T15 |
| X10 | Chiusura A=300 e HaEffettivi=false; aggiungere tardivamente +100 e −100 | Conoscenza Corrente E=0 e HaEffettivi=true; Alla Chiusura flag false | E14 DB (E5 storico), IR10; T11 |
| X11 | Contratto assente dalla Chiusura; rettifica tardiva 100; poi rinomina Fornitore e CdC vivo | Sorgente tardiva visibile; riferimenti storici documentati stabili, dati non documentati non ricostruiti | IR09; T11/T12 |
| X12 | System 1.200 + manuale 300 importate; primo ricalcolo | S=1.200, M=300, stesso numero/identità delle system; nessun duplicato o conversione | **Rinviato:** Restore/Recalculate; T13/T14 |
| X13 | Prima Stima positiva → zero/spostamento; nessun Budget/Chiusura; export/import e cambio Fornitore | Primo uso ancora opponibile; cambio rifiutato anche senza Righe correnti positive | IR12; T13. Parte viva E19; export/import **rinviato** |
| X14 | Autonoma 300 approvata; dopo Budget spostarla in Progetto/Contratto; esportare | Storico: autonoma 300. Corrente: padre 300. Backup valido e report dei due riferimenti corretti | E8 storico, IR07/IR14; T14/T15. Parte backup **rinviata** |
| X15 | Figlia storica con owner Contratto 7, importata in DB con Contratto 70 | owner storico rimappato a 70, non solo FK viva | E7 storico, IR13; T14 **rinviato** |
| X16 | Condizione annuale dal 1 luglio con attribuzione fine; cessazione 15 giugno successivo | Il ciclo iniziato mentre Attivo può attribuire il costo al 1 luglio successivo; stato terminale non implica R=0 in quell'anno | ContractCycle/StateTimeline; T1/T3. Non tagliare per solo stato al 31 dicembre |
| X17 | Riga manuale 18×145,63, poi importo autoritativo corretto a 2.620 con presa visione; modifica del piano | Totale 2.620, fattori preservati; differenza non «riparata» dal motore o dal payload | ManualExpenseLine/ProposalActionPayload/Apply; T2/T6 |
| X18 | Contratto senza Condizioni e senza uso; altro Contratto incluso in Budget a zero | Sul primo Fornitore modificabile; sul secondo bloccato, anche senza costi | §18.12; T13. Non usare A>0 come unico primo uso |
| X19 | Spesa di Progetto senza Effettivi da spostare in anno futuro; modifica condivisa per B+D | Resta applicabile Riprogrammazione quando prescritta; nessuna nuova eccezione generale al cambio anno | UpdateExpense/ProjectDeferral; T15 |
| X20 | Due conferme di piano o Chiusura con stessa revisione; una manuale cambia fra anteprima e conferma | Una conferma obsoleta rifiutata; nessun totale confermato diverso dal persistito | Locks/revisioni esistenti, T16; concorrenza reale N |

### 6.3 Sequenze concatenate: attesi B+D e prove attuali separati

**Sequenza principale da implementare, non passata.** Data operativa 04/10/2026, N=2025 ancora Aperto; Contratto attivo dal 01/01/2025 con S=1.200, manuale M=300, E=900. A=1.500, scostamento −600, H=true. Proposta iniziale dalla realtà → approvazione v1=1.500, nessun Effettivo nel payload. Aprire Revisione e modificare M vivo a 400 con motivo: corrente A=1.600, scostamento −700, E=900, v1=1.500; Bozza obsoleta. Riallineare preservando ID e metadati, approvare v2=1.600. Chiudere N senza draft: preview=Snapshot=1.600/900, link v1/v2, report Budget selezionato rispettivamente 1.500/1.600. Alla Chiusura conserva quei valori anche dopo mutazioni di N+1. **Oggi E10 si ferma al divieto della manuale; IR03/IR04/IR15 sono incompatibilità C/P, non Chiusure B+D eseguite.** E22 verifica gli stessi passaggi condivisi usando 300/400 su un'autonoma; non prova identità contrattuale, proiezione M o storico misto.

**Passaggio annuale e proposta fuori anno.** N+1 nuovo: M=0, R=1.200, A=1.200; nessuna copia dei 400 di N. N+1 già aperto: M proprio=450, R=1.200, A=1.650, Budget precedente invariato e Bozza invalidata solo da mutazioni pertinenti. Nuova figlia 200 pianificata da Proposta N in N+1: A N invariato, N+1=1.850 se si parte dal caso precedente, contributo Budget N=0, identità/azione fuori anno conservata; nessuna approvazione implicita del Budget N+1. E12 riproduce il difetto con autonoma; E26 prova la conservazione di 450 autonomi, non M contrattuale. Variazione fra preview e conferma deve essere rifiutata; i test futuri devono includere classificazioni, Budget e draft propri in N+1, con identità delle Righe e non soltanto somme.

**Sophos senza ricorrenza.** Inizio 22/02/2025, scadenza 22/02/2028, rinnovo disabilitato, zero Condizioni. Con 2025 Aperto: M=2.621,34 soltanto nel 2025, Budget e Chiusura attribuiscono tutto lì; 2026/2027/2028 hanno A=0 ma Contratto ancora presente quando richiesto da stato/validità, nessun Riporto o costo al rinnovo. Con 2025 già Chiuso al censimento 2026: non ricostruire la Stima 2025; soltanto un costo realmente sostenuto e dichiarato può diventare tardiva, mai un Effettivo fittizio per colmare la previsione. E10 rifiuta la creazione senza Condizioni; E25 verifica separatamente censimento storico reale senza Stime retroattive e omissione della sorgente tardiva. L'intera sequenza Sophos rimane T2/T3/T5/T10/T11, subordinata a modifica normativa.

**Dipendenze e terminali.** E11/E13 provano il padre nuovo/esistente sul pattern Progetto e trovano difetti attuali; T6/T7 devono ripeterli sul Contratto con figlia manuale esistente che contiene Effettivi, nuova figlia e system protetta. Verificare azione, result, origine, ID Riga e metadati prima/dopo Keep/Reload/Manual, ritiro e scarto, non soltanto readiness. E21 prova rollback di due nuove autonome: ripetere l'errore sulla seconda figlia del nuovo Contratto includendo rollback del padre, classificazioni, system, revisioni, Snapshot e audit economici. Dopo scarto/approvazione un nuovo invio non può riapplicare decisioni; retry della stessa operazione può soltanto restituire il risultato già esistente.

**Storico e aggregati.** Dataset E20: 1.200/900 Contratto, 300 Progetto, 50 autonoma → 1.550/900; Fornitori storici oggi 50 e 50/0. Non basta confrontare due report fra loro. T12 aggiunge M, CdC padre/figlio, Fornitori distinti e Riporto separato con importi attesi; uno spostamento dopo Budget deve conservare owner storico e cambiare solo il riferimento corrente. E14/E15/E25 provano +100/−100: mantenere l'identità della sorgente anche a netto zero e H=true, distinguendo sorgente già presente e assente. Il riuso della futura manuale contrattuale storica con Stima resta impedito da IR11; non alterare la fixture per simularne il successo.

### 6.4 Tracciabilità delle transizioni e dei rischi

| Transizione/scenario → regola | Percorso concreto | Evidenza | Rilievo/decisione | Test/accettazione → stato |
|---|---|---|---|---|
| Creare anni aperti, zero e classificazioni → §§11.1/11.8 | CreateExercise::execute/createWithinTransaction; ProposalSourceCatalog::forExercise | C, E18/E24; zero senza Condizioni P | IR01/IR02; significato classificazione retrograda §5.6 | T3/T5/T19, criteri 2/3/9 → parziale attuale, B+D futura |
| Bozza non applica → §§12.1/12.14 | InitializeProposal; PlanContract/PlanExpense; ProposalSourceSnapshot | C, E10/E11; PlanContractTest | IR04, D2 | T6/T9, criteri 4/6/7 → pianificazione attuale provata, figlie Contratto mancanti |
| Dipendenze→riallineamento/ritiro | ProposalActionReplay; RealignProposalItem; ApproveProposal | E13 DB | IR05 confermato e aggravato | T7, criterio 6 → difetto attuale riprodotto |
| Budget annuale e sorgenti di primo livello → §§7.6.4/13.3 | BudgetSnapshotPayload::allocation/expenseDetail; BuildReport::budgetSources | E11/E12 DB | IR06/IR07 | T8/T9/T12, criteri 7/9 → difetti attuali riprodotti |
| Vivo→Revisione, E indipendente → §§11.4/13 | UpdateExpenseLine; InitializeProposal; ApproveProposal | E22, ApproveRevisionTest | IR03/IR04 per M | T4/T6/T9, criteri 5–7 → controllo attuale passato, misto N |
| Anno coinvolto chiuso durante Bozza | ExpensePlan::validateResult; ApproveProposal | E23 DB | Vincolo mantenuto | T8/T16/T19 → rifiuto diretto provato su principale diverso |
| Prepare→abbandono/conferma | PrepareExerciseClosing; ReviewExerciseClosing; CloseExercise; ClosingSnapshot | E16/E17/E27, ClosingAtomicityTest | IR03/IR15; nessun bypass Nota dimostrato | T10/T19, criterio 8 → protezioni attuali provate, M futuro |
| N→N+1 nuovo/esistente/chiuso | CreateExercise; CloseExercise; ContractClosingProjection | E18/E22/E26 | IR03; §31.18 prevalente | T10/T19, criteri 8/13 → controlli attuali, M non eseguito |
| Riporto/Riprogrammazione solo Progetti | PlanProjectDeferral; ApplyProjectDeferral; ProjectClosingReprogrammingTest | C + test DB con 100/20/30/7 | Nessun nuovo comportamento contrattuale | T15, criterio 13 → regressione attuale passata |
| Chiuso→tardive, H per presenza → §§6.4/14/24 | RecordLateCorrection; HistoricalExpenseCompatibility; BuildReport::closingSources | E14/E15/E25 DB | IR09/IR10 attuali, IR11 estensione | T11/T12, criterio 10 → due difetti riprodotti, manuale mista N |
| Primo uso→spostamento→Fornitore → §18.12 | ContractEconomicUse; UpdateExpense; UpdateContract | E19 DB | IR12 vivo | T13/T16, criteri 3/13 → difetto attuale riprodotto |
| Corrente/Budget/Chiusura→Fornitore/CdC/PDF | BuildReport; ReportAggregator; EconomicDashboardReadModel; ReportPdfController | E20; ReportPdfTest | IR08/IR15 | T5/T12/T18, criterio 11 → dati difettosi; renderer attuale passato; browser N |
| Backup/restore, V3/#33 | Percorsi storici IR12–IR14 | E7/E8 del 3 ottobre soltanto | D3 rinviata | T14/parte T13/T17, criterio 12 → sospesi, non risolti |

## 7. Soluzione raccomandata dopo la revisione

Le regole seguenti sono una **raccomandazione consolidata**, subordinata all'approvazione dei cambi di dominio del §9. Non reinterpretano retroattivamente la Canonica vigente.

### 7.1 Oggetti, responsabilità e limite del modello

Conservare Contratto, Condizione, Spesa e Riga. Rendere facoltativa la presenza di Condizioni, mantenendole esclusivamente come istruzioni per ricorrenze automatiche. Una Condizione non rappresenta il periodo di validità di una licenza acquistata una volta. Date dell'accordo, scadenza e rinnovi restano indipendenti dall'attribuzione annuale dei costi.

Consentire Stime sulle Spese contrattuali manual, oltre agli Effettivi già ammessi. L'anno appartiene alla Spesa ed è una scelta operativa esplicita: più anni richiedono più Spese. Non aggiungere data di competenza, pagamento o fattura per guidare un algoritmo non richiesto. Non aggiungere flag one_off, una nuova classe di Riga, una tabella componenti o un piano economico separato per rappresentare dati già esprimibili.

Origine system significa generazione esclusiva dal motore; origine manual significa registrazione esplicita. Queste sono due autorità di produzione, non due viste concorrenti dello stesso costo. L'utente deve poter distinguere setup e canone, ma il sistema non può inferire duplicati da importo, Fornitore o descrizione. Se una manuale e una Condizione vengono inserite per il medesimo obbligo, il software non possiede una chiave economica per riconoscerlo: la soluzione evita la duplicazione tecnica, non promette deduplicazione semantica automatica.

Quantità e importo unitario, fino a sei decimali, forniscono un suggerimento. L'importo della Riga, a due decimali, resta autoritativo e si conserva attraverso Proposta, Snapshot e backup; una differenza segue la presa visione già prevista. Stime non negative, Effettivi negativi con Nota, zero esplicito secondo i vincoli esistenti. Nessun Effettivo modifica, esaurisce o riconcilia automaticamente una Stima.

### 7.2 Formule e perimetro annuale

Per Contratto C ed Esercizio Y, escludendo Spese Stornate e Righe annullate:

    R(C,Y)  = ricorrenza calcolata dalle Condizioni e dallo stato all'inizio di ogni ciclo
    S(C,Y)  = somma delle Stime system materializzate in Y, oppure zero
    M(C,Y)  = somma delle Stime manual in Y
    E(C,Y)  = somma degli Effettivi manual in Y
    H(C,Y)  = esiste almeno un Effettivo attivo non zero in Y
    A(C,Y)  = S(C,Y) + M(C,Y)
    A′(C,Y) = R′(C,Y) + M′(C,Y)
    Scostamento operativo = E(C,Y) − A(C,Y)
    Delta di proiezione = A′(C,Y) − A(C,Y)

Dopo una materializzazione valida S=R. Solo sotto tale condizione, e a M invariato, il delta della modifica di ricorrenza coincide con R′−R. Non mostrare una proiezione come se il dato persistito fosse stato già ricalcolato: un reader non deve riparare S per farlo coincidere con R.

Una sola system per Contratto/Esercizio; se già esistente e poi azzerata, conservarne identità e Riga secondo il comportamento corrente. Nessuna nuova system necessaria quando R=0 e non esiste ancora. Il ricalcolo scrive S, mai M o E. Budget e Chiusura devono riconciliare i totali alle componenti effettivamente materializzate, non usare una composizione ricorrente come prova dell'intero costo.

Il totale aziendale usa o le Righe/Spese oppure i contenitori di primo livello, secondo il percorso; mai entrambi nello stesso aggregato. Nei Progetti si aggiunge il Riporto pertinente, nei Contratti nessun Riporto. Il Budget è annuale anche quando le azioni approvate hanno effetti su più esercizi: effetti fuori anno non incrementano i valori economici del Budget principale. Tracciabilità di tali azioni ed elementi resta conservata separatamente dal contributo annuale.

### 7.3 Operazioni vive e ciclo di vita

Una Stima manuale ordinaria si può pianificare in un anno aperto della stessa Azienda su Contratto non Archiviato Pianificato o Attivo. Non richiede l'attivazione già efficace oggi. Non essendoci una data economica giornaliera sulla Riga, l'anno esplicito non va sostituito da una competenza dedotta dalla durata dell'accordo. Un costo residuo in stato Cessato/Annullato richiede contesto terminale esplicito e Nota secondo la decisione D1; non deve passare come «Effettivo ordinario» né riattivare il Contratto.

Gli Effettivi mantengono i vincoli vigenti: niente ordinari su Pianificato, niente anni futuri, niente normale scrittura negli anni chiusi; in terminale dichiarazione e Nota. Le richieste miste soddisfano entrambe le famiglie di regole e sono atomiche. Modifica, annullamento Riga, ripristino Riga e ripristino Spesa devono applicare la regola pertinente ai dati che tornano efficaci, senza imporre a una sola Stima la semantica di un Effettivo.

La cessazione taglia i futuri inizi dei cicli secondo le regole vigenti, preservando quelli già iniziati anche con attribuzione successiva. Non prorata, annulla o ripartisce M. Un rimborso reale è un Effettivo negativo. L'annullamento prima dell'attivazione annulla la ricorrenza; la raccomandazione è conservare le manuali finché esplicitamente modificate, previa modifica di §18.9. La riattivazione consente una nuova Condizione facoltativa, senza riaprire automaticamente quelle precedenti. Rinnovo, riattivazione e creazione di N+1 non copiano manuali. Archivio conserva storia e valori, limita le nuove operazioni e non è una cancellazione economica.

La prima Condizione aggiunta dopo la creazione è distinta da un cambio tariffa: non ha una precedente da chiudere né un confine precedente da rispettare. D2 deve definirne la decorrenza, inclusi i casi di avvio del canone successivo all'inizio dell'accordo. Le modifiche di Condizioni esistenti continuano a usare §18.13: minimo futuro, confine di ciclo, motivi per variazioni reali già avvenute e impossibilità di alterare composizioni chiuse. Il nuovo caso non autorizza retroattività di Proposta.

Lo Storno resta vietato con H=true anche a saldo E=0. Se solo le Stime non sono più valide, si interviene sulle Righe Stima. Gli spostamenti vivi preservano identità, tutte le Righe, motivi e controllo delle revisioni; entrata nel Contratto eredita Fornitore e CdC annuale. Le regole specifiche di autonome e Progetti restano vigenti. Non estendere a spostamenti contrattuali in Proposta ciò che i payload non rappresentano esplicitamente.

### 7.4 Proposta e Budget

Per Contratto esistente, il padre contiene il piano delle figlie esistenti, con system in sola lettura e manuali modificabili solo sul piano. Per nuova figlia, creare un solo elemento Expense, riferito al padre vivo o nuovo tramite l'identità già usata dalle Proposte. Il risultato del padre può mostrarla nel riepilogo relazionale, ma non possiede un secondo insieme modificabile delle sue Righe.

Baseline e fingerprint includono ciò che serve a riconoscere manual/system, anno, ownership e modifiche pertinenti delle figlie. Nuove manuali, spostamenti o Effettivi vivi durante una Bozza non possono essere ignorati; le revisioni devono arrivare al padre e agli anni coinvolti. Con Effettivi, la Proposta modifica soltanto Stime, senza cambiare anno, contenitore, Fornitore, classificazione o Effettivi. Riga omessa, nuova o aggiornata devono avere significati espliciti e identità coerenti, non essere dedotti da una conversione id/line_id incompleta.

L'impatto presenta il totale per anno e la spiegazione per padre/figlie contando ogni importo una volta. Gli effetti negli altri anni aperti vengono confermati e applicati insieme. Il replay separa azioni proprie e riferimenti ad altri elementi; il riallineamento non può lasciare una figlia attiva con la sua decisione ritirata o applicarne la creazione al padre.

Approvazione: conservare il confine transazionale esistente, rivalidare l'intero insieme coinvolto, risolvere padri prima di figlie, applicare le Stime manuali, rigenerare le sole system, preservare Effettivi e creare lo Snapshot dell'anno principale. Un fallimento intermedio annulla tutta l'operazione, incluse identità nuove e revisioni. Non introdurre un workflow multilivello o un nuovo tipo di approvazione.

Il Budget conserva soltanto il piano. Il dettaglio deve includere le componenti manuali con origine, anno, owner storico e dati delle Stime; la composizione delle Condizioni spiega la system. I totali del padre e delle figlie sono consultabili, ma i report sommano solo le sorgenti economiche di primo livello. L'identità storica non si ricostruisce dall'owner vivo. Versionare i dettagli quando si cambia il contratto della loro forma, mantenendo lettori delle versioni precedenti.

Per un elemento efficace relativo soltanto a un altro anno, conservare identità e tracciabilità dell'azione, con contributo approvato nell'anno principale pari a zero. Non collocare le sue Righe fuori anno fra le componenti che spiegano l'Allocato approvato principale; gli importi multianno rimangono nel contesto delle azioni/impatti con il proprio anno esplicito. In questo modo si rispettano insieme l'inclusione degli elementi efficaci (§7.6.4) e la natura annuale della Snapshot (§13.3), senza attribuzioni contraddittorie.

### 7.5 Chiusura, N+1 e avvisi

Per ogni anno aperto interessato, l'anteprima conserva M dello stesso anno e ricalcola R′. Per N+1 già esistente, usare i suoi costi manuali e le sue revisioni, non quelli di N. Per N+1 nuovo non esistono ancora manuali: proiettare solo la ricorrenza e i normali dati di classificazione previsti dal flusso. Non copiare costi iniziali, non trasformarli in Condizioni, non applicare Riporto o Riprogrammazione contrattuale.

Conferma obsoleta rifiutata; ricalcolo e Snapshot devono coincidere con anteprima confermata. Conservare la verifica di uguaglianza e il rollback. Nella Snapshot, materializzare S, M, E, H e la spiegazione leggibile secondo lo schema adottato; i valori chiusi non dipendono da futuri cambi delle Condizioni o della gerarchia dei CdC.

L'avviso «Allocato presente, nessun Effettivo» usa A>0 e H=false. L'assenza di Condizioni non è di per sé un difetto nel nuovo dominio. Se permane un avviso di copertura ricorrente, valutarlo sugli intervalli dichiarati, non su R=0 o composizione vuota. Non inferire da una mancanza di dati né gratuità né un pagamento mancante.

### 7.6 Storico, correzioni tardive, reporting e UI

Alla Chiusura legge esclusivamente valori e contesti materializzati. Conoscenza Corrente compone quei valori con rettifiche append-only, includendo anche sorgenti assenti dalla Snapshot e aggiornando H per presenza di Righe non zero. Le annotazioni di errori storici restano descrittive con impatto economico zero. Nessuna rettifica aggiunge Stime, riclassifica retroattivamente o modifica Budget/Chiusure.

Una manuale storica può contenere Stime ed essere destinataria di un nuovo Effettivo tardivo compatibile. Per sorgenti mancanti, conservare identità e contesto realmente registrati dalla correzione; stato/CdC/lineage non documentati restano non ricostruiti. Totale aziendale, dettaglio, aggregati per Fornitore e CdC devono quadrare anche in presenza di dati storici non classificati. Non usare un cambiamento anagrafico vivo per «completare» una vecchia Snapshot.

Previsto/Non previsto dipendono dall'importo positivo approvato, non dalla mera presenza a zero. Con H=true ed E=0 il costo non è «mai avvenuto». I confronti usano le identità d'origine e l'appartenenza di ciascun riferimento; niente matching per nome e nessuna fusione arbitraria fra costo che era autonomo e costo ora contenuto.

UI minima: usare le risorse e Actions esistenti per registrare Stime/Effettivi manuali, liberare la creazione senza Condizioni e mantenere le system in sola lettura. Elenco, scheda, annualità, suggerimento di Effettivo e report devono concordare sul totale e dichiarare il riferimento storico. Sophos nel 2026 deve mostrare validità fino al 2028, Allocato 2026 zero e costo 2025 di 2.621,34 separato; non «gratuito», non «Contratto senza costi» e non una seconda previsione del 2026. Rinominare l'operazione di registrazione come Effettivo senza introdurre semantica di pagamento o nuovi passi di workflow.

### 7.7 Alternative: valutazione precedente conservata

Nessun controesempio di questa passata impone di riaprire tutte le alternative: i difetti condivisi non dimostrano che B+D sia sproporzionata. La tabella conserva il confronto precedente, non introduce nuove decisioni.

| Alternativa | Valutazione conservata |
|---|---|
| A: costo esterno al Contratto | Non soddisfa Sophos: perde appartenenza, totale contrattuale, classificazione e identità nei confronti |
| B soltanto: Condizioni facoltative, nessuna Stima manuale | Risolve oggetti gratuiti/ignoti e soli Effettivi, non una previsione contrattuale singola |
| C con B: Condizione una tantum | Può rappresentare il costo e centralizzare la generazione, ma richiede un secondo comportamento della Condizione, sovrapposizione con canone, attribuzione esplicita e nuovi dettagli. Non risolve automaticamente caso 25 né difetti comuni di storico/backup. Credibile se si decidesse che ogni previsione deve essere generata; tale requisito non è presente |
| D senza B | Le manuali rappresentano i costi ma resterebbe una Condizione artificiale obbligatoria. Insufficiente |
| B+D | Minor cambiamento strutturale, purché origine distingua le autorità, totali siano completi e Proposte/storico siano corretti. È la raccomandazione condizionata |
| Componente generica o Piano economico separato | Aumenta concetti e sincronizzazioni; nessuna casistica richiesta dimostra che siano necessari oggi |
| Condizione annuale limitata a un ciclo, importo zero fittizio o copia a rinnovo | Artifici che confondono natura del costo e validità; la copia introduce un automatismo non definito |

L'ampiezza delle correzioni di lettura non rende da sola preferibile C: molti difetti precedono B+D e resterebbero con qualsiasi modello che produca dettagli economici coerenti e storico portabile.

## 8. Compatibilità e transizione

### 8.1 Interventi obbligatori

- **Dominio:** recepire esplicitamente Condizioni facoltative, Stime manuali contrattuali, validazione per tipo di attività, decorrenza della prima Condizione tardiva e decisione sull'annullamento. Aggiornare le regole di inclusione dove citano soltanto Condizioni/Effettivi, perché una manuale residua può essere l'unico valore di un Contratto terminale.
- **Dati vivi:** nessuna conversione automatica di Condizioni annuali esistenti in una tantum, neppure se sembrano Sophos. Non si può dedurre la natura commerciale da un importo o da una durata. Le attuali system restano generate; manuali actual-only restano valide. Le nuove manuali con Stime sono additive rispetto a questo modello.
- **Schema di rappresentazione:** non serve una nuova tabella per il costo singolo; owner, origin, esercizio e campi Riga già esistono. Gli indici, la FK aziendale e l'esclusività contenitore restano necessari. Una eventuale persistenza del primo uso richiede invece un intervento specifico in avanti, giustificato da IR12.
- **Bozze esistenti:** conservare azioni e baseline originarie. Una baseline priva dell'origine delle figlie non può essere resa modificabile assumendo manual. Richiedere riallineamento esplicito con la realtà per usare il nuovo piano, preservando le scelte che possono essere rigiocate correttamente; non aggiungere M vivo in silenzio a un risultato già confermato. Leggere i vecchi payload, validare quelli nuovi e impedire approvazioni semanticamente ambigue.
- **Snapshot:** nuovi dettagli completi e lettura delle vecchie versioni. Non riscrivere Snapshot approvate per aggiungere manuali o correggere classificazioni. Correggere il reader se i valori storici esistono già; quando mancano fatti, dichiarare il limite. Per errori storici usare i percorsi canonici di annotazione/rettifica applicabili, non «ricalcolare il passato».
- **Reporting:** IR07–IR10 devono essere risolti nei percorsi condivisi, perché un nuovo writer corretto non corregge i reader esistenti. Verificare azienda, Fornitore, CdC, confronti e export sul medesimo dataset numerico.
- **Backup — rinviato, raccomandazione storica non riverificata:** origin, Righe e versioni dei dettagli sono già nel formato attuale. Correggere trasformatori di owner e validazione dell'appartenenza storica senza un aumento di versione dettato dal solo fatto di aver introdotto Stime manuali. Aggiungere informazione portabile del primo uso solo per la necessità dimostrata, non esportare audit o Bozze per comodità.

### 8.2 Primo uso e pacchetti legacy — parte backup rinviata

**Provenienza: revisione del 3 ottobre. Nessuna verifica di formato, package, V3 o #33 in questa passata.** La correzione viva di IR12 resta obbligatoria indipendentemente da questi lavori.

Il formato business osservato nella prima revisione è versione 2 e accetta una versione legacy; [BusinessBackupContract](/root/MP2/app/BusinessBackup/V1/BusinessBackupContract.php:9) elenca schemi, dettagli e relazioni. Audit e Bozze non ne fanno parte. Un backup che contiene soltanto lo stato finale «system zero, nessun Effettivo, nessun Budget/Chiusura» non distingue un Contratto mai usato da uno già usato e poi azzerato. È una perdita d'informazione, non un errore correggibile con un'altra somma.

Raccomandazione tecnica minima: un fatto monotono esplicito, portabile, senza inventare timestamp se non noto. Al rilascio si può valorizzare solo sulla base di evidenze disponibili (audit e sorgenti già previste); non assumere false per mancanza di prova. La modalità concreta, campo del Contratto o evidenza circoscritta, è una scelta tecnica proporzionata al requisito già obbligatorio. Se modifica lo schema contrattuale del backup, va gestita come estensione versionata compatibile, con vecchi pacchetti ancora leggibili e limite dichiarato.

I legacy privi di evidenza richiedono D3. Non promettere equivalenza del vincolo su tutti i vecchi pacchetti, non sbloccare automaticamente né marcare tutti i Contratti come già usati senza decisione. Anche dettagli importati in passato con owner perduto non si riparano leggendo l'owner vivo: si può recuperare solo da un pacchetto o da una prova storica disponibile.

### 8.3 Interventi non necessari o facoltativi

Non sono prerequisiti: nuovo motore economico, periodicità pluriennale, automatismo a rinnovo, unificazione architetturale di tutti gli applicatori, redesign delle pagine, nuovo pannello delle annualità, esportazione dell'audit completo, migrazione di costi già approvati o conversione massiva di Condizioni. Eventuali rinominazioni interne di classi come RegisterContractPayment sono secondarie rispetto alla terminologia visibile corretta e al comportamento verificato.

La correzione dei difetti comuni può essere consegnata in cambi separati e coerenti, ma non omessa dai criteri di completamento della funzionalità quando incide sui suoi flussi. Nessuna dichiarazione di «retrocompatibilità completa» è possibile prima delle regressioni sui vecchi dettagli, sulle vecchie Bozze e sui backup reali isolati.

### 8.4 Ordine minimo di implementazione e integrazione

1. **Chiudere le decisioni normative B+D, D1/D2.** Esplicitare inclusione delle manuali residue, prima Condizione e riattivazione; chiarire l'inizializzazione retrograda del §5.6 se il comportamento viene toccato. Nessuna automazione pluriennale o annullamento contrattuale in Proposta aggiunto.
2. **Correggere i difetti comuni necessari:** IR05/IR06/IR07/IR08/IR09/IR10 e permanenza viva IR12. Possono essere cambi separati; regressioni con importi e identità espliciti prima del riuso. Questi difetti esistono anche senza B+D.
3. **Preparare modello operativo e piano**, senza esporre ancora la nuova capacità: discriminazione per tipo Riga, prima Condizione, protezione system; baseline/payload versionati con origin, anno, ID, metadati e riferimenti padre/figlia. Applicatori e approvazione atomica; vecchie Bozze leggibili e riallineamento necessario esplicito, senza conversioni silenziose.
4. **Completare insieme writer e reader del ciclo:** S+M corrente, R′+M′ nelle proiezioni; Budget principale annuale e righe di tracciabilità fuori anno; Snapshot di Chiusura completa con uguaglianza conservata; storici, aggregati, confronti e compatibilità tardive IR11. Creazione N+1 e ricorrenze conservano M/E per anno, Progetti invariati.
5. **Esporre form e azioni soltanto dopo l'integrazione dei punti precedenti**, aggiornando i consumatori attivi IR15. Nessun rilascio intermedio che ammetta M mentre Proposta/Budget/Chiusura/report assumono ancora actual-only/R. Non serve introdurre un feature flag o un nuovo engine per ordinare il lavoro.
6. **Eseguire le sequenze complete B+D e il gate CI**, poi verifica browser mirata e PDF con dataset numerici della feature, fault injection e concorrenza pertinente. Confrontare ogni criterio, non equiparare i test attuali a collaudo futuro. **Backup/restore rimangono un filone separato rinviato** con IR13/IR14/D3 conservati; questa review non ne certifica compatibilità né rilasciabilità.

## 9. Decisioni realmente ancora necessarie

L'approvazione della candidata non è implicita nella richiesta di revisione. Oltre all'esplicito recepimento di B+D nella Canonica, le scelte non ricavabili univocamente sono le seguenti.

### D1 — Annullamento e pianificazione dei costi residui terminali

**Alternative:** (a) conservare manuali, consentendo un contesto terminale esplicito; (b) richiedere annullamento esplicito di tutte le Stime prima di annullare il Contratto, mantenendo A=0; (c) azzerarle automaticamente. La terza perde una decisione economica e non è raccomandata. La seconda impedisce di pianificare sullo stesso Contratto costi ancora dovuti, salvo cambiare di nuovo la regola.

**Raccomandazione:** (a), con Nota per nuova pianificazione terminale e intervento esplicito sulle Stime non più valide. Nessuna cancellazione/ripristino implicito. **Impatto:** §§15.4, 18.9, Actions e form dei residui; IR01/IR02; T3/T4. **Bloccante:** sì, prima di implementare il comportamento terminale. Non esiste oggi una decisione approvata su quale costo residuo sia dovuto.

### D2 — Decorrenza della prima Condizione aggiunta successivamente

**Lacuna concreta:** accordo iniziato il 1 gennaio senza ricorrenza; il primo canone reale parte il 1 luglio. §18.13 descrive la prima Condizione del nuovo Contratto/riattivazione e le modifiche di una precedente, non questa nuova situazione. «Applicare le regole pertinenti» non risolve l'assenza della precedente e il rapporto fra inizio accordo e inizio primo canone.

**Alternative:** (a) obbligare la prima Condizione a partire dall'inizio dell'accordo/riattivazione; (b) ammettere una propria decorrenza reale successiva, senza confine di ciclo precedente; per il futuro applicare il minimo del mese successivo oppure riconoscerla come prima decorrenza esente, da scegliere esplicitamente. La prima alternativa può inventare costi nei mesi gratuiti o richiedere Condizioni fittizie a zero.

**Raccomandazione:** decorrenza reale autonoma non anteriore all'accordo/riattivazione; registrazione già avvenuta solo in perimetro aperto, con motivo e impatto; per un primo canone futuro usare la data reale dichiarata, senza confine precedente né rinvio automatico. Questa esenzione dal minimo delle modifiche future va approvata espressamente. Stati, assenza di sovrapposizioni e immutabilità del chiuso restano vincoli. **Impatto:** CreateContractCondition, SaveContractEdits, form, ContractPlan e Canonica. **Bloccante:** sì per completare la promessa «prima Condizione aggiunta successivamente». Non serve ridiscutere le date delle normali sostituzioni tariffarie.

### D3 — Contratti legacy il cui primo uso non è ricostruibile — rinviata

**Stato di questa passata:** decisione backup sospesa, nessuna integrazione V3/#33 né nuova verifica. Il contenuto seguente è conservato per il lavoro successivo, non è un blocco aggiuntivo imposto alla presente analisi.

**Regola già certa:** dopo primo uso il Fornitore non può cambiare. **Informazione mancante:** pacchetto storico senza audit/fatto portabile e senza prove correnti; il passato non è determinabile.

**Alternative concrete:** recuperare il fatto dall'ambiente sorgente ancora disponibile prima dell'export; importare il pacchetto dichiarando il limite e prevedere una procedura esplicita per attestare la precondizione di un successivo cambio Fornitore; oppure rifiutare la piena operatività di quei pacchetti quando non è possibile garantire la continuità richiesta. Sbloccare in silenzio o bloccare indistintamente ogni Contratto non è deducibile dai dati.

**Raccomandazione:** privilegiare arricchimento da evidenze prima dell'export; per pacchetti irrimediabilmente incompleti, concordare trattamento e limite prima di promettere ripristino pienamente equivalente. La revisione non sceglie una nuova procedura utente o un nuovo stato senza mandato. **Impatto:** compatibilità restore e cambio Fornitore, non rappresentazione del costo. **Bloccante:** per la garanzia di continuità su tutti i backup legacy; non per il bit minimo nei nuovi backup o per i nuovi Contratti.

### D4 — Costo automatico a ogni rinnovo pluriennale, soltanto se richiesto

**Alternative:** previsione manuale per ogni evento noto; ricorrenza a calendario economico fisso; costo generato dal rinnovo effettivo dell'accordo. Le ultime due differiscono se un rinnovo cambia data, viene annullato o viene registrato tardi.

**Raccomandazione nel perimetro attuale:** manuale, senza automazione. **Impatto:** se si richiede l'automatismo, definire evento/ancora e comportamento di variazione prima di scegliere una piccola estensione; una Condizione una tantum non basta. **Bloccante:** no per B+D manuale; sì per dichiarare automatizzato il caso 25.

Non richiedono decisioni di prodotto: formule S+M/R′+M′, anno del Budget, esclusione degli Effettivi dal Budget, readonly system, tenant isolation, permanenza del primo uso, HaEffettivi a saldo zero, immutabilità e divieto di Stime retroattive. Sono invarianti o correzioni necessarie. Le esatte strutture interne dei payload e il punto di calcolo dei contributi per Fornitore sono scelte implementative, purché rispettino queste regole e le versioni storiche.

## 10. Matrice minima delle regressioni

Da eseguire nella futura implementazione, in ambiente isolato secondo [testing-policy.md](/root/MP2/docs/testing-policy.md). La matrice descrive **la futura copertura B+D**, non ancora eseguita; i test attuali e i relativi limiti sono separati nel §10.1. Estendere i test esistenti quando verificano già la stessa invariante; non replicare CRUD o comportamento del framework.

| ID | Livello e dataset minimo | Asserzioni rilevanti / rischio coperto |
|---|---|---|
| T1 | Unit del motore, estendere ContractAnnualAllocationTest | Quattro frequenze, inizio/fine ciclo, estremi inclusivi, cessazione a metà ciclo, cambio tariffa, gap; risultati ricorrenti invariati, nessuna manuale nel motore |
| T2 | Feature manuali e importi, un dataset Sophos e uno con differenza autorizzata | 18×145,63; autorità dell'importo diverso dal prodotto; più una tantum; Effettivo non modifica Stima; niente ripartizione o costo negli altri anni |
| T3 | Feature ciclo di vita, dataset zero/solo M/misto | Creazione e riattivazione senza Condizioni, prima Condizione secondo D2, cessazione, annullamento D1, rinnovo, archivio; nessuna copia/ripristino implicito di M |
| T4 | Feature Righe, matrice essenziale stato × attività | Stima/actual/mista, Pianificato/Attivo/terminale/Archiviato; rifiuto Effettivo futuro, richieste miste atomiche, annullamento/ripristino, H vero con +100/−100, system protetta |
| T5 | Feature totali correnti/UI read model | S=1.200/M=300/E=900 → A=1.500, scostamento −600 in elenco, scheda, report e impatti; Sophos attivo a zero negli anni seguenti, Previsto/Non previsto su Budget zero/positivo |
| T6 | Feature approvazione figlie, estendere PlanContract/ApproveProposalSnapshot | Padre nuovo/esistente, nuove figlie ed esistenti; origin protetta, identità Riga, metadati preservati, nessuna doppia rappresentazione, Effettivi identici prima/dopo |
| T7 | Feature readiness e RealignProposalItem | Mutazione figlia viva durante Bozza; Keep/Reload/Manual con azioni proprie e riferite; id/UUID estranei e riferimenti annidati; nessun risultato residuo di azione ritirata |
| T8 | Feature multi-Esercizio | Budget N con nuova autonoma/figlia in N+1, M preesistente in più anni; impatti annuali corretti, contributo N senza costi N+1, anno dettaglio corretto; rifiuto di anno chiuso |
| T9 | Feature Snapshot e aggregazioni di primo livello | 1.200+300: header=padre=componenti; nuova figlia non ricontata; no Effettivi nel payload; vecchie versioni leggibili e ownership storico distinto dal vivo |
| T10 | Feature ClosingContract/Atomicity/Snapshot | Preview=conferma=Snapshot; N+1 nuovo ed esistente con M=450; ricalcolo ripetuto; fingerprint obsoleto e fallimento dopo ricalcolo lasciano dati/stati/audit economico coerenti col rollback |
| T11 | Feature late corrections + Unit confronti | Sorgente presente/assente Snapshot; manuale storica con Stime; +100/−100; flag e totale/dettaglio; rifiuto Stima retroattiva e system; Alla Chiusura/Budget immutabili; annotazioni impatto zero |
| T12 | Feature reporting per Fornitore/CdC e riferimenti | Stesso dataset con autonoma, Progetto, Contratto e Riporto; Corrente/Budget/Chiusura/Conoscenza Corrente; somma bucket riconcilia totale, contesti storici e rettifiche preservati |
| T13 | Feature primo uso vivo; restore rinviato | Positivo→zero, positivo→spostamento, actual zero, Budget zero; blocco Fornitore permanente nel vivo; portabilità dopo restore rinviata; mai usato modificabile; politica legacy D3 rinviata |
| T14 | **Rinviato:** backup round-trip con ID diversi | Origin e dettagli/versioni, owner annidato, spostamento dopo Budget in entrambe le direzioni, primo ricalcolo senza duplicati; pacchetti precedenti secondo contratto di compatibilità |
| T15 | Feature mutazioni condivise autonome/Progetti | Spostamenti anno/owner, eredità Fornitore/CdC, motivi post-Budget/con Effettivi, Storno vietato con H, Riprogrammazione/Riporto invariati; nessun vincolo rimosso per tutti i contenitori |
| T16 | Feature autorizzazioni/atomicità e concorrenza mirata | Chiamate dirette senza permesso, tenant estraneo e Riga system rifiutate; revisione obsoleta; fallimento seconda figlia fa rollback; competizione cambio Fornitore/primo uso e conferma/modifica manuale non viola invarianti |
| T17 | Feature compatibilità dei payload | Vecchia Bozza senza origin richiede riallineamento quando necessario; nuove versioni conservano vecchie scelte; vecchie Snapshot non riscritte; parte export/import rinviata, senza reinterpretazione |
| T18 | Livewire, browser e PDF mirati | Form senza Condizioni, pianificazione manuale/mista, errori e readonly; Sophos 2026 con storia distinta; report e PDF coerenti sullo stesso dataset, navigazione/tastiera nei controlli modificati |

**Nota storica, backup rinviato:** RestoredContractContinuityTest e BusinessBackupReportingEquivalenceTest restano punti di estensione non rieseguiti in questa passata; ma la sola uguaglianza prima/dopo restore può preservare un errore in entrambi i lati: servono importi attesi e identità storiche esplicite. ClosedKnowledgeReportTest consultato usa una sorgente già presente con HaEffettivi=true; non copre IR09/IR10. RealignProposalItemTest consultato usa una Spesa autonoma; non copre IR05. Queste sono osservazioni sulle fixture lette, non una dichiarazione di completezza dell'inventario di tutti i test.

Dopo le verifiche focalizzate, la futura funzionalità completa deve superare il quality gate di [.github/workflows/ci.yml](/root/MP2/.github/workflows/ci.yml): validazione Composer, installazioni dai lock, build frontend, audit dipendenze, migrazioni nel testing isolato, Pint, PHPStan, Pest e smoke login, con dipendenze PDF previste dal job. Non eseguire reset sul database persistente. Nessun deploy è richiesto per la verifica.

### 10.1 Prove attuali: che cosa verificano davvero

T1–T18 sono obiettivi futuri, non 18 test passati. Il comando del §2.4 ha superato 466 test esistenti; le seguenti fixture e asserzioni spiegano le prove utilizzate nelle conclusioni.

| File/gruppo effettivamente eseguito | Fixture/asserzioni pertinenti | Limite rispetto a B+D |
|---|---|---|
| CreateExerciseTest | Creazione/duplicato, ereditarietà classificazioni, ricorrenze e divieti tenant/permessi | Non costituisce prova della manuale contrattuale o dell'interpretazione retrograda «ultima nota» |
| PlanContractTest / PlanProjectTest / PlanExpenseTest | Nuovi padri soltanto nel piano; modifica Stima esistente 10→12 senza scrittura prematura; condizioni/confini e classificazione vietata con Effettivi | Condizioni presenti, nessuna nuova figlia contrattuale mista |
| ApproveProposalTest / ApproveProposalSnapshotTest / ApproveRevisionTest | Apply, retry unico, dettagli/evidenze e terminalità; fallimenti fra header/eventi/righe/evidenze/stato; v2 con predecessore e motivo | I payload attuali non contengono il futuro M di Contratto; E21 aggiunge errore sulla seconda Spesa |
| RealignProposalItemTest / ReviewProposalReadinessTest / DiscardProposalTest | Autonoma mutata e sorgente nuova, readiness; scarto con motivo/retry conserva cambi vivi 5→7 e vieta riscrittura terminale | Non coprono il grafo di E13; quel difetto rimane pur con suite verde |
| CloseExerciseTest / ClosingReviewTest / ClosingAtomicityTest / ContractClosingTest / ClosingSnapshotTest | Fine anno, precedenti, draft, warning/classificazioni, senza Budget, conferma/retry, rollback e ricorrenze/identità Snapshot | Nessuna Chiusura corrente di M contrattuale; non scambiare fault injection con competizione concorrente |
| ProjectClosingReprogrammingTest / ProjectDeferralBudgetTest | Riduzione/ripianificazione con importi espliciti, riapplicazione e deriva dell'effetto, inversione terminale e conservazione spesa indipendente | Non trasferisce queste regole ai Contratti |
| RecordLateCorrectionTest / test immutabilità/annotazioni | Append/retry, compensazione, token obbligatori/obsoleti, tenant/anno, rollback se audit fallisce; annotazioni senza riscrittura economica | Sorgenti storiche predisposte; E14/E15/E25 aggiungono assenza Snapshot e H inizialmente false |
| ClosedKnowledgeReportTest | Fonte già presente, H inizialmente true | Non protegge IR09/IR10, confermati dalle riproduzioni nuove |
| ReportPdfTest | Percorso HTTP con autorizzazioni, input, report ed export; esercizio del renderer reale e controlli del documento nei test | Non è ispezione visiva; nessun dataset B+D accettato dalle Actions attuali |

Aggiunte future alla matrice, senza rinumerare gli ID precedenti:

| ID | Livello/scenari | Asserzioni necessarie |
|---|---|---|
| T19 | Feature ciclo Esercizio e chiamate dirette | Anni passati/correnti/futuri; inizializzazione diretta/Chiusura con classificazioni; zero nel catalogo; senza Budget consentito; draft iniziale/Revisione bloccano Close; dopo scarto chiusura e rifiuto di nuova approvazione; nessuna riapertura; N+1 già chiuso senza rinvio vs trasferimento vietato; Prepare senza scritture; guard Note e rollback anche chiamando Close direttamente |
| T20 | Feature concatenata + riferimenti selezionati | Eseguire realmente §6.3 sul Contratto misto: 1.500→1.600/E=900, vecchio Budget 1.500, N+1 1.200/1.650, figlia fuori anno +200; Sophos zero negli anni successivi; lettori, identità e versioni, non sole uguaglianze fra report |

Il quality gate completo, le gare concorrenti reali, la navigazione browser e il PDF del futuro dataset B+D **non sono eseguiti**. I test backup e la compatibilità V3/#33 **sono rinviati**, non mancanti per un impedimento ambientale. Nessun test del codice attuale può dichiarare completate T2–T20 nella variante B+D.

## 11. Criteri di accettazione per l'implementazione

1. La Canonica approvata distingue accordo, ricorrenza e previsione manuale; D1 e D2 hanno una risposta esplicita. L'automazione del caso 25 non viene dichiarata presente se esclusa.
2. Sophos è registrabile con date reali, nessun rinnovo e nessuna Condizione artificiale; 2.621,34 nel solo 2025 della fixture aperta; zero negli anni successivi e validità fino al 2028. Nel caso reale con 2025 chiuso, nessuna Stima retroattiva viene introdotta per ottenere il medesimo prospetto.
3. Contratti senza Condizioni, con sole manuali o misti attraversano creazione, modifica, prima Condizione, cessazione, annullamento, riattivazione e archivio secondo le regole approvate; le richieste miste non aggirano i vincoli degli Effettivi.
4. Un costo generato ha un solo autore; nessuna Action ordinaria o payload di piano modifica manualmente la system. Ricalcoli mantengono unicità e identità e non toccano M/E; la verifica restore è rinviata.
5. Ogni totale corrente di Contratto usa S+M; ogni totale proiettato usa R′+M′ dell'anno corretto; spiegazioni ricorrenti sono riconoscibili come tali. Scostamento usa E−A, H non dipende dal saldo.
6. Proposte con nuovi padri/figlie ed esistenti hanno una sola rappresentazione modificabile di ogni Spesa. Baseline, metadati, identità, revisioni, replay e readiness impediscono approvazioni basate su dati obsoleti o su risultati di azioni ritirate.
7. Approvazione multianno atomica: costi degli altri anni aggiornano la realtà di quegli anni senza alterare Budget esistenti o il totale dell'anno principale. Header, righe economiche di primo livello e componenti annuali quadrano; il Budget non contiene Effettivi.
8. Chiusura possibile senza Budget ma non con Bozza iniziale/Revisione pendente; nessuna scrittura alla sola preparazione, controlli anche nelle chiamate dirette e nessuna riapertura. Preview, conferma e Snapshot coincidono; conferma obsoleta rifiutata; rollback verificato. N+1 nuovo non riceve manuali copiate; N+1 esistente conserva i propri costi manuali.
9. Nessun reader ricostruisce valori, appartenenza o classificazione di una Snapshot dal vivo. Vecchie versioni ancora leggibili; sorgenti a zero incluse quando richiesto; nessun doppio conteggio padre/figlie.
10. Correzioni tardive di sorgenti presenti e assenti compaiono in dettaglio e aggregati di Conoscenza Corrente. Alla Chiusura resta identica; H aggiornato anche con netto zero; nessuna Stima nel chiuso o falsa classificazione storica.
11. Azienda, Fornitore, CdC, dashboard, confronti e PDF riconciliano gli stessi dati e preservano l'identità dei riferimenti. Gratis, ignoto e anno privo di costi non vengono equiparati dal software.
12. **Criterio backup conservato ma rinviato, non collaudato in questa passata:** backup nuovo conserva origin, relazioni vive e storiche, versioni e primo uso; round-trip con ID diversi e spostamenti dopo Budget superato. D3 disciplina i legacy non determinabili, senza dichiarazioni di equivalenza non provate.
13. Il primo uso blocca permanentemente il Fornitore anche dopo annullamento/azzeramento/spostamento vivo, indipendentemente dal restore rinviato. Permessi, tenant isolation, motivi, vincoli su anni/stati, protezione system e concorrenza pertinente sono verificati nelle Actions. I comportamenti di autonome, Progetti, Riporto e Riprogrammazione non cambiano fuori scope.
14. Le regressioni pertinenti del §10, il gate corrente della CI e l'ispezione browser dei percorsi modificati sono completati; il diff finale non contiene migrazioni retroattive, conversioni arbitrarie o infrastruttura non necessaria.

## 12. Verifica finale

La checklist riguarda **l'analisi aggiornata**, non certifica la futura implementazione.

- [x] **Fonti e perimetro:** letti i documenti richiesti, emendamenti finali inclusi; branch/HEAD e modifiche preesistenti rilevati; nessun terzo rapporto o nuova norma introdotta.
- [x] **Ciclo completo:** mappati stati effettivi, precondizioni, permessi, scritture, riferimenti economici, invalidazioni e fallimenti da creazione a conoscenza successiva (§5.6).
- [x] **Proposte/Budget:** padre/figlia e multianno verificati con DB; IR05 rafforzato, IR06/IR07 confermati; sequenza Revisione attuale E22 distinta dal futuro misto.
- [x] **Chiusura/N+1:** eseguite preparazione, conferma, rifiuto obsoleto, nuovo/esistente/chiuso e rollback; distinta persistenza degli audit diagnostici. Nessuna eliminazione del controllo di uguaglianza raccomandata.
- [x] **Storico/lettori:** IR08–IR10 riprodotti con importi, H e identità; IR11/IR15 restano incompatibilità/letture C/P da correggere, non dichiarate risolte.
- [x] **Primo uso vivo:** E19 conferma la perdita del vincolo dopo spostamento; IR12 aggiornato senza dipendere dal restore.
- [x] **Backup:** IR13/IR14 e parte storica IR12 conservati esplicitamente rinviati; D3/V3/#33 non riesaminati.
- [x] **Prove e limiti:** 466 test attuali superati, E10–E27 documentati con fixture e limiti; renderer attuale esercitato, browser e concorrenza reale non eseguiti; rifiuto B+D corrente non etichettato come bug.
- [x] **Seconda passata critica:** ricontrollati Bozza→approvazione, vivo→Revisione, Prepare→Close, N→N+1 e Chiusura→tardiva; escluso il falso bypass Nota grazie al guard del modello; qualificata la creazione di N dopo N+1 Chiuso; preservata la Bozza N+1 quando nessuna sorgente cambia. Formule, sequenze, IR, D e criteri confrontati fra loro.
- [x] **Ordine e compatibilità:** prima writer/reader/piani completi, poi esposizione delle nuove Stime; vecchie Bozze con riallineamento esplicito, Snapshot non riscritte, nessun motore o refactoring generale.
- [x] **Diff:** unico aggiornamento autorizzato in questo documento; impronte di lock, rapporto originale e Canonica conservate. Nessuna modifica a codice/test/migrazioni o lavoro altrui, nessun commit/push/deploy.
- [ ] **Futura feature non collaudata:** D1/D2 non approvate, B+D non implementata; sequenze miste/Sophos, metadati, tutte le regressioni future, browser, concorrenza e gate CI ancora da eseguire.

**Conclusione operativa:** B+D rimane una candidata proporzionata, ma deve attraversare Proposte, versioni Budget, mutazioni vive, Chiusura, N+1 e conoscenza successiva con un solo conteggio annuale e identità storiche coerenti. Le correzioni e l'ordine del §8.4, seguiti dalle prove T1–T20 pertinenti, sono le condizioni per valutarne l'implementazione. La suite attuale verde non elimina i difetti riprodotti né approva D1/D2. Backup esplicitamente rinviato; nessuna promessa di assenza assoluta di regressioni o piena rilasciabilità derivata dalla sola analisi.
