# Invariant Test Map

**Coverage:** 61 / 61 canonical invariants mapped.

Each invariant receives its first authoritative automated test in the primary slice
below. Later slices may add integration or regression coverage.

| Invariant | Canonical title | Primary test slice | Status |
|---|---|---|---|
| 28.1 | Tipi economici | S3 — Esercizi, Spese e Righe | verified |
| 28.2 | Presenza di Effettivi | S3 — Esercizi, Spese e Righe | verified |
| 28.3 | Importo autoritativo | S3 — Esercizi, Spese e Righe | verified |
| 28.4 | Appartenenza esclusiva | S5 — Contratti | planned |
| 28.5 | Sorgenti di primo livello | S5 — Contratti | planned |
| 28.6 | Nessun matching | S3 — Esercizi, Spese e Righe | verified |
| 28.7 | Allocato della Spesa autonoma | S3 — Esercizi, Spese e Righe | verified |
| 28.8 | Allocato del Progetto | S4 — Progetti | verified |
| 28.9 | Allocato del Contratto | S5 — Contratti | planned |
| 28.10 | Scostamento Operativo | S3 — Esercizi, Spese e Righe | verified |
| 28.11 | Residuo e disponibilità riportabile | S8 — Riporto e Riprogrammazione | planned |
| 28.12 | Riporto entro il limite | S8 — Riporto e Riprogrammazione | planned |
| 28.13 | Effettivo negativo | S8 — Riporto e Riprogrammazione | planned |
| 28.14 | Riporto esclusivo del Progetto | S8 — Riporto e Riprogrammazione | planned |
| 28.15 | Modalità di rinvio | S8 — Riporto e Riprogrammazione | planned |
| 28.16 | Progetto terminale | S8 — Riporto e Riprogrammazione | planned |
| 28.17 | Budget immutabile | S6 — Proposta e Budget iniziale | planned |
| 28.18 | Revisioni | S7 — Revisioni, riallineamento e impatto multi-Esercizio | planned |
| 28.19 | Proposta isolata | S6 — Proposta e Budget iniziale | planned |
| 28.20 | Proposta solo sul piano | S6 — Proposta e Budget iniziale | planned |
| 28.21 | Nuovi oggetti proposti | S6 — Proposta e Budget iniziale | planned |
| 28.22 | Riallineamento per sorgente | S7 — Revisioni, riallineamento e impatto multi-Esercizio | planned |
| 28.23 | Approvazione atomica | S6 — Proposta e Budget iniziale | planned |
| 28.24 | Stato alla data | S4 — Progetti | verified |
| 28.25 | Stato alla Chiusura | S9 — Chiusura | planned |
| 28.26 | Nessuna riapertura | S9 — Chiusura | planned |
| 28.27 | Chiusura atomica | S9 — Chiusura | planned |
| 28.28 | Chiusura cronologica | S9 — Chiusura | planned |
| 28.29 | Correzioni tardive | S10 — Correzioni post-Chiusura | planned |
| 28.30 | Nessuna riclassificazione storica | S10 — Correzioni post-Chiusura | planned |
| 28.31 | Riporto storico invariato | S10 — Correzioni post-Chiusura | planned |
| 28.32 | Durata e ciclo contrattuale distinti | S5 — Contratti | planned |
| 28.33 | Rinnovo automatico | S5 — Contratti | planned |
| 28.34 | Condizioni non sovrapposte | S5 — Contratti | planned |
| 28.35 | Decorrenza delle modifiche contrattuali | S5 — Contratti | planned |
| 28.36 | Nessun differimento silenzioso | S5 — Contratti | planned |
| 28.37 | Ricorrenze ancorate | S5 — Contratti | planned |
| 28.38 | Data di attribuzione | S5 — Contratti | planned |
| 28.39 | Nessun prorata | S5 — Contratti | planned |
| 28.40 | Stima di Contratto | S5 — Contratti | planned |
| 28.41 | Spese manuali di Contratto | S5 — Contratti | planned |
| 28.42 | Classificazione annuale | S5 — Contratti | planned |
| 28.43 | Ereditarietà del Centro di Costo | S5 — Contratti | planned |
| 28.44 | Archivio non economico | S2 — Anagrafiche | verified |
| 28.45 | Nessuna cancellazione fisica ordinaria | S2 — Anagrafiche | verified |
| 28.46 | Identità non riutilizzabile | S2 — Anagrafiche | verified |
| 28.47 | Snapshot autonome | S6 — Proposta e Budget iniziale | planned |
| 28.48 | Schema Budget | S6 — Proposta e Budget iniziale | planned |
| 28.49 | Schema Chiusura | S9 — Chiusura | planned |
| 28.50 | Previsto e Non previsto | S11 — Reportistica ed esportazione | planned |
| 28.51 | Categorie del confronto | S11 — Reportistica ed esportazione | planned |
| 28.52 | Nessun doppio conteggio | S5 — Contratti | planned |
| 28.53 | Valuta e IVA | S3 — Esercizi, Spese e Righe | verified |
| 28.54 | Annualità dell'Effettivo | S3 — Esercizi, Spese e Righe | verified |
| 28.55 | Copia fra Esercizi | S7 — Revisioni, riallineamento e impatto multi-Esercizio | planned |
| 28.56 | Scadenze contrattuali | S5 — Contratti | planned |
| 28.57 | Permessi per Azienda | S1 — Azienda, accesso e impostazioni | verified |
| 28.58 | Esercizio successivo | S9 — Chiusura | planned |
| 28.59 | Nessun Forecast | S0 — Foundation e ambiente di sviluppo live | verified |
| 28.60 | Relazioni informative | S5 — Contratti | planned |
| 28.61 | Plafond | S3 — Esercizi, Spese e Righe | verified |

## Test rule

An invariant cannot become `verified` solely because code appears to satisfy it.
Its primary slice must contain an automated test that would fail if the invariant
were violated.
