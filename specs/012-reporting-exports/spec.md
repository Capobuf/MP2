# Feature Specification: Reportistica ed esportazione

**Feature Branch**: `012-reporting-exports`

**Created**: 2026-08-24

**Status**: Implemented

**Input**: User description: "S11 — Reportistica ed esportazione (`012-reporting-exports`): confronti canonici fra Budget, situazione corrente, Chiusura e conoscenza corrente; categorie deterministiche, drill-down, report specialistici ed esportazioni semanticamente complete."

## Clarifications

### Session 2026-08-24

- Q: Quale formato e modalità di consegna deve usare S11 per le esportazioni? → A: Singolo PDF con anteprima reale e download immediato dalla personalizzazione.
- Q: Come deve comportarsi S11 con l'etichetta `Sostituito`? → A: S11 non la assegna né la espone perché la §32 l'ha rimossa permanentemente dal dominio.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Leggere la situazione annuale senza ambiguità (Priority: P1)

Un utente con capacità `visualizza` apre la reportistica di un'Azienda e di un Esercizio e legge, nello stesso contesto dichiarato, Budget iniziale, Budget corrente, Allocato Corrente, Effettivo Corrente, Scostamento Operativo e, quando esistono, valori di Chiusura e a Conoscenza Corrente.

**Why this priority**: È la vista esecutiva che rende distinguibili piano approvato, realtà viva, fotografia di Chiusura e conoscenze successive.

**Independent Test**: Con un Esercizio dotato di due Budget e una Chiusura, la vista mostra tutte le misure previste, identifica sempre la versione Budget e il tipo di Effettivo e non usa una misura al posto di un'altra.

**Acceptance Scenarios**:

1. **Given** un Esercizio Aperto con Budget v1 e v2, **When** l'utente seleziona v1 e l'Effettivo Corrente, **Then** il report dichiara entrambi i riferimenti e calcola la varianza rispetto all'Allocato approvato di v1.
2. **Given** un Esercizio Chiuso, **When** l'utente legge la vista annuale, **Then** Effettivo alla Chiusura ed Effettivo a Conoscenza Corrente restano separati e Residuo, Risparmio, Allocato non utilizzato e Riporto provengono solo dalla Snapshot di Chiusura.
3. **Given** un utente senza `visualizza` per l'Azienda corrente, **When** tenta di aprire un report o una sua esportazione, **Then** l'accesso è negato senza esporre dati dell'Azienda.

---

### User Story 2 - Spiegare ogni variazione con una classificazione deterministica (Priority: P1)

Un utente confronta due riferimenti espliciti e vede ogni sorgente economica di primo livello una sola volta, con una categoria primaria, le dimensioni cambiate, le etichette secondarie applicabili e i fatti disponibili che spiegano il delta.

**Why this priority**: FR-087, FR-088 e gli invarianti 28.50-28.52 richiedono che classificazione e conteggi siano deterministici e non duplicati.

**Independent Test**: Un insieme contenente una Spesa autonoma, un Progetto con più Spese figlie e un Contratto con Stima di sistema e Spese Effettive produce una sola riga primaria per sorgente, categorie esclusive e totali uguali alla somma economica non duplicata.

**Acceptance Scenarios**:

1. **Given** una sorgente presente in entrambi i riferimenti con almeno una dimensione diversa, **When** viene confrontata, **Then** riceve solo `Modificato` come categoria primaria e tutte le dimensioni effettivamente cambiate.
2. **Given** una sorgente con Allocato approvato zero o assente dal Budget e con Allocato finale non zero oppure `HaEffettivi = true`, **When** il Budget viene confrontato col riferimento finale, **Then** la sorgente di primo livello riceve `Non previsto`.
3. **Given** un Progetto o Contratto già previsto con nuove Spese figlie, **When** si apre il drill-down, **Then** le Spese figlie mostrano solo fatti strutturali neutrali e non diventano nuove sorgenti `Non previste`.
4. **Given** due Spese autonome simili per titolo, importo, Fornitore o Nota, **When** vengono confrontate, **Then** restano sorgenti distinte salvo identità o derivazione esplicita canonica.

---

### User Story 3 - Comprendere correzioni successive alla Chiusura (Priority: P1)

Un utente legge separatamente Effettivo alla Chiusura, correzioni tardive positive, negative e nette, Effettivo a Conoscenza Corrente e Annotazioni di errore storico prive di impatto economico.

**Why this priority**: FR-043 e FR-096 proteggono l'immutabilità della Chiusura e impediscono che il solo saldo netto nasconda le correzioni.

**Independent Test**: Partendo da una Snapshot di Chiusura, una correzione positiva, una negativa e un'Annotazione, il report mantiene invariata la Snapshot, mostra ogni correzione e non ricalcola decisioni di Chiusura.

**Acceptance Scenarios**:

1. **Given** correzioni tardive positive e negative, **When** l'utente seleziona Conoscenza Corrente, **Then** vede i due totali separati, il netto, le singole correzioni e l'Effettivo aggiornato.
2. **Given** un'Annotazione di errore storico, **When** il report viene generato, **Then** l'imputazione materializzata resta invariata e l'Annotazione è visibile con impatto economico nullo.
3. **Given** una correzione tardiva su un Progetto chiuso con Risparmio, **When** il report viene letto, **Then** Risparmio e Riporto restano quelli della Chiusura e l'impatto tardivo è mostrato separatamente rispetto all'Allocato finale.

---

### User Story 4 - Approfondire aggregazioni e report specialistici (Priority: P2)

Un utente apre i report annuali e specialistici per Budget vs Actual, Budget vs Allocato Corrente, Scostamento Operativo, versioni Budget, Esercizi, Riporti, Contratti, Progetti e Fornitori, quindi scende fino alle evidenze che compongono i valori.

**Why this priority**: Il capitolo 25 richiede viste nominate e drill-down completi, non un unico totale privo di spiegazione.

**Independent Test**: Per ogni famiglia di report viene selezionato un riferimento valido e ogni totale può essere ricondotto a sorgenti, Spese figlie, Righe e fatti pertinenti senza doppio conteggio.

**Acceptance Scenarios**:

1. **Given** un report per Fornitore con Spese autonome, Spese di Progetto, un Contratto e Riporto di Progetto, **When** i totali vengono aggregati, **Then** ogni importo compare una volta, le Spese senza Fornitore e il Riporto senza Fornitore sono separati e il totale del Progetto non è sommato sopra le Spese.
2. **Given** un report Contratti con un intervallo date esplicito, **When** la prossima scadenza ricade nell'intervallo, **Then** viene applicata l'etichetta relativa; senza intervallo non viene dedotta alcuna imminenza.
3. **Given** una differenza non spiegata dagli eventi disponibili, **When** si apre la spiegazione, **Then** compare `Variazione non sufficientemente spiegata` senza inventare una causa.
4. **Given** una sorgente archiviata o rinominata dopo una Snapshot, **When** si apre un report storico, **Then** etichette e valori provengono dai dati materializzati e restano leggibili.

---

### User Story 5 - Esportare un report semanticamente completo (Priority: P2)

Un utente apre `Personalizza PDF`, seleziona i soli blocchi e colonne applicabili al report, vede l'anteprima del PDF reale e scarica lo stesso singolo documento.

**Why this priority**: Un'esportazione priva di metadati renderebbe ambiguo il significato economico dei valori e violerebbe il §25.24.

**Independent Test**: L'artefatto esportato contiene gli stessi filtri, riferimenti, categorie, definizioni e dati del report autorizzato e conserva l'indicazione EUR/netto IVA.

**Acceptance Scenarios**:

1. **Given** un report con Budget v2, Effettivo alla Chiusura e filtri applicati, **When** l'utente esporta, **Then** l'artefatto dichiara Azienda, Esercizio, riferimenti temporali, Budget v2, tipo di Effettivo, data/ora, filtri, definizioni delle categorie e EUR netto IVA.
2. **Given** un report con drill-down, **When** viene esportato, **Then** nessuna riga o metadato necessario a ricostruire i totali e la classificazione viene omesso.
3. **Given** un utente autorizzato su Azienda A ma non su Azienda B, **When** tenta di esportare un identificativo o riferimento di B, **Then** la richiesta è negata.

### Edge Cases

- Un Esercizio può non avere alcun Budget, avere più versioni Budget, non avere ancora una Chiusura oppure avere una sola Chiusura con successive correzioni.
- `HaEffettivi` resta vero con Righe Effettivo attive non zero che si compensano fino a totale netto zero; una Riga a zero non vale come presenza.
- Una sorgente presente nel Budget con Allocato zero non è automaticamente prevista economicamente.
- Storno o azzeramento di una sorgente ancora presente nel riferimento finale produce `Modificato`, non automaticamente `Rimosso`.
- In un Esercizio Aperto, una sorgente operativa con Allocato approvato positivo e senza Effettivi riceve `Senza Effettivi`, non `Previsto e non avvenuto`.
- CopiedFromOriginKey dichiara derivazione fra Esercizi ma non identità condivisa; nessuna continuità viene inferita per somiglianza.
- Un riferimento richiesto assente, incoerente con Azienda/Esercizio o semanticamente incompatibile deve essere rifiutato esplicitamente.
- Un filtro senza intervallo date non può produrre un'etichetta di scadenza “imminente”.
- Annotazioni storiche possono riguardare sorgenti archiviate e devono restare leggibili senza alterare aggregazioni.
- Le Snapshot e i report sono viste o materializzazioni di lettura e non diventano sorgenti alternative degli Effettivi.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-S11-001**: Ogni report MUST dichiarare Azienda, Esercizio, riferimento temporale, Budget selezionato quando presente, tipo di Effettivo, data/ora di generazione e filtri applicati.
- **FR-S11-002**: Il sistema MUST distinguere Budget v1, Budget corrente e versione Budget specifica; un confronto Budget vs Actual MUST sempre nominare la versione usata. *(Riconcilia FR-014.)*
- **FR-S11-003**: Il sistema MUST offrire tutti i confronti canonici del §25.2 e rifiutare combinazioni che confrontano misure diverse senza nominarle.
- **FR-S11-004**: Corrente, alla Chiusura e a Conoscenza Corrente MUST restare riferimenti distinti anche quando i valori numerici coincidono. *(Riconcilia FR-043.)*
- **FR-S11-005**: Ogni confronto MUST correlare sorgenti usando, nell'ordine, OriginKey, CopiedFromOriginKey come derivazione esplicita e presenza in un solo riferimento; MUST NOT usare somiglianza di titolo, importo, Fornitore o Note.
- **FR-S11-006**: `Previsto`, `Non previsto` e `Previsto e non avvenuto` MUST applicarsi solo a Spesa autonoma, Progetto e Contratto, mai autonomamente alle rispettive Spese figlie. *(Riconcilia FR-087 e INV-28.50.)*
- **FR-S11-007**: Progetti e Contratti MUST aggregare le Spese figlie nella propria sorgente primaria senza doppio conteggio; le Spese figlie nel drill-down MUST mostrare soltanto i fatti neutrali definiti dal §25.3.
- **FR-S11-008**: Una sorgente MUST essere prevista economicamente solo quando l'Allocato approvato nel Budget selezionato è maggiore di zero.
- **FR-S11-009**: Ogni sorgente MUST ricevere esattamente una categoria primaria fra `Invariato`, `Aggiunto`, `Rimosso` e `Modificato`, determinata dalle regole del §25.5. *(Riconcilia FR-088 e INV-28.51.)*
- **FR-S11-010**: I conteggi esecutivi MUST usare esclusivamente la categoria primaria, così ogni sorgente viene contata una volta.
- **FR-S11-011**: Per una sorgente `Modificata`, il sistema MUST mostrare tutte e sole le dimensioni effettivamente cambiate fra quelle applicabili del §25.6.
- **FR-S11-012**: Le etichette secondarie applicabili MUST potersi sovrapporre senza alterare o duplicare conteggi primari.
- **FR-S11-013**: La sorgente di primo livello MUST ricevere `Non previsto` secondo la formula del §25.8, usando `HaEffettivi` e non il solo saldo netto.
- **FR-S11-014**: La sorgente di primo livello MUST ricevere `Previsto e non avvenuto` solo nelle condizioni chiuse del §25.9; una sorgente ancora operativa in Esercizio Aperto riceve soltanto `Senza Effettivi`.
- **FR-S11-015**: S11 MUST NOT assegnare o esporre l'etichetta `Sostituito`, rimossa permanentemente dal dominio dalla §32.
- **FR-S11-016**: L'etichetta di scadenza entro intervallo MUST essere applicata solo quando il report dichiara un intervallo esplicito che contiene la scadenza; MUST NOT esistere una soglia implicita.
- **FR-S11-017**: La vista annuale esecutiva MUST mostrare tutti i valori, conteggi e Annotazioni elencati nel §25.10.
- **FR-S11-018**: Ogni totale MUST offrire drill-down per tutti i livelli applicabili elencati nel §25.11, fino a Righe, condizioni/cicli, eventi, Riporti e Annotazioni.
- **FR-S11-019**: Ogni differenza MUST mostrare riferimenti, valori, delta, categoria, dimensioni, etichette, eventi/motivi e allegati decisionali pertinenti; in assenza di spiegazione sufficiente MUST mostrare la frase canonica senza inventare cause.
- **FR-S11-020**: Per un Esercizio Chiuso, Residuo, Risparmio, Allocato non utilizzato e Riporto MUST provenire dalla Snapshot di Chiusura e MUST NOT essere ricalcolati dopo correzioni tardive.
- **FR-S11-021**: Il report MUST mostrare separatamente Effettivo alla Chiusura, correzioni positive, negative e nette, Effettivo a Conoscenza Corrente e le singole correzioni. *(Riconcilia FR-096.)*
- **FR-S11-022**: Le Annotazioni di errore storico MUST essere visibili separatamente con impatto economico nullo e MUST NOT riclassificare Centro di Costo, Fornitore, contenitore, Progetto, Contratto, Esercizio o stato storico. *(Riconcilia FR-096.)*
- **FR-S11-023**: Il sistema MUST fornire i report e le formule nominati nei §§25.14-25.22: Budget vs Actual, Budget vs Allocato Corrente, Scostamento Operativo, versioni Budget, Esercizi, Riporti, Contratti, Progetti e Fornitori.
- **FR-S11-024**: Il confronto fra Esercizi MUST usare la stessa misura su entrambi gli anni e MUST rappresentare le Spese copiate come derivate, non come la stessa Spesa.
- **FR-S11-025**: Il report Riporti MUST mostrare tutti i campi del §25.19 e le correzioni tardive senza ricalcolare il Riporto.
- **FR-S11-026**: I report Contratti e Progetti MUST mostrare tutti i campi, stati alla data, eventi e Annotazioni previsti dai §§25.20-25.21.
- **FR-S11-027**: Il report Fornitori MUST aggregare attraverso le Spese secondo il §25.22, includere `Senza Fornitore` e `Riporto senza Fornitore`, e MUST NOT sommare i totali dei Progetti sopra le relative Spese.
- **FR-S11-028**: Ogni importo MUST contribuire una sola volta a totali e aggregazioni. *(Regressione obbligatoria INV-28.52.)*
- **FR-S11-029**: I report storici MUST usare dati materializzati quando il riferimento è una Snapshot e restare leggibili dopo Archivio o modifiche successive.
- **FR-S11-030**: Report e Snapshot MUST rimanere superfici di lettura e MUST NOT diventare sorgenti alternative degli Effettivi.
- **FR-S11-031**: Ogni esportazione MUST essere consegnata tramite download immediato come singolo documento PDF.
- **FR-S11-032**: Ogni esportazione MUST conservare intestazione del riferimento, Budget, tipo di Effettivo, categorie con definizioni, EUR netto IVA, filtri e dettaglio necessario a interpretare e riconciliare i valori. *(Riconcilia FR-089 e §25.24.)*
- **FR-S11-033**: Stati incoerenti, riferimenti mancanti o combinazioni incompatibili MUST fallire esplicitamente; il sistema MUST NOT applicare fallback o default silenziosi.
- **FR-S11-034**: Lettura, drill-down ed esportazione MUST richiedere `visualizza` per la specifica Azienda e MUST mantenere isolamento tenant su ogni riferimento e record.
- **FR-S11-035**: La data annuale di riferimento MUST seguire il §9.2; una data tecnica di approvazione o Chiusura MUST NOT sostituire la data economica.
- **FR-S11-036**: Tutti gli importi MUST essere espressi in EUR e netti IVA, preservando calcolo decimale esatto e semantica di `HaEffettivi`.
- **FR-S11-037**: La configurazione PDF MUST essere effimera e limitata a blocchi/colonne realmente disponibili ricostruiti lato server; MUST NOT accettare HTML, CSS, URL, path o JavaScript dall'utente.
- **FR-S11-038**: Anteprima e download MUST usare lo stesso composer e lo stesso renderer WeasyPrint 69.0, senza fallback, file PDF temporanei, code o rendering browser.
- **FR-S11-039**: I grafici PDF MUST essere SVG statici server-side derivati dagli stessi dati canonici e con la stessa semantica della UI.

### Canonical Requirement Reconciliation

- **FR-014** → FR-S11-002 e FR-S11-032: ogni vista ed export nomina la versione Budget.
- **FR-043** → FR-S11-004, FR-S11-020 e FR-S11-021: Chiusura e Conoscenza Corrente sono separate e non riscrivono decisioni storiche.
- **FR-087** → FR-S11-006, FR-S11-007, FR-S11-013 e FR-S11-014: etichette previsionali solo al primo livello.
- **FR-088** → FR-S11-009-FR-S11-012: una categoria primaria esclusiva e più etichette non additive.
- **FR-089** → FR-S11-001-FR-S11-004 e FR-S11-032: ogni report dichiara riferimenti e filtri espliciti.
- **FR-096** → FR-S11-020-FR-S11-022: correzioni e Annotazioni restano separate dalla Chiusura.

### Key Entities

- **Report Definition**: famiglia di report, Azienda, Esercizio, riferimento iniziale e finale, misura Effettivo, Budget selezionato, data/intervallo e filtri dichiarati.
- **Report Header**: metadati semanticamente obbligatori condivisi da vista ed esportazione.
- **Report Source**: una sorgente economica di primo livello identificata e descritta nel riferimento selezionato, con valori, stato e dati materializzati o correnti appropriati.
- **Comparison Result**: coppia di riferimenti per una sorgente, valori iniziale/finale, delta, categoria primaria, dimensioni modificate, etichette e spiegazioni.
- **Drill-down Item**: dettaglio non duplicante di Centro di Costo, Spesa, Riga, condizione, ciclo, Riporto, evento o Annotazione.
- **Correction Summary**: Effettivo alla Chiusura, correzioni positive/negative/nette, Effettivo a Conoscenza Corrente e singole evidenze.
- **Export Artifact**: singolo documento PDF, scaricato immediatamente, che rappresenta in modo autorizzato e semanticamente completo il report.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Il 100% dei report e delle esportazioni generati contiene tutti i metadati obbligatori del §25.1 e, quando applicabile, una versione Budget esplicita.
- **SC-002**: Il 100% delle sorgenti incluse in un confronto riceve esattamente una delle quattro categorie primarie; la somma dei loro conteggi coincide con il numero di sorgenti uniche.
- **SC-003**: In tutti i casi di Progetto, Contratto e Fornitore verificati, la riconciliazione dal totale alle Righe produce differenza zero e nessun importo compare più di una volta.
- **SC-004**: Ogni confronto Budget selezionato e ogni confronto fra Esercizi usa esattamente la versione o misura scelta dall'utente e rifiuta riferimenti mancanti o incompatibili.
- **SC-005**: Per ogni Esercizio Chiuso verificato, i valori di Chiusura restano invariati dopo correzioni e Annotazioni, mentre il riepilogo a Conoscenza Corrente coincide con Chiusura più correzioni nette.
- **SC-006**: Il 100% dei totali mostrati nelle famiglie di report previste può essere approfondito fino alle evidenze applicabili senza perdere Azienda, Esercizio e riferimenti.
- **SC-007**: Un report storico verificato prima e dopo Archivio/ridenominazione delle sorgenti conserva gli stessi dati materializzati e rimane leggibile.
- **SC-008**: Il 100% dei tentativi di lettura o esportazione cross-tenant e senza capacità `visualizza` viene negato senza dati del tenant estraneo.
- **SC-009**: L'esportazione concordata riproduce tutti i valori, categorie, definizioni, filtri e livelli di dettaglio richiesti con riconciliazione esatta rispetto alla vista.
- **SC-010**: La dimostrazione verticale autenticata completa selezione riferimenti, lettura delle categorie, drill-down ed esportazione senza errori console, Livewire o risposte HTTP fallite.

## Assumptions

- L'autenticazione, la tenancy Filament e la capacità aziendale `visualizza` già esistenti vengono riutilizzate senza introdurre nuove capacità.
- Budget Snapshot, Closing Snapshot, correzioni tardive, Annotazioni, Timeline e oggetti vivi restano le fonti già definite dal dominio; S11 non crea nuove baseline economiche.
- FR-095 e INV-28.60 riguardano esclusivamente `Collegato a` e sono verificati in S5; S11 non introduce relazioni informative ulteriori.
- S9 dispone di codice, test, prova browser e CI verdi, ma la roadmap resta correttamente `implemented` finché manca evidenza formale della revisione umana richiesta; S11 non ne modifica lo stato.
- Il formato concordato è un singolo PDF con download immediato; il piano tecnico giustifica e vincola la dipendenza di rendering dopo il confronto con lo stack corrente.

## Dependencies and Scope Boundaries

- **Dependency S6**: righe e header materializzati delle versioni Budget.
- **Dependency S9**: Snapshot di Chiusura autonoma, valori e stati al 31 dicembre.
- **Dependency S10**: correzioni tardive e Annotazioni append-only separate dalla Snapshot.
- **In scope**: tutto il capitolo 25, i sei FR assegnati a S11, INV-28.50 e INV-28.51, regressioni di INV-28.52 e regole storiche/autorizzative direttamente necessarie.
- **Out of scope**: Forecast, as-of arbitrario, matching fuzzy, relazioni strutturate di sostituzione vietate dalla §32, modifica delle Snapshot, riapertura di Esercizi, nuovi Effettivi prodotti dai report, approvazioni o mutazioni economiche.
