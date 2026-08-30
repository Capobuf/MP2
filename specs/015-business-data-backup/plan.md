# Implementation Plan: MP2 Business Data Backup

**Branch**: `015-business-data-backup` | **Date**: 2026-08-30 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/015-business-data-backup/spec.md`

## Summary

Implementare un contratto XLSX V1 esplicito e simmetrico: un collector legge una singola Azienda in una transazione MySQL a snapshot coerente, converte ogni relazione in riferimenti portabili e produce viste business più fogli macchina nascosti con checksum. Un validator carica e valida integralmente il workbook senza scritture; un restorer dedicato crea in una sola transazione una nuova Azienda/Tenant, importa il grafo in ordine, ricostruisce riferimenti e verifica i totali. UI tenant, UI piattaforma, comando e destinazione Drive riusano gli stessi servizi.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13.17, Filament 5, `maatwebsite/excel` 4.0 / PhpSpreadsheet 5.x, `yaza/laravel-google-drive-storage` 5.x

**Storage**: MySQL 8.4 per dominio e journal import; filesystem Laravel locale per artefatto temporaneo e disk `google` opzionale

**Testing**: Pest 4, Feature/Livewire su MySQL isolato, fake filesystem Drive, confronto dei read model reporting S11

**Target Platform**: Linux/shared hosting con PHP 8.3 e scheduler Laravel

**Project Type**: applicazione web Laravel/Filament con comando Artisan

**Performance Goals**: nessun limite arbitrario di record; generazione e import completano per dataset rappresentativi senza troncamento, mantenendo memoria proporzionata tramite righe materializzate e file temporaneo

**Constraints**: stato export coerente; XLSX singolo; limite cella; decimal esatti; nessuna formula; import pre-validato, atomico e idempotente; nessun accesso a Tenant archiviati; nessun ID sorgente nel package

**Scale/Scope**: 27 fogli macchina stabili, 11 viste leggibili, intero grafo business corrente di una Azienda, nessun binario allegato

## Constitution Check

### Pre-design gate

- **Canonical authority**: PASS. Il formato trasporta le primitive esistenti e non introduce una nuova regola economica.
- **Simplicity**: PASS. Due porte applicative (`export`, `restore`) e value object del package; nessun ETL generico, repository layer, queue, cache o sync.
- **Vertical slices**: PASS. Contratto/manifest, export, validazione/preview, restore e superfici sono verificabili in sequenza.
- **Dependency integrity**: PASS. Le dipendenze sono richieste direttamente dal formato XLSX e dal disk Drive; nessun sorgente vendor viene modificato.
- **Explicit operations**: PASS. Snapshot read, validation e restore risiedono in classi dedicate; Filament resta presentazione.
- **Test discipline**: PASS. Sono previsti test di rejection, rollback, retry, round-trip, reporting, Riprogrammazione, Contratto e Revisione.
- **Historical/transactional integrity**: PASS. Snapshot importate non vengono ricalcolate; import e journal condividono una transazione.
- **Operational discipline**: PASS. Nessun refactor o comportamento di roadmap futuro è incluso.

### Post-design gate

PASS senza eccezioni. Il contratto V1 elenca esplicitamente ogni foglio e colonna; il piano non dipende da introspezione schema o serializzazione Eloquent generica. Le sole nullabilità nuove corrispondono esattamente agli autori storici indisponibili e `proposal_id` del Budget importato.

## Project Structure

### Documentation (this feature)

```text
specs/015-business-data-backup/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── workbook-v1.md
│   ├── restore.md
│   └── interfaces.md
├── checklists/
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/BusinessBackup/
│   ├── ExportBusinessBackup.php
│   ├── ImportBusinessBackup.php
│   └── StoreBusinessBackupOnDrive.php
├── BusinessBackup/
│   ├── V1/BusinessBackupContract.php
│   ├── V1/BusinessBackupCollector.php
│   ├── V1/BusinessBackupValidator.php
│   ├── V1/BusinessBackupWorkbook.php
│   ├── V1/PortablePayload.php
│   └── BackupPreview.php
├── Console/Commands/ExportBusinessBackupCommand.php
├── Filament/Pages/BusinessDataBackup.php
├── Filament/Platform/Pages/ImportCompanyBackup.php
└── Models/BusinessBackupImport.php

database/migrations/
├── 2026_08_30_000100_prepare_business_backup_restore.php
└── 2026_08_30_000200_create_business_backup_imports_table.php

config/filesystems.php
composer.json
composer.lock
app/Installer/Steps/CheckRequirements.php
.github/workflows/ci.yml

tests/
├── Feature/BusinessBackup/
└── Unit/BusinessBackup/
```

**Structure Decision**: mantenere il dominio corrente invariato; collocare soltanto il contratto seriale V1 e la sua validazione in `app/BusinessBackup/V1`, mentre transazioni e autorizzazione restano Actions/Policies/Filament secondo i pattern MP2.

## Delivery Slices

1. **Contratto e artefatto minimo**: dipendenze, migrazioni, manifest, fogli visibili/minimi, riferimenti portabili, checksum, testo sicuro e chunking.
2. **Export completo**: collector di tutto il grafo, viste leggibili, inventario allegati, download e comando.
3. **Validazione e preview**: parser read-only, schema/referential/domain checks e UI Platform Admin senza write.
4. **Restore atomico**: nuovo Tenant/Azienda, mapping, snapshot portabili, journal idempotente e rollback.
5. **Continuità operativa**: Riprogrammazione reversibile, Stima di sistema unica, Revisione da Budget importato e UI autore assente.
6. **Drive e release**: disk Google opzionale, salvataggio dello stesso file, runtime/shared-hosting e quality gate.

## Complexity Tracking

Nessuna violazione costituzionale da giustificare.
