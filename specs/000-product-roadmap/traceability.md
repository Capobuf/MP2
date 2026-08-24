# Canonical FR Traceability

**Coverage:** 101 / 101 canonical FRs mapped.

`Primary slice` means the first slice responsible for fully implementing and
verifying the requirement. A requirement may be exercised by later slices without
changing its traceability anchor.

| FR | Canonical requirement | Normative source | Primary slice | Status |
|---|---|---|---|---|
| FR-001 | Realtà operativa unica | §5.1 | S3 — Esercizi, Spese e Righe | verified |
| FR-002 | Soli tipi Stima ed Effettivo | §5.2 | S3 — Esercizi, Spese e Righe | verified |
| FR-003 | Importo autoritativo della Riga | §8.1 | S3 — Esercizi, Spese e Righe | verified |
| FR-004 | Nessun matching | §5.3 | S3 — Esercizi, Spese e Righe | verified |
| FR-005 | Appartenenza esclusiva della Spesa | §7.2 | S5 — Contratti | verified |
| FR-006 | Sorgenti economiche di primo livello | §5.6 | S5 — Contratti | verified |
| FR-007 | Nessun doppio conteggio | §§5.6, 8.6 | S5 — Contratti | verified |
| FR-008 | Identità stabile e OriginKey | §5.4 | S3 — Esercizi, Spese e Righe | verified |
| FR-009 | Nessuna cancellazione fisica ordinaria | §§5.7, 24.1 | S2 — Anagrafiche | verified |
| FR-010 | Archivio non economico | §§5.8, 24.3 | S2 — Anagrafiche | verified |
| FR-011 | Budget Approvato immutabile | §§1.2, 13 | S6 — Proposta e Budget iniziale | verified |
| FR-012 | Versione iniziale v1 preservata | §13.4 | S6 — Proposta e Budget iniziale | verified |
| FR-013 | Revisioni sempre disponibili in Esercizio Aperto | §§12.3, 13.4, 26.3 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-014 | Versione Budget esplicita nei report | §§13.7, 25 | S11 — Reportistica ed esportazione | planned |
| FR-015 | Proposta isolata | §§1.3, 12.1 | S6 — Proposta e Budget iniziale | verified |
| FR-016 | Una Proposta attiva per Esercizio | §12.2 | S6 — Proposta e Budget iniziale | verified |
| FR-017 | Proposta limitata al piano | §12.6 | S6 — Proposta e Budget iniziale | verified |
| FR-018 | Inizializzazione deterministica della Proposta | §§7.6.2, 12.4 | S6 — Proposta e Budget iniziale | verified |
| FR-019 | Copia con nuova identità e lineage obbligatoria | §12.4 | S6 — Proposta e Budget iniziale | verified |
| FR-020 | Azioni di piano sulla Spesa | §12.7 | S6 — Proposta e Budget iniziale | verified |
| FR-021 | Azioni di piano sul Progetto | §12.8 | S6 — Proposta e Budget iniziale | verified |
| FR-022 | Azioni di piano sul Contratto | §12.9 | S6 — Proposta e Budget iniziale | verified |
| FR-023 | Relazioni tra nuovi ProposalItem | §12.10 | S6 — Proposta e Budget iniziale | verified |
| FR-024 | Riallineamento dell'intera sorgente | §§12.11–12.12 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-025 | Presa visione delle nuove sorgenti | §12.13 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-026 | Incoerenze della Proposta definite in modo chiuso | §12.14 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-027 | Precondizioni di approvazione | §§12.15, 13.2 | S6 — Proposta e Budget iniziale | verified |
| FR-028 | Approvazione atomica su tutti gli Esercizi interessati | §13.3 | S6 — Proposta e Budget iniziale | verified |
| FR-029 | Applicazione futura senza riscrivere Esercizi Chiusi | §§10, 13.3 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-030 | Scarto senza rollback della realtà | §12.16 | S7 — Revisioni, riallineamento e impatto multi-Esercizio | implemented |
| FR-031 | Più Esercizi Aperti | §11.1 | S3 — Esercizi, Spese e Righe | verified |
| FR-032 | Divieto di Effettivi in un anno futuro | §11.3 | S3 — Esercizi, Spese e Righe | verified |
| FR-033 | Dichiarazione annuale autoritativa dell'Effettivo | §§6.4, 11.3 | S3 — Esercizi, Spese e Righe | verified |
| FR-034 | Chiusura cronologica | §11.5 | S9 — Chiusura | implemented |
| FR-035 | Nessuna riapertura globale | §§11.6, 14.9 | S9 — Chiusura | implemented |
| FR-036 | Stato di Chiusura al 31 dicembre | §§9.2, 14.1 | S9 — Chiusura | implemented |
| FR-037 | Controlli bloccanti di Chiusura | §14.3 | S9 — Chiusura | implemented |
| FR-038 | Avviso generale Allocato positivo e assenza di Effettivi | §14.4 | S9 — Chiusura | implemented |
| FR-039 | Chiusura atomica | §14.7 | S9 — Chiusura | implemented |
| FR-040 | Creazione condizionale di N+1 | §§11.7, 14.7 | S9 — Chiusura | implemented |
| FR-041 | Chiusura senza Budget | §14.2 | S9 — Chiusura | implemented |
| FR-042 | Correzione tardiva append-only | §24 | S10 — Correzioni post-Chiusura | planned |
| FR-043 | Distinzione Chiusura e Conoscenza Corrente | §§6.10–6.12, 24.11 | S11 — Reportistica ed esportazione | planned |
| FR-044 | Nessuna riclassificazione economica storica | §§14.9, 24.10 | S10 — Correzioni post-Chiusura | planned |
| FR-045 | Errori post-Chiusura annotati e corretti solo negli anni Aperti | §14.9 | S10 — Correzioni post-Chiusura | planned |
| FR-046 | Struttura della Spesa | §15.1 | S5 — Contratti | verified |
| FR-047 | Correlazione manuale della Spesa autonoma | §§15.2, 25.3 | S3 — Esercizi, Spese e Righe | verified |
| FR-048 | Spese manuali di Contratto con soli Effettivi | §15.4 | S5 — Contratti | verified |
| FR-049 | Unica Stima annuale di sistema del Contratto | §§15.4, 18.18 | S5 — Contratti | verified |
| FR-050 | Cambio Esercizio della Spesa | §15.7 | S3 — Esercizi, Spese e Righe | verified |
| FR-051 | Cambio contenitore | §15.8 | S5 — Contratti | verified |
| FR-052 | Riclassificazione integrale di una Spesa con Effettivi | §15.9 | S5 — Contratti | verified |
| FR-053 | Storno soltanto senza Effettivi | §15.11 | S3 — Esercizi, Spese e Righe | verified |
| FR-054 | Nessun tipo Imprevista, Preventivo o Plafond | §§15.12–15.14 | S3 — Esercizi, Spese e Righe | verified |
| FR-055 | Stati del Progetto | §16.2 | S4 — Progetti | verified |
| FR-056 | Effettivi compatibili con lo stato del Progetto | §16.5 | S4 — Progetti | verified |
| FR-057 | Transizioni del Progetto valutate alla data | §§9.3, 16.4 | S4 — Progetti | verified |
| FR-058 | Avvisi di Sovraspesa | §16.8 | S4 — Progetti | verified |
| FR-059 | Modalità Nessuna, Riporto o Riprogrammazione mutuamente esclusive | §16.10 | S8 — Riporto e Riprogrammazione | verified |
| FR-060 | Formule del Riporto | §§8.4, 17 | S8 — Riporto e Riprogrammazione | verified |
| FR-061 | Effettivo negativo non genera Riporto oltre l'Allocato | §§17.5, 28.13 | S8 — Riporto e Riprogrammazione | verified |
| FR-062 | Dati contrattuali, rinnovo e scadenza | §18.2 | S5 — Contratti | verified |
| FR-063 | Separazione durata, ciclo e rinnovo | §18.3 | S5 — Contratti | verified |
| FR-064 | Stato del Contratto alla data | §§9.4, 18.4 | S5 — Contratti | verified |
| FR-065 | Rinnovo automatico e avanzamento della scadenza | §18.5 | S5 — Contratti | verified |
| FR-066 | Preavviso e termine di disdetta informativi | §18.6 | S5 — Contratti | verified |
| FR-067 | Cessazione | §18.7 | S5 — Contratti | verified |
| FR-068 | Riattivazione | §18.8 | S5 — Contratti | verified |
| FR-069 | Annullamento prima dell'attivazione | §18.9 | S5 — Contratti | verified |
| FR-070 | Condizioni economiche del Contratto | §18.10 | S5 — Contratti | verified |
| FR-071 | Condizioni non sovrapposte | §18.11 | S5 — Contratti | verified |
| FR-072 | Fornitore del Contratto immutabile dopo uso economico | §18.12 | S5 — Contratti | verified |
| FR-073 | Decorrenza al primo confine di ciclo utile | §18.13 | S5 — Contratti | verified |
| FR-074 | Comunicazione e conferma della data effettiva | §18.13 | S5 — Contratti | verified |
| FR-075 | Ricorrenze ancorate | §18.14 | S5 — Contratti | verified |
| FR-076 | Data di attribuzione della Stima | §18.16 | S5 — Contratti | verified |
| FR-077 | Nessun prorata | §18.17 | S5 — Contratti | verified |
| FR-078 | Effettivi contrattuali manuali senza matching | §18.19 | S5 — Contratti | verified |
| FR-079 | Centro di Costo annuale | §20 | S5 — Contratti | verified |
| FR-080 | Cambio Centro di Costo sull'intero Esercizio | §20.3 | S5 — Contratti | verified |
| FR-081 | Ereditarietà del Centro di Costo | §20.5 | S5 — Contratti | verified |
| FR-082 | Fornitore e Referenti | §21 | S2 — Anagrafiche | verified |
| FR-083 | Fornitore Archiviato utilizzabile nello storico | §§21.4, 24.6 | S10 — Correzioni post-Chiusura | planned |
| FR-084 | Timeline esplicativa append-only | §22 | S3 — Esercizi, Spese e Righe | verified |
| FR-085 | Schemi separati delle Snapshot | §23 | S6 — Proposta e Budget iniziale | verified |
| FR-086 | Snapshot autonome | §§23.2, 23.13 | S6 — Proposta e Budget iniziale | verified |
| FR-087 | Previsto e Non previsto solo al primo livello | §§25.3–25.4 | S11 — Reportistica ed esportazione | planned |
| FR-088 | Categoria primaria e etichette sovrapponibili | §§25.5–25.7 | S11 — Reportistica ed esportazione | planned |
| FR-089 | Report con riferimenti espliciti | §§25.1–25.2 | S11 — Reportistica ed esportazione | planned |
| FR-090 | Scadenze informative dei Contratti | §19 | S5 — Contratti | verified |
| FR-091 | Impostazioni minime per Azienda | §26 | S1 — Azienda, accesso e impostazioni | verified |
| FR-092 | Permessi assegnati per Azienda | §§26.5–26.6 | S1 — Azienda, accesso e impostazioni | verified |
| FR-093 | Audit di permessi e Impostazioni | §§26.8–26.10 | S1 — Azienda, accesso e impostazioni | verified |
| FR-094 | Operazioni inter-Esercizio atomiche | §10 | S5 — Contratti | verified |
| FR-095 | Relazioni informative senza effetto economico | §7.3 | S5 — Contratti | planned |
| FR-096 | Report separato delle correzioni e annotazioni | §§24.11, 25.13 | S11 — Reportistica ed esportazione | planned |
| FR-097 | Approvazione economica esterna ammessa | §26.11 | S6 — Proposta e Budget iniziale | verified |
| FR-098 | EUR, netto IVA e anno solare | §4.3 | S3 — Esercizi, Spese e Righe | verified |
| FR-099 | Evoluzione del dominio tramite categorie A–E | §3 | S0 — Foundation e ambiente di sviluppo live | verified |
| FR-100 | Nessun Forecast | §§1.4, 28.59 | S0 — Foundation e ambiente di sviluppo live | verified |
| FR-101 | Presenza di Effettivi distinta dal totale netto | §§6.4, 28.2 | S3 — Esercizi, Spese e Righe | verified |

## Status rules

- `planned`: mapped but not implemented;
- `implemented`: code exists and local relevant tests pass;
- `verified`: independent slice demonstration and CI have passed.

Status changes must not edit the canonical requirement wording or source reference.
