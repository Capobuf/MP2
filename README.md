# MP2

Applicazione Laravel/Filament per la governance della spesa IT MP2. Le regole di
dominio autorevoli sono raccolte nella
[`Specifica Canonica Semplificata`](docs/domain/Specifica_Canonica_Semplificata_v4.md).

## Requisiti

- host Linux;
- Git;
- Docker Engine con Docker Compose v2.

PHP, Composer, Node e MySQL non sono richiesti sull'host.

## Primo avvio

```bash
scripts/bootstrap-dev.sh
```

Il bootstrap crea `.env` se assente, installa le dipendenze tramite Docker, avvia
Sail, applica solo migrazioni forward e prepara l'amministratore locale.

- URL: <http://127.0.0.1:9000/admin>
- e-mail: `admin@mp2.local`
- password: `admin@mp2.local`

Lo script stampa anche l'URL LAN quando rileva un IPv4 utilizzabile. L'ambiente è
solo locale/LAN e non deve essere esposto direttamente a Internet.

## Avvio e arresto

```bash
./vendor/bin/sail up -d
./vendor/bin/sail stop
```

Lo stop normale conserva il database `mp2` nel volume nominato `sail-mysql`.

## Test e qualità

```bash
./vendor/bin/sail composer quality
./vendor/bin/sail composer test:foundation
```

La suite usa esclusivamente il database MySQL `testing` e si arresta prima dei
reset se ambiente o database non sono quelli previsti. La policy corrente è in
[`docs/testing-policy.md`](docs/testing-policy.md); il gate eseguibile dalla CI è
definito in [`.github/workflows/ci.yml`](.github/workflows/ci.yml).
