# UI Contract: Reportistica

## Entry point

- Pagina Filament tenant-scoped `Report` nel gruppo `Controllo`.
- Visibile e raggiungibile solo a utenti con capacità `visualizza` sull'Azienda corrente.
- Il nome e l'identità dell'Azienda sono sempre visibili.

## Initial state

- Nessun report viene generato automaticamente.
- Nessun Esercizio, Budget, tipo Effettivo o riferimento viene scelto come default silenzioso.
- La pagina spiega che i riferimenti devono essere selezionati esplicitamente.

## Selection form

Campi comuni:

1. Esercizio;
2. famiglia report;
3. riferimenti richiesti dalla famiglia;
4. versione Budget quando applicabile;
5. tipo Effettivo quando applicabile;
6. eventuale secondo Esercizio e stessa misura;
7. eventuale intervallo date completo;
8. filtri espliciti per Centro di Costo, Progetto, Contratto, Spesa autonoma o Fornitore quando applicabili.

Le opzioni dipendenti sono tenant-scoped. Una selezione incompatibile o divenuta obsoleta viene rifiutata con il campo esatto; non viene sostituita.

## Generate action

- Label: `Genera report`.
- Ricostruisce e valida `ReportDefinition` server-side.
- Non scrive database, Snapshot, Timeline o Effettivi.
- In successo mostra `ReportResult`; in errore conserva le selezioni valide e mostra un messaggio esplicito.

## Result header

Mostra sempre:

- Azienda;
- Esercizio;
- famiglia report;
- riferimenti temporali e date economiche;
- Budget/versione selezionata;
- tipo Effettivo;
- data/ora generazione nel timezone aziendale;
- filtri/intervallo;
- `EUR · importi netti IVA`.

I campi non applicabili sono nominati come `Non applicabile`; un riferimento obbligatorio assente blocca invece la generazione.

## Result body

- Riepilogo delle misure previste dalla famiglia.
- Conteggi per le quattro categorie primarie.
- Conteggi separati per etichette, mai presentati come somma esclusiva.
- Tabella sorgenti con valori iniziale/finale/delta, categoria, dimensioni ed etichette.
- `Sostituito` non compare.
- Le Spese figlie usano solo i quattro fatti neutrali canonici.
- Ogni totale ha un controllo di drill-down applicabile.

## Drill-down

Il dettaglio conserva header e identità della sorgente e può mostrare:

- Centro di Costo;
- Progetto/Contratto/Spesa autonoma;
- Spese figlie e Righe;
- condizioni e cicli;
- Riporti;
- eventi e motivi;
- allegati decisionali disponibili;
- correzioni singole;
- Annotazioni di errore storico.

Una differenza non spiegata mostra esattamente `Variazione non sufficientemente spiegata`.

## Closed-year behavior

- Mostra Effettivo alla Chiusura, correzioni positive, negative e nette ed Effettivo a Conoscenza Corrente separatamente.
- Residuo, Risparmio, Allocato non utilizzato e Riporto sono etichettati `alla Chiusura` e non vengono ricalcolati.
- Annotazioni: `Nessun impatto economico`.

## Export action

- Label: `Esporta PDF`.
- Visibile solo dopo un report generato con successo.
- Trasmette soltanto i parametri di definizione, non totali o righe client-side.
- Produce download immediato secondo [pdf-export.md](pdf-export.md).

## Empty/loading/error/accessibility states

- Stato iniziale e nessun risultato: messaggio esplicito, nessun totale zero inventato.
- Dataset valido senza sorgenti: header completo più `Nessuna sorgente per i riferimenti e filtri selezionati`.
- Durante generazione: azione disabilitata e stato busy annunciabile.
- Errori: nessun report parziale.
- Campi con label associate, navigazione tastiera, tabelle leggibili e contrasto coerente con il tema.

