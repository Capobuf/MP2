# Implementation Plan: Reportistica ed esportazione

**Branch**: `012-reporting-exports` | **Date**: 2026-08-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/012-reporting-exports/spec.md`

## Summary

S11 aggiunge una pagina Filament aziendale di sola lettura che richiede riferimenti espliciti, normalizza Budget Snapshot, situazione viva, Closing Snapshot e Conoscenza Corrente in sorgenti di primo livello, quindi applica un motore deterministico per categorie, dimensioni, etichette, aggregazioni e drill-down. La stessa struttura `ReportResult` alimenta la UI e un singolo PDF scaricato immediatamente, così metadati e valori non possono divergere. Nessun report viene persistito e nessuna Snapshot o Riga Effettivo viene modificata.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13.17+, Filament 5, Eloquent; aggiunta pianificata di `dompdf/dompdf:^3.1.6` per il solo rendering PDF

**Storage**: MySQL 8.4 esistente; nessuna nuova tabella o persistenza di report/export

**Testing**: Pest, test Unit per regole deterministiche, Feature/Livewire per query/UI/autorizzazione/download, verifica browser autenticata

**Target Platform**: applicazione web Linux/Sail esistente

**Project Type**: applicazione web Laravel/Filament multi-tenant

**Performance Goals**: nessun obiettivo numerico definito dalla fonte canonica; generazione sincrona coerente con il download immediato scelto, senza introdurre queue/cache

**Constraints**: isolamento tenant e capacità `visualizza`; calcoli monetari decimali esatti; Snapshot immutabili; nessun doppio conteggio; nessun matching fuzzy; riferimenti e filtri espliciti; PDF semanticamente completo; nessuna risorsa remota nel renderer PDF

**Scale/Scope**: tutte le sorgenti e i drill-down degli Esercizi selezionati nella singola Azienda corrente; il dominio non definisce soglie di volume o concorrenza

## Constitution Check

*GATE: PASS prima della ricerca; PASS dopo il design.*

- **I — Autorità canonica**: PASS. Le regole derivano dai §§6.4, 6.10-6.12, 9.2, 13.7, 14.9, 22-25, 26.5-26.6 e 28.47-28.53. `Sostituito` resta escluso senza colmare FR-095/INV-28.60.
- **II — Semplicità**: PASS. Una pagina, un builder Eloquent, strutture dati dirette, calcoli deterministici e un renderer PDF; nessun repository layer, CQRS, cache, queue, Redis o feature flag.
- **III — Slice e tracciabilità**: PASS. Il piano copre solo S11 e riconcilia FR-014, FR-043, FR-087-FR-089 e FR-096 con INV-28.50-28.52.
- **IV — Dipendenze**: PASS. `dompdf/dompdf:^3.1.6` è giustificato in [research.md](research.md); non verrà modificato codice sotto `vendor/` e il lockfile sarà aggiornato tramite Composer.
- **V — Operazioni esplicite**: PASS. La UI raccoglie input; caricamento/normalizzazione e calcoli restano separati e testabili. Non esistono nuove mutazioni di dominio.
- **VI — Test proporzionali**: PASS. Unit per classificazione/formule; Feature/Livewire per riferimenti, tenant, PDF e MUST NOT; regressioni autorevoli per INV-28.50-28.52.
- **VII — Sviluppo ispezionabile**: PASS. Si riusa Sail e il gate CI; ogni fase termina con superficie autenticata bootabile.
- **VIII — Integrità storica**: PASS. Conoscenza Corrente deriva dalla Chiusura materializzata più correzioni, senza ricalcolare valori di Chiusura; le Annotazioni restano non economiche.
- **IX — Disciplina operativa**: PASS. Le fasi sotto hanno non più di otto task sostanziali e non anticipano funzionalità successive.

## Project Structure

### Documentation (this feature)

```text
specs/012-reporting-exports/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── reporting-ui.md
│   └── pdf-export.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/Reporting/
│   └── BuildReport.php
├── Domain/Reporting/
│   ├── ActualReference.php
│   ├── ComparisonCategory.php
│   ├── ComparisonEngine.php
│   ├── ModificationDimension.php
│   ├── ReportAggregator.php
│   ├── ReportDefinition.php
│   ├── ReportKind.php
│   ├── ReportReference.php
│   ├── ReportResult.php
│   ├── ReportSource.php
│   └── SecondaryLabel.php
├── Filament/Pages/
│   └── Reports.php
├── Http/Controllers/
│   └── ReportPdfController.php
└── Support/Reporting/
    └── ReportPdfRenderer.php

resources/views/
├── filament/pages/reports.blade.php
└── reports/pdf.blade.php

routes/
└── web.php

tests/
├── Unit/Domain/Reporting/
│   ├── ComparisonEngineTest.php
│   ├── ReportDefinitionTest.php
│   └── ReportingAggregationTest.php
└── Feature/Reporting/
    ├── ReportBuilderTest.php
    ├── ReportAuthorizationTest.php
    ├── ClosedKnowledgeReportTest.php
    ├── ReportUiTest.php
    ├── ReportPdfTest.php
    ├── ReportingMustNotTest.php
    ├── ReportingRequirementsTest.php
    ├── SpecializedReportsTest.php
    └── S11InvariantTest.php
```

**Structure Decision**: estendere direttamente l'applicazione Laravel esistente. `BuildReport` è il confine Eloquent di sola lettura; `Domain/Reporting` contiene esclusivamente vocabolari, strutture e calcoli deterministici; Filament e il renderer PDF consumano lo stesso `ReportResult`.

## Design Phases

### Phase A — Vocabolario e confronto deterministico

Definire riferimenti, famiglie di report, categorie, dimensioni ed etichette ammesse (senza `Sostituito`), poi implementare correlazione esatta e classificazione pura. Verificare subito FR-S11-005-FR-S11-016 e INV-28.50-28.51.

### Phase B — Normalizzazione delle fonti e aggregazioni

Caricare per Azienda/Esercizio Budget materializzati, sorgenti vive, Chiusura e Conoscenza Corrente. Per gli anni Chiusi, comporre Conoscenza Corrente dalla riga di Chiusura più le correzioni raggruppate per `source_origin_key`, mantenendo imputazione, etichetta e stato materializzati. Costruire drill-down e report specialistici senza doppio conteggio, con regressione INV-28.52.

### Phase C — UI Filament e autorizzazione

Creare una pagina `Report` accessibile solo con `visualizza`, senza selezioni implicite. L'utente sceglie Esercizio, famiglia, riferimenti, Budget, tipo Effettivo e filtri applicabili prima di generare. Mostrare header, riepilogo, righe, spiegazioni e drill-down, inclusi stati vuoti/invalidi espliciti.

### Phase D — PDF immediato

Aggiungere `dompdf/dompdf:^3.1.6`, renderer controllato e controller autenticato. Ricostruire server-side lo stesso report dagli stessi parametri validati; non fidarsi di dati calcolati dal client. Il PDF include tutto il dettaglio e i metadati del contratto, usa DejaVu Sans, HTML escapato, risorse remote disabilitate e nessun HTML utente interpretato.

### Phase E — Verifica e tracciabilità

Eseguire test focalizzati, dimostrazione browser autenticata, riconciliazione completa dei requisiti, quality gate CI e diff review. Aggiornare S11 a `implemented` solo dopo codice/test; `verified` solo dopo dimostrazione indipendente e CI. Non modificare lo stato di S9 senza la sua evidenza formale mancante.
