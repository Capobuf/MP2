# Interface Contract: Esportazione PDF

## Request

Endpoint autenticato di download, invocato dalla pagina Report con i soli campi serializzabili di `ReportDefinition`.

### Required authorization

- sessione autenticata;
- Azienda esistente;
- capacità `visualizza` esatta sull'Azienda;
- ogni Esercizio, Budget e filtro appartiene alla stessa Azienda.

Un fallimento restituisce 403 o validazione 422/redirect con errori appropriati senza contenuto PDF o dati cross-tenant.

## Server behavior

1. valida forma e vocabolari della definizione;
2. autorizza l'Azienda;
3. risolve tutti i riferimenti tenant-scoped;
4. ricostruisce `ReportResult` con lo stesso builder usato dalla UI;
5. renderizza un template Blade posseduto dal progetto;
6. converte l'HTML in un singolo PDF;
7. restituisce il download immediato.

La richiesta non accetta righe, totali, categorie o HTML calcolati dal client.

## Response

- Status: 200.
- `Content-Type: application/pdf`.
- `Content-Disposition: attachment; filename="report-<azienda>-<esercizio>-<tipo>-<timestamp>.pdf"` con segmenti sanitizzati server-side.
- Body: bytes con firma PDF valida `%PDF-`.
- Nessun file permanente o record export.

## Required PDF content

### Cover/header

- Azienda ed Esercizio;
- titolo/famiglia;
- riferimenti e date economiche;
- versione Budget;
- tipo Effettivo;
- generato il;
- filtri/intervallo;
- EUR e importi netti IVA.

### Definitions

- definizioni delle quattro categorie primarie;
- definizioni delle etichette effettivamente presenti;
- spiegazione che etichette sovrapponibili non sono conteggi esclusivi;
- `Sostituito` assente.

### Data

- riepilogo e conteggi;
- tutte le sorgenti incluse;
- valori iniziali/finali/delta;
- categoria, dimensioni, etichette;
- drill-down completo applicabile;
- eventi, motivi e allegati decisionali come riferimenti leggibili;
- correzioni positive, negative, nette e singole;
- Annotazioni separate senza impatto economico.

## Rendering constraints

- `dompdf/dompdf` almeno 3.1.6.
- DejaVu Sans per testo Unicode italiano.
- A4; orientamento scelto in modo fisso dal template per contenere le colonne, non dall'utente.
- HTML e CSS statici posseduti da MP2.
- Valori utente/database escapati come testo; nessun raw HTML, SVG o CSS utente.
- Risorse remote disabilitate.
- Chroot ristretto agli asset strettamente necessari, mai `/`.
- Ogni istanza renderer produce un solo documento.

## Semantic equivalence

Dato lo stesso `ReportDefinition`, header, conteggi, sorgenti, valori, categorie, etichette, correzioni e Annotazioni del PDF devono coincidere con `ReportResult` mostrato dalla UI. Il test confronta il risultato sorgente e testo PDF estratto o ispezionabile, senza affidarsi a una seconda implementazione dei calcoli.

