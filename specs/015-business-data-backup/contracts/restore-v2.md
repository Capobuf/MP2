# Restore Contract V2

V2 applica tutte le fasi, le garanzie di rollback e l'idempotenza di
`restore.md` (contratto V1), con queste variazioni:

1. il validator accetta esplicitamente `format_version=2` e usa le intestazioni V2;
2. valida `parent_cost_center_ref`, inclusi riferimenti mancanti, auto-riferimenti e
   cicli, prima di ogni write;
3. crea tutti i Centri di Costo senza parent;
4. risolve le reference portabili nei nuovi ID e applica i parent in un secondo
   passaggio, senza dipendere dall'ordine del foglio;
5. reidrata le reference portabili nelle lineage Snapshot usando gli stessi nuovi ID;
6. qualsiasi errore mantiene il rollback integrale.

Il medesimo importer continua ad accettare V1 con il suo schema immutato; poiché V1
non contiene `parent_cost_center_ref`, tutti i Centri importati da V1 sono radici.
