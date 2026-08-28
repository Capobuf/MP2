# Implementation Plan: Installazione shared hosting e release ZIP

**Branch**: `014-shared-hosting-installer` | **Date**: 2026-08-28 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/014-shared-hosting-installer/spec.md`

## Summary

Integrare `relayercore/laravel-installer` `1.5.0` come wizard di prima installazione production-only, sostituendo esclusivamente gli step generici incompatibili con MP2: requisito memoria illimitata, configurazione MySQL senza auto-create, preparazione/reset esplicito del database, migrazioni senza cleanup automatico e configurazione scheduler. Conservare lo step admin nativo con callback `is_platform_admin`, proteggere il callback finale dalla chiamata Livewire diretta, localizzare le view necessarie e produrre a ogni push un ZIP runtime autosufficiente tramite una CI di release separata, avviata solo dopo il successo del quality gate. L'artefatto include una `.env` di bootstrap generata per build e un file `REVISION`, ma esclude stato runtime, cache bootstrap, Tinker e materiale di sviluppo; la struttura è intenzionalmente compatibile con una futura CI di update, che resta fuori scope.

## Technical Context

**Language/Version**: PHP 8.3+; JavaScript solo per asset esistenti/Vite build

**Primary Dependencies**: Laravel 13.17+, Filament 5, Livewire 4 tramite stack corrente, `relayercore/laravel-installer` `1.5.0`, GitHub Actions

**Storage**: MySQL; filesystem locale privato per allegati; file per cache/session nella configurazione shared-hosting iniziale

**Testing**: Pest 4 / PHPUnit 12, feature tests mirati, MySQL 8.4 CI con schema distruttivo `testing_installer` separato da `testing`, smoke HTTP su release estratta con `testing_installer_smoke`

**Target Platform**: shared hosting Linux con web server configurabile verso `public/`, PHP web/CLI >=8.3, cron, MySQL

**Project Type**: Laravel web application monorepo

**Performance Goals**: mantenere il quality gate vicino all'obiettivo esistente (~3 minuti); packaging deve aggiungere solo lavoro proporzionato; il wizard deve completare le migrazioni correnti entro i limiti normali di una richiesta web shared-hosting

**Constraints**:

- niente Composer/npm/Node/Git/Docker richiesti sull'hosting;
- niente API CloudPanel/cPanel/Plesk specifiche;
- niente fork o modifica di `/vendor`;
- installer senza password/token aggiuntivo;
- solo MySQL nel wizard;
- MySQL 8.4 certificato in CI ma nessun gate rigido sulla versione server;
- database già creato dal pannello;
- reset DB solo dietro conferma testuale;
- password admin: validazione nativa del package;
- scheduler manuale con stringa pronta e conferma obbligatoria;
- nome fisso `Master Plan IT`;
- wizard solo italiano;
- niente `INSTALL.txt`;
- update production automation escluso dalla slice.

**Scale/Scope**: una modalità di prima installazione; un archivio per ogni push valido; nessuna nuova entità economica o tabella di dominio

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

### I. Canonical Domain Authority — PASS

La slice non modifica regole economiche o gestionali. Usa il modello `User`, il flag `is_platform_admin`, la tenancy e i processi schedulati già esistenti.

### II. Simplicity and Proportionality — PASS

Il package elimina la costruzione di un wizard custom. Gli step MP2 sono limitati ai punti in cui il comportamento generico sarebbe errato o distruttivo. Non vengono introdotti Redis, worker, API hosting, update engine o un secondo journal d'installazione.

### III. Vertical Slices with Complete Traceability — PASS

La feature è dimostrabile come flusso completo: push → ZIP → upload → wizard → login. Il processo di update resta esplicitamente una slice successiva.

### IV. Dependency Integrity — PASS

La nuova dependency è richiesta dalla feature, pinnata e documentata in `research.md`. MP2 usa solo config, contratti, step e view override pubblici; `/vendor` resta immutabile.

### V. Explicit Domain Operations — PASS

Non vengono introdotte nuove operazioni di dominio. Il reset database appartiene al lifecycle pre-installazione e non opera su una istanza installata.

### VI. Proportional Test Discipline — PASS

I test coprono i failure mode unici: reset distruttivo, admin, lock, scheduler, env e artefatto. Nessun obiettivo di coverage e nessun browser framework aggiuntivo.

### VII. Reproducible, Inspectable Development — PASS

Il normale bootstrap Sail resta invariato. Il custom `InstallationStateManager` considera `local` e `testing` già installati.

### VIII. Historical and Transactional Integrity — PASS

La feature non muta dati storici di un'istanza esistente. Il reset è consentito soltanto prima della prima installazione e con consenso esplicito.

### IX. Agent Operational Discipline — PASS

Il lavoro è separabile in fasi con meno di otto task ciascuna; nessun refactor non collegato.

### Post-Design Re-check — PASS

I contratti e il data model non richiedono eccezioni alla costituzione.

## Project Structure

### Documentation (this feature)

```text
specs/014-shared-hosting-installer/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── installer-wizard.md
│   ├── release-artifact.md
│   └── shared-hosting-runtime.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Installer/
│   ├── Callbacks/
│   │   ├── FinalizeInstallation.php
│   │   └── PromotePlatformAdmin.php
│   ├── Steps/
│   │   ├── CheckRequirements.php
│   │   ├── ConfigureEnvironment.php
│   │   ├── ConfigureScheduler.php
│   │   ├── PrepareDatabase.php
│   │   └── RunMigrations.php
│   └── Support/
│       └── Mp2InstallationStateManager.php
└── Providers/
    └── AppServiceProvider.php

config/
└── installer.php

database/
└── seeders/
    └── DatabaseSeeder.php

lang/
└── vendor/
    └── installer/
        └── it/
            └── installer.php

resources/
└── views/
    └── vendor/
        └── installer/
            ├── installer.blade.php
            ├── layouts/
            │   └── installer.blade.php
            └── steps/
                ├── admin.blade.php
                ├── environment.blade.php
                ├── migrations.blade.php
                ├── prepare-database.blade.php
                └── scheduler.blade.php

tests/
└── Feature/
    └── Installation/
        ├── AdminFinalizationTest.php
        ├── DatabasePreparationTest.php
        ├── EnvironmentConfigurationTest.php
        ├── InstallerAvailabilityTest.php
        ├── MigrationSafetyTest.php
        ├── ReleaseContractTest.php
        └── SchedulerConfigurationTest.php

.env.production.example
.github/workflows/quality.yml
.github/workflows/hosting-release.yml
composer.json
composer.lock
```

**Structure Decision**: Riutilizzare la struttura Laravel corrente. `app/Installer` è un namespace locale e limitato alla feature; non introduce layer generici. Le view pubblicate sono solo quelle che devono cambiare contenuto/comportamento: il form principale deve smettere di azzerare `state.password`, la view migrazioni deve rimuovere il toggle demo e le altre coprono lingua/identità/step MP2. Il CSS del package viene pubblicato nella directory di staging dalla CI e non diventa sorgente MP2. La sola sottoclasse di uno step package è `CheckRequirements`, necessaria per accettare correttamente `memory_limit=-1`.

## Design Notes

### Pipeline installer prevista

```text
CheckRequirements (MP2 thin subclass, package behavior)
→ CheckPermissions (package)
→ ConfigureEnvironment (MP2)
→ PrepareDatabase (MP2)
→ RunMigrations (MP2)
→ CreateAdmin (package)
→ ConfigureScheduler (MP2)
→ finish() package
   └→ FinalizeInstallation callback MP2
```

`CreateAdmin` resta quello del package per preservare esattamente la sua validazione password.

### Stato di installazione

`Mp2InstallationStateManager` implementa il contratto del package:

```text
APP_ENV != production → installed = true
APP_ENV == production → installed = file_exists(configured marker)
```

La `markInstalled()` production crea e verifica il marker previsto, lanciando un errore se la scrittura fallisce. La finalizzazione della chiave avviene tramite callback prima che il normale `finish()` arrivi al marker. Lo stesso callback controlla il progresso server-side, l'admin piattaforma e la conferma scheduler, così una chiamata Livewire diretta a `finish()` non può installare l'istanza.

### Database safety boundary

`PrepareDatabase` è il confine che autorizza le migrazioni:

```text
connection valid
    ↓
schema empty ─────────────→ reset not needed
schema non-empty
    ↓
typed DB name confirmation?
    ├─ no → blocked, no mutations
    └─ yes → db:wipe --drop-views → verify empty
                                      ↓
                         RunMigrations rechecks empty
```

`PrepareDatabase` e `RunMigrations` leggono il target dalla connessione `mysql` caricata dalla `.env`, non dal solo stato Livewire. `RunMigrations` non esegue mai operazioni distruttive: ricontrolla tabelle/view e, su errore, lascia lo schema parziale. Il retry richiede quindi un nuovo passaggio e una nuova conferma nello step di preparazione.

### Release build

Il workflow separato `Hosting Release` usa una staging directory e costruisce uno ZIP esplicito dopo il successo di `Quality`. Non si carica direttamente l'intero checkout come artifact.

```text
Quality workflow passed on push
→ Hosting Release checks out the exact validated SHA
→ install production Composer set
→ ensure Vite build exists
→ publish installer public asset
→ copy runtime allowlist to staging
→ create fresh empty bootstrap/cache + storage skeleton
→ create .env from .env.production.example
→ inject random bootstrap APP_KEY
→ write REVISION = full SHA
→ validate required/excluded paths
→ zip staging contents
→ extract zip into second temp dir
→ production-like smoke checks
→ upload zip as GitHub Actions artifact
```

La release NON è un clone del repository. `bootstrap/cache/*.php`, l'albero storage del checkout e `vendor/laravel/tinker` non vengono copiati. I test distruttivi usano `testing_installer`; lo smoke estratto usa `testing_installer_smoke`.

### Future update compatibility

Questa slice stabilisce soltanto i confini:

- release code: sostituibile;
- `.env` istanza: persistente;
- `storage/` istanza: persistente;
- `REVISION`: identità della release.

La futura CI potrà usare gli stessi ZIP senza considerarli sicuri per overwrite in-place.

## Complexity Tracking

Nessuna violazione della costituzione richiede eccezioni.
