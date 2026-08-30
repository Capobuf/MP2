# Interface Contract

## Tenant: Backup dati

- Visibile soltanto su Tenant attivo a utente con `visualizza`.
- Mostra avviso di consultazione/copia e formato V1.
- `Scarica backup XLSX`: genera e restituisce l'artefatto completo.
- `Salva backup su Google Drive`: visibile soltanto quando il disk configurato è risolvibile; salva lo stesso artefatto e mostra nome/percorso risultante.
- Nessuna scelta di tabelle, formato, retention o frequenza.

## Platform: Importa Azienda da backup

- Accessibile soltanto a `is_platform_admin`.
- Step 1 upload temporaneo XLSX.
- Step 2 validazione read-only e preview con: Azienda, export time, versione, Esercizi, conteggi, totali, allegati inventariati, collisione nome e warning.
- Step 3 conferma esplicita; nessun selettore Azienda destinazione.
- Successo: collegamento al Tenant/Azienda creata o risultato esistente del medesimo package.
- Errore: messaggio specifico, nessuna Azienda parziale.

## Artisan

```text
php artisan mp2:business-backup <company-id> [--disk=<disk>] [--path=<directory>]
```

- Senza `--disk`: genera nel disk locale configurato e stampa il path.
- Con `--disk=google`: richiede configurazione valida e conserva XLSX.
- Un company id privo di Tenant attivo fallisce senza artefatto pubblicato.
- Il comando non definisce schedule o retention.
