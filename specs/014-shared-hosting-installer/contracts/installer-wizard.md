# Contract: Installer Wizard

**Feature**: `014-shared-hosting-installer`

## Entry contract

- Production senza marker: ogni route web ordinaria viene indirizzata a `/install`.
- `/install` e le route Livewire necessarie restano raggiungibili durante il wizard.
- Production con marker: `/install*` viene bloccato/redirect.
- `local` e `testing`: MP2 è considerato già installato indipendentemente dal marker.
- Il wizard può diagnosticare soltanto dopo che Composer e Laravel hanno fatto bootstrap; PHP o estensioni mancanti che bloccano il platform check devono essere corretti nel pannello hosting e non giustificano un bootstrap PHP parallelo.

## Ordered steps

### 1. Requisiti

Mostra in italiano:

- PHP >= 8.3;
- estensioni runtime MP2 riconciliate con il lockfile: `bcmath`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xmlreader`, `zip`;
- permessi/scrivibilità necessari.

`memory_limit=-1` è valido come limite illimitato. OPcache e versione MySQL esatta non sono gate bloccanti.

### 2. Permessi

Verifica almeno:

- `.env`;
- `storage/app`;
- `storage/framework`;
- `storage/logs`;
- `bootstrap/cache`.

### 3. Configurazione

Campi:

| Campo | Tipo | Regola |
|---|---|---|
| URL istanza | URL/text | precompilato dalla richiesta quando possibile, modificabile |
| DB host | text | richiesto |
| DB port | integer/text | default 3306 |
| DB name | text | richiesto |
| DB username | text | richiesto |
| DB password | password | passato alla connessione senza logging |

Database engine: MySQL fisso, non selezionabile.

Action `Test connessione`:

- si connette allo schema specificato;
- non crea database;
- non modifica schema;
- restituisce messaggio italiano.

`Continua`:

- richiede test/validazione riuscita;
- il form principale non sovrascrive `state.password` con uno scope Alpine separato prima di chiamare `next()`;
- scrive `.env`;
- verifica successo scrittura;
- non memorizza password nei log.

Dalla richiesta successiva, il target autoritativo è `config('database.connections.mysql')` ricaricato dalla `.env`; gli step distruttivi non usano il solo array Livewire come autorità sul database.

### 4. Preparazione database

Se vuoto:

- verifica assenza sia di tabelle sia di view;
- mostra stato pronto;
- non esegue wipe.

Se non vuoto:

- warning evidente;
- mostra il nome database bersaglio letto dalla connessione MySQL effettiva;
- spiega che tutto il contenuto verrà eliminato;
- richiede input testuale uguale esattamente al nome DB;
- nessun reset finché l'input non coincide;
- dopo conferma esegue `db:wipe --database=mysql --drop-views --force`;
- verifica nuovamente assenza di tabelle e view sulla stessa connessione;
- fallisce esplicitamente se non può eliminare gli oggetti.

### 5. Migrazioni

Precondition: immediatamente prima della migrazione lo step ricontrolla, sulla connessione MySQL effettiva, che non esistano tabelle o view. Un flag client-side non soddisfa la precondizione.

Behavior:

- applica migrazioni forward;
- esegue il seeder production-safe;
- la view non mostra né preseleziona alcun toggle demo;
- nessun dato demo;
- nessun admin di sviluppo;
- su errore mostra failure;
- su errore non esegue cleanup o wipe e lascia lo schema parziale;
- un retry sullo schema parziale resta bloccato finché l'operatore non torna alla preparazione database e conferma nuovamente il reset col nome esatto.

### 6. Amministratore

Usa lo step nativo del package.

Campi/validazione: quelli nativi della versione pinnata.

La view non mostra requisiti di robustezza o lunghezza che il backend nativo non applica; in particolare rimuove il placeholder "Min. 8 caratteri" e il meter di robustezza, traducendo soltanto i testi senza aggiungere regole password MP2.

Post-condition MP2:

```text
created user.is_platform_admin == true
```

Nessuna Company viene creata qui.

### 7. Scheduler

Mostra:

**Comando PHP CLI suggerito**

Input modificabile, default coerente con major/minor PHP web, esempio `php8.3`.

**Crontab completo**

```text
* * * * * php8.3 '/absolute/path/to/artisan' schedule:run >> /dev/null 2>&1
```

**Solo comando**

```text
php8.3 '/absolute/path/to/artisan' schedule:run >> /dev/null 2>&1
```

Entrambi devono aggiornarsi se cambia il comando PHP.

Fornisce azione di copia per entrambe le stringhe.

Conferma obbligatoria:

```text
Confermo di aver configurato lo scheduler.
```

Nessuna API hosting viene chiamata.

### 8. Finalizzazione

Ordine di garanzia:

1. `installer.progress` server-side contiene tutti gli step configurati e termina con lo scheduler; una chiamata Livewire diretta a `finish()` viene rifiutata;
2. esiste admin piattaforma;
3. scheduler è stato confermato;
4. `.env` contiene configurazione DB finale;
5. viene generata sincronicamente e verificata una nuova APP_KEY valida per l'istanza;
6. viene creato e verificato il marker `storage/installed`; un errore di scrittura blocca la finalizzazione;
7. il wizard diventa non eseguibile;
8. redirect `/admin/login`.

## Error contract

- Gli errori devono essere comprensibili in italiano.
- Le eccezioni non devono mostrare password DB.
- Un errore non può convertire implicitamente un database non autorizzato in disposable.
- Un errore di migrazione non esegue cleanup automatico.
- Un failure resta sullo step corrente e consente correzione/retry quando sicuro.
