# Restore Contract V1

## Fasi osservabili

1. Caricare XLSX come file temporaneo.
2. Verificare tipo XLSX e assenza di formule nei fogli macchina.
3. Leggere `_MP2_manifest` e richiedere `format_version=1`.
4. Verificare insieme esatto dei fogli macchina e intestazioni esatte.
5. Leggere celle come stringhe/null, validare chunk e ricomporre payload.
6. Ricalcolare conteggi e checksum.
7. Validare ref, enum, decimal, date, dominio e totali.
8. Produrre preview senza write.
9. Dopo conferma e nuova autorizzazione Platform Admin, aprire una transazione.
10. Se `package_id` è già completato, restituire l'Azienda esistente.
11. Creare Azienda, Tenant attivo e capability del solo importatore.
12. Importare il grafo nell'ordine documentato nel data model.
13. Ricostruire metadata Riprogrammazione usando nuovi ID e revision correnti.
14. Verificare conteggi/totali/report di controllo ripristinabili.
15. Inserire il journal completato e commit.

## Error semantics

Ogni errore prima della conferma è read-only. Ogni errore dopo la conferma causa rollback. Nessun validator corregge, deduplica o interpreta un valore non valido.

## Idempotenza

`package_id` è unique nel journal. Un retry di un import completato restituisce la stessa Azienda; un import fallito può essere ritentato perché non lascia journal.
