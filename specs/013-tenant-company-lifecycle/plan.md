# Implementation Plan: Tenant Azienda e ciclo di vita

**Branch**: `013-tenant-company-lifecycle` | **Date**: 2026-08-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/013-tenant-company-lifecycle/spec.md`

## Summary

Separare il tenant tecnico Filament dalla `Company` di dominio introducendo `TenantCompany` in relazione uno-a-uno e mantenendo `company_id` come identità condivisa per preservare URL, chiavi e proprietà esistenti. Il pannello operativo userà la tenancy nativa Filament 5 su `TenantCompany`, risolverà sempre la `Company` collegata e consentirà accesso soltanto a Tenant attivi con `CompanyCapability::View`. Un pannello Filament globale minimale e non tenant-scoped, protetto da `is_platform_admin`, governerà Archivio, Ripristino e cancellazione.

Archivio e Ripristino saranno Action transazionali che bloccano prima la `Company` e poi il `TenantCompany`, coerentemente con il lock order delle mutazioni esistenti. La cancellazione userà una Action dedicata, due conferme lato server e una migrazione forward-only delle foreign key tenant-owned verso cascade/set-null deterministici. La transazione registrerà anche un manifest globale deduplicato dei file; solo dopo il commit verrà tentata la rimozione, con comando schedulato idempotente per i residui. La parte database è atomica, la parte storage è deliberatamente eventuale e osservabile.

La registrazione creerà atomicamente `Company`, `TenantCompany`, capacità e audit, preservando il contratto esistente della Action che restituisce la `Company`; la pagina Filament ne restituirà il Tenant collegato. Il processo rinnovi filtrerà Tenant attivi e continuerà a rivalidare tramite policy. La Chiusura rinominerà input, valori persistiti, audit e copy in termini esclusivi di creazione N+1, senza alcun effetto sul ciclo di vita.

## Technical Context

**Language/Version**: PHP 8.3; JavaScript/Vite per gli asset esistenti

**Primary Dependencies**: Laravel 13.17, Filament 5.7.6 con tenancy nativa, Livewire, Eloquent; nessuna nuova dipendenza

**Storage**: MySQL 8.4 per dati e manifest di pulizia; filesystem Laravel configurato per allegati (`local` oggi, disco persistito per record)

**Testing**: Pest 4, test Feature/Livewire/Filament, test di migrazione e vincoli su MySQL isolato, browser verification per i flussi visibili

**Target Platform**: Applicazione web Laravel/Filament su Linux, scheduler Laravel attivo

**Project Type**: Applicazione web monolitica Laravel

**Performance Goals**: Nessun nuovo obiettivo di throughput; elenco tenant e autorizzazione non devono introdurre N+1 query, processo rinnovi deve filtrare prima di caricare record archiviati, cancellazione deve lavorare con cascade DB e percorsi file deduplicati

**Constraints**: relazione Tenant/Azienda 1:1; stati solo `active`/`archived`; nessun bypass Super Admin nel dominio; nessun package tenancy, multi-database, ruolo o membership; database deletion atomica; storage post-commit ritentabile; migrazioni storiche intoccabili

**Scale/Scope**: 1 nuovo tenant model, 1 pannello globale, 9 Resource operative e relative pagine/widget, 1 processo schedulato di dominio, 30 tabelle dipendenti tenant-owned censite oltre alla radice `companies`, 2 famiglie di percorsi file persistiti

## Constitution Check

*GATE: Passed before research; re-checked after design.*

| Gate | Result | Evidence |
|---|---|---|
| Canonical domain authority | PASS | Il piano applica integralmente il §31 e usa la sua precedenza esplicita solo per lifecycle e N+1. |
| No invented product behavior | PASS | Stati, attori, azioni e semantica sono quelli richiesti; il pannello separato e il manifest sono scelte tecniche motivate. |
| Simplest proportional design | PASS | Riusa tenancy, policy, Action, transazioni, scheduler e Storage Laravel; nessun package o layer generalizzato. |
| Domain mutations outside UI callbacks | PASS | Archivio, Ripristino, cancellazione e registrazione risiedono in Action dedicate. |
| Authorization and tenant isolation | PASS | `CompanyCapability` resta la fonte; lo stato attivo è una condizione indipendente e centrale; panel globale solo Super Admin. |
| Atomicity and concurrency justified | PASS | Lock solo sulla coppia Company/Tenant bersaglio; transazione unica per distruzione DB; storage esplicitamente fuori transazione. |
| Historical immutability | PASS | Archivio/Ripristino non riscrivono dominio; cancellazione totale è l'unica eccezione canonica alla non-eliminazione interna. |
| Dependencies policy | PASS | Nessuna dipendenza aggiunta. |
| Migration safety | PASS | Solo migrazioni forward; nessun reset e nessuna riscrittura delle migrazioni esistenti. |
| Verification proportionality | PASS | Test mirati per policy, isolamento, migrazione, processi, transazioni, FK, storage e browser; quality gate completo alla fine. |

### Post-design re-check

PASS. Il secondo pannello non è un nuovo prodotto o sistema di autenticazione: è la minima superficie nativa Filament che resta raggiungibile quando i Tenant operativi sono esclusi. Il manifest storage è l'unica nuova infrastruttura tecnica e risponde a un requisito reale che database e filesystem non possono soddisfare atomicamente.

## Project Structure

### Documentation (this feature)

```text
specs/013-tenant-company-lifecycle/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
├── contracts/
│   ├── automatic-processing.md
│   ├── delete-foreign-key-matrix.md
│   ├── destruction.md
│   ├── filament-tenancy.md
│   └── lifecycle-actions.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/
│   ├── CreateCompany.php
│   └── Tenancy/
│       ├── ArchiveTenantCompany.php
│       ├── RestoreTenantCompany.php
│       ├── DestroyTenantCompany.php
│       └── DeletePendingTenantFiles.php
├── Console/Commands/
│   ├── ProcessContractRenewalsCommand.php
│   └── DeletePendingTenantFilesCommand.php
├── Domain/Company/TenantCompanyStatus.php
├── Filament/
│   ├── Pages/, Resources/, Widgets/       # pannello operativo adattato
│   └── Platform/Resources/TenantCompanies/
│       ├── TenantCompanyResource.php
│       ├── Pages/
│       └── Tables/
├── Models/
│   ├── Company.php
│   ├── TenantCompany.php
│   ├── PendingFileDeletion.php
│   ├── User.php
│   └── [modelli direttamente esposti come Resource]
└── Providers/Filament/
    ├── AdminPanelProvider.php
    └── PlatformPanelProvider.php

bootstrap/providers.php
routes/console.php
resources/views/filament/resources/exercises/pages/close-exercise.blade.php

database/
├── factories/CompanyFactory.php
└── migrations/
    ├── 2026_08_26_000100_create_tenant_companies_table.php
    ├── 2026_08_26_000200_enable_tenant_company_deletion.php
    ├── 2026_08_26_000300_create_pending_file_deletions_table.php
    └── 2026_08_26_000400_rename_next_exercise_disposition.php

tests/Feature/
├── Company/
│   ├── CompanyTenancyTest.php
│   ├── CompanyBoundaryTest.php
│   └── CreateCompanyTest.php
├── Tenancy/
│   ├── TenantCompanyMigrationTest.php
│   ├── TenantCompanyLifecycleTest.php
│   ├── TenantCompanyAuthorizationTest.php
│   ├── TenantCompanyIsolationTest.php
│   ├── TenantCompanyDestructionTest.php
│   ├── TenantFileDeletionTest.php
│   └── PlatformTenantManagementTest.php
├── Contracts/ProcessContractRenewalsCommandTest.php
└── Closing/
    ├── CloseExerciseTest.php
    ├── ClosingSnapshotTest.php
    └── ClosingUiTest.php
```

**Structure Decision**: Mantenere il monolite Laravel esistente. Le nuove mutazioni risiedono in `app/Actions/Tenancy`; il pannello operativo resta nell'albero corrente, mentre la sola Resource globale vive in un namespace non scoperto dal pannello operativo. Non viene introdotto un service/repository layer.

## Design and Implementation Phases

### Phase 0 - Evidence and decisions

Completata in [research.md](research.md): comportamento Filament 5, autorizzazioni correnti, risorse tenant, processo automatico, grafo FK, storage e strategia di migrazione sono ricostruiti dal codice e dalla documentazione ufficiale.

### Phase 1 - Schema and tenant identity

1. Creare `tenant_companies` con `company_id` primary key/FK, stato enum e timestamp; backfill `active` nella stessa migrazione e rendere la coppia obbligatoria per il percorso applicativo.
2. Creare enum/model/factory e relazioni `Company::tenantCompany()` / `TenantCompany::company()`.
3. Migrare il pannello operativo a `TenantCompany` mantenendo lo stesso route key numerico.
4. Aggiungere `tenantCompany()` ai nove modelli Resource e le relazioni inverse richieste dall'associazione/scoping nativi Filament.
5. Adattare pagine, Resource, widget, Livewire e test che assumono `Filament::getTenant()` come `Company`.

### Phase 2 - Active-state boundary and migration safety

1. Rendere `User::getTenants()` e `canAccessTenant()` dipendenti da Tenant attivo e `visualizza`.
2. Rendere `hasCapability()` falsa per Tenant archiviato, preservando fisicamente le righe `CompanyCapability`; questo protegge policy, Action dirette, download e report esistenti.
3. Aggiungere middleware tenant persistente come rifiuto anticipato delle route operative e mantenere la policy come controllo autorevole fuori pannello.
4. Verificare esplicitamente tutte le route custom e i download, oltre agli URL Resource, con casi archiviati/cross-tenant.
5. Eseguire test di backfill e rollback migration esclusivamente sul database test isolato.

### Phase 3 - Lifecycle and global management

1. Implementare Action Archive/Restore con `Gate` Super Admin, transazione, lock order `companies` → `tenant_companies`, rivalidazione dello stato e aggiornamento unico.
2. Garantire che le mutazioni concorrenti acquisiscano il lock Company e rivalidino la policy dopo il lock; coprire i confini esistenti che non lo fanno.
3. Creare `PlatformPanelProvider` su `/platform`, login condiviso e `canAccessPanel()` distinto per panel ID.
4. Creare una Resource globale non tenant-scoped, read-only salvo le tre azioni di lifecycle, con azioni visibili per stato e conferme server-side.
5. Registrare il provider in `bootstrap/providers.php` e verificare accesso anche senza Tenant attivi.

### Phase 4 - Permanent destruction and storage completion

1. Applicare la matrice nominativa di [contracts/delete-foreign-key-matrix.md](contracts/delete-foreign-key-matrix.md): cascade lungo ownership e per le composite con `company_id NOT NULL`, set-null solo per le due FK semplici opzionali cicliche, invariati i riferimenti a `users`.
2. Creare `pending_file_deletions` senza FK verso Tenant/Azienda affinché sopravviva al commit distruttivo; unicità `(storage_disk, storage_path)`.
3. Implementare `DestroyTenantCompany`: autorizzazione e due conferme, lock, raccolta distinta dei file da `attachments` e `budget_evidence`, esclusione dei path referenziati anche da altri Tenant, inserimento manifest per i soli file esclusivi, cancellazione `Company` che attiva i cascade, commit.
4. Dopo commit tentare la pulizia sincrona; eliminare una voce manifest solo quando il file è assente o la cancellazione è riuscita.
5. Implementare comando idempotente schedulato per ritentare le righe residue e riportare successi/fallimenti senza includere dati di altri Tenant.
6. Testare il grafo completo, rollback database, storage fallito/recuperato, file duplicato/assente e conservazione `User`/altri Tenant.

### Phase 5 - Creation and automatic processes

1. Estendere `CreateCompany` affinché crei la coppia senza spezzare il ritorno `Company`, audit o capacità; adattare `RegisterCompany` a restituire il Tenant collegato.
2. Filtrare `ProcessContractRenewalsCommand` tramite la relazione a un Tenant attivo prima dell'iterazione.
3. Mantenere la Gate dentro `ProcessContractRenewals` dopo i lock come seconda validazione; verificare il caso Archivio concorrente e il recupero idempotente dopo Ripristino.
4. Censire nuovamente scheduler/job/listener in review finale e applicare lo stesso contratto a ogni processo tenant-owned eventualmente aggiunto nel frattempo.

### Phase 6 - N+1 semantics

1. Migrare in avanti l'enum `closing_snapshots.next_exercise_disposition` da `not_created_management_terminated` a `not_created`, preservando righe storiche e ricreando coerentemente il check `closing_snapshots_next_exercise_shape`.
2. Rinominare l'input applicativo `management_continues` in `create_next_exercise` e i reason/code interni in termini N+1.
3. Aggiornare review, Close Action, validazione model, infolist, UI, factory e helper test.
4. Dimostrare che `Non creare N+1` non cambia Tenant e consente creazione manuale successiva se attivo.

### Phase 7 - Verification and release gate

1. Eseguire i test mirati dopo ciascuna fase e l'intera quality gate CI alla fine.
2. Applicare le migrazioni a una copia/test DB popolata e confrontare conteggi/chiavi prima e dopo.
3. Eseguire la verifica browser dei flussi Super Admin e dei dinieghi operativi.
4. Riesaminare diff, inventario tabelle/file/processi e copertura FR-TL-001–045; nessun rollout se resta una famiglia non verificata.

## Migration and Rollback Strategy

- Le quattro migrazioni sono forward-only rispetto alla storia esistente; nessun file già applicato viene modificato.
- `000100` crea e popola `tenant_companies` usando gli ID `companies`; il `down()` rimuove solo la nuova tabella e non tocca `companies`.
- `000200` sostituisce FK nominative una per una. Il `down()` ripristina le azioni referenziali originali; viene eseguito solo nel database test isolato. Prima del deploy devono passare prove su MySQL, non SQLite.
- `000300` crea il manifest globale; il rollback è vietato in ambiente condiviso se contiene righe pending, e il comando di verifica deve segnalarlo esplicitamente.
- `000400` rinomina il valore enum preservando i dati e ricrea il check `closing_snapshots_next_exercise_shape`; il `down()` ripristina temporaneamente la forma compatibile, rimappa `not_created` al valore storico, restringe l'enum e ricrea il check storico.
- Il deploy applica prima schema/backfill, poi codice capace di usare `TenantCompany`; nessun intervallo deve permettere nuove Company senza Tenant. Se l'ambiente non supporta deploy atomico, rilasciare prima codice compatibile dual-read è escluso perché introdurrebbe un fallback non richiesto: usare maintenance/deploy coordinato.
- Non esiste rollback applicativo della cancellazione definitiva; è un'azione utente irreversibile. Il solo recupero operativo riguarda file ancora pending, non dati DB già eliminati.

## Verification Matrix

| Area | Automated evidence | Direct evidence |
|---|---|---|
| 1:1 e backfill | Migration + model tests su MySQL | Query conteggi/duplicati da quickstart |
| Accesso attivo | Feature/Filament tests | Login e selezione Tenant |
| Archiviato | URL, Resource, route, download, Action tests | Browser: sparizione e diniego |
| Super Admin | Panel/policy/Action tests | Browser `/platform` senza Tenant attivi |
| Processi | Command + Action concurrency tests | Output comando mirato |
| Distruzione DB | Full graph test + failure injection | Conteggi pre/post |
| Storage | Storage fake failure/retry/idempotency | Command pending cleanup |
| N+1 | Closing Action/model/UI tests | Browser Chiusura |
| Regressione | Full CI quality workflow | Review diff e console browser |

## Complexity Tracking

| Introduced concept | Why needed | Simpler alternative rejected because |
|---|---|---|
| Secondo pannello Filament minimale | Gestione globale deve funzionare senza Tenant attivi e includere archiviati | Nel pannello tenant ogni Resource resta sotto `/{tenant}`; una Resource “unscoped” non rimuove il requisito di route tenant e un middleware con eccezioni mescolerebbe amministrazione globale e dominio. |
| Manifest globale `pending_file_deletions` | Filesystem/object storage non partecipa alla transazione MySQL | Cancellare prima del commit perde file su rollback; cancellare dopo senza manifest perde il lavoro su crash/fallimento. |
| Migrazione sistematica delle FK | La cancellazione deve coprire l'intero grafo e superare cicli/restrict senza bypass | Una lista di `DELETE` applicativi duplica il grafo, aggira i model guard e diventa facilmente incompleta quando cambia lo schema. |
