# MP2

Applicazione Laravel/Filament per la governance della spesa IT MP2. Le regole di
dominio autorevoli sono raccolte nella
[`Specifica Canonica Semplificata`](docs/domain/Specifica_Canonica_Semplificata_v4.md).

## Requisiti

- host Linux;
- Git;
- Docker Engine con Docker Compose v2.

PHP, Composer, Node e MySQL non sono richiesti sull'host.
L'immagine Sail del progetto installa e verifica WeasyPrint 69.0, usato come unico renderer PDF.
Per il prerequisito shared-hosting e la configurazione del binario vedere
[`docs/deployment/pdf-rendering.md`](docs/deployment/pdf-rendering.md).

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

## Log di produzione

Il template `.env.production.example` usa `LOG_CHANNEL=stack`, `LOG_STACK=daily`,
`LOG_DAILY_DAYS=14` e `LOG_LEVEL=error`. Il canale conserva al massimo 14 file
giornalieri in `storage/logs/laravel-YYYY-MM-DD.log`; la rotazione avviene quando
vengono scritti nuovi messaggi. I log sono consultabili dagli operatori con accesso
al filesystem del deployment e non devono essere esposti dal web server.

Per le installazioni esistenti, applicare gli stessi valori al file `.env` del
deployment e rigenerare l'eventuale configurazione in cache con
`php artisan config:cache`. Il vecchio `storage/logs/laravel.log` non viene ruotato
dal nuovo canale: l'operatore deve gestirne separatamente archiviazione e rimozione.

Il cleanup orario `tenant-files:cleanup` registra gli esiti falliti nel log con
conteggi di file elaborati, completati e falliti e l'ID operazione, se il comando
è limitato a una singola operazione. Non registra percorsi, contenuti dei file o
messaggi delle eccezioni dello storage. Un'esecuzione riuscita non genera errori
nel log. Il cron può quindi mantenere la redirezione dell'output a `/dev/null`.
