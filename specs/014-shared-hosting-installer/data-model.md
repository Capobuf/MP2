# Data Model: Installazione shared hosting e release ZIP

**Feature**: `014-shared-hosting-installer`  
**Date**: 2026-08-28

## Premessa

La feature non introduce nuove tabelle di dominio. Il suo stato persistente appartiene principalmente a file di installazione/release e al modello `User` già esistente.

## Existing Entity: User

**Purpose**: rappresenta il primo amministratore creato dal wizard.

**Fields used by this feature**:

| Field | Requirement |
|---|---|
| `name` | valorizzato dallo step admin nativo |
| `email` | valorizzato dallo step admin nativo |
| `password` | valorizzato dallo step admin nativo |
| `is_platform_admin` | MUST diventare `true` tramite callback MP2 |

**State transition**:

```text
non-existent
    ↓ CreateAdmin
User persisted
    ↓ PromotePlatformAdmin
is_platform_admin = true
```

La feature non crea `CompanyCapability`, `Company` o `TenantCompany`. La prima Azienda continua a essere creata dal flusso MP2 esistente dopo il login.

## File State: Installation Marker

**Logical name**: `InstallationMarker`

**Default location**: `storage/installed`

**Meaning**:

- assente in production → istanza da installare;
- presente in production → wizard bloccato e applicazione operativa.

**Validation**:

- MUST NOT essere incluso nella release;
- MUST essere scrivibile indirettamente tramite `storage`;
- la creazione MUST verificarne la presenza e fallire esplicitamente se la scrittura non riesce;
- MUST essere preservato dalla futura CI di update insieme allo storage persistente.

**State transition**:

```text
absent
  ↓ successful finalization
present
```

Non esiste una transizione di uninstall in questa slice.

## File State: Instance Environment

**Logical name**: `InstanceEnvironment`

**Location**: `.env`

**Initial state in release**:

- `APP_NAME="Master Plan IT"`;
- `APP_ENV=production`;
- `APP_DEBUG=false`;
- locale italiano;
- file session/cache;
- sync queue;
- MySQL placeholders;
- random bootstrap `APP_KEY`.

**Wizard-owned final values**:

- `APP_URL`;
- `DB_CONNECTION=mysql`;
- `DB_HOST`;
- `DB_PORT`;
- `DB_DATABASE`;
- `DB_USERNAME`;
- `DB_PASSWORD`;
- final `APP_KEY`.

**Invariants**:

- non contiene `DEV_ADMIN_*`;
- non usa le credenziali Sail;
- non è generato dal `.env.example` di sviluppo;
- dopo l'installazione appartiene all'istanza, non alla release;
- la futura CI di update deve preservarlo.

## Build File: Production Environment Template

**Logical name**: `ProductionEnvironmentTemplate`

**Repository path**: `.env.production.example`

**Purpose**: input versionato della CI per creare `.env` dentro lo staging della release.

**Invariants**:

- `APP_KEY` vuota nel repository;
- nessun secret;
- nessuna credenziale development;
- non sostituisce `.env.example`, che resta orientato al bootstrap dev esistente.

## Build File: Revision

**Logical name**: `ReleaseRevision`

**Release path**: `REVISION`

**Content**: SHA Git completo, una riga.

**Purpose**:

- tracciare con precisione il commit sorgente;
- preparare la futura pipeline di update;
- evitare un sistema di versioning numerico inventato.

**Validation**:

- deve corrispondere al commit del workflow che ha costruito lo ZIP;
- deve essere presente nello ZIP e nello staging estratto.

## Transient State: Installer Session Progress

**Owner**: package installer.

**Storage**: sessione Laravel file durante la prima installazione.

**Purpose**: riprendere lo step successivo dopo refresh normali.

**Decision**: MP2 non introduce una seconda tabella o un secondo journal persistente.

La lista sessione nativa `installer.progress` è anche la prova server-side minima usata dal callback finale per rifiutare una chiamata Livewire diretta a `finish()`. Non sostituisce la verifica dello schema e non autorizza wipe futuri.

**Limit**: la perdita completa della sessione prima del marker può richiedere di ripercorrere il wizard. Qualsiasi reset distruttivo richiede comunque nuova conferma.

## Logical State: Database Preparation

Non viene introdotta una tabella. Lo stato viene calcolato dal database e dallo state del componente.

```text
UNVERIFIED
   ↓ inspect effective mysql connection
EMPTY ─────────────────────────────→ MIGRATION RECHECK
NON_EMPTY
   ↓ exact configured database-name confirmation
RESETTING
   ↓ db:wipe --drop-views + empty verification
MIGRATION RECHECK
   ├─ still empty → migrate
   └─ non-empty → blocked
```

**Invariants**:

- `NON_EMPTY` non può passare a `RESETTING` senza conferma esatta;
- target e nome mostrato provengono dalla connessione `mysql` effettivamente caricata dalla `.env`, non dal solo stato Livewire;
- le migrazioni possono partire solo dopo un nuovo controllo immediato di assenza di tabelle e view;
- un failure di reset non produce uno stato pronto;
- un failure di migrazione lascia lo schema parziale, non esegue cleanup e rende necessario un nuovo reset esplicito.

## Logical State: Scheduler Confirmation

Non viene persistito come impostazione applicativa.

**State**:

```text
UNCONFIRMED
   ↓ operator checks confirmation
CONFIRMED
```

**Invariant**: il normale `next()` non raggiunge `finish()` con stato `UNCONFIRMED`; poiché il metodo package è pubblico in Livewire, il callback finale ripete il controllo server-side e rifiuta invocazioni dirette.

Il nome del binario PHP CLI è un input di presentazione per costruire la stringa; non è necessario conservarlo in `.env`.

## Installation Lifecycle

```text
RELEASE_EXTRACTED
  ↓ first HTTP request
UNINSTALLED
  ↓ requirements/permissions pass
ENV_CONFIGURED
  ↓ DB verified/reset
DATABASE_READY
  ↓ migrations + production seed
SCHEMA_INSTALLED
  ↓ native admin creation + MP2 promotion
ADMIN_READY
  ↓ scheduler manual confirmation
SCHEDULER_ACKNOWLEDGED
  ↓ server progress/admin/scheduler guard
  ↓ final APP_KEY + verified marker
INSTALLED
```

Il lifecycle non rappresenta stati del dominio economico e non richiede migrazioni proprie.

## Release Lifecycle

```text
PUSH
 ├─ quality failed → NO_RELEASE
 └─ quality passed
      ↓ production staging
      ↓ artifact contract validation
      ↓ extracted smoke
      → RELEASE_ZIP
```

## Data not owned by this feature

- `Company`;
- `TenantCompany`;
- `CompanyCapability`;
- dati economici;
- allegati runtime;
- cache/session/log persistenti;
- configurazione del cron nel pannello hosting.

Questi elementi non devono essere duplicati dal wizard.
