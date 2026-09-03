# Research: Installazione shared hosting e release ZIP

**Feature**: `014-shared-hosting-installer`  
**Date**: 2026-08-28

## Scope della ricerca

Questa ricerca copre esclusivamente i rischi tecnici necessari a distribuire MP2 come archivio autosufficiente e installarlo da browser su shared hosting. Non ridefinisce il dominio economico e non apre il lifecycle di update della produzione, che resta la slice successiva.

## Decisione 1 - Installer selezionato

**Decision**: adottare `relayercore/laravel-installer` versione esatta `1.5.0`.

**Exact source verified**: tag Composer `v1.5.0`, commit `d224af7271a6b018670f4f1a52043ac29873bccb`. La verifica è stata eseguita sul relativo archivio dist, non sul branch `main`.

**Rationale**:

- dichiara compatibilità con PHP 8.3, Laravel 13 e Livewire 4;
- fornisce routing `/install`, middleware di gating, pipeline di step configurabile, controllo requisiti/permessi, scrittura `.env`, creazione amministratore e marker file;
- consente di sostituire step e callback senza modificare `/vendor`;
- è MIT;
- elimina la necessità di costruire un wizard di installazione MP2 da zero.

**Risk**: il package è giovane e la sua CI verifica soprattutto compatibilità Composer e sintassi, non un'installazione funzionale completa.

**Mitigation**: pinning esatto e pochi test d'integrazione MP2 ad alto valore.

**Dependency policy record**:

| Policy item | Verified decision |
|---|---|
| Current requirement | Wizard Web di prima installazione shared-hosting |
| Why core is insufficient | Laravel/Filament non forniscono un wizard completo con gating, pipeline, env, requisiti e marker |
| Compatibility | Dry-run contro il lock MP2: una sola nuova installazione; PHP `^8.2|^8.3|^8.4`, Illuminate 10–13, Livewire 3–4 |
| Maintenance | Release esatta giovane; il commit verificato contiene la pipeline config-driven ma una CI upstream limitata, quindi MP2 non delega al package i propri invarianti di sicurezza |
| License | MIT |
| Security | Sono stati verificati wipe su errore, finalizzazione pubblica Livewire, debug forzato, scrittura env/marker, route gating e CDN; audit Composer senza advisory note al 2026-08-28 |
| Custom code removed | Routing/middleware installer, orchestrazione Livewire, gestione step, env writer, requisiti/permessi, creazione admin e marker di base |
| Removal path | Rimuovere package/config/view override e sostituire il solo lifecycle installer; nessuna tabella o dato di dominio dipende dal package, mentre `.env`, schema MP2, `User` e marker restano formati applicativi MP2 |

**Alternatives considered**:

- installer Filament/Web Installer più vecchi: incompatibili con Filament 5/Laravel 13;
- pacchetti recenti limitati a Laravel <=12: incompatibili;
- `dacoto/laravel-wizard-installer`: tecnicamente compatibile ma repository archiviato/abbandonato;
- `froiden/laravel-installer`: più maturo ma troppo datato e senza verifica corrente chiara su Laravel 13;
- installer custom MP2: rifiutato perché duplicherebbe funzionalità già disponibili e aumenterebbe manutenzione.

## Decisione 2 - Pinning e ownership

**Decision**: `composer.json` deve richiedere `relayercore/laravel-installer` esattamente a `1.5.0`, non con range caret.

**Rationale**: il branch `main`, il changelog e la release Composer del package non sono perfettamente allineati; l'implementazione deve essere verificata contro il codice effettivamente installato.

**Alternatives considered**:

- `^1.5`: permetterebbe cambi comportamentali non verificati in una dependency critica per il deploy;
- fork MP2: rifiutato perché violerebbe l'obiettivo di mantenere il package aggiornabile e aumenterebbe codice posseduto.

## Decisione 3 - Installer attivo solo in production

**Decision**: MP2 fornisce un proprio `InstallationStateManager` tramite il contratto pubblico del package.

**Behavior**:

- `local` e `testing`: l'applicazione è sempre considerata installata;
- `production`: lo stato dipende dal marker configurato, di default `storage/installed`.

**Rationale**: l'attuale sviluppo e la suite non devono richiedere un marker file e non devono essere intercettati da `/install`.

**Alternatives considered**:

- creare `storage/installed` nel repository: errato perché il file finirebbe nelle release fresche o diventerebbe stato versionato;
- disabilitare il provider in dev/test: più invasivo e meno coerente con l'auto-discovery Composer.

## Decisione 4 - Configurazione database MP2 al posto dello step generico

**Decision**: sostituire `ConfigureEnvironment` del package con uno step MP2 che supporta solo MySQL, richiede un database esistente, verifica la connessione e scrive la configurazione.

**Rationale**: lo step `v1.5.0`:

- presenta MySQL, MariaDB, PostgreSQL, SQLite e SQL Server;
- connette inizialmente al server senza selezionare lo schema MySQL;
- tenta di creare automaticamente il database se non esiste;
- contiene SQL di creazione database che MP2 non deve usare su shared hosting;
- ignora il booleano restituito da `EnvironmentWriter::save()`.

Per MP2 l'operatore crea già il database nel pannello hosting. Ridurre l'interfaccia a MySQL evita opzioni non certificate e permessi `CREATE DATABASE` che normalmente non servono.

**Validation**:

- host non vuoto;
- porta numerica valida;
- nome database non vuoto;
- username non vuoto;
- password può essere vuota solo se MySQL lo consente;
- connessione reale allo specifico schema;
- salvataggio `.env` verificato;
- nessuna credenziale sensibile nei log.

## Decisione 5 - Versione MySQL non stringente

**Decision**: non introdurre un gate `server_version == 8.4`.

**Rationale**: MySQL 8.4 è la baseline effettivamente verificata in sviluppo/CI, ma l'utente vuole poter usare altri server MySQL compatibili. Il criterio corretto è capacità effettiva: connessione e migrazioni devono riuscire.

**Support statement**:

- MySQL 8.4: certificato dalla CI;
- altre versioni MySQL: non bloccate per il solo numero di versione, ma non dichiarate equivalenti finché non verificate;
- motori non MySQL: fuori scope.

## Decisione 6 - Database non vuoto e reset esplicito

**Decision**: introdurre uno step MP2 di preparazione database tra configurazione e migrazioni.

**Behavior**:

1. ispeziona tabelle e view della connessione `mysql` effettivamente caricata dalla `.env`, senza fidarsi del solo stato Livewire;
2. se vuoto, lo dichiara pronto senza eseguire wipe;
3. se non vuoto, mostra un warning distruttivo;
4. richiede di digitare esattamente il nome del database;
5. solo con conferma corretta esegue `db:wipe --database=mysql --drop-views --force`;
6. verifica nuovamente che non esistano tabelle o view.

**Rationale**: l'utente vuole poter riutilizzare un DB non vuoto; la conferma testuale rende la distruzione esplicita senza introdurre password o token ulteriori.

**Alternatives considered**:

- blocco definitivo del DB non vuoto: non soddisfa il requisito;
- wipe automatico: rischio inaccettabile;
- conferma singola generica "Sì": meno inequivocabile per un'azione irreversibile.

## Decisione 7 - Migrazioni sicure

**Decision**: sostituire `RunMigrations` del package.

**Rationale**: `v1.5.0` esegue `db:wipe --force` dentro il `catch` di qualsiasi errore di migrazione. Questo è incompatibile con il requisito MP2 di non distruggere contenuto preesistente senza consenso.

**Behavior MP2**:

- immediatamente prima di `migrate`, lo step ricontrolla tabelle e view sul database effettivamente configurato; lo stato `READY` non è un flag client-side persistente;
- viene eseguito il seeder di produzione previsto;
- se la migrazione fallisce, l'errore viene mostrato;
- lo schema parziale viene lasciato intatto e nessun cleanup distruttivo viene usato come fallback;
- un retry trova quindi il database non vuoto, resta bloccato e richiede di tornare allo step precedente e confermare nuovamente il reset col nome esatto.

**Rationale**: lasciare lo stato parziale è più semplice e più sicuro di conservare un'autorizzazione distruttiva attraverso errori, refresh o perdita di sessione. Evita anche che dati aggiunti dopo il primo consenso diventino implicitamente disposable.

**Seeder**: `DatabaseSeeder` deve diventare production-safe e non deve più creare `test@example.com`. L'amministratore di sviluppo resta responsabilità dell'esistente `mp2:ensure-dev-admin`, che già rifiuta `production`.

## Decisione 8 - Validazione admin nativa

**Decision**: mantenere `CreateAdmin` del package senza introdurre regole password MP2 aggiuntive.

**Rationale**: richiesta esplicita dell'utente e assenza di una policy password MP2 distinta che giustifichi duplicazione.

**Integration**: `on_admin_created` punta a una classe invocabile MP2 che imposta `is_platform_admin = true`.

**Note**: la README di `v1.5.0` mostra callback closure, ma il codice/config effettivo accetta una classe invocabile; l'implementazione deve seguire il codice della versione pinnata.

## Decisione 9 - Scheduler come step obbligatorio manuale

**Decision**: aggiungere uno step MP2 prima della finalizzazione che genera due stringhe:

1. crontab completo:
   `* * * * * <php-cli> '<absolute-path>/artisan' schedule:run >> /dev/null 2>&1`
2. solo comando:
   `<php-cli> '<absolute-path>/artisan' schedule:run >> /dev/null 2>&1`

**Default PHP CLI**: suggerire `php<major>.<minor>` coerente con la versione web rilevata, ma permettere modifica diretta del solo comando PHP.

**Rationale**:

- CloudPanel e altri pannelli possono separare pianificazione e comando;
- il path `artisan` può essere generato in modo affidabile dall'applicazione;
- il nome del binario CLI non è portabile tra tutti i provider;
- non esiste un'API shared-hosting generica per creare/verificare cron.

**Completion rule**: checkbox obbligatoria "Confermo di aver configurato lo scheduler".

**No automatic verification**: una conferma manuale è più onesta di un controllo host-specifico fragile.

## Decisione 10 - `.env` di bootstrap generato in CI

**Decision**: il repository mantiene un template production-safe senza chiave; durante il build della release la CI genera una `.env` reale solo nella directory di staging e le assegna una `APP_KEY` casuale.

**Rationale**:

- Laravel deve poter fare bootstrap prima che il componente Livewire del wizard possa modificare `.env`;
- la `.env.example` attuale è deliberatamente orientata allo sviluppo Sail e non deve diventare il template di produzione;
- una chiave fissa in repository renderebbe tutte le installazioni inizialmente identiche;
- non serve un GitHub Secret: la chiave di bootstrap non è condivisa, viene generata casualmente a ogni artefatto e viene sostituita in finalizzazione.

**Template production**:

- `APP_NAME="Master Plan IT"`;
- `APP_ENV=production`;
- `APP_DEBUG=false`;
- locale italiano;
- session/cache su file;
- queue `sync`;
- filesystem locale;
- mailer non operativo/log finché non verrà richiesta una configurazione mail;
- placeholder DB innocui;
- nessuna `DEV_ADMIN_*`.

## Decisione 11 - Chiave definitiva e marker

**Decision**: MP2 usa il callback di finalizzazione del package per generare/verificare una chiave definitiva prima che l'installazione venga considerata sicura.

**Package risks**:

- `Installer::finish()` è un metodo Livewire pubblico e può essere invocato direttamente dal client saltando gli step UI;
- `finish()` registra `key:generate` come callback `terminating()`, poi crea il marker prima che quella callback venga eseguita;
- il `FileInstallationStateManager` nativo non controlla il risultato di `file_put_contents()`.

**Mitigation**: l'`after_install` MP2, prima di mutare lo stato, verifica che la sessione server-side `installer.progress` contenga tutti gli step configurati e termini con lo scheduler, che esista un `User` piattaforma e che la conferma scheduler sia presente. Solo allora genera sincronicamente una nuova chiave, verifica che `.env` e config contengano una chiave Laravel valida diversa da quella bootstrap e restituisce il controllo. Il callback terminante del package la rigenererà una seconda volta; la chiave finale resta valida e unica per istanza, mentre non esiste mai un marker senza almeno una chiave valida. `Mp2InstallationStateManager::markInstalled()` controlla scrittura e presenza del marker e lancia un errore in caso contrario.

**Rejected**: forkare/modificare `Installer.php` soltanto per cambiare l'ordine.

## Decisione 12 - Debug forzato dal package

**Decision**: MP2 riafferma `app.debug=false` a fine bootstrap quando l'ambiente è production.

**Rationale**: il provider `v1.5.0` forza `app.debug=true` quando non trova il marker. L'utente ha accettato di non proteggere `/install` con token, ma non c'è motivo di aumentare anche l'esposizione degli errori di produzione.

**Implementation**: usare il normale lifecycle del service container/providing app-owned, senza patch al package.

## Decisione 13 - UI italiana, identità MP2 e nessuna CDN

**Decision**:

- pubblicare/creare traduzioni italiane del namespace installer;
- sovrascrivere soltanto le view necessarie;
- rimuovere testi hard-coded `BookFlow`;
- limitare il selettore database a MySQL;
- tradurre i testi hard-coded dello step admin senza cambiarne la validazione, rimuovendo il meter e il placeholder "Min. 8 caratteri" perché la validazione nativa `1.5.0` controlla solo presenza e conferma e non deve comunicare una policy inesistente;
- rimuovere `canvas-confetti` da CDN;
- usare l'identità visiva già disponibile in MP2 senza ridisegnare l'intero wizard.

**Rationale**: la versione pinnata contiene riferimenti BookFlow e carica una libreria esterna decorativa. Entrambi sono estranei al requisito.

**Blocking view defect verified**: il form di `resources/views/installer.blade.php` dichiara un proprio Alpine `pw/pc` e, a ogni submit, esegue `$wire.set('state.password', pw)` prima di `next()`. Gli input reali sono in uno scope Alpine figlio, quindi il valore esterno resta vuoto: questo cancella anche una password MySQL valida e rende inaffidabile lo step admin. L'override MP2 rimuove le due assegnazioni manuali e chiama soltanto `next()`, lasciando a `wire:model` l'invio dello state.

**Migration view**: la view nativa mostra e preseleziona "Install Demo Data" anche se lo step MP2 non deve creare demo. MP2 sovrascrive anche `steps/migrations.blade.php` per mostrare soltanto migrazioni e seeding production-safe, senza toggle demo.

## Decisione 14 - Requisiti server proporzionati

**Decisione aggiornata**: usare il controllo requisiti del package con un'estensione MP2 minima che corregge `memory_limit=-1` e verifica tramite il checker runtime condiviso che WeasyPrint 69.0 sia presente, eseguibile e compatibile; OPcache resta non bloccante.

**Rationale**: non tutti i requisiti generici del package sono requisiti reali di MP2. Prima dell'implementazione la lista deve essere riconciliata con i platform requirements bloccati da `composer.lock`.

**Minimum**:

- PHP >= 8.3;
- WeasyPrint 69.0 come binario esterno disponibile al processo PHP web;
- estensioni runtime richieste dal lock/app: `bcmath`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xmlreader`, `zip`;
- scrivibilità di `.env`, `storage/**`, `bootstrap/cache`.

**Not a hard gate**:

- OPcache;
- una versione esatta MySQL.

**Bootstrap boundary**: Composer genera un platform check e Laravel 13 richiede già PHP 8.3. Se PHP o un'estensione Composer obbligatoria impediscono l'avvio, il wizard non può mostrare la diagnosi. Il problema resta nel pannello hosting; non viene creato un secondo bootstrap PHP. Il check nel wizard copre solo condizioni verificabili dopo il bootstrap, incluso `pdo_mysql`.

**Package defect handled**: `CheckRequirements::memoryInBytes('-1')` restituisce `-1`, quindi l'implementazione nativa blocca erroneamente un limite illimitato. La sottoclasse MP2 tratta `-1` come illimitato e delega tutto il resto al package.

## Decisione 15 - Workflow Hosting Release separato dopo il quality gate

**Decision**: mantenere `.github/workflows/quality.yml` dedicato esclusivamente al quality gate e costruire lo ZIP in `.github/workflows/hosting-release.yml`.

**Behavior**:

- `Quality` continua a eseguire push su tutti i branch e pull request;
- `Hosting Release` parte tramite `workflow_run` solo quando `Quality` termina con successo per un evento `push`;
- il workflow release esegue checkout dello SHA esatto validato e prepara autonomamente dipendenze production, asset, staging, ZIP, smoke e artifact.

**Rationale**: separa chiaramente verifica del codice e produzione dell'artefatto, impedendo che lo ZIP venga costruito quando il quality gate fallisce.

**Trade-off**: il workflow release ripete l'installazione delle dipendenze e la build frontend, necessarie per essere autonomo e non dipendere dal workspace effimero del quality gate.

## Decisione 16 - Contratto dell'archivio

**Decision**: nome `mp2-<sanitized-branch>-<short-sha>.zip`.

**Required**:

- codice runtime;
- `vendor/` senza require-dev;
- `public/build/`;
- `public/.htaccess`;
- asset installer;
- directory runtime vuote;
- `.env` di bootstrap;
- `REVISION` contenente SHA completo;
- `composer.json` e `composer.lock` per tracciabilità.

Lo staging crea `bootstrap/cache` e l'albero `storage` da zero e copia solo i placeholder necessari; non copia le omonime directory runtime del checkout. `laravel/tinker`, oggi classificato come runtime nel repository ma non usato dall'applicazione, viene spostato in `require-dev` affinché `composer install --no-dev` non lo includa.

**Excluded**:

- `.git`, `.github`;
- `.agents`, `.specify`, `specs`, test e documentazione di sviluppo;
- `node_modules`;
- `public/hot`, perché è il marker runtime del dev server Vite;
- Docker/Sail e script dev non necessari;
- `.env.example` di sviluppo e credenziali dev;
- dati runtime, log, sessioni, cache;
- `bootstrap/cache/*.php`, `storage/framework/installer-progress.json` e qualsiasi upload del checkout;
- `storage/installed`.

## Decisione 17 - Preparazione per la futura CI di update

**Decision**: la release viene progettata come immutable code payload identificato da `REVISION`, mentre `.env` e `storage` sono considerati stato dell'istanza.

**Rationale**: permette alla slice successiva di implementare un update con:

- download release;
- backup;
- maintenance;
- estrazione in staging/nuova release;
- sostituzione della `.env` bootstrap con quella persistente;
- collegamento/preservazione storage;
- migrazioni;
- smoke;
- promozione/rollback.

**Out of scope now**: nessuna di queste operazioni viene implementata in questa slice.

**Explicit non-contract**: sovrascrivere manualmente una produzione esistente con lo ZIP non è un update supportato.

## Decisione 18 - Test proporzionati

**Decision**: evitare Dusk/Selenium e copertura duplicata.

**Focused automated coverage**:

1. state manager: dev/test non intercettati; production segue marker;
2. environment step: MySQL esistente, credenziali valide/errate, scrittura env;
3. DB preparation: non-empty no-confirmation = nessuna perdita; conferma esatta = reset;
4. migrations: ricontrollo immediato dello schema + seeder production-safe; failure path lascia lo schema parziale e richiede un nuovo reset esplicito;
5. admin callback: `is_platform_admin=true`;
6. scheduler step: path e stringhe corrette, conferma obbligatoria;
7. post-install: finalizzazione diretta rifiutata, marker verificato, route gating e chiave valida;
8. release archive contract;
9. smoke della copia realmente estratta dallo ZIP con dipendenze production.

**Rationale**: protegge i rischi unici della feature senza testare framework behavior banale.

**Database test isolation**: i test distruttivi dell'installer usano `INSTALLER_TEST_DATABASE=testing_installer`, distinto dal database suite `testing`. Il default dell'applicazione resta `testing`, così `TestEnvironmentGuard` continua a fallire closed; `Quality` crea lo schema e concede a `sail` privilegi soltanto su `testing_installer.*` prima di Pest. Lo smoke ZIP del workflow `Hosting Release` usa il proprio schema `testing_installer_smoke` e non riusa né il database della suite né dati di sviluppo.

## Decisione finale

La soluzione è considerata implementabile senza modificare il dominio MP2 e senza introdurre infrastruttura permanente aggiuntiva. Il custom code è limitato alle differenze tra il comportamento generico dell'installer e il contratto di sicurezza/deploy di MP2.
