# Contract: Shared Hosting Runtime

**Feature**: `014-shared-hosting-installer`

## Generic host contract

L'hosting deve offrire:

- Linux;
- PHP web >= 8.3 compatibile con la release;
- PHP CLI >= 8.3 disponibile ai cron;
- estensioni PHP runtime richieste da MP2;
- binario WeasyPrint 69.0 eseguibile dal processo PHP web, con le sue dipendenze native;
- MySQL raggiungibile con database e credenziali già create;
- possibilità di impostare il document root del dominio alla directory `public`;
- permessi di scrittura per `.env`, `storage/**` e `bootstrap/cache`;
- possibilità di creare un cron ogni minuto;
- supporto normale a rewrite/front controller Laravel.

Non sono richiesti:

- SSH per eseguire comandi applicativi durante la prima installazione;
- Composer;
- npm/Node;
- Git;
- Docker;
- Redis;
- supervisor/queue worker permanente;
- API CloudPanel/cPanel/Plesk.

## Confine dei controlli

### Il wizard può controllare, dopo il bootstrap Laravel

- versione del PHP web effettivamente in esecuzione;
- estensioni non già bloccate dal platform check Composer;
- scrivibilità di `.env`, `storage/**` e `bootstrap/cache`;
- connessione allo specifico database MySQL già configurato;
- presenza di tabelle/view prima delle migrazioni.
- presenza, eseguibilità e versione supportata del binario WeasyPrint configurato da `WEASYPRINT_BINARY`.

### Il wizard non può configurare o garantire genericamente

- document root verso `public/` e rewrite del web server;
- scelta della versione PHP nel pannello provider;
- condizioni PHP/estensioni che impediscono a Composer/Laravel di fare bootstrap;
- presenza, versione o nome del PHP CLI usato dal cron;
- installazione o aggiornamento di WeasyPrint e delle sue librerie native;
- creazione di database e utente MySQL;
- creazione o reale esecuzione del cron;
- emissione e configurazione del certificato HTTPS.

Queste condizioni restano responsabilità dell'operatore/provider. La slice non introduce un file PHP diagnostico esterno a Laravel. HTTPS non viene configurato o certificato dal wizard; l'operatore deve predisporlo per l'uso production.

## Web root

Esempio:

```text
/home/example/htdocs/mp2/
├── app/
├── artisan
├── vendor/
└── public/      ← document root
```

Esporre l'intera root Laravel come document root non fa parte del contratto supportato.

## Database

- engine: MySQL;
- baseline CI: 8.4;
- nessun blocco basato solo sul numero di versione;
- il database deve esistere prima del wizard;
- un DB non vuoto può essere riutilizzato solo tramite reset esplicito nel wizard.

## Scheduler

Laravel deve essere invocato ogni minuto.

Generic crontab shape:

```text
* * * * * <php-cli> '<absolute-path>/artisan' schedule:run >> /dev/null 2>&1
```

Panel command-only shape:

```text
<php-cli> '<absolute-path>/artisan' schedule:run >> /dev/null 2>&1
```

Il wizard conosce in modo affidabile `<absolute-path>` ma non può conoscere universalmente `<php-cli>`. Deve suggerire un valore coerente con la versione web e renderlo modificabile.

## Runtime defaults for this deployment mode

```text
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

Questi default evitano servizi permanenti non richiesti e sono compatibili con il runtime MP2 attuale.

## Scheduled MP2 responsibilities

Il cron è obbligatorio perché il repository contiene almeno:

```text
contracts:process-renewals       daily
tenant-files:cleanup             hourly
```

La feature non modifica frequenza o comportamento di questi processi.

## Provider-specific notes

CloudPanel è un ambiente di riferimento utile ma non è una dependency. Se il pannello separa frequenza e comando, usare la stringa command-only del wizard. Se il comando CLI è `php8.3`, `php8.4`, `/usr/bin/php` o altro, correggere soltanto quel campo nel wizard.
