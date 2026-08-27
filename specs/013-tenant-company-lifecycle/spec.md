# Feature Specification: Tenant Azienda e ciclo di vita

**Feature Branch**: `013-tenant-company-lifecycle`

**Created**: 2026-08-26

**Status**: Draft

**Input**: User description: "Separare il Tenant Azienda tecnico dall'Azienda di dominio, introdurre il ciclo di vita Attivo/Archiviato con Archivio, Ripristino e cancellazione definitiva Super Admin, sospendere i processi automatici, migrare i dati esistenti e sostituire Gestione continuata/terminata con Crea N+1/Non creare N+1."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Operare solo in Tenant attivi (Priority: P1)

Un utente autenticato vede e può selezionare esclusivamente i Tenant Azienda attivi per i quali possiede la capacità aziendale `visualizza`. Tutti i dati e le operazioni continuano a riferirsi all'Azienda di dominio collegata, senza confondere il contenitore tecnico con la radice economico-gestionale.

**Why this priority**: La separazione tra contenitore tecnico e dominio, insieme all'isolamento tenant, è la base di sicurezza per ogni altra storia.

**Independent Test**: Con due Tenant attivi collegati a due Aziende e capacità diverse, l'utente vede solo il Tenant autorizzato, non può indovinare l'URL dell'altro e ogni lettura o mutazione rimane confinata all'Azienda corretta.

**Acceptance Scenarios**:

1. **Given** un utente con `visualizza` per un'Azienda collegata a un Tenant attivo, **When** accede al pannello operativo, **Then** il Tenant è selezionabile e le risorse mostrano soltanto dati di quell'Azienda.
2. **Given** un utente senza `visualizza` per un'Azienda, **When** tenta l'accesso diretto al relativo Tenant o a un record tramite URL, **Then** la richiesta è negata senza esporre dati.
3. **Given** un Super Admin privo di capacità aziendale per una specifica Azienda, **When** tenta di usare il pannello operativo di quella Azienda, **Then** non ottiene un bypass delle `CompanyCapability`.
4. **Given** un database esistente con Aziende e dati di dominio, **When** viene applicata la migrazione, **Then** ogni Azienda ottiene esattamente un Tenant Azienda attivo e nessun dato o collegamento di dominio cambia proprietario.

---

### User Story 2 - Archiviare e ripristinare un Tenant (Priority: P1)

Un Super Admin può archiviare un Tenant attivo e ripristinare un Tenant archiviato. L'Archivio conserva integralmente dati, relazioni, cronologia e configurazione, ma rende il Tenant indisponibile agli utenti ordinari e blocca ogni operazione manuale o automatica. Il Ripristino riabilita l'accesso senza riscrivere date o stato del dominio.

**Why this priority**: L'Archivio è il meccanismo reversibile richiesto per sospendere un cliente senza alterarne la storia.

**Independent Test**: Dopo l'Archivio di un Tenant popolato, nessun utente ordinario può selezionarlo, leggerlo, scaricarne file o mutarlo e nessun processo automatico lo elabora; dopo il Ripristino gli stessi dati e riferimenti sono nuovamente disponibili invariati.

**Acceptance Scenarios**:

1. **Given** un Tenant attivo, **When** un Super Admin conferma l'Archivio, **Then** lo stato diventa `archived`, tutti i dati restano presenti e il Tenant scompare dal pannello operativo.
2. **Given** un Tenant archiviato, **When** un utente prova accesso tenant, route diretta, download o invocazione di un'operazione di dominio, **Then** l'accesso è negato indipendentemente dalle capacità aziendali conservate.
3. **Given** un Tenant archiviato con scadenze maturate, **When** viene eseguito un processo automatico globale, **Then** quel Tenant viene ignorato e non genera mutazioni o audit operativi.
4. **Given** un Tenant archiviato, **When** un Super Admin conferma il Ripristino, **Then** il Tenant torna `active`, dati e configurazioni sono identici a prima e nessuna data reale o stato di dominio viene spostato, compensato o ricalcolato implicitamente.
5. **Given** un utente non Super Admin, **When** tenta Archivio o Ripristino per qualsiasi via, **Then** l'operazione è negata.

---

### User Story 3 - Cancellare definitivamente un Tenant (Priority: P1)

Un Super Admin può cancellare definitivamente un Tenant attivo o archiviato soltanto dopo due conferme distinte e inequivocabili. La cancellazione elimina il Tenant, la relativa Azienda e tutti i dati, associazioni, allegati e audit di sua proprietà, senza eliminare gli account globali condivisi.

**Why this priority**: La distruzione definitiva ha il rischio più alto e deve essere completa, autorizzata, osservabile e priva di residui accessibili.

**Independent Test**: Un Tenant popolato con tutte le famiglie di dati e file viene eliminato dopo due conferme; il database non conserva dati tenant-owned, gli utenti globali restano, i file vengono eliminati o registrati per completamento affidabile e nessuna richiesta può più raggiungere il Tenant.

**Acceptance Scenarios**:

1. **Given** un Tenant attivo o archiviato, **When** il Super Admin fornisce soltanto la prima conferma, **Then** nessun dato viene eliminato.
2. **Given** un Tenant attivo o archiviato, **When** il Super Admin fornisce entrambe le conferme corrette, **Then** Tenant, Azienda e intero grafo tenant-owned vengono eliminati in un'unica transazione di database.
3. **Given** un errore nella cancellazione database, **When** la transazione fallisce, **Then** Tenant, Azienda, dati, associazioni e registrazioni di pulizia restano nello stato precedente e i file non vengono rimossi.
4. **Given** una cancellazione database completata e un errore temporaneo dello storage, **When** la rimozione di un file non riesce, **Then** il Tenant resta definitivamente inaccessibile, il lavoro residuo è osservabile e un nuovo tentativo può completarlo senza ripristinare dati eliminati.
5. **Given** utenti globali associati anche ad altri Tenant o soltanto a quello eliminato, **When** il Tenant viene cancellato, **Then** gli account `User` non vengono eliminati e le loro relazioni con altri Tenant restano valide.
6. **Given** un utente non Super Admin, **When** tenta una cancellazione definitiva per interfaccia o invocazione diretta, **Then** l'operazione è negata.

---

### User Story 4 - Amministrare il ciclo di vita come Super Admin (Priority: P1)

Un Super Admin dispone di una superficie di gestione globale che elenca Tenant attivi e archiviati, ne mostra lo stato e offre soltanto le azioni consentite sul ciclo di vita. Questa superficie resta raggiungibile anche quando non esiste alcun Tenant attivo.

**Why this priority**: Archivio, Ripristino e cancellazione devono poter essere governati senza dipendere dall'accesso a un Tenant operativo.

**Independent Test**: Un Super Admin senza capacità su alcuna Azienda accede alla gestione globale, filtra o individua Tenant attivi e archiviati ed esegue l'azione coerente con lo stato; un utente ordinario non accede alla superficie.

**Acceptance Scenarios**:

1. **Given** Tenant in entrambi gli stati, **When** un Super Admin apre la gestione globale, **Then** vede nome Azienda, stato e azioni coerenti con ciascun record.
2. **Given** nessun Tenant attivo, **When** un Super Admin accede alla gestione globale, **Then** può comunque ripristinare o cancellare un Tenant archiviato.
3. **Given** un utente privo di `is_platform_admin`, **When** tenta di accedere alla gestione globale o alle sue azioni, **Then** l'accesso è negato.
4. **Given** due Super Admin che tentano azioni incompatibili sullo stesso Tenant, **When** le richieste vengono elaborate, **Then** lo stato viene rivalidato e nessuna transizione impossibile o cancellazione parziale viene accettata.

---

### User Story 5 - Creare sempre la coppia Tenant/Azienda (Priority: P2)

Quando un Super Admin registra una nuova organizzazione, il sistema crea insieme il Tenant Azienda tecnico e l'Azienda di dominio, assegna al creatore le capacità aziendali previste e rende disponibile il nuovo Tenant attivo soltanto se l'intera operazione ha successo.

**Why this priority**: La relazione uno-a-uno deve valere anche per ogni creazione futura, non soltanto per i dati migrati.

**Independent Test**: La registrazione riuscita produce una sola coppia uno-a-uno attiva e le capacità iniziali; un errore in qualsiasi passaggio non lascia né Tenant né Azienda né capacità orfane.

**Acceptance Scenarios**:

1. **Given** un Super Admin, **When** registra una nuova organizzazione valida, **Then** vengono creati atomicamente un Tenant attivo, una Azienda collegata e le capacità iniziali del creatore.
2. **Given** un errore durante la registrazione, **When** l'operazione termina, **Then** nessuna parte della coppia o delle capacità iniziali resta persistita.
3. **Given** un utente non Super Admin, **When** tenta la registrazione, **Then** l'operazione è negata.
4. **Given** una registrazione non ancora persistita, **When** l'utente sceglie `Annulla`, **Then** il flusso viene abbandonato senza creare Tenant, Azienda o dati da conservare.

---

### User Story 6 - Decidere soltanto la creazione di N+1 (Priority: P2)

Durante la Chiusura di un Esercizio, l'utente sceglie esclusivamente `Crea N+1` oppure `Non creare N+1`. La seconda scelta non descrive la fine della gestione, non archivia il Tenant e non impedisce una successiva creazione manuale di N+1 se il Tenant resta attivo.

**Why this priority**: Rimuove un significato improprio dalla Chiusura e impedisce che il ciclo di vita tecnico venga dedotto da una decisione economico-temporale.

**Independent Test**: Scegliendo `Non creare N+1`, la Chiusura non crea l'Esercizio successivo ma lascia il Tenant attivo e permette di crearlo in seguito; nessuna UI, messaggio o dato persistito usa il concetto `Gestione terminata`.

**Acceptance Scenarios**:

1. **Given** N+1 assente, **When** l'utente sceglie `Crea N+1` e completa validamente la Chiusura, **Then** N+1 viene creato Aperto secondo le regole esistenti.
2. **Given** N+1 assente, **When** l'utente sceglie `Non creare N+1` e completa validamente la Chiusura, **Then** N+1 non viene creato e il Tenant conserva il proprio stato.
3. **Given** una Chiusura con `Non creare N+1` e Tenant attivo, **When** un utente autorizzato crea successivamente N+1, **Then** l'operazione è consentita dalle normali regole.
4. **Given** N+1 già esistente, **When** viene completata la Chiusura, **Then** la Snapshot continua a riferire l'Esercizio esistente senza presentare una scelta inutile.

### Edge Cases

- Un'Azienda esistente priva di qualsiasi dato di dominio deve ricevere comunque un unico Tenant attivo durante la migrazione.
- Un record `CompanyCapability` resta conservato durante Archivio/Ripristino, ma non può rendere accessibile un Tenant archiviato.
- L'Archivio di un Tenant già archiviato e il Ripristino di un Tenant già attivo devono fallire esplicitamente senza effetti collaterali.
- Una richiesta già iniziata mentre un altro processo archivia il Tenant deve rivalidare lo stato al confine della mutazione e non produrre una modifica successiva all'Archivio.
- Un processo automatico che seleziona un Tenant attivo ma lo trova archiviato prima della mutazione deve interrompere quel Tenant senza bypassare il nuovo stato.
- Il Ripristino successivo a un lungo periodo di Archivio non sposta scadenze, date economiche, Esercizi, configurazioni o fatti; i processi riprendono usando il calendario reale e le regole di recupero già esistenti.
- I percorsi di download diretti devono negare file di un Tenant archiviato anche se il record e il file esistono ancora.
- Più riferimenti database possono descrivere lo stesso file fisico; la cancellazione deve deduplicare il lavoro di storage.
- Un file già assente al momento della pulizia definitiva deve essere considerato completato senza errore.
- Il fallimento temporaneo della pulizia storage non deve lasciare una falsa indicazione di cancellazione completa né rendere nuovamente accessibile il Tenant.
- La cancellazione deve gestire relazioni cicliche o autoreferenziali senza disabilitare globalmente l'integrità referenziale e senza usare reset distruttivi.
- Un `User` globale non deve essere eliminato neppure quando perde l'ultima `CompanyCapability` in seguito alla cancellazione.
- Dati di altri Tenant e relativi file devono risultare byte-per-byte e record-per-record estranei a Archivio, Ripristino e cancellazione del Tenant bersaglio.
- Gli stati ammessi del Tenant sono soltanto `active` e `archived`; la cancellazione non introduce uno stato persistente `deleted`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-TL-001**: Il sistema MUST rappresentare `Tenant Azienda` e `Azienda` come entità distinte collegate da una relazione obbligatoria uno-a-uno.
- **FR-TL-002**: `Azienda` MUST restare la radice del dominio e proprietaria dei dati economici, gestionali e storici; il Tenant MUST avere solo responsabilità tecnica di accesso, isolamento e ciclo di vita.
- **FR-TL-003**: Ogni Azienda esistente e futura MUST avere esattamente un Tenant Azienda, e ogni Tenant MUST riferire esattamente una Azienda.
- **FR-TL-004**: Gli unici stati persistiti del Tenant MUST essere `active` e `archived`; il valore iniziale MUST essere `active`.
- **FR-TL-005**: Il sistema MUST continuare a usare `CompanyCapability` come unica autorizzazione aziendale esistente e MUST NOT introdurre ruoli, membership o capacità parallele.
- **FR-TL-006**: Un Tenant MUST essere accessibile nel pannello operativo solo quando è `active` e l'utente possiede `visualizza` per la sua Azienda.
- **FR-TL-007**: `is_platform_admin` MUST NOT costituire un bypass delle capacità aziendali nel pannello operativo.
- **FR-TL-008**: Ogni query, URL, relazione automatica, pagina, widget, download, report e mutazione nel contesto operativo MUST risolversi dal Tenant tecnico alla relativa Azienda e mantenere l'isolamento tenant esistente.
- **FR-TL-009**: La migrazione MUST creare un Tenant `active` per ogni Azienda esistente, preservando identificativi, proprietà, relazioni e dati di dominio.
- **FR-TL-010**: La migrazione MUST garantire l'unicità uno-a-uno e fallire esplicitamente in presenza di uno stato che non può essere riconciliato senza inventare dati.
- **FR-TL-011**: Soltanto un utente con `is_platform_admin = true` MUST poter archiviare, ripristinare o cancellare definitivamente un Tenant.
- **FR-TL-012**: L'Archivio MUST essere consentito solo da `active` a `archived` e il Ripristino solo da `archived` a `active`; transizioni ridondanti o sconosciute MUST essere rifiutate.
- **FR-TL-013**: L'Archivio MUST conservare integralmente Azienda, capacità, dati, associazioni, Snapshot, audit, allegati e configurazioni.
- **FR-TL-014**: Un Tenant archiviato MUST essere escluso dall'elenco dei Tenant operativi e MUST negare ogni accesso ordinario, anche con URL o identificatori conosciuti.
- **FR-TL-015**: Un Tenant archiviato MUST negare ogni mutazione di dominio indipendentemente dalle `CompanyCapability` conservate e dal punto di invocazione.
- **FR-TL-016**: Un Tenant archiviato MUST negare letture e download tenant-owned fuori dal pannello tenant quando tali superfici richiedono accesso aziendale.
- **FR-TL-017**: Tutti i processi automatici che leggono o modificano dominio tenant-owned MUST selezionare soltanto Tenant attivi e MUST rivalidare lo stato prima di ogni mutazione protetta; il cleanup tecnico di file appartenuti a Tenant già eliminati opera invece esclusivamente sul manifest globale.
- **FR-TL-018**: Archivio e processi automatici concorrenti MUST essere coordinati in modo che nessuna nuova mutazione tenant-owned possa impegnarsi dopo il completamento dell'Archivio.
- **FR-TL-019**: Il Ripristino MUST rendere nuovamente accessibile il Tenant secondo le capacità conservate e MUST NOT modificare dati, date, scadenze, calendari, Snapshot o stati di dominio.
- **FR-TL-020**: Dopo il Ripristino, i processi automatici MUST riprendere secondo il calendario reale e le regole di recupero/idempotenza già definite, senza compensare il periodo di Archivio con date artificiali.
- **FR-TL-021**: La cancellazione definitiva MUST essere disponibile sia per Tenant `active` sia per Tenant `archived`, senza prerequisiti economici o di chiusura.
- **FR-TL-022**: La cancellazione definitiva MUST richiedere due azioni di conferma sequenziali e distinte, entrambe validate lato server e specifiche per il Tenant bersaglio; ripetere accidentalmente la prima azione MUST NOT soddisfare la seconda.
- **FR-TL-023**: Prima della seconda conferma il sistema MUST presentare in modo inequivocabile l'irreversibilità e le categorie di dati eliminate; una conferma mancante o errata MUST produrre zero cancellazioni.
- **FR-TL-024**: La cancellazione definitiva MUST eliminare Tenant, Azienda, capacità, tutti i dati e associazioni tenant-owned, Snapshot, audit, allegati e ogni altro record di proprietà della Azienda.
- **FR-TL-025**: La cancellazione definitiva MUST NOT eliminare gli account globali `User`, le sessioni globali o dati appartenenti ad altri Tenant.
- **FR-TL-026**: La parte database della cancellazione MUST essere atomica e osservabile: o l'intero grafo tenant-owned è eliminato oppure nessuna sua parte è eliminata.
- **FR-TL-027**: La cancellazione MUST rispettare l'integrità referenziale del grafo completo, incluse relazioni composite, cicliche, autoreferenziali e indirette, senza affidarsi a una lista applicativa incompleta di record.
- **FR-TL-028**: I file tenant-owned MUST essere identificati e deduplicati prima della cancellazione database e rimossi soltanto dopo il commit della transazione database.
- **FR-TL-029**: Il sistema MUST registrare atomicamente con la cancellazione database il lavoro di pulizia file necessario; il lavoro MUST restare osservabile e ritentabile finché ogni file è assente.
- **FR-TL-030**: Un errore di storage MUST NOT essere descritto come rollback atomico della cancellazione database, MUST NOT ripristinare il Tenant e MUST NOT impedire i tentativi successivi di completamento.
- **FR-TL-031**: La pulizia file MUST essere idempotente, considerare completato un file già assente e MUST NOT eliminare file di altri Tenant.
- **FR-TL-032**: La gestione globale dei Tenant MUST mostrare Tenant attivi e archiviati ed essere raggiungibile senza selezionare un Tenant operativo.
- **FR-TL-033**: La gestione globale e ogni relativa azione MUST essere accessibile esclusivamente a `is_platform_admin`; nessun nuovo sistema di autenticazione o autorizzazione MUST essere introdotto.
- **FR-TL-034**: L'interfaccia globale MUST offrire Archivio soltanto per Tenant attivi, Ripristino soltanto per Tenant archiviati e cancellazione definitiva per entrambi gli stati.
- **FR-TL-035**: Le azioni di ciclo di vita MUST rivalidare autorizzazione, identità e stato del Tenant sul server al momento della mutazione e proteggere le transizioni concorrenti.
- **FR-TL-036**: La registrazione di una nuova organizzazione MUST creare atomicamente Tenant attivo, Azienda e capacità iniziali previste per il creatore; un fallimento o `Annulla` prima della persistenza MUST lasciare zero record parziali, mentre dopo la persistenza si applicano soltanto Archivio o eliminazione definitiva.
- **FR-TL-037**: La registrazione di una nuova organizzazione MUST restare consentita solo al Super Admin secondo la policy esistente di creazione Azienda.
- **FR-TL-038**: La Chiusura MUST presentare soltanto le scelte `Crea N+1` e `Non creare N+1` quando N+1 non esiste.
- **FR-TL-039**: `Non creare N+1` MUST significare esclusivamente mancata creazione dell'Esercizio successivo e MUST NOT archiviare, disabilitare, cancellare o reinterpretare il Tenant.
- **FR-TL-040**: Un Tenant attivo MUST consentire la successiva creazione manuale di N+1 secondo le normali autorizzazioni e regole, anche se la Chiusura aveva scelto `Non creare N+1`.
- **FR-TL-041**: Codice, valori persistiti, audit, messaggi, interfacce, factory e test coinvolti MUST rimuovere il significato `Gestione continuata`/`Gestione terminata` e usare una terminologia riferita soltanto alla creazione di N+1.
- **FR-TL-042**: Snapshot e audit storici MUST continuare a distinguere N+1 creato, già esistente o non creato senza dedurre un ciclo di vita del Tenant.
- **FR-TL-043**: Il sistema MUST conservare le regole esistenti che vietano la cancellazione fisica degli oggetti interni mentre il Tenant esiste; la sola eccezione è l'operazione dedicata di cancellazione definitiva dell'intero Tenant.
- **FR-TL-044**: Ogni rifiuto per Tenant archiviato o accesso cross-tenant MUST evitare la divulgazione di dati, nomi, stato interno o file non già autorizzati.
- **FR-TL-045**: Il sistema MUST fornire prove automatiche di migrazione, relazione uno-a-uno, isolamento, autorizzazione Super Admin, Archivio/Ripristino, concorrenza rilevante, sospensione processi, cancellazione completa/rollback e completamento storage.

### Canonical Requirement Reconciliation

- Il §31 prevale sulle formulazioni precedenti limitatamente al ciclo di vita del Tenant e alla decisione N+1.
- I §§11.7, 11.8 e i relativi casi precedenti restano validi per inizializzazione e Chiusura, ma `Gestione continuata`/`Gestione terminata` sono sostituiti da `Crea N+1`/`Non creare N+1`.
- Le regole di capacità aziendale, isolamento, immutabilità storica, idempotenza dei processi e divieto di cancellazione degli oggetti interni restano valide salvo la cancellazione dedicata dell'intero Tenant prevista dal §31.

### Key Entities

- **Tenant Azienda**: contenitore tecnico uno-a-uno di una Azienda, con stato `active` o `archived`; determina accessibilità, isolamento e ciclo di vita, ma non possiede significato economico.
- **Azienda**: radice del dominio MP2 e proprietaria di dati gestionali, economici, storici, associazioni e capacità.
- **CompanyCapability**: associazione conservata fra utente globale, Azienda e capacità; resta necessaria ma non sufficiente quando il Tenant è archiviato.
- **Lifecycle Operation**: comando autorizzato e serializzato di Archivio, Ripristino o cancellazione definitiva, con stato bersaglio validato.
- **Pending File Deletion**: registrazione tecnica globale di un file tenant-owned da rimuovere dopo il commit database, con identità dell'operazione, disco, percorso e stato/tentativi osservabili.
- **User**: account globale condivisibile fra più Aziende, mai di proprietà esclusiva del Tenant e mai eliminato dalla cancellazione tenant.
- **Closing Snapshot**: fotografia immutabile della Chiusura che registra se N+1 è stato creato, era già esistente o non è stato creato, senza rappresentare il ciclo di vita del Tenant.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Il 100% delle Aziende esistenti e create dopo il rilascio ha esattamente un Tenant Azienda e il 100% dei Tenant ha esattamente una Azienda.
- **SC-002**: Il 100% dei tentativi verificati di accesso, lettura, download o mutazione su Tenant archiviati e dei tentativi cross-tenant viene negato senza esposizione di dati.
- **SC-003**: Prima e dopo un ciclo Archivio/Ripristino, il confronto di tutti i record tenant-owned, capacità, file e riferimenti produce zero differenze salvo lo stato e i timestamp tecnici del Tenant.
- **SC-004**: Il 100% delle esecuzioni automatiche verificate elabora soltanto Tenant attivi; il Ripristino riprende il comportamento usando date reali senza riscritture temporali.
- **SC-005**: Il 100% dei tentativi di azione ciclo di vita da parte di utenti non Super Admin viene negato, mentre un Super Admin può gestire Tenant attivi e archiviati anche senza Tenant operativi disponibili.
- **SC-006**: Ogni cancellazione database riuscita lascia zero record tenant-owned in tutte le tabelle censite, conserva tutti gli account globali e lascia invariati tutti i record degli altri Tenant.
- **SC-007**: Ogni errore database iniettato durante la cancellazione lascia il 100% del grafo bersaglio e dei file nello stato precedente; nessun risultato parziale è osservabile.
- **SC-008**: Ogni errore storage iniettato lascia un lavoro persistente osservabile che, a storage ripristinato, completa la rimozione con nuovi tentativi idempotenti e senza coinvolgere altri Tenant.
- **SC-009**: Il 100% delle registrazioni riuscite crea una coppia Tenant/Azienda attiva e capacità iniziali; il 100% dei fallimenti iniettati e degli abbandoni pre-persistenza lascia zero record parziali.
- **SC-010**: Il 100% delle superfici e dei dati interessati dalla Chiusura usa `Crea N+1`/`Non creare N+1`; scegliere `Non creare N+1` non cambia mai lo stato del Tenant.
- **SC-011**: La suite mirata copre tutte le famiglie di record e file tenant-owned individuate nello schema e dimostra che la cancellazione non lascia residui accessibili o orfani.
- **SC-012**: La verifica browser autenticata dimostra gestione globale, Archivio, diniego operativo, Ripristino e doppia conferma di cancellazione senza errori HTTP, Livewire o console.

## Assumptions

- L'autenticazione Laravel, il flag globale `users.is_platform_admin` e `CompanyCapability` esistenti vengono riutilizzati.
- Il pannello operativo corrente conserva il proprio significato e le proprie URL tenant per quanto compatibile con la separazione tecnica; l'identità della Azienda non viene rigenerata.
- Il processo schedulato attualmente esistente per i rinnovi Contratto rappresenta l'inventario iniziale dei processi automatici; il piano impone comunque un controllo estendibile e verificabile a ogni nuovo processo tenant-owned.
- Gli allegati correnti usano dischi Laravel e percorsi persistiti; un percorso fisico può essere referenziato da più evidenze dello stesso Tenant.
- La cancellazione definitiva non è recuperabile; il registro tecnico di pulizia file non costituisce uno stato `deleted` del Tenant né conserva dati applicativi del Tenant.

## Dependencies and Scope Boundaries

- **In scope**: separazione tecnica Tenant/Azienda; migrazione dati; adattamento tenancy nativa, risorse e superfici operative; Archivio/Ripristino; gestione globale Super Admin; cancellazione definitiva completa; completamento affidabile dello storage; sospensione processi automatici; registrazione atomica; terminologia e semantica N+1; prove automatiche e browser.
- **Out of scope**: pacchetti tenancy esterni; database per Tenant; nuovi ruoli, membership o capacità; offboarding economico implicito; conservazione selettiva dopo cancellazione; soft delete o stato `deleted`; riapertura degli Esercizi; ricalcolo o spostamento delle date dopo Ripristino; modifica delle regole economiche di creazione N+1; cancellazione degli account globali; implementazione anticipata di processi automatici non esistenti.
- **Dependency**: il comportamento canonico di Azienda, `CompanyCapability`, processi di rinnovo, Chiusura, Snapshot, allegati e audit già implementato deve restare invariato salvo gli adattamenti esplicitamente richiesti.
