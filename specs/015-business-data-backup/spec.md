# Feature Specification: MP2 Business Data Backup

**Feature Branch**: `015-business-data-backup`

**Created**: 2026-08-30

**Status**: Complete

**Input**: Business Data Backup versionato di una singola Azienda in un file XLSX leggibile e ripristinabile come nuova Azienda semanticamente equivalente.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Scaricare un backup aziendale leggibile (Priority: P1)

Come utente autorizzato alla lettura completa di un Tenant operativo, posso scaricare un singolo file XLSX che conserva il patrimonio informativo dell'Azienda e che resta comprensibile senza MP2.

**Why this priority**: l'artefatto leggibile e completo è il valore primario della feature e il prerequisito del restore.

**Independent Test**: generare il backup di un'Azienda minimale e di un'Azienda rappresentativa, aprire i fogli visibili e verificare manifest, viste business, fogli macchina nascosti, conteggi e valori economici.

**Acceptance Scenarios**:

1. **Given** un Tenant attivo e un utente con `visualizza`, **When** richiede il backup, **Then** riceve `MP2-<Azienda>-<data>.xlsx` completo, integro e generato da uno stato coerente dell'Azienda.
2. **Given** dati testuali che iniziano con caratteri interpretabili come formule, **When** il file viene aperto, **Then** i testi restano dati e nessuna formula proveniente dal dominio viene eseguita.
3. **Given** testo o contenuto materializzato oltre il limite di una cella, **When** il backup viene generato, **Then** nessun contenuto viene troncato e il valore completo resta ricomponibile.
4. **Given** allegati aziendali, **When** il backup viene generato, **Then** il file contiene il loro inventario leggibile ma non i binari né riferimenti allo storage locale.
5. **Given** un utente privo di accesso o un Tenant archiviato, **When** tenta l'export, **Then** l'operazione viene rifiutata senza esporre dati.

---

### User Story 2 - Validare e vedere l'anteprima di un restore (Priority: P1)

Come Platform Admin posso caricare un backup MP2, verificarne formato, versione, integrità e coerenza prima di qualsiasi scrittura e vedere un'anteprima comprensibile della nuova Azienda.

**Why this priority**: un restore non deve iniziare da un artefatto corrotto, modificato o semanticamente incoerente.

**Independent Test**: caricare un package valido e varianti con manifest, checksum, riferimenti, decimal, enum e totali alterati; soltanto il package valido deve raggiungere l'anteprima.

**Acceptance Scenarios**:

1. **Given** un workbook V1 valido, **When** il Platform Admin lo carica, **Then** vede Azienda sorgente, data, formato, Esercizi, conteggi, totali, allegati non ripristinabili e warning sul nome duplicato.
2. **Given** un workbook non MP2, futuro, incompleto, alterato o incoerente, **When** viene validato, **Then** il restore viene rifiutato prima di qualunque scrittura e senza correzioni automatiche.
3. **Given** un utente non Platform Admin, **When** tenta upload, preview o conferma, **Then** l'operazione viene negata.

---

### User Story 3 - Ripristinare come nuova Azienda (Priority: P1)

Come Platform Admin posso confermare il restore di un package valido per creare un nuovo Tenant attivo e una nuova Azienda economicamente e operativamente equivalente, senza importare utenti, permessi, audit o Proposte sorgente.

**Why this priority**: completa la garanzia di portabilità semantica del backup.

**Independent Test**: esportare Azienda A, importare in un database pulito come Azienda B e confrontare semanticamente dominio, storico materializzato e report canonici, ignorando esclusivamente gli elementi dichiarati fuori perimetro.

**Acceptance Scenarios**:

1. **Given** un package V1 valido e confermato, **When** il restore termina, **Then** esiste un solo nuovo Tenant attivo con nuova Azienda, riferimenti locali ricostruiti e normali capability iniziali assegnate soltanto al Platform Admin importatore.
2. **Given** un errore in qualunque fase di persistenza o verifica finale, **When** il restore fallisce, **Then** non resta visibile alcuna Azienda parziale.
3. **Given** lo stesso `package_id` già importato con successo, **When** il restore viene ritentato, **Then** viene restituito il risultato esistente e non nasce una seconda Azienda.
4. **Given** un'Azienda esistente con la stessa denominazione, **When** il restore viene confermato, **Then** viene creata comunque una nuova identità dopo il warning, senza merge o matching.

---

### User Story 4 - Continuare a operare dopo il restore (Priority: P1)

Come utente della nuova istanza posso continuare i normali processi MP2 dai dati ripristinati senza duplicare Stime contrattuali, perdere la reversibilità della Riprogrammazione o dipendere da Proposte e utenti sorgente.

**Why this priority**: la sola leggibilità storica non soddisfa l'equivalenza operativa richiesta.

**Independent Test**: dopo il restore ricalcolare un Contratto, invertire una Riprogrammazione attiva e creare/approvare una Revisione da un Budget importato.

**Acceptance Scenarios**:

1. **Given** una Stima di sistema contrattuale importata, **When** avviene il normale ricalcolo, **Then** rimane una sola Spesa di sistema per Contratto/Esercizio e i totali non raddoppiano.
2. **Given** una Riprogrammazione attiva importata, **When** la modalità viene cambiata o annullata, **Then** vengono toccate soltanto le righe dell'effetto importato e restano validi gli stessi blocchi per modifiche indipendenti.
3. **Given** un Budget importato vN privo di Proposal locale, **When** viene creata e approvata una Revisione locale, **Then** nasce vN+1 e tutti i Budget importati restano immutabili.
4. **Given** record storici senza autore disponibile, **When** vengono consultati, **Then** la UI mostra un'indicazione neutra e le nuove operazioni locali continuano a registrare l'autore locale.

---

### User Story 5 - Salvare lo stesso backup su Drive o da comando (Priority: P2)

Come utente autorizzato posso salvare sul disco Google Drive configurato lo stesso XLSX prodotto dal motore; come operatore posso invocare lo stesso servizio da comando e scheduler senza definire in questa feature una cadenza o retention automatica.

**Why this priority**: offre una destinazione remota e rende il motore automatizzabile senza introdurre sincronizzazione.

**Independent Test**: confrontare il contenuto del file scaricato con quello scritto su un fake disk Drive e invocare il comando per una singola Azienda.

**Acceptance Scenarios**:

1. **Given** un disk Drive configurato, **When** l'utente salva il backup, **Then** viene scritto lo stesso XLSX completo senza conversione in Google Sheet.
2. **Given** un disk Drive non configurato, **When** viene mostrata la pagina Backup dati, **Then** l'azione Drive non è disponibile e il download locale resta utilizzabile.
3. **Given** un Tenant archiviato, **When** comando o scheduler tentano il backup, **Then** il dominio non viene letto né modificato.

### Edge Cases

- Azienda senza Esercizi o dati economici.
- Effettivi non zero che si compensano a saldo netto zero; righe annullate e Spese stornate.
- Testi UTF-8 molto lunghi, JSON materializzato lungo e celle che iniziano con `=`, `+`, `-` o `@`.
- Workbook rinominato, fogli visibili modificati, fogli macchina modificati, fogli canonici mancanti o aggiunti.
- `package_ref` duplicati, riferimenti orfani/cross-company e lineage circolare o inesistente.
- Esercizio Chiuso senza Chiusura, Snapshot con Budget errati, correzione o annotazione priva del proprio contesto storico.
- Due Stime di sistema per lo stesso Contratto/Esercizio o Spesa con appartenenza incompatibile.
- Riprogrammazione con effetti incompleti o metadata non riconciliabili.
- Collisione di denominazione Azienda e retry concorrenti dello stesso package.
- Errore di filesystem durante creazione temporanea o copia su Drive.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-BDB-001**: Il Business Data Backup MUST rappresentare una sola Azienda e garantire equivalenza semantica dei dati aziendali, non uguaglianza tecnica dell'istanza.
- **FR-BDB-002**: Il backup MUST includere ogni informazione che modifica valori economici o il loro calcolo, classificazione, interpretazione temporale o comportamento futuro, oltre alle anagrafiche e agli storici materializzati necessari.
- **FR-BDB-003**: Il backup MUST escludere utenti, credenziali, MFA, sessioni, capability sorgente, Audit, Proposte e relative entità, dati tecnici di queue/cache, `PendingFileDeletion`, percorsi storage, ID locali, revision token tecnici, operation UUID e timestamp ordinari senza significato di dominio.
- **FR-BDB-004**: Date e timestamp di dominio MUST essere preservati; le date MUST restare date e i timestamp MUST usare una rappresentazione ISO 8601 non ambigua.
- **FR-BDB-005**: V1 MUST produrre un solo file `MP2-<Azienda>-<data>.xlsx` con `format_version=1`; ZIP, JSON, CSV e dump database MUST NOT essere formati canonici alternativi.
- **FR-BDB-006**: Il workbook MUST contenere manifest diagnostico con package UUID, export time, versione applicativa se disponibile, Azienda, timezone, EUR netto IVA, conteggi e checksum SHA-256 del contenuto canonico dei fogli macchina.
- **FR-BDB-007**: Il workbook MUST contenere fogli visibili Informazioni, Riepilogo per Esercizio, Budget, Spese, Progetti, Contratti, Fornitori, Centri di Costo, Chiusure, Correzioni/Annotazioni e inventario allegati.
- **FR-BDB-008**: Le viste visibili MUST mostrare valori materializzati e spiegare che il file è consultabile, che l'analisi va svolta su una copia e che modificare il backup invalida il restore garantito.
- **FR-BDB-009**: Il contratto di restore MUST risiedere in fogli `_MP2_*` nascosti, normalizzati, deterministici, versionati, con nomi e intestazioni inglesi `snake_case` stabili e indipendenti da database, `$fillable` e classi applicative.
- **FR-BDB-010**: Ogni entità e relazione portabile MUST usare `package_ref` deterministici validi soltanto nel package; gli ID database sorgente MUST NOT essere usati come identità o matching.
- **FR-BDB-011**: V1 MUST definire e mantenere stabili prefissi e ordinamento dei riferimenti portabili per Azienda, Esercizi, anagrafiche, Progetti, Contratti, Spese, Righe, transizioni, condizioni, lifecycle, classificazioni, rinvii, Budget, Chiusure, correzioni, annotazioni e allegati inventariati.
- **FR-BDB-012**: Il restore MUST risolvere deterministicamente `package_ref` nei nuovi ID locali e ricostruire `OriginKey`, `CopiedFromOriginKey`, riferimenti delle Snapshot, correzioni, annotazioni e Riprogrammazioni senza fuzzy matching.
- **FR-BDB-013**: Azienda, impostazioni, Fornitori, contatti, Centri di Costo e stati Archivio MUST essere preservati integralmente nei loro dati business.
- **FR-BDB-014**: Esercizi, Spese e Righe MUST preservare anno/stato, appartenenza, origine, Storno, tipo, importi, quantità, unitario, unità, note, annullamenti e presenza semantica degli Effettivi a saldo netto zero.
- **FR-BDB-015**: Progetti MUST preservare dati descrittivi, stato iniziale, Archivio, transizioni, efficacia, annullamenti, classificazioni annuali, Spese, collegamenti e rinvii.
- **FR-BDB-016**: I rinvii MUST preservare modalità, esercizi, importi e stato; una Riprogrammazione attiva MUST preservare in forma portabile gli effetti minimi necessari alla verifica e inversione successiva, ricostruendo revision metadata coerenti con i nuovi record.
- **FR-BDB-017**: Contratti MUST preservare dati descrittivi, Fornitore, date, rinnovo e storico configurazioni, condizioni, ciclo, attribuzione, lifecycle, annullamenti, classificazioni, Spese e comportamento economico futuro senza riesecuzione retroattiva.
- **FR-BDB-018**: Le Spese di sistema dei Contratti MUST essere esportate e ripristinate una sola volta; il restore MUST NOT rigenerarle e il successivo ricalcolo MUST aggiornarle senza duplicati.
- **FR-BDB-019**: Le relazioni `Collegato a` MUST preservare Progetto, Contratto, Nota e Archivio senza acquisire significato economico.
- **FR-BDB-020**: Il backup MUST includere Budget approvati e MUST escludere Proposal, ProposalItem e ProposalAction.
- **FR-BDB-021**: Ogni Budget MUST preservare Esercizio, versione, purpose, approvazione, predecessore, totale, righe e dettaglio business materializzato richiesto da reporting e drill-down, senza `proposal_id`, ProposalItem UUID, audit o operation UUID canonici.
- **FR-BDB-022**: Il dettaglio Budget V1 MUST essere un contratto portabile esplicito e MUST NOT serializzare ciecamente il payload tecnico corrente.
- **FR-BDB-023**: Un Budget importato MUST poter esistere senza Proposal locale e diventare predecessore di una nuova Revisione locale.
- **FR-BDB-024**: La Snapshot di Chiusura MUST essere ripristinata come fotografia originale materializzata, inclusi riferimenti Budget portabili, valori, righe, warning, impostazioni e decisione N+1; MUST NOT essere ricalcolata dalla realtà viva.
- **FR-BDB-025**: Correzioni tardive e Annotazioni di errore storico MUST essere preservate anche a impatto zero, mantenendo distinzione fra Chiusura e Conoscenza Corrente e remappando soltanto riferimenti esplicitamente portabili.
- **FR-BDB-026**: Gli allegati binari MUST NOT essere ripristinati; il workbook MUST inventariarne proprietario, nome, media type, dimensione, SHA-256 e stato, escludendo disk, path, uploader e detacher.
- **FR-BDB-026A**: Il logo aziendale, dato binario di presentazione, MUST NOT essere incluso né ripristinato dal formato V1; l'interfaccia MUST dichiarare che va riconfigurato dopo il restore.
- **FR-BDB-027**: Le informazioni business di BudgetEvidence non legate al file MUST essere preservate quando applicabili, senza creare Attachment fittizi.
- **FR-BDB-028**: Le FK autore dei record importabili MAY essere assenti soltanto per fatti importati; le normali operazioni locali MUST continuare a valorizzare l'autore e la UI MUST rappresentarne neutralmente l'assenza.
- **FR-BDB-029**: Soltanto un Platform Admin MUST poter validare, vedere l'anteprima e confermare l'import.
- **FR-BDB-030**: V1 MUST importare esclusivamente come nuova Azienda e nuovo Tenant attivo; merge, import in Azienda esistente, upsert, sync, deduplicazione e conflict resolver MUST NOT esistere.
- **FR-BDB-031**: Il Platform Admin importatore MUST ricevere le normali capability iniziali della nuova Azienda; nessun altro utente o capability sorgente MUST essere importato o assegnato.
- **FR-BDB-032**: La collisione di denominazione MUST produrre un warning ma MUST NOT dedurre identità né impedire la nuova Azienda.
- **FR-BDB-033**: Validazione completa, preview e conferma MUST precedere una singola transazione che crea Azienda, importa dipendenze, ricostruisce riferimenti e Snapshot, verifica il risultato e crea Tenant/capability.
- **FR-BDB-034**: Qualunque errore di import MUST produrre rollback completo; foreign key checks MUST NOT essere disabilitati globalmente e le normali Actions riferite a oggi MUST NOT essere usate per riprodurre lo storico.
- **FR-BDB-035**: Ogni package MUST avere `package_id` UUID univoco; un retry dopo successo MUST restituire la precedente Azienda e non duplicarla.
- **FR-BDB-036**: I checksum MUST coprire una serializzazione canonica deterministica dei fogli macchina e l'import MUST ricalcolarli e confrontarli prima di ogni write; V1 MUST NOT implementare firme o PKI.
- **FR-BDB-037**: Importi, quantità e unitari nei fogli macchina MUST essere stringhe decimali canoniche validate per sintassi e scala prima della conversione.
- **FR-BDB-038**: Tutti i testi MUST essere scritti con tipo esplicito e non essere interpretati come formule; i fogli macchina MUST NOT contenere formule.
- **FR-BDB-039**: Testi e JSON oltre il limite XLSX MUST usare chunk ordinati sotto il limite con margine di sicurezza e MUST essere ricomposti byte-per-byte in UTF-8 senza troncamento.
- **FR-BDB-040**: L'export MUST leggere uno stato coerente dell'Azienda, generare un artefatto temporaneo completo e pubblicarlo soltanto dopo successo.
- **FR-BDB-041**: L'import MUST rifiutare prima delle scritture formato/versione/manifest/fogli invalidi, checksum errati, riferimenti duplicati/orfani/cross-company, enum o decimal invalidi, lineage Budget incoerente, Chiusure e correzioni incoerenti, relazioni incompatibili, duplicate Stime di sistema, Riprogrammazioni incomplete e totali non riconciliati.
- **FR-BDB-042**: L'equivalenza round-trip MUST confrontare l'intero patrimonio incluso e tutti i report canonici S11, ammettendo differenze soltanto per utenti, audit, Proposte, binari allegati, ID e altri dati tecnici esclusi.
- **FR-BDB-043**: Il Tenant operativo MUST offrire `Backup dati` con download e, solo quando configurata, scrittura Drive riutilizzando l'autorizzazione `visualizza` senza nuova capability.
- **FR-BDB-044**: Il pannello piattaforma MUST offrire `Importa Azienda da backup` soltanto ai Platform Admin e mostrare prima della conferma manifest, Esercizi, conteggi, totali, allegati non ripristinabili, collisione nome e warning.
- **FR-BDB-045**: Google Drive MUST essere soltanto una destinazione immutabile dello stesso XLSX; nessuna conversione Sheet, lettura di modifiche, sincronizzazione, retention o cancellazione automatica è ammessa.
- **FR-BDB-046**: Il motore di export MUST essere riutilizzabile da UI, comando e scheduler, senza introdurre frequenza, orario o retention non definiti.
- **FR-BDB-047**: La release e il wizard shared-hosting MUST dichiarare e verificare tutte le estensioni PHP realmente richieste dal formato XLSX e dal disk Drive configurabile.
- **FR-BDB-048**: Il Business Data Backup MUST restare distinto dal PDF reporting, dal disaster recovery tecnico, da template Excel modificabili e da futuri bundle con file.

### Key Entities *(include if feature involves data)*

- **Business Backup Package**: singolo workbook versionato relativo a un'Azienda, identificato da package UUID, manifest, conteggi e checksum.
- **Portable Reference**: identità deterministica interna al package che sostituisce ogni ID database sorgente nelle relazioni portabili.
- **Machine Sheet Contract**: insieme V1 stabile di fogli, colonne, enum e regole di serializzazione usato simmetricamente da export e restore.
- **Long Payload Chunk**: porzione ordinata di un testo o JSON canonico troppo lungo per una cella XLSX.
- **Backup Import Journal**: risultato tecnico univoco per `package_id`, necessario esclusivamente a rendere idempotente la creazione della nuova Azienda.
- **Attachment Inventory Entry**: descrizione non ripristinabile di un allegato aziendale senza coordinate storage o autore.
- **Imported Historical Fact**: fatto business valido il cui autore originale non è disponibile nella nuova istanza.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Il 100% dei dataset obbligatori completa il round-trip con zero differenze semantiche nel patrimonio incluso.
- **SC-002**: Il 100% dei report canonici S11 generati prima e dopo il round-trip coincide per totali, sorgenti, categorie, dimensioni, etichette, stati, classificazioni, rinvii, correzioni e annotazioni.
- **SC-003**: Il 100% dei workbook corrotti o incoerenti nei casi obbligatori viene rifiutato prima della prima scrittura persistente.
- **SC-004**: Ogni errore iniettato durante l'import lascia zero nuovi record aziendali visibili e il retry dello stesso package riuscito lascia esattamente una Azienda.
- **SC-005**: Dopo il restore, inversione Riprogrammazione, ricalcolo Contratto e Revisione vN+1 producono lo stesso risultato economico dei flussi locali equivalenti e zero duplicati.
- **SC-006**: Il 100% dei testi lunghi e dei valori UTF-8 di prova viene ricostruito identicamente e il 100% dei testi formula-like resta testo.
- **SC-007**: Un utente autorizzato può produrre e scaricare il backup di un'Azienda rappresentativa senza selezionare tabelle o opzioni di mapping; un Platform Admin può completarne l'anteprima senza scritture.
- **SC-008**: Il file scritto su Drive è byte-identico all'artefatto generato dal motore e resta XLSX.
- **SC-009**: Nessun test di restore crea utenti, capability sorgente, Audit, Proposal o Attachment fittizi.
- **SC-010**: Il quality gate completo e la verifica shared-hosting restano verdi con le estensioni runtime aggiornate.

## Assumptions

- Il formato V1 è definito dal contratto esplicito della feature e può essere esteso soltanto con un nuovo `format_version` compatibile.
- La versione applicativa diagnostica usa la revisione resa disponibile dal packaging corrente; la sua assenza non rende invalido il package.
- L'Azienda sorgente è sempre raggiungibile tramite un Tenant attivo; il backup non amplia l'accesso ai Tenant archiviati.
- Il disk Drive è configurato esternamente secondo il normale filesystem Laravel; questa feature non aggiunge una UI per credenziali o OAuth.
- Non è definito un limite business massimo di record; V1 privilegia correttezza e artefatto coerente, con verifica su dataset rappresentativi del prodotto corrente.
- Il workbook è un backup consultabile, non un'interfaccia di importazione generica o un documento destinato all'editing.

## Dependencies and Domain Traceability

- Canonical §§4-26, 30-32: dati economici, storico, immutabilità, autorizzazione, Tenant e rimozione di `Sostituisce`.
- Slice 002-014: modello persistente e comportamento implementato di Azienda, anagrafiche, Esercizi/Spese, Progetti, Contratti, Budget/Revisioni, rinvii, Chiusura, correzioni, reporting, Tenant e release shared-hosting.
- Category B/C: la portabilità usa le primitive e gli storici esistenti senza aggiungere nuove regole economiche; l'inventario allegati è informativo.
- Nessuna lacuna Category E è stata individuata: le sole nuove persistenze sono il journal tecnico di import e la nullabilità controllata degli autori storici importabili.
