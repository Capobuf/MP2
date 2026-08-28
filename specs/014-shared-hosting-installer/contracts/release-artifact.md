# Contract: Release Artifact

**Feature**: `014-shared-hosting-installer`

## Trigger

Evento: ogni `push` di branch (`branches: ['**']`; i tag non sono inclusi da questo contratto).

Precondition: quality gate completato con successo.

Pull request: quality resta eseguibile; la pubblicazione ZIP non è un requisito del solo evento `pull_request`.

## File name

```text
mp2-<sanitized-branch>-<short-sha>.zip
```

Example:

```text
mp2-main-a82fc91.zip
```

La branch viene trasformata sostituendo ogni sequenza di caratteri diversa da lettere ASCII, numeri, punto, underscore o trattino con un singolo `-`, rimuovendo i trattini iniziali/finali e usando `branch` se il risultato è vuoto. Lo short SHA usa i primi 7 caratteri di `GITHUB_SHA`; il full SHA resta autoritativo in `REVISION`.

## Root layout

Lo ZIP contiene direttamente la root Laravel; non aggiunge una directory wrapper obbligatoria.

Required paths:

```text
.env
REVISION
artisan
app/
bootstrap/
bootstrap/cache/
config/
database/migrations/
database/seeders/
lang/
public/
public/.htaccess
public/build/
public/installer/
resources/
routes/
storage/
storage/app/private/
storage/app/public/
storage/framework/cache/data/
storage/framework/sessions/
storage/framework/views/
storage/logs/
vendor/
composer.json
composer.lock
```

`bootstrap/cache` e le directory `storage` necessarie vengono create da zero nello staging. Non si copia l'albero runtime omonimo del checkout. Possono contenere solo directory vuote o i placeholder `.gitignore` necessari a conservarle nello ZIP, mai cache, upload o stato CI.

## `.env` build contract

La `.env` viene generata esclusivamente nella staging directory.

Required baseline:

```text
APP_NAME="Master Plan IT"
APP_ENV=production
APP_DEBUG=false
APP_LOCALE=it
APP_FALLBACK_LOCALE=it
DB_CONNECTION=mysql
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
```

`APP_KEY`:

- random per release build;
- formato Laravel valido;
- non letto da GitHub Secret;
- non committed;
- sostituito durante la finalizzazione dell'installazione.

Forbidden:

```text
DEV_ADMIN_NAME
DEV_ADMIN_EMAIL
DEV_ADMIN_PASSWORD
DB_HOST=mysql
DB_USERNAME=sail
DB_PASSWORD=password
```

## `REVISION`

Contiene esattamente il full commit SHA che ha generato la release, seguito opzionalmente da newline.

## Required runtime behavior

Dopo estrazione:

- `vendor/autoload.php` esiste;
- Vite manifest/build esiste;
- installer CSS pubblico esiste;
- Laravel può fare bootstrap senza Composer/npm;
- `/install` può essere servito in production senza marker.

## Excluded content

Almeno:

```text
.git/
.github/
.agents/
.specify/
specs/
tests/
node_modules/
compose.yaml
.dockerignore
coverage/
storage/installed
storage/logs/*.log
storage/framework/sessions/*
storage/framework/views/*
storage/framework/cache/data/*
storage/framework/installer-progress.json
bootstrap/cache/*.php
public/hot
vendor/laravel/tinker/
vendor/psy/psysh/
```

Gli script esclusivamente dev possono essere esclusi quando non necessari al runtime.

La copia applicativa nello staging usa una allowlist di root: `artisan`, `app`, i file bootstrap escluso il contenuto generato di `bootstrap/cache`, `config`, `database/migrations`, `database/seeders`, `lang`, `public`, `resources`, `routes`, `vendor`, `composer.json` e `composer.lock`. `.env`, `REVISION`, `bootstrap/cache` e lo skeleton `storage` vengono generati separatamente nello staging.

`laravel/tinker` viene spostato da `require` a `require-dev` nel repository prima del build, così l'esclusione deriva correttamente da `composer install --no-dev` e non da una cancellazione manuale dentro `vendor`.

## Artifact validation

Prima dell'upload la CI deve:

1. controllare i required paths;
2. controllare gli excluded paths;
3. verificare `.env` production-safe;
4. verificare `REVISION`;
5. creare ZIP;
6. estrarre ZIP in una seconda directory pulita;
7. verificare che la copia estratta non contenga symlink o riferimenti necessari al checkout;
8. modificare solo la `.env` della copia estratta per puntarla allo schema CI dedicato `testing_installer_smoke`;
9. eseguire bootstrap Artisan, migrazioni e smoke HTTP sul contenuto estratto, non sul checkout;
10. caricare l'unico ZIP già validato soltanto dopo il successo dello smoke.

## Future update boundary

Lo ZIP è:

- supportato per nuova installazione;
- supportato come input della futura CI di update;
- NON dichiarato sicuro per overwrite manuale in-place.

La futura CI deve sostituire/preservare la `.env` bootstrap con quella dell'istanza e preservare lo storage dell'istanza prima della promozione della nuova release.
