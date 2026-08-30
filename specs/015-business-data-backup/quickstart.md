# Quickstart: validazione Business Data Backup

## Prerequisiti

- stack Sail avviato;
- database `testing` isolato e guard attivo;
- estensioni XLSX disponibili;
- opzionale disk `google` configurato, oppure fake disk nei test.

## Verifiche focalizzate

```bash
./vendor/bin/sail artisan test tests/Unit/BusinessBackup
./vendor/bin/sail artisan test tests/Feature/BusinessBackup
```

Risultato atteso: contratto, validazione, export/import, rollback, idempotenza e superfici passano.

## Round-trip rappresentativo

1. Creare fixture con dataset minimale e dataset completo.
2. Generare tutti i report S11 dell'Azienda A.
3. Esportare XLSX V1.
4. Importare come nuova Azienda B nello stesso database di test isolato.
5. Rigenerare report e confrontare semanticamente.
6. Ricalcolare il Contratto, invertire la Riprogrammazione e approvare una nuova Revisione.

Risultato atteso: zero differenze incluse; una sola Stima di sistema; inversione coerente; Budget vN+1 valido.

## Drive

Usare un fake disk `google`, generare tramite azione Drive e confrontare hash/byte con l'artefatto locale dello stesso run.

## Quality gate

```bash
composer quality
npm run build
```

Verificare inoltre il workflow release/shared-hosting e aprire in browser le due pagine Filament con account autorizzati.
