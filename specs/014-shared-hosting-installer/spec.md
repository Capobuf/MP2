# Feature Specification: Installazione shared hosting e release ZIP

**Feature Branch**: `014-shared-hosting-installer`

**Created**: 2026-08-28

**Status**: Ready for Implementation

**Input**: User description: "Distribuire MP2 su hosting condiviso tramite un archivio ZIP generato dalla CI a ogni push e consentire una prima installazione completa da Web UI, usando un installer Laravel esistente e solo integrazioni MP2 proporzionate. Il wizard deve configurare istanza, database, amministratore e scheduler; deve permettere di azzerare esplicitamente un database non vuoto; l'artefatto deve essere già predisposto per una successiva CI di aggiornamento della produzione."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Installare una nuova istanza dalla Web UI (Priority: P1)

Un amministratore dell'hosting carica l'archivio di MP2, configura il dominio affinché punti alla directory pubblica dell'applicazione e apre il sito. Finché l'istanza non è installata, l'applicazione lo guida in italiano attraverso un wizard che verifica i requisiti, raccoglie solo la configurazione necessaria e porta l'istanza a uno stato operativo senza richiedere Composer, Node, Git o comandi applicativi manuali sull'host.

**Why this priority**: È il valore principale della feature: trasformare il deploy iniziale da procedura tecnica manuale a installazione guidata e ripetibile.

**Independent Test**: Su una directory pulita contenente il solo archivio di release estratto, con un database MySQL già creato e un hosting conforme al contratto, l'operatore completa il wizard dal browser e raggiunge il login di Master Plan IT senza installare dipendenze o compilare asset sull'host.

**Acceptance Scenarios**:

1. **Given** una release appena estratta e l'istanza non ancora installata, **When** l'operatore apre una route applicativa, **Then** viene indirizzato al wizard di installazione.
2. **Given** un host sul quale Laravel riesce a fare bootstrap ma manca un requisito controllabile dal wizard, **When** l'operatore apre il wizard, **Then** vede quale requisito non è soddisfatto e non può proseguire oltre il controllo.
3. **Given** un host conforme, **When** l'operatore configura URL pubblico e credenziali MySQL valide, **Then** la connessione viene verificata prima di qualsiasi modifica al database.
4. **Given** un database vuoto e valido, **When** l'operatore completa l'installazione, **Then** lo schema MP2 viene creato senza dati demo o account di test.
5. **Given** un'installazione completata, **When** una richiesta successiva raggiunge `/install`, **Then** il wizard non è più eseguibile.
6. **Given** un'installazione completata, **When** l'operatore apre il login, **Then** l'applicazione risponde con l'identità `Master Plan IT`.

---

### User Story 2 - Azzerare consapevolmente un database non vuoto (Priority: P1)

Se il database scelto contiene già tabelle, il wizard non assume che possano essere eliminate. L'operatore può interrompere l'installazione oppure autorizzare esplicitamente la cancellazione dell'intero contenuto del database e proseguire su un database nuovamente vuoto.

**Why this priority**: La possibilità di riutilizzare un database è richiesta, ma la distruzione involontaria di dati sarebbe il failure mode più grave della feature.

**Independent Test**: Con un database contenente tabelle sentinella, il wizard non elimina nulla senza conferma; con conferma distruttiva corretta elimina il contenuto, verifica il risultato e può completare l'installazione.

**Acceptance Scenarios**:

1. **Given** un database non vuoto, **When** l'operatore arriva alla preparazione database, **Then** il wizard segnala chiaramente che esistono dati e non esegue automaticamente alcun reset.
2. **Given** un database non vuoto, **When** l'operatore non fornisce la conferma distruttiva richiesta, **Then** nessuna tabella, vista o dato viene eliminato.
3. **Given** un database non vuoto, **When** l'operatore conferma la distruzione digitando esattamente il nome del database, **Then** il wizard può eliminare l'intero contenuto di quel database.
4. **Given** un reset autorizzato, **When** la cancellazione termina, **Then** il wizard verifica che il database sia vuoto prima di eseguire le migrazioni MP2.
5. **Given** un errore durante le migrazioni dopo che il database è stato verificato vuoto o azzerato esplicitamente, **When** l'installazione fallisce, **Then** lo schema parziale resta visibile, nessun cleanup automatico viene eseguito e un nuovo tentativo richiede di tornare alla preparazione database e autorizzare nuovamente il reset se lo schema non è più vuoto.
6. **Given** credenziali che permettono la connessione ma non la distruzione richiesta, **When** l'operatore conferma il reset, **Then** l'operazione fallisce esplicitamente senza dichiarare il database pronto.

---

### User Story 3 - Creare l'amministratore e configurare lo scheduler (Priority: P1)

Durante l'installazione l'operatore crea il primo amministratore della piattaforma usando il controllo password nativo dell'installer. Prima della chiusura del wizard riceve una schermata dedicata allo scheduler con la riga cron già costruita sul percorso reale dell'istanza e può copiarla nel pannello hosting.

**Why this priority**: Senza il primo amministratore l'istanza non è governabile; senza scheduler i rinnovi automatici dei contratti e la pulizia differita dei file tenant non funzionano correttamente.

**Independent Test**: Il wizard crea un utente che può accedere al pannello piattaforma, mostra il comando scheduler corretto per il path installato e impedisce la finalizzazione finché l'operatore non conferma di aver configurato il cron.

**Acceptance Scenarios**:

1. **Given** le migrazioni completate, **When** l'operatore inserisce nome, e-mail, password e conferma accettati dall'installer, **Then** viene creato il primo utente amministratore della piattaforma.
2. **Given** credenziali amministratore non accettate dalla validazione nativa dell'installer, **When** l'operatore tenta di proseguire, **Then** l'utente non viene creato.
3. **Given** l'amministratore creato, **When** viene mostrato lo step scheduler, **Then** il wizard presenta una riga crontab completa con frequenza ogni minuto, percorso reale di `artisan` e comando PHP CLI suggerito.
4. **Given** un pannello hosting che separa frequenza e comando, **When** l'operatore apre lo step scheduler, **Then** dispone anche del solo comando applicativo da copiare.
5. **Given** un host nel quale il binario PHP CLI usa un nome differente, **When** l'operatore modifica il comando PHP suggerito, **Then** le stringhe da copiare si aggiornano senza modificare altre impostazioni dell'applicazione.
6. **Given** lo scheduler non ancora confermato, **When** l'operatore tenta di completare l'installazione, **Then** il wizard resta aperto.
7. **Given** la conferma scheduler, **When** l'installazione viene finalizzata, **Then** viene stabilita una chiave applicativa definitiva, l'istanza viene marcata come installata e l'operatore viene indirizzato al login.
8. **Given** il primo amministratore senza alcuna Azienda, **When** effettua il login operativo, **Then** può usare il flusso MP2 già esistente per creare la prima Azienda senza che il wizard duplichi tale funzionalità.
9. **Given** un tentativo di invocare direttamente la finalizzazione Livewire senza aver completato la pipeline, **When** il callback MP2 verifica il progresso server-side, **Then** rifiuta l'operazione senza creare il marker e senza dichiarare l'istanza installata.

---

### User Story 4 - Ottenere una release ZIP verificata a ogni push (Priority: P1)

A ogni push nel repository, dopo il superamento dei controlli di qualità, il proprietario del progetto dispone di un archivio ZIP identificabile e autosufficiente da scaricare e caricare sull'hosting. L'archivio contiene dipendenze runtime e asset compilati ma non materiale di sviluppo o stato appartenente a un'istanza già installata.

**Why this priority**: Il wizard è utile solo se l'artefatto distribuito contiene già tutto ciò che serve per avviarlo su un hosting privo di tool di sviluppo.

**Independent Test**: Un push valido produce un solo ZIP identificato da branch e commit; estraendo quello ZIP in una directory pulita si può avviare MP2 senza eseguire Composer o npm e la copia estratta supera uno smoke test di bootstrap e migrazione.

**Acceptance Scenarios**:

1. **Given** un push che supera il quality gate, **When** la CI termina, **Then** è disponibile un archivio ZIP di release.
2. **Given** un push che fallisce il quality gate, **When** la CI termina, **Then** non viene pubblicato un archivio dichiarato installabile.
3. **Given** un archivio prodotto, **When** viene ispezionato, **Then** contiene le dipendenze PHP runtime, gli asset frontend compilati, gli asset dell'installer, il front controller e la configurazione necessaria al primo bootstrap.
4. **Given** un archivio prodotto, **When** viene ispezionato, **Then** non contiene `node_modules`, suite di test, repository Git, tooling Docker/Sail, cache o dati di un'istanza già eseguita.
5. **Given** un archivio prodotto, **When** viene ispezionato, **Then** contiene un identificatore della revisione sorgente completa da cui è stato costruito.
6. **Given** due push diversi, **When** vengono prodotti i rispettivi archivi, **Then** nome file e revisione permettono di distinguerli senza inventare una numerazione applicativa.
7. **Given** una release appena costruita, **When** la CI ne estrae il contenuto in una directory pulita, **Then** la copia estratta fa bootstrap con sole dipendenze di produzione e rende raggiungibile il percorso di installazione.

### Edge Cases

- Il wizard deve restare inutilizzabile nelle normali esecuzioni `local` e `testing`, anche se `storage/installed` non esiste, così da non interrompere sviluppo e test esistenti.
- Una release nuova non deve contenere `storage/installed`; altrimenti una nuova istanza risulterebbe falsamente già installata.
- La configurazione iniziale inclusa nella release deve essere sufficiente a far partire Laravel prima che il wizard possa scrivere i valori definitivi.
- La chiave usata per il primo bootstrap non deve essere una chiave fissa del repository o un secret condiviso tra installazioni.
- Un database inesistente deve produrre un errore di connessione; il wizard non deve tentare di crearlo.
- Il nome dell'istanza resta `Master Plan IT` e non diventa una configurazione white-label.
- Il wizard supporta MySQL come motore di installazione. La CI certifica MySQL 8.4, ma il wizard non blocca una connessione MySQL solo perché il server dichiara una versione differente.
- Una versione MySQL non compatibile può comunque fallire nei controlli effettivi o nelle migrazioni; in tal caso l'installazione deve fermarsi esplicitamente.
- Il comando PHP CLI proposto dal wizard può differire dal comando richiesto dal provider; l'operatore deve poter correggere solo quella parte della stringa.
- La Web UI non può creare genericamente un cron nel pannello hosting e non deve fingere di averlo verificato: la conferma resta manuale.
- La Web UI non può cambiare versione PHP, installare estensioni, cambiare document root o creare genericamente il database nel pannello hosting; deve rilevare o comunicare i prerequisiti senza costruire integrazioni host-specifiche.
- Se PHP o un'estensione obbligatoria impediscono al platform check di Composer o a Laravel di avviarsi, il wizard non può diagnosticare il problema: l'operatore deve correggerlo nel pannello hosting. Non viene introdotto un bootstrap PHP parallelo.
- Un refresh ordinario durante il wizard deve sfruttare il meccanismo di ripresa dell'installer; non viene introdotto un secondo journal d'installazione persistente.
- La perdita completa della sessione prima della finalizzazione può richiedere di ripercorrere il wizard; qualsiasi reset database continua comunque a richiedere una nuova conferma distruttiva.
- La release prodotta in questa slice è una sorgente valida anche per la futura CI di aggiornamento, ma il semplice overwrite manuale di un'istanza già installata non è dichiarato un processo di update supportato.
- La futura CI di update dovrà preservare almeno configurazione dell'istanza e storage persistente; questa slice non implementa backup, maintenance mode, rollback o deploy atomico.
- Il wizard non richiede una password/token di installazione aggiuntiva.
- Non viene distribuito un `INSTALL.txt`: le istruzioni necessarie all'installazione appartengono al wizard e al normale pannello hosting.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-SH-001**: Ogni push al repository MUST eseguire il quality gate previsto dal progetto.
- **FR-SH-002**: Un push che supera il quality gate MUST produrre un archivio ZIP di release scaricabile; un push che non lo supera MUST NOT produrre un archivio dichiarato installabile.
- **FR-SH-003**: Il nome della release MUST identificare almeno il branch sorgente e una forma abbreviata del commit; l'archivio MUST contenere anche la revisione Git completa.
- **FR-SH-004**: L'archivio MUST essere autosufficiente per il runtime: il server di destinazione MUST NOT richiedere Composer, npm, Node, Git o Docker per completare la prima installazione.
- **FR-SH-005**: L'archivio MUST includere dipendenze PHP di produzione, asset frontend già compilati, asset necessari all'installer, file applicativi runtime e le directory vuote necessarie al funzionamento.
- **FR-SH-006**: L'archivio MUST NOT includere dipendenze frontend di sviluppo, test, metadata Git, tooling Docker/Sail/Tinker, il marker Vite `public/hot`, cache runtime o bootstrap, log runtime, dati caricati, sessioni pregresse, progressi di un installer precedente o il marker di installazione.
- **FR-SH-007**: La release MUST contenere una configurazione iniziale production-safe capace di far eseguire il primo bootstrap dell'applicazione.
- **FR-SH-008**: La configurazione iniziale MUST usare `Master Plan IT` come nome fisso e MUST NOT contenere credenziali di sviluppo o account amministratore predeterminati.
- **FR-SH-009**: Ogni release MUST ricevere una chiave di bootstrap casuale generata durante il build; tale chiave MUST NOT essere una chiave persistita nel repository.
- **FR-SH-010**: Finché l'istanza di produzione non è installata, le richieste web ordinarie MUST essere indirizzate al wizard; negli ambienti non production il normale sviluppo e testing MUST restare utilizzabili senza marker di installazione.
- **FR-SH-011**: Il wizard MUST essere presentato in italiano e MUST usare l'identità `Master Plan IT` senza riferimenti visibili al prodotto originario del plugin.
- **FR-SH-012**: Il wizard MUST NOT dipendere da risorse JavaScript o CSS esterne non necessarie per completare l'installazione.
- **FR-SH-013**: Quando Laravel può fare bootstrap, il wizard MUST verificare almeno la versione PHP minima richiesta da MP2, le estensioni runtime effettivamente necessarie e la scrivibilità delle directory/file necessari; MUST trattare `memory_limit=-1` come memoria illimitata valida.
- **FR-SH-014**: La versione PHP minima dichiarata dal wizard MUST essere 8.3; il controllo MUST accettare versioni successive compatibili.
- **FR-SH-015**: Il wizard MUST configurare solo le impostazioni necessarie alla prima istanza: URL pubblico, connessione MySQL e primo amministratore; MUST NOT introdurre configurazioni premature per SMTP, Redis, S3, queue worker o servizi non richiesti.
- **FR-SH-016**: Il wizard MUST supportare MySQL come database di installazione e MUST NOT presentare motori non certificati da questa slice.
- **FR-SH-017**: La CI MUST verificare l'installabilità contro MySQL 8.4; il wizard MUST NOT imporre un confronto rigido con la versione `8.4` come condizione per procedere.
- **FR-SH-018**: Il database MUST essere già esistente; il wizard MUST NOT tentare di creare database o utenti database nel server MySQL.
- **FR-SH-019**: Il wizard MUST verificare host, porta, database, username e password attraverso una connessione reale prima di persistere la configurazione come pronta; il submit MUST usare gli stessi valori correnti e MUST NOT azzerare la password tramite stato JavaScript separato.
- **FR-SH-020**: La scrittura della configurazione database MUST fallire esplicitamente se il file di configurazione dell'istanza non può essere salvato.
- **FR-SH-021**: Prima delle migrazioni il wizard MUST distinguere un database senza tabelle né view da un database che contiene almeno una tabella o view, usando la connessione MySQL effettivamente caricata dalla `.env` e non un target fidato dal solo stato Livewire.
- **FR-SH-022**: Un database vuoto MUST procedere alle migrazioni senza eseguire un reset distruttivo.
- **FR-SH-023**: Un database non vuoto MUST bloccare le migrazioni finché l'operatore non sceglie esplicitamente di azzerarlo.
- **FR-SH-024**: La conferma distruttiva MUST richiedere all'operatore di digitare esattamente il nome del database bersaglio e MUST mostrare chiaramente che l'intero contenuto verrà eliminato.
- **FR-SH-025**: Senza una conferma distruttiva valida il sistema MUST NOT eseguire `db:wipe` o un'operazione equivalente.
- **FR-SH-026**: Il reset autorizzato MUST eliminare tabelle e view tramite la connessione MySQL configurata e MUST verificare nuovamente l'assenza di entrambe prima di procedere.
- **FR-SH-027**: Lo step di migrazione MUST verificare nuovamente, immediatamente prima di `migrate`, che il database MySQL effettivamente configurato non contenga tabelle o view; MUST NOT fidarsi di un flag client-side o di una readiness riferita a un target diverso.
- **FR-SH-028**: In caso di migrazione fallita il sistema MUST riportare l'errore, MUST NOT eseguire cleanup o `db:wipe` automatici e MUST bloccare il retry finché il database non risulta nuovamente vuoto; se contiene lo schema parziale, il reset richiede una nuova conferma esatta del nome database.
- **FR-SH-029**: L'installazione MUST eseguire soltanto seed di produzione necessari; MUST NOT mostrare opzioni demo né creare dati demo, `Test User` o altri dati di sviluppo.
- **FR-SH-030**: Il wizard MUST usare il flusso amministratore dell'installer selezionato e conservarne la validazione password nativa.
- **FR-SH-031**: L'utente creato dal wizard MUST essere promosso a amministratore della piattaforma MP2 senza introdurre un sistema di ruoli parallelo.
- **FR-SH-032**: Il wizard MUST includere uno step dedicato allo scheduler prima della finalizzazione.
- **FR-SH-033**: Lo step scheduler MUST mostrare una riga crontab completa con frequenza `* * * * *`, comando PHP CLI e percorso reale del file `artisan`.
- **FR-SH-034**: Lo step scheduler MUST mostrare anche il solo comando applicativo per i pannelli hosting che gestiscono la frequenza separatamente.
- **FR-SH-035**: Il comando PHP CLI suggerito MUST essere modificabile dall'operatore senza richiedere una modifica manuale al resto della riga.
- **FR-SH-036**: Il wizard MUST NOT tentare di creare o verificare genericamente il cron attraverso API specifiche di un provider.
- **FR-SH-037**: L'installazione MUST NOT essere finalizzata finché l'operatore non conferma esplicitamente di aver configurato lo scheduler.
- **FR-SH-038**: Il callback MP2 di finalizzazione MUST rifiutare chiamate dirette o premature verificando il progresso server-side della pipeline, l'esistenza dell'amministratore piattaforma e la conferma scheduler; solo dopo tali controlli MUST generare sincronicamente e verificare una nuova APP_KEY valida per l'istanza prima del marker.
- **FR-SH-039**: La finalizzazione MUST creare e verificare un marker persistente di installazione, fallire esplicitamente se non può scriverlo e impedire alle richieste successive di rieseguire il wizard.
- **FR-SH-040**: Dopo la finalizzazione l'operatore MUST essere indirizzato al login MP2; la creazione della prima Azienda MUST restare responsabilità del flusso tenancy già esistente.
- **FR-SH-041**: La release MUST preservare un contratto compatibile con la futura automazione di update: configurazione dell'istanza e storage sono stato persistente e MUST poter essere sostituiti/preservati separatamente dal codice della release.
- **FR-SH-042**: Questa slice MUST NOT dichiarare supportato l'aggiornamento manuale in-place tramite semplice sovrascrittura dello ZIP su un'istanza esistente.
- **FR-SH-043**: Questa slice MUST NOT implementare backup, rollback, maintenance mode, promozione release o deploy atomico della futura CI di produzione.
- **FR-SH-044**: L'adozione dell'installer MUST avvenire tramite i suoi punti di estensione pubblici; il sorgente della dipendenza MUST NOT essere modificato o forkato come parte di MP2.
- **FR-SH-045**: I controlli automatici della slice MUST restare proporzionati e proteggere almeno: bootstrap del wizard, comportamento del database non vuoto, migrazione fallita senza cleanup, rifiuto della finalizzazione diretta, promozione del primo amministratore, lock post-installazione e integrità dell'archivio reale.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-SH-001**: Il 100% dei push che superano il quality gate produce esattamente un archivio di release identificabile e scaricabile; i push che falliscono il gate non producono una release dichiarata valida.
- **SC-SH-002**: Una release estratta su un host conforme può raggiungere e completare il wizard senza eseguire Composer, npm, Node, Git o Docker sul server.
- **SC-SH-003**: Nei test automatici un database non vuoto mantiene il 100% delle proprie tabelle e view quando la conferma distruttiva manca o è errata, e viene azzerato solo dopo conferma valida; una migrazione fallita non innesca cleanup automatico.
- **SC-SH-004**: Al termine dell'installazione il primo account creato può accedere al pannello piattaforma MP2 e non esistono account di test o demo creati dal processo.
- **SC-SH-005**: Il wizard presenta sempre una stringa scheduler completa e copiabile, derivata dal percorso reale dell'istanza, e non permette la finalizzazione senza la conferma manuale richiesta.
- **SC-SH-006**: Dopo la finalizzazione `/install` non può essere rieseguito e `/admin/login` risponde correttamente.
- **SC-SH-007**: Lo smoke test CI eseguito sulla copia realmente estratta dallo ZIP dimostra bootstrap, disponibilità del wizard e capacità di applicare le migrazioni con sole dipendenze di produzione.
- **SC-SH-008**: L'archivio validato non contiene `.git`, `node_modules`, test, Tinker, `public/hot`, cache bootstrap/config, marker `storage/installed`, progressi installer, sessioni/log/runtime data o credenziali di sviluppo.
- **SC-SH-009**: Sviluppo locale e test esistenti continuano a funzionare senza richiedere la presenza di `storage/installed`.

## Assumptions

- L'operatore possiede accesso al pannello hosting sufficiente per caricare/estrarre file, impostare il document root, creare un database MySQL e creare un cron.
- Il document root del dominio può essere impostato sulla directory `public` della release.
- PHP CLI 8.3 o successivo è disponibile, ma il nome/percorso esatto del binario può variare tra provider.
- La CI usa MySQL 8.4 come baseline certificata; altre versioni MySQL compatibili non vengono rifiutate per il solo numero di versione.
- Il database scelto è dedicato a MP2. Se non è vuoto, l'operatore accetta consapevolmente di distruggerne tutto il contenuto per riutilizzarlo.
- La protezione del wizard tramite password/token di installazione non fa parte della feature.
- `Master Plan IT` è il nome fisso dell'applicazione e non viene introdotto white-labeling.
- Queue sincrona, sessioni file, cache file e filesystem locale sono sufficienti per questa modalità di deploy iniziale.
- Lo scheduler Laravel è necessario per i processi automatici MP2 già esistenti.
- L'update automatico della produzione è la slice successiva. Questa feature prepara l'artefatto ma non implementa il processo di aggiornamento.
