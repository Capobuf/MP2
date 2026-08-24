# Feature Specification: Correzioni post-Chiusura

**Feature Branch**: `main`

**Created**: 2026-08-24

**Status**: Draft

**Roadmap ID**: S10

**Input**: Continue the canonical roadmap after Closing: append late Actual corrections and historical-error annotations without reopening a Closed Exercise, rewriting historical attribution, or recalculating immutable history.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrare un Effettivo tardivo (Priority: P1)

Come utente autorizzato, posso registrare in un Esercizio Chiuso un importo che apparteneva realmente a quell'Esercizio, così la conoscenza corrente incorpora l'omissione o la correzione senza modificare i fatti e le Snapshot della Chiusura.

**Why this priority**: È l'unico percorso canonico per correggere importi o omissioni dopo la Chiusura mantenendo immutabile lo storico materializzato.

**Independent Test**: Su un Esercizio Chiuso, aggiungere una nuova Spesa manuale tardiva con solo Effettivo, aggiungere un Effettivo a una Spesa storica compatibile e aggiungere una Riga compensativa negativa; verificare che ogni operazione sia append-only, aggiorni soltanto l'Effettivo a Conoscenza Corrente e lasci invariati Chiusura, Budget, Riporto, stato e imputazioni storiche.

**Acceptance Scenarios**:

1. **Given** un Esercizio Chiuso e un utente con `corregge_esercizio_chiuso` per la stessa Azienda, **When** l'utente dichiara che un importo apparteneva realmente a quell'Esercizio e registra una nuova Spesa manuale tardiva, **Then** la Spesa contiene soltanto Righe Effettivo e la correzione entra nell'Effettivo a Conoscenza Corrente.
2. **Given** una Spesa manuale storica compatibile, **When** l'utente registra una correzione tardiva, **Then** viene aggiunta una nuova Riga Effettivo alla stessa Spesa senza modificare alcuna Riga esistente.
3. **Given** una Spesa storica non compatibile, **When** l'utente registra la correzione per la stessa sorgente storica, **Then** viene creata una nuova Spesa manuale tardiva nello stesso contenitore storico, senza riclassificare la Spesa originaria.
4. **Given** un Effettivo storico di importo errato, **When** l'utente registra la differenza positiva o negativa, **Then** viene aggiunta una Riga compensativa e l'Effettivo originario resta invariato.
5. **Given** un Fornitore storico Archiviato, **When** la correzione riguarda dati storici che lo referenziano, **Then** il Fornitore resta utilizzabile nel contesto della correzione senza diventare selezionabile per una nuova Spesa ordinaria.
6. **Given** un Esercizio Aperto, un'altra Azienda o un utente privo della capacità richiesta, **When** viene tentata una correzione tardiva, **Then** l'operazione viene rifiutata senza creare Spese, Righe, allegati o eventi parziali.

---

### User Story 2 - Annotare un errore storico di imputazione (Priority: P1)

Come utente autorizzato, posso dichiarare che un dato storico chiuso era errato e indicare il dato ritenuto corretto, così l'errore resta visibile senza riclassificare economicamente lo storico.

**Why this priority**: Gli errori di imputazione, Riporto, stato o Chiusura accidentale non possono essere corretti riscrivendo un Esercizio Chiuso.

**Independent Test**: Annotare separatamente un errore di Centro di Costo, Fornitore, Progetto, Contratto, contenitore, Esercizio, stato storico e Riporto; verificare che l'annotazione sia append-only e leggibile, mentre oggetti vivi chiusi, Snapshot, Budget, Riporto consolidato e valori restano identici.

**Acceptance Scenarios**:

1. **Given** un errore storico di imputazione, **When** l'utente registra dato memorizzato, dato ritenuto corretto e motivo, **Then** viene conservata un'Annotazione di errore storico con autore, timestamp, sorgenti e Snapshot interessate, senza trasferimenti o riclassificazioni economiche.
2. **Given** un errore di Riporto, **When** viene registrata l'annotazione, **Then** il Riporto storico e la Snapshot di Chiusura restano invariati e nessun importo viene automaticamente applicato a un Esercizio successivo.
3. **Given** un Progetto erroneamente Chiuso o Cancellato, **When** viene annotato l'errore, **Then** lo stato storico resta invariato; l'eventuale nuova attività richiede una distinta transizione esplicita in un Esercizio Aperto.
4. **Given** un Esercizio chiuso accidentalmente, **When** viene annotato l'errore, **Then** l'Esercizio resta Chiuso e ogni effetto successivo appartiene a una distinta operazione nell'Esercizio Aperto dichiarato dall'utente.
5. **Given** un'altra Azienda o un utente privo di `corregge_esercizio_chiuso`, **When** viene tentata un'annotazione, **Then** nessun dato o evento viene persistito.

---

### User Story 3 - Consultare l'evidenza delle operazioni (Priority: P2)

Come utente con accesso in lettura, posso consultare ogni correzione tardiva e Annotazione nel proprio contesto storico, così comprendo cosa è emerso successivamente senza confonderlo con i valori immutabili della Chiusura.

**Why this priority**: La correzione è utile solo se resta spiegabile e non viene presentata come riscrittura dello storico. La reportistica comparativa completa resta nella slice S11.

**Independent Test**: Aprire un Esercizio Chiuso con correzioni e Annotazioni; verificare che ogni evidenza sia leggibile con motivo, autore, riferimenti e impatto dichiarato, mentre la Snapshot di Chiusura resta distinta e invariata.

**Acceptance Scenarios**:

1. **Given** correzioni tardive positive e negative, **When** l'utente consulta il contesto dell'Esercizio o della sorgente, **Then** vede ogni singola correzione con importo, motivo, autore, timestamp e riferimento originario, separata dalla Snapshot di Chiusura immutabile.
2. **Given** una o più Annotazioni di errore storico, **When** l'utente consulta il contesto interessato, **Then** le annotazioni sono separate dagli importi e mostrano dato registrato, dato ritenuto corretto, motivo, autore, timestamp, riferimenti ed evidenze.
3. **Given** una correzione o annotazione con allegato, **When** viene consultata successivamente, **Then** l'evidenza resta leggibile anche se la sorgente o il Fornitore è Archiviato.
4. **Given** un utente senza `visualizza` per l'Azienda, **When** tenta l'accesso diretto ai dettagli, **Then** la lettura viene rifiutata senza esposizione tra Aziende.

### Edge Cases

- Una correzione viene inviata mentre la sorgente o l'Esercizio cambia revisione tra conferma e persistenza.
- Una Riga compensativa rende il totale netto delle correzioni zero, ma le singole correzioni devono restare visibili.
- Una correzione positiva e una negativa riguardano la stessa Riga originaria.
- Il riferimento alla Riga originaria non è noto; la correzione resta ammessa con motivo e dichiarazione obbligatori.
- La Spesa storica è Stornata, di sistema o appartiene a una diversa sorgente di primo livello e quindi non è compatibile.
- Il Fornitore storico è Archiviato dopo la Chiusura.
- Un allegato opzionale viene aggiunto o consultato dopo che la sorgente o il Fornitore è stato Archiviato.
- L'utente tenta di modificare o eliminare una correzione o annotazione già registrata.
- L'utente tenta di usare la correzione tardiva per cambiare Centro di Costo, Fornitore, Progetto, Contratto, contenitore, Esercizio, stato o Riporto storico.
- Un Esercizio Chiuso non possiede Budget ma possiede la Snapshot di Chiusura canonica.

## Requirements *(mandatory)*

### Functional Requirements

- **S10-FR-001**: Ogni correzione tardiva, Annotazione di errore storico, allegato ed evento associato MUST appartenere a una sola Azienda e MUST NOT essere letto o mutato attraverso un'altra Azienda.
- **S10-FR-002**: La lettura MUST richiedere `visualizza`; la creazione di una correzione tardiva o Annotazione MUST richiedere `corregge_esercizio_chiuso` per l'Azienda esatta e l'autorizzazione MUST essere rivalidata alla conferma.
- **S10-FR-003**: Il percorso di correzione post-Chiusura MUST operare soltanto su un Esercizio Chiuso e MUST NOT riaprirlo.
- **S10-FR-004**: Una correzione tardiva di importo MUST aggiungere soltanto nuove Righe Effettivo append-only e MUST NOT modificare, annullare, ripristinare o eliminare Righe storiche esistenti.
- **S10-FR-005**: Il sistema MUST consentire una nuova Spesa manuale tardiva con soli Effettivi, una nuova Riga Effettivo su una Spesa storica compatibile e una Riga compensativa positiva o negativa.
- **S10-FR-006**: Una correzione tardiva MUST NOT essere inserita nella Spesa Stima di sistema di un Contratto e MUST NOT introdurre Stime manuali in una Spesa di Contratto.
- **S10-FR-007**: Una Spesa storica è compatibile soltanto quando è Manuale, appartiene all'Esercizio Chiuso corretto e alla stessa sorgente economica di primo livello, non è Stornata, consente Effettivi e conserva il Fornitore storico disponibile anche se Archiviato.
- **S10-FR-008**: Quando la Spesa storica non è compatibile, il sistema MUST creare una nuova Spesa manuale tardiva nello stesso contenitore storico anziché modificare o riclassificare la Spesa originaria.
- **S10-FR-009**: Ogni correzione tardiva MUST richiedere motivo e dichiarazione esplicita che l'importo apparteneva realmente all'Esercizio Chiuso, e MUST registrare autore, timestamp e riferimento alla Riga originaria quando noto; l'allegato è opzionale.
- **S10-FR-010**: Un importo realmente sostenuto nell'Esercizio corrente MUST appartenere all'Esercizio corrente e MUST NOT essere registrato come correzione tardiva soltanto perché è commercialmente riferibile a un anno precedente.
- **S10-FR-011**: Una correzione tardiva MUST aggiornare soltanto l'Effettivo a Conoscenza Corrente attraverso il saldo delle nuove Righe Effettivo e MUST lasciare invariato l'Effettivo materializzato alla Chiusura.
- **S10-FR-012**: Correzioni tardive e Annotazioni MUST lasciare invariati Budget Approvati, Snapshot di Chiusura, Riporto consolidato, Stime, condizioni e scadenze contrattuali storiche, stato storico e ogni imputazione storica.
- **S10-FR-013**: Dopo la Chiusura il sistema MUST NOT cambiare Centro di Costo, Fornitore, Progetto, Contratto, contenitore, Esercizio o stato storico per correggere un errore di imputazione.
- **S10-FR-014**: Un errore di imputazione MUST essere rappresentato da un'Annotazione append-only contenente dato registrato, dato ritenuto corretto, autore, timestamp, motivo, allegati opzionali, sorgenti e Snapshot interessate.
- **S10-FR-015**: Un errore di Riporto MUST lasciare invariato il Riporto storico; qualsiasi effetto necessario in un Esercizio Aperto successivo MUST essere una distinta modifica esplicita del piano con Nota obbligatoria e MUST NOT essere etichettato come Riporto.
- **S10-FR-016**: Un Progetto erroneamente Chiuso o Cancellato MUST conservare lo stato storico; nuova attività in un Esercizio Aperto successivo MUST dipendere da una distinta transizione esplicita efficace in quell'Esercizio.
- **S10-FR-017**: Un Esercizio chiuso accidentalmente MUST restare Chiuso e MUST conservare l'Annotazione; nessuna correzione S10 può costituire una riapertura globale.
- **S10-FR-018**: Le evidenze associate a correzioni tardive e Annotazioni MUST essere conservate in modo immutabile o versionato e restare leggibili dopo Archivio o modifica delle sorgenti vive.
- **S10-FR-019**: Ogni mutazione S10 MUST registrare Timeline/audit append-only con Azienda, attore, timestamp, operazione, Esercizio e sorgenti interessate, valori precedenti e nuovi, impatto Effettivo, motivo, riferimento alla correzione o Annotazione ed eventuali evidenze.
- **S10-FR-020**: La persistenza di una singola correzione o Annotazione MUST essere atomica: fallimento di validazione, concorrenza o persistenza MUST lasciare zero Spese, Righe, annotazioni o eventi parziali.
- **S10-FR-021**: Correzioni e Annotazioni persistite MUST essere immutabili e MUST NOT avere azioni ordinarie di modifica o eliminazione fisica; un errore successivo richiede una nuova correzione o Annotazione.
- **S10-FR-022**: La consultazione locale MUST mostrare ogni singola correzione con importo, motivo, autore, timestamp, riferimenti ed evidenze, separata dalla Snapshot di Chiusura immutabile; aggregazioni comparative complete e report dell'Effettivo a Conoscenza Corrente restano in S11.
- **S10-FR-023**: Le Annotazioni di errore storico MUST essere mostrate separatamente dalle correzioni di importo e MUST NOT modificare totali o classificazioni.
- **S10-FR-024**: S10 MUST NOT introdurre reportistica comparativa completa, categorie `Previsto`/`Non previsto`, esportazioni, ricostruzione arbitraria nel tempo, matching, competenza contabile, riapertura, riclassificazione storica o ricalcolo di Snapshot e Riporti.

### Key Entities

- **Correzione tardiva**: Operazione append-only che aggiunge una nuova Riga Effettivo a una Spesa storica compatibile oppure crea una nuova Spesa manuale tardiva nello stesso contesto storico; conserva motivo, dichiarazione, autore, timestamp, eventuale Riga originaria ed evidenze.
- **Annotazione di errore storico**: Evidenza append-only senza impatto economico che conserva il dato registrato, il dato ritenuto corretto, il motivo e i riferimenti storici interessati.
- **Effettivo a Conoscenza Corrente**: Effettivo dell'Esercizio secondo le conoscenze attuali, pari all'Effettivo alla Chiusura più le correzioni tardive nette, senza riclassificazione dello storico.
- **Contesto storico della sorgente**: Esercizio, sorgente economica di primo livello, contenitore, Fornitore e classificazioni materializzati o persistiti che una correzione deve preservare.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Nel 100% dei casi testati, ogni correzione di importo aggiunge nuove Righe Effettivo e modifica zero Righe storiche esistenti.
- **SC-002**: Nel 100% dei casi testati, Snapshot di Chiusura, Budget, Riporto consolidato, Stime, stato e imputazioni storiche restano byte-per-byte o valore-per-valore invariati dopo correzioni e Annotazioni.
- **SC-003**: Ogni correzione positiva o negativa testata produce esattamente il previsto Effettivo a Conoscenza Corrente, incluso il caso di saldo netto zero con dettaglio non vuoto.
- **SC-004**: Ogni tipo canonico testato di errore di imputazione è registrabile come Annotazione leggibile e produce zero variazione economica.
- **SC-005**: Nel 100% dei tentativi non autorizzati, cross-company, su Esercizio Aperto o con contesto storico non valido, non resta alcun effetto parziale.
- **SC-006**: Un utente autorizzato può completare una correzione o Annotazione dal contesto dell'Esercizio Chiuso e ritrovarne motivo, autore, riferimenti ed evidenze senza ambiguità con i valori della Chiusura.
- **SC-007**: I test autoritativi degli invarianti 28.29, 28.30 e 28.31 falliscono se vengono consentite modifiche storiche, riclassificazioni o ricalcolo del Riporto.
- **SC-008**: Controlli mirati, quality gate del repository, avvio applicativo e dimostrazione autenticata delle due operazioni principali passano prima che S10 sia marcata `verified`.

## Assumptions

- S9 fornisce Esercizi irreversibilmente Chiusi, Snapshot di Chiusura autonome e valori materializzati dell'Effettivo alla Chiusura.
- Le normali operazioni sugli Esercizi Aperti restano i percorsi canonici per importi sostenuti nell'anno corrente e per eventuali effetti futuri espliciti.
- S10 implementa la creazione e la consultazione locale di correzioni e Annotazioni; S11 resta responsabile della reportistica comparativa completa e delle esportazioni.
- Non viene introdotto un nuovo concetto di competenza contabile né un motore di riclassificazione storica.
- Tutto il comportamento richiesto deriva direttamente dai §§6.10–6.12, 14.9, 21.4, 22, 23.12, 24 e invarianti 28.29–28.31; non emerge un caso strutturale di categoria E.