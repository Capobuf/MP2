# Data Model: MP2 Business Data Backup

## Persistenza nuova o modificata

### BusinessBackupImport

- `id`: identità tecnica locale.
- `package_id`: UUID del workbook, unique.
- `format_version`: `1`.
- `company_id`: nuova Azienda creata, FK unique e cascade con la distruzione Tenant.
- `imported_by_id`: Platform Admin locale, FK restrict.
- `completed_at`: timestamp tecnico UTC.
- timestamps ordinari.

Stato: esiste soltanto per import completati. Nessuno stato pending/failed persistito perché validazione e transazione non devono lasciare residui.

### FK autore importabili

Le FK elencate in research Decision 9 diventano nullable. Un valore null significa esclusivamente `Autore originale non disponibile`; non autorizza le Actions locali a omettere l'attore.

### BudgetSnapshot importato

`proposal_id=null`, `approved_by_id=null`, `approved_at` originale, lineage `previous_budget_id` ricostruita. Le versioni restano univoche per Esercizio. Una Proposal locale futura usa normalmente l'ultima Snapshot come `reference_budget_id`.

## Modello logico del package

### PortablePackage

- metadata manifest V1;
- ordered machine sheets;
- visible business views;
- counts and checksums;
- package-wide ref registry;
- optional long payload chunks.

### Portable reference registry

| Tipo | Formato |
|---|---|
| Azienda | `COM-0000000001` |
| Esercizio | `EXE-0000000001` |
| Fornitore / Contatto / CdC | `SUP-0000000001` / `CON-0000000001` / `CDC-0000000001` |
| Progetto / Transizione / Classificazione | `PRJ-0000000001` / `PTR-0000000001` / `PCL-0000000001` |
| Contratto / Config / Lifecycle / Condizione / Classificazione | `CTR-0000000001` / `RCF-0000000001` / `LCF-0000000001` / `CCN-0000000001` / `CCL-0000000001` |
| Link | `PCLN-0000000001` |
| Spesa / Riga | `EXP-0000000001` / `LIN-0000000001` |
| Rinvio | `DEF-0000000001` |
| Budget / Riga / Evidenza | `BUD-0000000001` / `BUR-0000000001` / `BEV-0000000001` |
| Chiusura / Riga | `CLS-0000000001` / `CLR-0000000001` |
| Correzione / Annotazione / Allegato | `LCR-0000000001` / `ANN-0000000001` / `ATT-0000000001` |
| Payload lungo | `PAY-0000000001` |

L'elenco normativo completo dei prefissi è in `contracts/workbook-v1.md`; ogni sequenza è assegnata dopo ordinamento per identità locale, che non viene esportata.

## Dipendenze di restore

```text
Company + Tenant + capabilities
├── Suppliers ── SupplierContacts
├── CostCenters
├── Exercises
├── Projects ── ProjectTransitions / ProjectClassifications
├── Contracts ── RenewalConfigs / LifecycleFacts / Conditions / Classifications
├── ProjectContractLinks
├── Expenses ── ExpenseLines
├── ProjectDeferrals (dopo Expenses/Lines per gli effects)
├── BudgetSnapshots ── BudgetRows / BudgetEvidence business-only
├── ClosingSnapshots ── ClosingRows
├── LateCorrections
└── HistoricalErrorAnnotations

BusinessBackupImport (stessa transazione, dopo verifiche finali)
```

## Validazioni principali

- Registry `package_ref` globalmente coerente per tipo e senza duplicati.
- Ogni FK portabile risolve un ref del tipo atteso e della stessa Azienda.
- Enum uguali ai vocabolari correnti.
- Money scala 2; quantity/unit amount scala massimo 6; niente esponente.
- Una Spesa non ha contemporaneamente Progetto e Contratto; system expense richiede Contratto ed è unica per Contratto/Esercizio.
- Versioni Budget contigue da 1, purpose coerente e predecessor esatto.
- Un Esercizio chiuso ha esattamente una Chiusura e viceversa.
- Correzioni/Annotazioni riferiscono Esercizio e Chiusura coerenti.
- Riprogrammazione usa ref di righe/spese origine/destinazione esistenti e importi riconciliati.
- Totali header/righe e totali di controllo coincidono con aritmetica decimale.

## Transizioni

Il package e il journal non hanno workflow applicativo. Il validator produce `valid` o errore; la preview è un value object effimero. Il restore produce atomicamente un journal completato oppure nessuna persistenza.
