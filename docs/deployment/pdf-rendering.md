# Rendering PDF con WeasyPrint

MP2 supporta un solo renderer PDF: **WeasyPrint 69.0**. La versione è fissata perché
il risultato di impaginazione fa parte del comportamento verificato dell'applicazione.

## Valutazione della dipendenza

- Requisito: anteprima e download di un PDF A4 semanticamente completo, con CSS di stampa e grafici SVG.
- Limite del framework: Laravel non include un motore HTML/CSS → PDF.
- Compatibilità: WeasyPrint 69.0 richiede Python >= 3.10; Sail usa Python 3.12 su Ubuntu 24.04.
- Manutenzione: la baseline è la release stabile corrente verificata nel registro e nella documentazione ufficiali.
- Licenza: BSD 3-Clause.
- Sicurezza: MP2 non passa HTML, CSS, URL o path utente; incorpora soltanto asset locali/data URI, usa argomenti di processo strutturati e applica un timeout.
- Codice evitato: implementazione propria di layout, font, paginazione e generazione PDF.
- Rimozione futura: sostituire il singolo comando/configurazione e riverificare lo stesso composer; non esistono PDF o preset persistiti da migrare.

## Sviluppo e CI

L'immagine Sail in `docker/8.3/Dockerfile` crea un virtual environment Python,
installa `weasyprint==69.0` e pubblica il comando `weasyprint` nel `PATH`. La CI esegue
la stessa installazione e verifica la versione prima dei test.

Dopo un aggiornamento del Dockerfile:

```bash
./vendor/bin/sail build --no-cache laravel.test
./vendor/bin/sail up -d
./vendor/bin/sail exec laravel.test weasyprint --version
```

## Shared hosting

WeasyPrint è un prerequisito esterno e non è incluso nello ZIP PHP. L'operatore deve
chiedere al provider di rendere disponibile WeasyPrint 69.0, con le librerie native
richieste, all'utente del processo PHP web. La documentazione upstream di riferimento
è <https://doc.courtbouillon.org/weasyprint/stable/first_steps.html>.

Se il provider consente ambienti Python privati, un esempio è:

```bash
python3 -m venv /percorso/privato/weasyprint
/percorso/privato/weasyprint/bin/pip install weasyprint==69.0
/percorso/privato/weasyprint/bin/weasyprint --version
```

In questo caso impostare nella `.env` il percorso assoluto:

```dotenv
WEASYPRINT_BINARY=/percorso/privato/weasyprint/bin/weasyprint
```

Il wizard di installazione blocca il proseguimento se il comando è mancante, non
eseguibile o restituisce una versione diversa. L'applicazione applica inoltre un
timeout finito e mostra all'utente un errore generico, registrando il dettaglio tecnico
nei log server.
