# MP2

Foundation Laravel/Filament per MP2. S0 include solo ambiente di sviluppo, accesso
tecnico, persistenza MySQL e controlli qualità; non contiene ancora entità del
dominio economico.

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
reset se ambiente o database non sono quelli previsti. I controlli equivalenti alla
CI sono documentati in
[`quickstart.md`](specs/001-foundation-dev-environment/quickstart.md).
