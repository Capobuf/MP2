# Research: Reportistica ed esportazione

## Decision 1 — Un unico risultato normalizzato per UI e PDF

**Decision**: `BuildReport` carica dati Eloquent autorizzati e li normalizza in `ReportSource`; `ComparisonEngine` e gli aggregatori producono un solo `ReportResult` consumato sia dalla pagina Filament sia dal PDF.

**Rationale**: evita formule o semantiche duplicate fra presentazione ed export e rende verificabile la corrispondenza esatta richiesta dal §25.24.

**Alternatives considered**:

- calcolo direttamente nei callback Filament: rifiutato perché non riusabile e difficile da testare;
- persistenza di ogni report: rifiutata perché il dominio non definisce una nuova Snapshot e vieta baseline alternative;
- repository/service layer generalizzato: rifiutato perché aggiunge astrazione senza bisogno concreto.

## Decision 2 — Conoscenza Corrente degli Esercizi Chiusi

**Decision**: per un Esercizio Chiuso, ogni sorgente a Conoscenza Corrente parte dalla riga materializzata della Closing Snapshot. Le correzioni tardive vengono sommate per `source_origin_key`, conservando separatamente positive, negative, nette e singole righe. Le Annotazioni vengono collegate alle `affected_sources`, ma non modificano importi o imputazioni.

**Rationale**: applica §§6.10-6.12, 24.10-24.11 e 25.13 senza dipendere da oggetti vivi rinominati/archiviati e senza ricalcolare Residuo, Risparmio, Allocato non utilizzato o Riporto.

**Alternatives considered**:

- ricalcolo completo dagli oggetti vivi: rifiutato perché può cambiare etichette/imputazioni storiche e trasformare correzioni in una nuova Chiusura;
- sola somma netta: rifiutata perché nasconde correzioni positive e negative;
- applicare Annotazioni come riclassificazioni: vietato dal dominio.

## Decision 3 — Correlazione e classificazione

**Decision**: correlare identità condivise con OriginKey; rappresentare CopiedFromOriginKey come derivazione esplicita nei confronti fra Esercizi senza fondere le identità; non usare `Sostituisce`, fuzzy matching o euristiche. La categoria primaria deriva solo da presenza e dimensioni confrontate; etichette e dimensioni non incidono sul conteggio.

**Rationale**: applica §§23.13, 25.3, 25.5-25.7 e 25.18, oltre a INV-28.50-28.51.

**Alternatives considered**:

- matching per titolo/importo/Fornitore/Note: vietato;
- unire CopiedFromOriginKey come identità: rifiutato perché il dominio la definisce derivazione, non stessa Spesa;
- esporre `Sostituito` senza relazione: escluso dalla chiarificazione del 2026-08-24.

## Decision 4 — Aggregazione di primo livello e Fornitori

**Decision**: i conteggi generali usano soltanto Spese autonome, Progetti e Contratti. Progetti e Contratti includono una volta le Spese figlie. Il report Fornitori aggrega invece le Spese secondo il §25.22, attribuisce separatamente il Riporto del Progetto e non aggiunge il totale del Progetto sopra le figlie.

**Rationale**: preserva simultaneamente granularità canonica delle etichette e INV-28.52.

**Alternatives considered**:

- contare sorgente e Spese figlie: rifiutato per doppio conteggio;
- attribuire l'intero Progetto a un Fornitore: rifiutato perché le figlie possono avere Fornitori differenti;
- distribuire il Riporto fra Fornitori: non definito e quindi vietato.

## Decision 5 — PDF con dipendenza diretta minimale

**Decision**: aggiungere `dompdf/dompdf:^3.1.6` come dipendenza Composer diretta e usare la sua API senza wrapper Laravel.

**Current slice requirement**: la chiarificazione richiede un singolo PDF scaricato immediatamente con tabelle, metadati e drill-down multipagina.

**Why core is insufficient**: Laravel produce risposte/download ma non converte HTML in PDF; Filament esporta CSV/XLSX tramite un sottosistema asincrono, non PDF.

**Compatibility**: dompdf 3.1.6 dichiara PHP `^7.1 || ^8.0`, `ext-dom` e `ext-mbstring`; l'immagine Sail PHP 8.3 corrente include DOM, mbstring, GD, Imagick e zlib. Un `composer require --dry-run` ha risolto 3.1.6 senza conflitti.

**Maintenance**: il progetto è attivo e 3.1.6 è una release disponibile nel 2026.

**License**: LGPL-2.1-only.

**Security implications**: le advisory GHSA-j8qw-6jw8-r297, GHSA-f5gf-2cj8-52g2 e GHSA-7x2p-4jvh-6384 interessano versioni `<=3.1.5` e risultano corrette in 3.1.6. MP2 renderizza soltanto un template Blade posseduto dal progetto, escapa i valori, disabilita risorse remote e non interpreta HTML/SVG fornito dall'utente. Il chroot non sarà `/`.

**Custom code removed**: impaginazione PDF, font Unicode, tabelle multipagina e serializzazione binaria.

**Removal path**: il package resta confinato in `ReportPdfRenderer`; un renderer sostitutivo può implementare lo stesso ingresso `ReportResult -> bytes PDF` senza cambiare calcoli o UI.

**Alternatives considered**:

- FPDF già transitivo: rifiutato perché non è dipendenza diretta, richiede impaginazione manuale e font Unicode aggiuntivi;
- wrapper `barryvdh/laravel-dompdf`: rifiutato perché aggiunge un ulteriore layer non necessario;
- browser print/PDF: rifiutato perché non produce il download PDF server-side richiesto e rende la verifica dipendente dal browser;
- wkhtmltopdf/headless Chrome: rifiutati perché richiedono binari o infrastruttura esterna sproporzionata.

## Decision 6 — Download immediato autenticato e ricostruito server-side

**Decision**: un controller autenticato riceve soltanto parametri di definizione, verifica `visualizza` sull'Azienda, ricostruisce il report e restituisce `application/pdf` con `Content-Disposition: attachment`.

**Rationale**: non si accettano dal client totali o righe manipolabili; UI ed export restano consistenti usando lo stesso builder.

**Alternatives considered**:

- inviare al controller il risultato serializzato dal browser: rifiutato per integrità e tenant isolation;
- queue e notifica: rifiutate perché la consegna scelta è immediata e non esiste requisito di elaborazione differita;
- salvare PDF permanenti: rifiutato perché non richiesto e introdurrebbe retention/versioning non definiti.

## Decision 7 — Nessuna selezione silenziosa

**Decision**: la pagina parte in stato non generato e richiede scelte esplicite. Le opzioni dipendenti vengono mostrate solo quando applicabili; riferimenti mancanti o incoerenti producono errore comprensibile e nessun report parziale.

**Rationale**: FR-S11-033 e le istruzioni MP2 vietano fallback/default silenziosi. L'Azienda è il tenant visibile, non una scelta implicita nascosta.

**Alternatives considered**:

- ultima versione Budget selezionata automaticamente: rifiutata perché FR-014 richiede riferimento esplicito;
- sostituire Chiusura assente con Corrente: vietato;
- generare automaticamente il primo report disponibile: rifiutato perché nasconde il riferimento scelto.

