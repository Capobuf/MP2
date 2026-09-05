# Rendering PDF con WeasyPrint

MP2 usa un solo renderer PDF: **WeasyPrint**. L'applicazione non impone una versione
specifica a runtime: considera disponibile qualsiasi installazione che possa essere
avviata correttamente e risponda a `weasyprint --version`.

## Valutazione della dipendenza

- Requisito: anteprima e download di un PDF A4 semanticamente completo, con CSS di stampa e grafici SVG.
- Limite del framework: Laravel non include un motore HTML/CSS → PDF.
- Compatibilità: la baseline di sviluppo attuale è WeasyPrint 69.0 su Python 3.12 e Ubuntu 24.04.
- Manutenzione: Docker e CI mantengono una baseline deterministica; le installazioni esterne non vengono bloccate in base al numero di versione.
- Licenza: BSD 3-Clause.
- Sicurezza: MP2 non passa HTML, CSS, URL o path utente; incorpora soltanto asset locali/data URI, usa argomenti di processo strutturati e applica un timeout.
- Codice evitato: implementazione propria di layout, font, paginazione e generazione PDF.
- Rimozione futura: sostituire il singolo comando/configurazione e riverificare lo stesso composer; non esistono PDF o preset persistiti da migrare.

## Individuazione del binario

Alla prima verifica MP2 prova il valore di `WEASYPRINT_BINARY` e, se non funziona,
cerca `weasyprint` nei percorsi disponibili al processo PHP e nei percorsi Linux più
comuni. Sono inclusi:

- le directory presenti nel `PATH` del processo PHP;
- `PIPX_BIN_DIR` e `PIPX_GLOBAL_BIN_DIR`, quando definiti;
- `~/.local/bin` e `~/bin` dell'utente del processo PHP;
- `.venv/bin` e `venv/bin` nella root dell'applicazione;
- `/usr/local/bin`;
- `/usr/bin`;
- `/opt/weasyprint/bin`;
- `/snap/bin`.

Il primo binario funzionante viene salvato nella cache file di Laravel e riutilizzato.
Prima di ogni rendering viene verificato nuovamente con `--version`. Se il percorso
salvato non è più valido, MP2 lo elimina dalla cache, ripete la ricerca completa e
salva il nuovo percorso funzionante.

`WEASYPRINT_BINARY` resta disponibile come override per installazioni in percorsi non
standard. Non viene eseguita una scansione ricorsiva del filesystem.

## Sviluppo e CI

L'immagine Sail in `docker/8.3/Dockerfile` crea un virtual environment Python,
installa `weasyprint==69.0` e pubblica il comando `weasyprint` nel `PATH`. La CI usa
la stessa baseline per mantenere riproducibile il rendering verificato dai test.

Dopo un aggiornamento del Dockerfile:

```bash
./vendor/bin/sail build --no-cache laravel.test
./vendor/bin/sail up -d
./vendor/bin/sail exec laravel.test weasyprint --version
```

## Shared hosting

WeasyPrint è un prerequisito esterno e non è incluso nello ZIP PHP. Il provider deve
permettere all'utente del processo PHP web di eseguire WeasyPrint e le relative
librerie native. La documentazione upstream di riferimento è
<https://doc.courtbouillon.org/weasyprint/stable/first_steps.html>.

Se il provider consente ambienti Python privati, un esempio è:

```bash
python3 -m venv /percorso/privato/weasyprint
/percorso/privato/weasyprint/bin/pip install weasyprint
/percorso/privato/weasyprint/bin/weasyprint --version
```

Se il virtual environment si trova in un percorso non coperto dalla ricerca
automatica, indicarlo nella `.env`:

```dotenv
WEASYPRINT_BINARY=/percorso/privato/weasyprint/bin/weasyprint
```

Il wizard di installazione blocca il proseguimento solo se non riesce a trovare ed
eseguire WeasyPrint. L'applicazione applica inoltre un timeout finito e mostra
all'utente un errore generico, registrando il dettaglio tecnico nei log server.

## Font del Report Contratti

Il template Contratti incorpora Geist tramite `@font-face` e data URI, compresi i
pesi usati dagli SVG. L’asset `resources/fonts/geist-latin-wght-normal.woff2` è una
copia non modificata del font distribuito da `@fontsource-variable/geist` 5.3.0;
la licenza OFL è conservata in `resources/fonts/Geist-OFL.txt`.

La directory `resources` è già inclusa nello ZIP di release. La generazione non
richiede Node, `node_modules`, connessioni remote o percorsi della macchina di
sviluppo. Il font è un asset obbligatorio del template, senza percorso alternativo.
