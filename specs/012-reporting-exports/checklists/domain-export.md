# Checklist qualità requisiti dominio ed export: S11 Reportistica ed esportazione

**Purpose**: Valutare completezza, chiarezza, coerenza e misurabilità dei requisiti S11 prima dell'implementazione, con priorità a semantica economica, integrità storica, isolamento tenant ed esportazione PDF.
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md)

**Note**: Questa checklist valuta ciò che è scritto nei requisiti; non è un piano di test dell'implementazione.

## Completezza dei requisiti di dominio

- [x] CHK001 Sono documentati tutti i metadati obbligatori per distinguere Azienda, Esercizio, tempo, Budget, tipo di Effettivo, generazione e filtri? [Completeness, Spec FR-S11-001/002]
- [x] CHK002 Sono elencate tutte le coppie di riferimenti canoniche e le condizioni che le rendono applicabili? [Completeness, Spec FR-S11-003/004]
- [x] CHK003 Sono definite identità, derivazione esplicita e presenza unilaterale senza lasciare spazio a correlazioni per somiglianza? [Completeness, Spec FR-S11-005]
- [x] CHK004 Sono distinti in modo completo sorgenti economiche primarie e fatti neutrali delle Spese figlie per Progetti e Contratti? [Completeness, Spec FR-S11-006/007]
- [x] CHK005 Sono documentate tutte le quattro categorie primarie, le dimensioni di modifica e le etichette secondarie applicabili? [Completeness, Spec FR-S11-009/011/012]
- [x] CHK006 Sono definiti tutti i valori, conteggi, annotazioni e livelli di drill-down richiesti dalla vista annuale? [Completeness, Spec FR-S11-017/018]
- [x] CHK007 Sono documentati tutti i campi e le formule per ciascuna famiglia specialistica richiesta? [Completeness, Spec FR-S11-023/025/026/027]

## Chiarezza e determinismo

- [x] CHK008 È inequivoco che una Spesa derivata tramite `CopiedFromOriginKey` resta una diversa identità e che la derivazione è solo informativa? [Clarity, Spec FR-S11-005/024]
- [x] CHK009 È quantificata senza ambiguità la condizione economica “previsto” come Allocato approvato strettamente maggiore di zero? [Clarity, Spec FR-S11-008]
- [x] CHK010 Le condizioni di `Non previsto`, `Previsto e non avvenuto` e `Senza Effettivi` distinguono esplicitamente saldo ed esistenza di Effettivi? [Clarity, Spec FR-S11-013/014]
- [x] CHK011 È definito che `Modificato` comprende solo le dimensioni applicabili effettivamente cambiate, senza dedurre cause non disponibili? [Clarity, Spec FR-S11-011/019]
- [x] CHK012 L'etichetta relativa a una scadenza è vincolata a un intervallo dichiarato, senza soglia implicita? [Clarity, Spec FR-S11-016]
- [x] CHK013 La data economica annuale è distinta dalle date tecniche di approvazione e Chiusura? [Clarity, Spec FR-S11-035]
- [x] CHK014 Le regole monetarie precisano valuta, trattamento IVA, esattezza decimale e semantica di `HaEffettivi`? [Clarity, Spec FR-S11-036]

## Coerenza e integrità storica

- [x] CHK015 I requisiti di Conoscenza Corrente sono coerenti con l'immutabilità di Snapshot, Residuo, Risparmio, Allocato non utilizzato e Riporto? [Consistency, Spec FR-S11-020/021]
- [x] CHK016 Le Annotazioni sono coerentemente definite come evidenze non economiche incapaci di riclassificare l'imputazione storica? [Consistency, Spec FR-S11-022]
- [x] CHK017 Le aggregazioni esecutive, specialistiche e per Fornitore condividono la stessa regola di contributo economico unico? [Consistency, Spec FR-S11-007/010/027/028]
- [x] CHK018 Le regole storiche indicano quando usare dati materializzati e impediscono di consultare oggetti vivi come sostituti di una Snapshot? [Consistency, Spec FR-S11-029/030]
- [x] CHK019 L'assenza permanente di `Sostituito` è coerente in requisiti, criteri e confini di scope con la §32? [Consistency, Spec FR-S11-015]

## Qualità dei criteri di accettazione

- [x] CHK020 I criteri rendono oggettivamente misurabile l'assegnazione di esattamente una categoria primaria per ogni sorgente unica? [Measurability, Spec SC-002]
- [x] CHK021 I criteri rendono oggettivamente misurabile la riconciliazione senza doppio conteggio fino alle Righe? [Measurability, Spec SC-003/006]
- [x] CHK022 I criteri definiscono un confronto osservabile fra Snapshot prima e dopo correzioni, Annotazioni, Archivio o ridenominazione? [Measurability, Spec SC-005/007]
- [x] CHK023 L'equivalenza semantica fra vista e PDF è definita mediante contenuti e valori confrontabili, non con formule qualitative? [Measurability, Spec SC-009]

## Copertura di scenari ed eccezioni

- [x] CHK024 Sono coperti Esercizi aperti e chiusi, assenza o molteplicità di Budget, assenza di Chiusura e correzioni successive? [Coverage, Spec Edge Cases]
- [x] CHK025 Sono coperti zero, storni, azzeramenti e saldo netto zero con Righe Effettivo non nulle? [Coverage, Spec Edge Cases; FR-S11-013/036]
- [x] CHK026 Sono definiti fallimenti espliciti per riferimenti assenti, cross-tenant, incoerenti o semanticamente incompatibili, senza default silenziosi? [Exception Flow, Spec FR-S11-033/034]
- [x] CHK027 Sono coperti dati storici archiviati e Annotazioni riferite a sorgenti non più vive? [Coverage, Spec FR-S11-022/029]

## Sicurezza, accessibilità e dipendenze

- [x] CHK028 Sono specificati autorizzazione e isolamento tenant per pagina, drill-down, ricostruzione dell'export e ogni record referenziato? [Security, Spec FR-S11-034]
- [x] CHK029 Sono documentati consegna, formato, completezza, assenza di persistenza e vincoli di sicurezza del singolo PDF? [Completeness, Spec FR-S11-031/032; Contract pdf-export]
- [x] CHK030 Sono definiti stati iniziale, vuoto, disabilitato, validazione ed errore e requisiti di accessibilità per controlli e drill-down? [Coverage, Contract reporting-ui]
- [x] CHK031 È aggiornata e validata l'assunzione sulla dipendenza PDF dopo la scelta esplicita del formato? [Assumption, Spec Assumptions; Plan Technical Context]
- [x] CHK032 È documentato l'impatto della dipendenza S9 non ancora formalmente `verified` sullo stato dichiarabile di S11? [Dependency, Spec Dependencies; Plan Phase E]

## Ambiguità e tracciabilità

- [x] CHK033 Sono coerenti l'identificatore della feature e il nome del branch dichiarato fra spec, piano e contesto Spec Kit? [Consistency, Spec Header; Plan Header]
- [x] CHK034 Ogni FR canonico assegnato a S11 e ogni invariante pertinente è riconciliato con almeno un requisito e un criterio misurabile? [Traceability, Spec Canonical Requirement Reconciliation]
- [x] CHK035 I confini escludono esplicitamente Forecast, as-of arbitrario, matching fuzzy, mutazioni economiche e nuove relazioni di sostituzione? [Scope, Spec Dependencies and Scope Boundaries]

## Notes

- Spuntare gli elementi solo dopo aver valutato il testo dei requisiti e aver annotato eventuali correzioni.
- Le evidenze di implementazione e test appartengono a `quickstart.md`, non a questa checklist.
