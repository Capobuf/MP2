# Quickstart: Validazione installazione shared hosting e release ZIP

**Feature**: `014-shared-hosting-installer`

Questo documento è una guida di verifica per sviluppatori/agent. Non è un file da distribuire obbligatoriamente nello ZIP.

## Prerequisiti

- repository MP2 pulito;
- Docker/Sail dev funzionante secondo il bootstrap esistente;
- GitHub Actions disponibile per la verifica artefatto;
- MySQL test isolato;
- `INSTALLER_TEST_DATABASE=testing_installer`, schema dedicato ai test distruttivi e distinto dal database suite `testing`;
- schema `testing_installer_smoke` dedicato alla copia ZIP estratta;
- nessun test deve usare o resettare il database development `mp2`.

## Scenario A - Regression gate locale

Eseguire il gate corrente tramite Sail:

```bash
./vendor/bin/sail composer quality
```

Expected:

- Pint passa;
- PHPStan passa;
- Pest passa;
- nessuna richiesta di `storage/installed` in local/testing.

## Scenario B - Wizard non intercetta development

Con stack dev avviato:

```bash
curl -I http://127.0.0.1:9000/admin/login
```

Expected:

- login normale Filament raggiungibile;
- nessun redirect a `/install`.

## Scenario C - Database vuoto

Preparare un database MySQL isolato per installazione.

Dal wizard:

1. aprire `/install`;
2. superare requisiti/permessi;
3. inserire URL e credenziali DB usando anche una password MySQL non vuota;
4. testare connessione;
5. verificare che lo step DB mostri schema vuoto;
6. continuare senza reset;
7. completare migrazioni;
8. creare admin;
9. configurare cron;
10. confermare scheduler;
11. completare.

Expected:

- nessun `Test User`;
- nessuna opzione "dati demo" visibile;
- la password MySQL non viene azzerata dal submit;
- admin `is_platform_admin=true`;
- `storage/installed` presente;
- `/install` non rieseguibile;
- `/admin/login` raggiungibile.

## Scenario D - Database non vuoto, nessuna conferma

Creare una tabella sentinella nel database di installazione.

Dal wizard arrivare allo step database.

Expected:

- warning database non vuoto;
- la tabella resta presente premendo Continua senza conferma corretta;
- input diverso dal nome DB non autorizza il reset.
- view e tabelle sentinella restano entrambe presenti.

## Scenario E - Database non vuoto, reset autorizzato

Ripetere lo scenario D e digitare esattamente il nome database.

Expected:

- il reset usa la connessione MySQL effettiva ed elimina tabelle e view;
- lo schema viene verificato privo di tabelle e view;
- le migrazioni MP2 possono iniziare;
- nessun oggetto sentinella resta.

## Scenario F - Scheduler

Nello step scheduler verificare:

- path `artisan` assoluto uguale alla directory installata;
- crontab completo inizia con `* * * * *`;
- è presente anche command-only;
- modificando il comando PHP cambiano entrambe le stringhe;
- senza checkbox non si finalizza;
- con checkbox si finalizza.

Non è richiesto al test automatizzato verificare l'esecuzione di un cron del sistema operativo.

## Scenario G - Errori e retry sicuro

Verificare separatamente:

1. credenziali errate → connessione fallita, `.env` non dichiarata pronta;
2. database inesistente → errore, nessun tentativo di creazione;
3. reset senza privilegi sufficienti → errore, database non pronto;
4. migrazione simulata fallita a metà → schema parziale lasciato intatto, nessun `db:wipe` automatico;
5. retry diretto della migrazione → bloccato perché tabelle/view sono presenti;
6. ritorno a Preparazione database → nuovo nome DB esatto richiesto prima del reset.

Expected: nessun errore o retry riutilizza implicitamente un consenso distruttivo precedente.

## Scenario H - Sessione, finalizzazione e chiave

Verificare:

- refresh ordinario → ripresa tramite `installer.progress`;
- perdita completa sessione → ripartenza del wizard; uno schema parziale richiede nuova conferma;
- chiamata Livewire diretta a `finish()` prima degli step → nessun marker e nessuna installazione;
- flusso completo → nuova APP_KEY valida e diversa dalla bootstrap prima del marker;
- errore scrittura marker → finalizzazione fallita;
- `/install` dopo marker → bloccato;
- primo login admin senza Aziende → redirect al flusso esistente `/admin/new`.

## Scenario I - Push e artifact

Eseguire un push su un branch.

Expected:

- quality gate eseguito;
- se passa, artifact con file `mp2-<branch>-<sha>.zip`;
- se fallisce, nessuna release valida.

Scaricare lo ZIP prodotto.

Verificare almeno:

```bash
unzip -l mp2-*.zip
```

Required:

```text
.env
REVISION
artisan
vendor/autoload.php
public/.htaccess
public/build/manifest.json
public/installer/installer.css
```

Forbidden:

```text
.git/
.github/
node_modules/
tests/
public/hot
storage/installed
storage/framework/installer-progress.json
bootstrap/cache/*.php
vendor/laravel/tinker/
```

## Scenario J - Smoke sulla release estratta

La CI deve farlo automaticamente, ma può essere riprodotto:

1. estrarre lo ZIP in una nuova directory;
2. modificare soltanto la `.env` della copia estratta per puntarla a `testing_installer_smoke`;
3. verificare bootstrap Artisan;
4. applicare migrazioni sul database isolato;
5. avviare temporaneamente il server PHP;
6. verificare una route installer prima del marker;
7. creare/fornire lo stato minimo di smoke previsto dal workflow;
8. verificare `/admin/login` dopo marker.

Expected:

- nessun Composer/npm;
- nessun require-dev necessario;
- nessun accesso al checkout sorgente.
- nessun database `testing` o `mp2` modificato dallo smoke.

## Scenario K - Shared hosting non risolvibile dal wizard

Verificare nella documentazione/UI che restino responsabilità dell'operatore:

- document root e rewrite;
- versione PHP configurata dal provider e failure pre-bootstrap;
- disponibilità/versione/nome PHP CLI;
- database già creato;
- cron realmente creato;
- HTTPS.

Cambiare il comando PHP nello step scheduler e verificare che crontab completo e command-only cambino insieme. Senza conferma cron la finalizzazione resta bloccata.

Limiti osservati durante la verifica locale:

- il PHP CLI dell'host workspace non esponeva `pdo_mysql`; lo smoke MySQL della copia estratta è stato quindi eseguito nel runtime Sail conforme, senza cambiare l'artefatto;
- la Web Clipboard API non è disponibile su un indirizzo IP servito in HTTP non sicuro. Verificare il pulsante Copia sul dominio HTTPS configurato dall'operatore; le due stringhe restano comunque visibili in campi di sola lettura.

## Scenario L - Contratto futura CI update

Controllare nello ZIP:

```bash
cat REVISION
```

Expected: full SHA del push.

Verificare inoltre:

- `.env` è chiaramente bootstrap di nuova installazione;
- `storage/installed` non è nella release;
- l'artefatto non contiene storage runtime proveniente dalla CI.

Questo scenario non esegue alcun update production.
