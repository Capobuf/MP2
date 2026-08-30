# Research: MP2 Business Data Backup

## Decision 1 — Libreria XLSX

**Decision**: usare `maatwebsite/excel:^4.0` (4.0.2 disponibile) e la sua dipendenza PhpSpreadsheet 5.x. Usare export object multi-sheet e binder stringa; accedere direttamente a PhpSpreadsheet soltanto per visibilità fogli, metadata, caricamento read-only controllato e ispezione del workbook.

**Rationale**: la versione 4 richiede PHP 8.3 e Laravel 12/13, quindi coincide con lo stack. Laravel Excel copre multi-sheet e storage; PhpSpreadsheet è necessario per i dettagli ammessi dal brief.

**Alternatives considered**: OpenSpout (scartato perché il contratto richiede sheet hidden, metadata e controllo celle più ricco); PhpSpreadsheet senza Laravel Excel (scartato perché duplicava integrazione export/storage già disponibile).

## Decision 2 — Google Drive

**Decision**: usare `yaza/laravel-google-drive-storage:^5.0`, compatibile con Laravel 13, come driver del disk `google`; scrivere tramite il normale filesystem Laravel lo stream dell'artefatto già generato.

**Rationale**: è il driver richiesto dal brief e non introduce un client custom.

**Alternatives considered**: Google API client diretto (troppo codice e OAuth fuori scope); conversione Sheets (vietata); nessun package (non offrirebbe il driver richiesto).

## Decision 3 — Snapshot coerente di export

**Decision**: raccogliere l'intero package in una transazione con isolamento MySQL `REPEATABLE READ`, materializzando array scalari prima del commit; generare il workbook fuori dalla transazione in un file temporaneo univoco.

**Rationale**: tutte le query vedono lo stesso snapshot senza bloccare le scritture per l'intera durata della serializzazione XLSX.

**Alternatives considered**: lock di tutto il grafo (eccessivo); query separate senza snapshot (incoerenti); generazione completa dentro la transazione (MVCC trattenuta inutilmente a lungo).

## Decision 4 — Contratto macchina e checksum

**Decision**: definire staticamente fogli/colonne V1 in codice e in `contracts/workbook-v1.md`. Tutti i valori macchina sono stringhe UTF-8 o null. Il checksum di un foglio è SHA-256 del JSON canonico `{"columns":[...],"rows":[[...],...]}` con ordine colonne e righe contrattuale, senza whitespace e con Unicode/slash non escaped.

**Rationale**: evita dipendenza dallo schema DB e rende export/import simmetrici e deterministici.

**Alternatives considered**: introspezione DB o `$fillable` (vietata); hash del file ZIP XLSX (instabile per metadata/packaging); firma (fuori V1).

## Decision 5 — Identità portabile

**Decision**: assegnare riferimenti sequenziali per tipo dopo ordinamento crescente dell'identità locale, senza esportare quell'identità. Gli Esercizi e Snapshot con chiave business unica usano anche anno/versione nel ref. Il mapping resta soltanto in memoria durante export/import.

**Rationale**: è semplice, non modifica PK e non usa matching semantico.

**Alternatives considered**: UUID permanenti (vietati); nomi/P.IVA/importi (fuzzy e ambigui); ID DB nel workbook (non portabili).

## Decision 6 — Payload lunghi

**Decision**: se un valore supera 30.000 byte UTF-8, o inizia letteralmente con `@payload:`, sostituirlo con `@payload:PAY-0000000001` e scrivere chunk consecutivi di massimo 30.000 byte in `_MP2_long_payloads` con le colonne definite dal contratto. Il validator richiede indici contigui da uno, confini validi UTF-8 e ricompone prima della validazione semantica.

**Rationale**: margine sotto 32.767 caratteri e ricomposizione esatta UTF-8.

**Alternatives considered**: byte chunk (può spezzare UTF-8); truncation o rifiuto (vietati); file esterno/ZIP (fuori V1).

## Decision 7 — Formula injection e precisione

**Decision**: binder esplicito stringa per tutte le celle macchina e per ogni testo business; solo celle di presentazione generate internamente e non autoritative possono essere numeriche. Nessuna formula. Decimal canonici con punto, niente esponente e scala massima specifica del campo.

**Rationale**: preserva esattezza e neutralizza prefissi formula-like senza alterare il contenuto.

## Decision 8 — Restore storico

**Decision**: validare tutto in memoria prima della transazione; nella transazione usare Query Builder/insert controllati in ordine di dipendenza e non le Actions riferite a oggi. Non disabilitare eventi globalmente o FK; bypassare selettivamente model event di immutabilità usando insert diretti.

**Rationale**: lo storico materializzato non deve essere reinterpretato; i vincoli DB restano attivi.

**Alternatives considered**: replay Actions/Timeline (cambierebbe i dati usando oggi); disabilitare FK (rischioso); import riga per riga con commit parziali (vietato).

## Decision 9 — Autori assenti e Budget senza Proposal

**Decision**: migration forward rende nullable soltanto `project_transitions.created_by_id`, `contract_renewal_configurations.created_by_id`, `contract_conditions.created_by_id`, `contract_lifecycle_facts.created_by_id`, `budget_snapshots.proposal_id`, `budget_snapshots.approved_by_id`, `closing_snapshots.closed_by_id`, `late_corrections.recorded_by_id`, `historical_error_annotations.recorded_by_id`; i check di annullamento richiedono data+motivo ma non un autore importato.

**Rationale**: corrisponde esattamente alla semantica richiesta e lascia intatte le Actions locali, che continuano a fornire l'utente.

**Alternatives considered**: utente fittizio/importatore come autore (falso); Proposal sintetica (vietata); tabella polimorfa autore storico (over-engineering).

## Decision 10 — Idempotenza import

**Decision**: tabella minima `business_backup_imports` con `package_id` unique, `company_id`, `format_version`, Platform Admin importatore e `completed_at`, creata nella stessa transazione del grafo.

**Rationale**: un fallimento non lascia journal né Azienda; un retry completato risolve la nuova Azienda.

## Decision 11 — Versione applicativa

**Decision**: usare `APP_REVISION` quando presente, altrimenti `null`; non invocare Git dal runtime.

**Rationale**: la release ZIP già incorpora una revisione diagnostica, mentre Git può non esistere su shared hosting.

## Decision 12 — Estensioni runtime

**Decision**: dichiarare/verificare `zip`, `xml`, `xmlreader`, `xmlwriter`, `dom`, `simplexml`, `mbstring`, `gd`, `fileinfo` oltre a quelle correnti richieste; aggiornare CI e contratto shared-hosting in base al lockfile risolto.

**Rationale**: PhpSpreadsheet 5 e il driver Drive devono fallire esplicitamente su host incompleti; nessun fallback custom.
