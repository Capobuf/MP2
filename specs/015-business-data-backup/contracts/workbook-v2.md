# Workbook Contract V2

V2 conserva integralmente struttura, tipi, checksum, riferimenti e semantica del
contratto immutabile `workbook-v1.md`, con il solo delta seguente.

## Manifest

`format_version` è `2`.

## `_MP2_cost_centers`

Colonne richieste, in ordine:

`cost_center_ref`, `name`, `parent_cost_center_ref`, `archived_at`

`parent_cost_center_ref` è nullo per una radice; altrimenti deve risolvere un'altra
riga dello stesso foglio. È sempre un riferimento portabile `CDC-*`, mai un ID
database. L'ordine delle righe non implica dipendenza: un figlio può precedere il
padre. Auto-riferimenti, riferimenti mancanti e cicli diretti o indiretti invalidano
l'intero workbook prima del restore.

## Snapshot materializzate

I `detail_json` versionati di `_MP2_budget_rows` e `_MP2_closing_rows` possono
contenere `cost_center_lineage`. Ogni `cost_center_id` tecnico presente nella lineage
viene serializzato come `cost_center_ref` portabile e reidratato con il nuovo ID
locale. La lineage viene ripristinata come dato materializzato: non viene ricostruita
dalla gerarchia corrente.
