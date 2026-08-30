# Quickstart: Verifica S11 Reportistica ed esportazione

Questa guida descrive le evidenze necessarie per dimostrare S11. Tutte le operazioni distruttive sono consentite solo sul database `testing` secondo `docs/testing-policy.md`.

## Prerequisites

- container Sail e MySQL attivi;
- dipendenze Composer installate dal lockfile;
- database `testing` disponibile;
- utente browser con `visualizza` sull'Azienda demo e nessuna autorizzazione su una seconda Azienda di controllo.

## Focused automated checks

```bash
docker compose exec -T laravel.test vendor/bin/pest tests/Unit/Domain/Reporting tests/Feature/Reporting
docker compose exec -T laravel.test vendor/bin/pint --test app/Actions/Reporting app/Domain/Reporting app/Filament/Pages/Reports.php app/Http/Controllers/ReportPdfController.php app/Support/Reporting tests/Unit/Domain/Reporting tests/Feature/Reporting
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
```

## Deterministic matrix

### INV-28.50 / FR-087

Costruire:

- una Spesa autonoma;
- un Progetto con due Spese figlie, una aggiunta dopo Budget;
- un Contratto con Stima di sistema e Spese Effettive manuali.

Verificare che `Previsto`, `Non previsto` e `Previsto e non avvenuto` compaiano soltanto sulle tre sorgenti primarie. Le figlie mostrano fatti neutrali e non incrementano conteggi.

### INV-28.51 / FR-088

Esercitare assenza/presenza e cambi dimensione per produrre esattamente:

- Invariato;
- Aggiunto;
- Rimosso;
- Modificato.

Una sorgente con più dimensioni/etichette resta conteggiata una volta. Storno o azzeramento materializzato resta Modificato.

### INV-28.52 regression

Riconciliare:

- totale annuale con Spesa autonoma + Progetto + Contratto;
- Progetto/Contratto con somma delle rispettive figlie;
- report Fornitori con le stesse Spese e `Riporto senza Fornitore`.

La differenza attesa a ogni livello è `0.00`; il totale del Progetto non viene risommato nel report Fornitori.

## Reference matrix

Verificare tutte le combinazioni del §25.2:

1. Budget selezionato ↔ Corrente;
2. Budget selezionato ↔ Chiusura;
3. Budget selezionato ↔ Conoscenza Corrente;
4. Budget ↔ Budget;
5. Chiusura ↔ Conoscenza Corrente;
6. Esercizio ↔ Esercizio con stessa misura.

Per ogni report controllare Azienda, Esercizio, date, versione Budget, tipo Effettivo, generazione, filtri e EUR/netto IVA. Una misura diversa nei due Esercizi o un riferimento assente deve essere rifiutato.

## Closed-year correction matrix

Partire da una Chiusura con Effettivo 100, Risparmio e Riporto materializzati. Aggiungere:

- correzione +30;
- correzione -10;
- Annotazione di errore storico.

Atteso:

- Chiusura 100;
- positive 30;
- negative -10;
- net 20;
- Conoscenza Corrente 120;
- Risparmio e Riporto identici alla Snapshot;
- Annotazione visibile con zero impatto economico;
- singole correzioni visibili, non solo il netto.

## Archive/autonomy matrix

Dopo aver creato Budget e Chiusura:

- rinominare o archiviare oggetti dove consentito;
- generare report storico dalla Snapshot;
- verificare che label, Fornitore, Centro di Costo e valori materializzati non cambino.

## MUST NOT matrix

Verificare il rifiuto o l'assenza di:

- matching per titolo, importo, Fornitore o Note;
- etichetta `Sostituito`;
- ricalcolo di Residuo/Risparmio/Riporto dopo correzioni;
- Annotazioni che cambiano totali;
- righe figlie contate come primarie;
- report o PDF che scrivono Effettivi/Snapshot;
- fallback all'ultimo Budget o alla situazione Corrente;
- riferimenti/filter cross-tenant;
- PDF da HTML/risorse remote fornite dall'utente.

## PDF verification

Per un report completo:

- risposta 200;
- `application/pdf`;
- attachment filename coerente e sanitizzato;
- body con firma `%PDF-`;
- testo contenente tutti i metadati, definizioni, sorgenti, drill-down, correzioni e Annotazioni;
- valori e conteggi uguali al `ReportResult` UI;
- nessun file/report persistito.

## Authenticated browser journey

1. Accedere a `/admin/<azienda>/reports`.
2. Verificare il chooser iniziale e l’assenza di Esercizio, Budget o tipo Effettivo impliciti.
3. Scegliere la famiglia Budget vs Actual.
4. Selezionare esplicitamente Esercizio, Budget e tipo Effettivo e verificare la comparsa automatica del report, senza un’azione Genera.
5. Modificare il tipo Effettivo o il Budget e verificare l’aggiornamento automatico di header, indicatori, grafici e tabelle.
6. Aprire Filtri, selezionare un Fornitore e verificare aggiornamento automatico e chip del filtro attivo anche a pannello chiuso.
7. Aprire il drill-down fino a Spese figlie, Righe ed eventi, verificando l’assenza di dump JSON.
8. Passare a un Esercizio Chiuso e verificare Chiusura, Conoscenza Corrente e correzioni; cambiare inoltre famiglia verso Progetti, Contratti, Riporti e Fornitori.
9. Scaricare il PDF del report visibile, aprirlo e verificare riferimenti e filtri identici; tentare poi un URL di report/PDF per una seconda Azienda non autorizzata.
10. Ripetere le superfici principali a viewport desktop, tablet e mobile e verificare nessun errore console, pagina, Livewire o risposta HTTP >= 400 nel viaggio autorizzato.

## Full quality gate

Eseguire l'equivalente corrente della CI:

```bash
composer validate --strict
composer audit --locked --no-interaction
npm ci --no-audit --no-fund
npm run build
docker compose exec -T laravel.test env APP_ENV=testing DB_DATABASE=testing php artisan migrate --force --no-interaction
docker compose exec -T laravel.test vendor/bin/pint --test
docker compose exec -T laravel.test vendor/bin/phpstan analyse --no-progress
docker compose exec -T laravel.test vendor/bin/pest
git diff --check
```

Prima di marcare S11 `verified`, registrare risultati, conteggi test/assertion, viaggio browser, PDF ispezionato e stato CI. S9 resta invariato finché non viene fornita la sua evidenza formale separata.

## Evidenze registrate — 24 agosto 2026

Stato finale della slice: `implemented`. Non è stata svolta una dimostrazione indipendente sufficiente per promuoverla a `verified`; S9 resta invariato.

### Verifica automatizzata

- suite Reporting mirata: **46 test superati, 172 asserzioni**, durata 52,55 s;
- suite completa: **653 test superati, 4.496 asserzioni**, durata 135,49 s;
- Pint: **596 file**, nessuna violazione;
- PHPStan: nessun errore;
- `composer validate --strict`: superato;
- `composer audit --locked --no-interaction`: nessun advisory;
- build frontend dopo `npm ci`: superata;
- migrazioni sull'ambiente isolato `testing`: nessuna migrazione pendente;
- `git diff --check`: superato.

### Verifica browser e PDF

Nel browser autenticato sono state generate e ispezionate tutte le dieci famiglie disponibili: report annuale, Budget vs Actual, Budget vs Allocato Corrente, Scostamenti Operativi, versioni Budget, Esercizi, Riporti, Contratti, Progetti e Fornitori. Sono stati inoltre verificati drill-down, stato iniziale senza riferimenti impliciti, filtri espliciti e assenza di errori console/Livewire nel percorso autorizzato.

Il filtro Fornitore ha mostrato il nome selezionato sia nella pagina sia nel documento. Il PDF autenticato è stato scaricato, aperto e ispezionato in tre pagine: header, riferimenti, filtro, riepilogo, righe e dettaglio risultavano presenti; la ricostruzione è avvenuta lato server senza persistenza del report.

### Limiti della dimostrazione browser

L'utente demo disponibile era autorizzato su entrambe le Aziende presenti, quindi non poteva dimostrare nel browser un rifiuto cross-tenant reale. Inoltre gli Esercizi disponibili nel database di sviluppo erano aperti. I due comportamenti sono coperti rispettivamente da `ReportAuthorizationTest` e `ClosedKnowledgeReportTest`, inclusi riferimento e filtro cross-tenant, Chiusura immutabile, correzioni tardive e Conoscenza Corrente. Questi limiti impediscono di dichiarare S11 `verified`, ma non lasciano lavoro implementativo aperto.

### Confini confermati

- `Sostituito` non è esposto per decisione canonica permanente; FR-095 e INV-28.60 sono verificati in S5 per `Collegato a`;
- S9 non è stato modificato;
- nessun reset o mutazione del database persistente di sviluppo è stato eseguito;
- nessun report o file PDF è stato persistito dall'applicazione.
