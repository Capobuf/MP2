# Domain — Specifica Canonica Semplificata

**Versione:** 4.0  
**Stato:** canonico consolidato; baseline funzionale chiusa rispetto al perimetro dichiarato  
**Data:** 16 agosto 2026  
**Ambito:** governo annuale e pluriennale della spesa IT per vCIO/MSP  
**Sostituisce:** Specifica Canonica Semplificata 3.0

---

# 0. Come leggere questo documento

Questo documento è l'unica fonte normativa del comportamento funzionale e del dominio del software. Non definisce architettura tecnica, interfaccia grafica, tecnologia, schema fisico del database o piano di sviluppo.

Le parole normative hanno il seguente significato:

- **MUST**: comportamento obbligatorio del sistema;
- **MUST NOT**: comportamento vietato;
- **SHOULD**: comportamento previsto, salvo motivo esplicito e documentato;
- **MAY**: scelta facoltativa dell'utente o libertà implementativa che non modifica il comportamento osservabile.

Un implementatore **MUST NOT** completare per assunzione una regola economica o funzionale non presente nel documento.

## 0.1 Gerarchia normativa

In caso di dubbio si applica questa gerarchia:

1. le sezioni da `0` a `26` e le sezioni `30`, `31` e `32` sono normative;
2. gli Invarianti della sezione `28` riepilogano le regole normative e **MUST NOT** introdurre comportamenti diversi;
3. l'indice dei Requisiti Funzionali della sezione `29` rinvia alle sezioni normative e **MUST NOT** parafrasarle in modo divergente;
4. le casistiche della sezione `27` e le Appendici sono esplicative e **MUST NOT** introdurre regole assenti dalle sezioni normative;
5. le matrici presenti nelle sezioni normative sono a loro volta normative;
6. una contraddizione fra parti del documento rende la specifica da correggere e **MUST NOT** autorizzare l'implementatore a scegliere autonomamente.

## 0.2 Significato della completezza

La completezza è relativa al perimetro dichiarato. Non significa che il software rappresenti ogni processo amministrativo o ogni situazione aziendale possibile.

Per ogni caso appartenente al perimetro, il comportamento deve essere uno dei seguenti:

1. il caso è rappresentato con le primitive definite;
2. l'operazione è rifiutata con una motivazione di dominio;
3. il caso è assegnato esplicitamente a un processo esterno.

## 0.3 Decisioni consolidate nella versione 4.0

La versione 4.0:

- mantiene una sola realtà operativa;
- mantiene esclusivamente `Stima` ed `Effettivo` come tipi economici;
- mantiene il Budget Approvato come Snapshot immutabile e versionata;
- mantiene la Proposta come spazio isolato che modifica il piano e non gli Effettivi;
- elimina i conflitti per singolo campo e usa il riallineamento dell'intera sorgente;
- mantiene il Riporto esclusivamente per i Progetti;
- rende esplicita, per ogni Progetto e passaggio d'anno, una sola modalità fra `Nessuna`, `Riporto` e `Riprogrammazione`;
- limita il Riporto in modo che un Effettivo negativo non crei disponibilità superiore all'Allocato;
- mantiene la distinzione tra Consuntivo alla Chiusura ed Effettivo a Conoscenza Corrente;
- non consente la riclassificazione economica di un Esercizio chiuso: gli errori di imputazione vengono annotati;
- definisce lo stato di Progetti e Contratti alla data;
- usa il 31 dicembre come data di riferimento della Snapshot di Chiusura;
- applica atomicamente le operazioni a tutti gli Esercizi Aperti interessati;
- separa il rinnovo contrattuale dal ciclo di fatturazione;
- mantiene il rinnovo automatico e introduce le scadenze contrattuali informative;
- applica le modifiche economiche contrattuali dal primo confine di ciclo utile, non prima del primo giorno del mese successivo, comunicandolo esplicitamente all'utente;
- separa gli schemi delle Snapshot di Budget e di Chiusura;
- definisce in modo deterministico `Previsto`, `Non previsto`, categorie e attributi dei report;
- elimina la cancellazione fisica ordinaria degli oggetti persistiti;
- usa l'Importo della Riga come valore economico autoritativo; quantità e importo unitario sono opzionali;
- elimina completamente Forecast, Previsione Corrente, Previsione a Finire e Snapshot di Forecast.

---

# Parte I — Contratto del prodotto

# 1. Modello scelto

Il modello canonico è:

> **realtà operativa viva + Budget Approvati immutabili + Proposta del piano + Snapshot di Chiusura**

## 1.1 Realtà operativa

La realtà operativa è costituita da:

- Spese e Righe;
- Progetti e transizioni di stato;
- Contratti, scadenze e condizioni economiche;
- Riporti dei Progetti;
- classificazioni annuali;
- Fornitori e Centri di Costo;
- Effettivi vivi e correzioni tardive ammesse.

Questi oggetti descrivono ciò che oggi risulta pianificato e ciò che l'utente dichiara realmente sostenuto.

## 1.2 Riferimenti immutabili

Il sistema conserva:

- **Budget Approvato:** ciò che era stato formalmente concordato;
- **Snapshot di Chiusura:** ciò che risultava al termine dell'Esercizio secondo le informazioni disponibili al momento della Chiusura.

Le modifiche successive non riscrivono questi riferimenti.

## 1.3 Proposta

La Proposta prepara un Budget iniziale o una Revisione senza modificare prematuramente la realtà.

La Proposta:

- modifica soltanto il piano;
- mostra gli Effettivi come realtà non modificabile;
- conserva le azioni proposte per ciascuna sorgente;
- deve essere riallineata quando la sorgente viva cambia;
- applica le azioni soltanto all'approvazione.

## 1.4 Nessun Forecast

Il sistema **MUST NOT** calcolare, memorizzare o richiedere quanto si pensa di spendere complessivamente alla fine dell'anno.

Il sistema distingue soltanto:

- Budget Approvato;
- Allocato Corrente;
- Effettivo;
- Snapshot di Chiusura;
- Effettivo a Conoscenza Corrente.

L'assenza del Forecast è una decisione di prodotto.

---

# 2. Contratto di perimetro

## 2.1 Domande supportate

Il sistema **MUST** permettere di rispondere a queste domande:

- quali Contratti esistono;
- quali Contratti sono attivi, pianificati, cessati, annullati o archiviati;
- quali sono le prossime scadenze contrattuali note;
- quali condizioni economiche producono le Stime dei Contratti;
- quali Progetti esistono e qual è il loro stato alla data di riferimento;
- quali Spese sono pianificate o sostenute;
- quanto era stato approvato in una specifica versione di Budget;
- quanto risulta oggi allocato;
- quanto è stato dichiarato effettivamente sostenuto;
- quali sorgenti erano presenti o assenti dal Budget selezionato;
- quali sorgenti erano previste economicamente e non hanno prodotto Effettivi;
- quali variazioni hanno modificato importo, classificazione, stato, condizioni o Riporto;
- quali motivazioni ed eventi spiegano una differenza;
- quale piano viene proposto per un Esercizio futuro o per una Revisione;
- quale situazione risultava alla Chiusura;
- quali correzioni tardive di importo sono state aggiunte successivamente;
- quali errori storici di imputazione sono stati annotati senza riscrivere il passato.

## 2.2 Domande escluse

Il sistema **MUST NOT** pretendere di rispondere a queste domande:

- quanto si pensa di spendere alla fine dell'anno;
- quanto probabilmente resterà alla fine dell'anno;
- quale fattura è scaduta, in ritardo o mancante;
- quale rata è stata pagata o non pagata;
- quale Effettivo ha consumato una specifica Stima;
- quale preventivo commerciale è valido, accettato, scaduto o sostituito;
- quale importo è di competenza contabile;
- quale importo deve essere pagato a una determinata data;
- quale disponibilità di cassa sarà necessaria;
- quale IVA, ritenuta, imposta o trattamento fiscale si applica;
- quale ordine, fattura o nota di credito deve essere emesso o registrato;
- quale causa amministrativa ha prodotto automaticamente una differenza;
- come ripartire una stessa sorgente fra più Centri di Costo;
- come convertire automaticamente valute diverse da EUR.

## 2.3 Conseguenza economica e processo esterno

Il sistema governa la conseguenza economica, non l'intero processo amministrativo che l'ha prodotta.

Esempi:

- preventivo non rispettato → differenza fra Stima ed Effettivo;
- guasto improvviso → nuova sorgente con Stima, Effettivo o entrambi;
- fattura ricevuta tardi → Effettivo registrato quando noto;
- Contratto cessato e ripreso → cessazione e successiva riattivazione;
- rinnovo contrattuale → scadenza e rinnovo informativi, senza scadenzario fatture.

## 2.4 Nessuna inferenza automatica della causa

Il sistema **MUST NOT** dedurre automaticamente che:

- una Stima senza Effettivi corrisponda a una fattura mancante;
- un Effettivo inferiore alla Stima costituisca un Risparmio prima della Chiusura del Progetto;
- un Effettivo superiore alla Stima dipenda da un errore, da un aumento o da un imprevisto;
- un Contratto senza Effettivi non sia stato erogato;
- due sorgenti con titolo simile rappresentino la stessa identità o una continuità canonica;
- una Spesa sia un Plafond dalla Descrizione.

La causa può essere dichiarata tramite Note, Timeline o allegati. `Collegato a` resta
una relazione informativa di navigazione fra Progetto e Contratto e **MUST NOT** essere
usata per rappresentare una sostituzione.

---

# 3. Definition of Done ed evoluzione controllata

## 3.1 Definition of Done

Il dominio è considerato completo quando, entro il perimetro dichiarato:

1. ogni evento rilevante è rappresentabile, bloccato esplicitamente o assegnato a un processo esterno;
2. ogni operazione definisce cosa cambia, cosa non cambia, quali Esercizi sono interessati e quali totali vengono ricalcolati;
3. ogni operazione vietata produce una motivazione di dominio;
4. non esistono due rappresentazioni canoniche equivalenti dello stesso fatto;
5. gli invarianti sono verificabili;
6. nessuna decisione economica necessaria è lasciata all'implementatore;
7. i limiti descrivono il comportamento concreto del sistema;
8. una revisione indipendente non individua casi nel perimetro privi di comportamento deterministico.

La Definition of Done **MUST NOT** essere interpretata come impossibilità di immaginare nuove funzionalità.

## 3.2 Classificazione delle nuove casistiche

Ogni nuova casistica deve essere classificata prima di modificare il dominio.

### A — Variante narrativa

Il comportamento economico è già coperto.

**Esito:** nessuna modifica.

### B — Caso componibile

Il caso è rappresentabile combinando primitive esistenti.

**Esito:** eventuale nuovo esempio o test.

### C — Informazione descrittiva

Il caso richiede Note, Timeline, allegati o una relazione informativa, ma non modifica calcoli o vincoli.

**Esito:** nessun nuovo concetto economico.

### D — Fuori perimetro

Il caso appartiene a fatturazione, contabilità, procurement, tesoreria o altro dominio escluso.

**Esito:** limite dichiarato o integrazione esterna.

### E — Lacuna strutturale

Il caso appartiene allo scopo, deve essere gestito, non è rappresentabile correttamente e lascia più comportamenti plausibili all'implementatore.

**Esito:** il dominio viene riaperto.

Solo la categoria **E** giustifica automaticamente una modifica strutturale.

## 3.3 Test della lacuna strutturale

Un caso è una lacuna strutturale soltanto se sono vere tutte queste condizioni:

1. appartiene alle domande del §2.1;
2. il sistema deve produrre un comportamento o una risposta;
3. le primitive esistenti non bastano;
4. ignorarlo produrrebbe un risultato falso o ambiguo;
5. esistono almeno due implementazioni plausibili e incompatibili.

## 3.4 Regola di ammissione dei campi

Un nuovo campo strutturato entra nel dominio soltanto se modifica almeno uno dei seguenti aspetti:

- calcolo;
- validazione o invariante;
- transizione di stato;
- autorizzazione;
- approvazione o Chiusura;
- report, filtro o raggruppamento obbligatorio;
- informazione di audit interrogabile in modo affidabile.

Negli altri casi si usano Note, Timeline o allegati.

## 3.5 Regola di ammissione delle relazioni

Una nuova relazione entra nel dominio soltanto se:

- ha semantica univoca;
- cambia un comportamento obbligatorio o una navigazione esplicitamente richiesta;
- non può essere sostituita da una Nota;
- non crea doppio conteggio;
- possiede cardinalità, direzione, efficacia e ciclo di vita definiti.

---

# Parte II — Specifica canonica

# 4. Scopo, base temporale e base monetaria

## 4.1 Scopo operativo

Il ciclo operativo è:

1. registrare Contratti, Progetti, Spese, Stime ed Effettivi;
2. preparare una Proposta per un Esercizio futuro o una Revisione;
3. approvare una versione immutabile del Budget;
4. continuare ad aggiornare la realtà durante l'anno;
5. confrontare Budget, Allocato Corrente ed Effettivo;
6. chiudere l'Esercizio e consolidare i Riporti;
7. conservare separatamente Chiusura, correzioni tardive e annotazioni di errore storico.

## 4.2 Confini

Il sistema **MUST NOT** diventare:

- contabilità generale;
- contabilità analitica completa;
- sistema di fatturazione;
- scadenzario fatture;
- procurement;
- sistema di ordini;
- motore fiscale;
- motore di ratei o risconti;
- sistema di impegni contabili;
- gestione di cassa;
- ERP generalista;
- sistema di Forecast finanziario;
- workflow approvativo multilivello obbligatorio.

## 4.3 Esercizio, valuta e precisione

- Ogni Esercizio corrisponde a un anno solare.
- La valuta è esclusivamente `EUR`.
- Gli importi sono al netto dell'IVA.
- L'IVA non viene gestita.
- Gli importi monetari autoritativi hanno due decimali.
- I calcoli **MUST** usare aritmetica decimale.
- Ogni Azienda **MUST** avere un fuso orario IANA.
- Le date economiche sono interpretate nel fuso dell'Azienda.
- I timestamp tecnici sono conservati in UTC.

---

# 5. Principi fondamentali

## 5.1 Una sola realtà operativa

Spese, Progetti, Contratti, condizioni, scadenze, classificazioni, Riporti ed Effettivi vivi costituiscono l'unica realtà operativa.

Budget Approvati, Proposte, Snapshot e report **MUST NOT** diventare sorgenti alternative degli Effettivi.

## 5.2 Due soli tipi economici

Le Righe supportano esclusivamente:

- `Stima`;
- `Effettivo`.

Non esistono tipi economici `Forecast`, `Preventivo`, `Imprevista`, `Plafond` o `Rettifica`.

## 5.3 Nessun matching Stima → Effettivo

Il sistema **MUST NOT** collegare una Stima a uno specifico Effettivo.

Sono vietati:

- FIFO;
- LIFO;
- consumo di una Stima;
- stato consumata/non consumata;
- matching Effettivo → ciclo contrattuale;
- matching Effettivo → quota di Riporto.

## 5.4 Identità stabile

Ogni oggetto persistente riceve un ID stabile e non riutilizzabile.

Per i confronti:

```text
OriginKey = TipoOrigine + IDOrigine
```

Titolo, Descrizione, Fornitore e importo **MUST NOT** essere usati come identità.

## 5.5 Immutabilità selettiva

- Budget Approvati e Snapshot di Chiusura sono immutabili.
- Gli oggetti vivi sono modificabili negli Esercizi Aperti entro i vincoli.
- Un Esercizio Chiuso non viene riaperto.
- Le correzioni tardive sono append-only.
- Le annotazioni di errore storico sono append-only.

## 5.6 Nessun doppio conteggio

Le sorgenti economiche di primo livello sono:

- Spese autonome;
- Progetti;
- Contratti.

Le Spese associate alimentano il contenitore e non vengono sommate nuovamente al totale generale.

## 5.7 Nessuna cancellazione fisica ordinaria

Un oggetto persistito del dominio **MUST NOT** essere eliminato fisicamente tramite operazioni ordinarie.

- una Bozza non ancora persistita può essere abbandonata;
- una Spesa persistita può essere Stornata solo se non contiene Effettivi;
- Progetti e Contratti usano stato e Archivio;
- Fornitori e Centri di Costo usano Archivio.

## 5.8 Archiviazione non economica

L'Archivio modifica visibilità e selezionabilità, ma **MUST NOT**:

- rimuovere valori dai totali;
- cambiare stato economico;
- cambiare classificazioni storiche;
- modificare Snapshot;
- trasformare una sorgente in assente.

## 5.9 Composizione prima dell'estensione

Una nuova situazione deve essere rappresentata combinando le primitive esistenti quando ciò conserva il significato corretto.

Il sistema **MUST NOT** introdurre un nuovo tipo, stato o relazione soltanto per distinguere una causa narrativa.

---

# 6. Glossario canonico

## 6.1 Esercizio

Anno solare per il quale vengono governati Stime, Effettivi, Budget, Riporti e Chiusura.

## 6.2 Spesa

Oggetto economico elementare composto da una o più Righe.

## 6.3 Riga Stima

Importo pianificato e allocato nell'Esercizio.

Può derivare da una valutazione, un preventivo, una disponibilità concessa o un calcolo automatico di Contratto.

Non rappresenta:

- un residuo;
- una previsione di fine anno;
- un importo consumato dagli Effettivi.

## 6.4 Riga Effettivo

Importo che l'utente dichiara realmente sostenuto nell'Esercizio indicato.

La dichiarazione dell'utente è autoritativa all'interno dell'anno. La baseline non richiede una Data Effettivo economica strutturata e non può validare il giorno o il mese dell'evento.

Il dominio non distingue strutturalmente fra importo pagato, fatturato o maturato. L'utente deve applicare in modo coerente la convenzione operativa adottata dall'Azienda; il sistema non la deduce né applica competenza contabile.

### Predicato HaEffettivi

```text
HaEffettivi(sorgente, esercizio) =
esiste almeno una Riga Effettivo Attiva con Importo diverso da zero
nella sorgente e nell'Esercizio
```

Il predicato non dipende dal totale netto. Due Righe `+100 €` e `-100 €` producono Effettivo netto zero, ma `HaEffettivi = true`. Una Riga Effettivo a zero non costituisce presenza di Effettivi.

Quando il documento usa `priva di Effettivi` o `possiede Effettivi`, si riferisce a questo predicato, salvo che sia indicato esplicitamente un confronto sull'importo.

## 6.5 Allocato Corrente

Importo oggi pianificato nella realtà operativa.

- Spesa autonoma: somma delle Stime;
- Progetto: Riporto ricevuto più Stime delle Spese del Progetto;
- Contratto: Stima annuale generata dalle condizioni economiche.

## 6.6 Allocato Approvato

Importo materializzato per una sorgente in una specifica versione di Budget Approvato.

## 6.7 Budget Approvato

Snapshot immutabile del piano formalmente approvato per un Esercizio.

## 6.8 Situazione Corrente

Vista derivata dagli oggetti vivi. Mostra almeno Allocato Corrente, Effettivo, classificazione e stato alla data di riferimento definita dal §9.2. Nel dettaglio globale dell'oggetto la data è quella corrente; nella vista annuale è la `DataRiferimentoEsercizio`.

## 6.9 Proposta

Spazio isolato nel quale si prepara il piano senza modificare gli Effettivi.

## 6.10 Snapshot di Chiusura

Fotografia immutabile della situazione dell'Esercizio alla Chiusura, con stato valutato al 31 dicembre.

## 6.11 Effettivo alla Chiusura

Effettivo materializzato nella Snapshot di Chiusura.

## 6.12 Effettivo a Conoscenza Corrente

Effettivo dell'Esercizio secondo le conoscenze attuali, comprese le correzioni tardive di importo.

Non riclassifica errori storici di Centro di Costo, Progetto, Contratto, Anno, Fornitore o contenitore.

## 6.13 Scostamento Operativo

```text
Effettivo - Allocato Corrente
```

## 6.14 Variazione Allocato vs Budget

```text
Allocato Corrente - Allocato Approvato selezionato
```

## 6.15 Varianza Budget vs Actual

```text
Effettivo selezionato - Allocato Approvato selezionato
```

## 6.16 Residuo del Progetto

Per un Progetto Pianificato o Aperto:

```text
Residuo = max(Allocato Corrente - Effettivo, 0)
```

## 6.17 Disponibilità massima riportabile

```text
DisponibilitàMassimaRiportabile = min(Residuo, Allocato Corrente)
```

Impedisce che un Effettivo negativo produca Riporto superiore all'Allocato.

## 6.18 Riporto

Quota della disponibilità massima riportabile trasferita all'Allocato dell'Esercizio successivo.

## 6.19 Riporto provvisorio

Importo temporaneamente usato nella preparazione di `N+1` prima della Chiusura di `N`.

Non è una previsione del Residuo finale.

## 6.20 Riporto consolidato

Importo definitivo scelto alla Chiusura entro la disponibilità massima riportabile.

## 6.21 Riprogrammazione

Modalità alternativa al Riporto, con la quale una quota della disponibilità ancora trasferibile viene rimossa dal piano dell'Esercizio origine e ricreata come nuove Stime nell'Esercizio futuro.

L'operazione conserva un `ImportoRiprogrammato` e deve ridurre l'Allocato origine e aumentare le Stime destinazione dello stesso importo.

## 6.22 Centro di Costo

Classificazione organizzativa annuale. Non genera importi.

## 6.23 Stato alla data

Stato di un Progetto o Contratto ottenuto applicando gli eventi e le regole efficaci entro una data specifica.

## 6.24 Scadenza contrattuale

Data informativa alla quale termina il periodo contrattuale corrente o si verifica il rinnovo automatico.

Non è la scadenza di una fattura e non rappresenta un debito.

## 6.25 Timeline

Registro append-only di eventi funzionali ed economici. Non costituisce event sourcing.

## 6.26 Annotazione di errore storico

Evento append-only che dichiara un errore scoperto dopo la Chiusura senza modificare valori, imputazioni, Snapshot o Riporti storici.


---

# 7. Modello concettuale, identità e inclusione

## 7.1 Relazioni principali

```text
Azienda
└── Esercizio
    ├── Proposta attiva opzionale
    │   └── Elementi di Proposta
    ├── Budget Approvato v1..vN
    ├── Snapshot di Chiusura opzionale
    └── Situazione Corrente derivata da
        ├── Spese autonome
        ├── Progetti
        │   ├── Transizioni di stato
        │   ├── Spese di Progetto
        │   └── Riporti
        ├── Contratti
        │   ├── Scadenze e rinnovi
        │   ├── Condizioni economiche
        │   ├── Spesa Stima annuale di sistema
        │   └── Spese Effettive manuali
        ├── classificazioni annuali
        └── Timeline
```

## 7.2 Appartenenza economica della Spesa

Una Spesa è esattamente uno dei seguenti casi:

1. autonoma;
2. associata a un Progetto;
3. associata a un Contratto.

Deve valere:

```text
NOT (Spesa.Progetto != null AND Spesa.Contratto != null)
```

## 7.3 Relazioni informative

È ammessa una sola relazione informativa opzionale:

- `Collegato a`, esclusivamente fra Progetto e Contratto.

### Collegato a

- è una relazione simmetrica e non direzionale;
- la coppia di sorgenti non può essere duplicata mentre la relazione è Attiva;
- non richiede un Esercizio di efficacia;
- cardinalità molti-a-molti è ammessa.

Ogni relazione contiene:

- ID;
- Progetto;
- Contratto;
- Nota;
- stato `Attiva` oppure `Archiviata`;
- audit.

Una relazione persistita non viene eliminata fisicamente. Può essere Archiviata o ripristinata con audit.

Le relazioni:

- non trasferiscono importi, stato, classificazione o Riporto;
- non producono inclusione economica;
- non cessano, aprono, chiudono o stornano automaticamente alcuna sorgente;
- non vengono dedotte automaticamente.

## 7.4 Appartenenza all'Azienda

Ogni Azienda contiene almeno:

- ID stabile;
- denominazione;
- fuso orario IANA;
- Impostazioni;
- assegnazioni dei permessi;
- audit.

Spese, Progetti, Contratti, Fornitori, Centri di Costo, Esercizi, Proposte, Budget, Snapshot e relazioni appartengono a una sola Azienda.

Il sistema **MUST NOT** condividere lo stesso oggetto economico o anagrafico fra Aziende differenti. Un soggetto presente in più Aziende viene rappresentato da identità distinte.

## 7.5 Contesto annuale

Sono annuali:

- Esercizio della Spesa;
- classificazione del Progetto;
- classificazione del Contratto;
- Stime;
- Effettivi;
- Allocato Corrente;
- Riporto ricevuto;
- modalità di rinvio;
- Budget Approvato;
- Snapshot di Chiusura.

Sono pluriennali:

- identità di Progetto;
- identità di Contratto;
- Fornitore;
- Centro di Costo;
- eventi temporali di Progetti e Contratti.

## 7.6 Predicati distinti

L'espressione generica `partecipa all'Esercizio` **MUST NOT** essere usata come regola normativa.

Il sistema usa predicati distinti.

### 7.6.1 ContribuisceAiTotaliCorrenti

Una sorgente contribuisce ai totali dell'Esercizio quando possiede Allocato o Effettivo diverso da zero secondo le formule del §8.

- lo stato Chiuso, Cancellato, Cessato o Annullato non rimuove importi già presenti;
- l'Archivio non rimuove importi;
- una Spesa Stornata non contribuisce;
- una Spesa con Effettivi non può essere Stornata.

### 7.6.2 InclusaAutomaticamenteInProposta

Una sorgente è inclusa automaticamente nella Proposta dell'Esercizio quando ricorre almeno uno dei seguenti casi:

#### Spesa autonoma

- esiste nell'Esercizio ed è Attiva;
- possiede Stime o Effettivi;
- era già presente in una versione di Budget dell'Esercizio.

#### Progetto

- è Pianificato o Aperto in almeno un giorno dell'Esercizio;
- possiede Stime, Effettivi o Riporto nell'Esercizio;
- possiede una transizione approvata o pianificata efficace nell'Esercizio;
- era presente in una versione di Budget dell'Esercizio.

#### Contratto

- è Pianificato o Attivo in almeno un giorno dell'Esercizio;
- una condizione produce una Stima nell'Esercizio;
- possiede Effettivi nell'Esercizio;
- possiede una scadenza, cessazione, riattivazione o rinnovo efficace nell'Esercizio;
- era presente in una versione di Budget dell'Esercizio.

Una sorgente Archiviata che soddisfa queste condizioni viene inclusa in sola lettura. L'Archivio non la trasforma in assenza.

### 7.6.3 SelezionabileManualmenteInProposta

- una Spesa autonoma di un altro Esercizio può essere selezionata per la copia;
- un Progetto Chiuso o Cancellato può essere selezionato per una futura riapertura; se è Archiviato, la Proposta deve includere anche il ripristino dall'Archivio;
- un Contratto Cessato o Annullato può essere selezionato per una futura riattivazione; se è Archiviato, la Proposta deve includere anche il ripristino dall'Archivio;
- un oggetto Archiviato deve essere ripristinato prima di nuove azioni ordinarie vive, oppure mediante un'azione esplicita della Proposta applicata all'approvazione;
- una sorgente Stornata non è selezionabile per nuova pianificazione finché non viene ripristinata.

### 7.6.4 InclusaNelBudgetApprovato

Il Budget Approvato include esattamente tutti gli Elementi di Proposta efficaci e rivalidati al momento dell'approvazione, anche quando l'Allocato approvato è zero.

### 7.6.5 InclusaNellaSnapshotDiChiusura

Una sorgente è inclusa nella Snapshot di Chiusura quando ricorre almeno uno dei seguenti casi:

- Allocato finale diverso da zero;
- HaEffettivi alla Chiusura è vero, anche se il totale netto è zero;
- Riporto ricevuto o consolidato diverso da zero;
- presenza in almeno un Budget Approvato dell'Esercizio;
- transizione di stato o decisione di Chiusura nell'Esercizio;
- il Progetto è Pianificato o Aperto al 31 dicembre;
- il Contratto è Pianificato o Attivo al 31 dicembre;
- una condizione Valida ha un intervallo che si sovrappone all'Esercizio oppure produce almeno una Data di attribuzione della Stima nell'Esercizio;
- una scadenza, cessazione, riattivazione, annullamento o rinnovo ha data di efficacia nell'Esercizio;
- Storno avvenuto nell'Esercizio.

### 7.6.6 InclusaNelRiferimentoCorrente

Una sorgente viene materializzata in una vista o confronto della Situazione Corrente quando ricorre almeno uno dei seguenti casi:

- Allocato Corrente diverso da zero;
- HaEffettivi Corrente è vero, anche se il totale netto è zero;
- Riporto ricevuto diverso da zero;
- presenza nel Budget selezionato o in almeno una versione di Budget dell'Esercizio;
- transizione di stato, Storno o ripristino nell'Esercizio;
- per un Progetto, stato Pianificato o Aperto alla `DataRiferimentoEsercizio` della vista annuale;
- per un Contratto, stato Pianificato o Attivo alla `DataRiferimentoEsercizio` della vista annuale, condizione sovrapposta all'Esercizio oppure evento contrattuale nell'Esercizio;
- Annotazione di errore storico associata alla sorgente.

## 7.7 Matrice sintetica di inclusione

| Caso | Totali correnti | Proposta automatica | Budget Approvato | Chiusura | Nuove operazioni |
|---|---:|---:|---:|---:|---:|
| Sorgente operativa con valori | Sì | Sì | Se approvata | Sì | Sì |
| Sorgente operativa a zero con decisione o stato | No | Sì | Se approvata | Secondo §7.6.5 | Sì |
| Spesa Stornata | No | Sola lettura se storica | Se già approvata o esplicitamente inclusa | Secondo §7.6.5 | Solo ripristino |
| Sorgente Archiviata con valori o storia nell'anno | Sì | Sola lettura, salvo azione esplicita di ripristino | Se approvata | Sì | Ripristino esplicito prima di nuove attività |
| Progetto Chiuso/Cancellato | Valori esistenti sì | Se valori, Budget o transizioni | Se approvato | Secondo §7.6.5 | Riapertura esplicita |
| Contratto Cessato/Annullato | Valori esistenti sì | Se valori, Budget o eventi | Se approvato | Secondo §7.6.5 | Riattivazione esplicita |

## 7.8 Stati ortogonali

Sono concetti distinti:

- stato dell'Esercizio;
- stato della Proposta;
- stato del Progetto;
- stato del Contratto;
- stato Attiva/Stornata della Spesa;
- stato Attiva/Annullata della Riga;
- proprietà di Archivio;
- esistenza e versione del Budget Approvato.

Uno stato **MUST NOT** essere dedotto automaticamente da un altro, salvo le transizioni esplicitamente definite.

---

# 8. Righe, formule economiche e aggregazioni

## 8.1 Struttura e Importo autoritativo della Riga

Ogni Riga contiene almeno:

- ID stabile;
- Tipo `Stima` oppure `Effettivo`;
- `Importo` autoritativo con due decimali;
- stato `Attiva` oppure `Annullata`;
- Nota opzionale, salvo i casi in cui è obbligatoria;
- Allegati opzionali;
- audit.

Può contenere inoltre:

- Quantità opzionale;
- Importo unitario opzionale;
- Unità di misura opzionale.

Se Quantità o Importo unitario sono implementati come campi numerici, devono supportare almeno sei cifre decimali. La loro precisione **MUST NOT** impedire il salvataggio dell'Importo autoritativo.

Soltanto le Righe Attive partecipano ai calcoli.

Una Riga persistita non viene eliminata fisicamente. In un Esercizio Aperto può essere Annullata o ripristinata con audit, nei limiti delle regole della Spesa. In un Esercizio Chiuso si applica il §24.

Quantità e Importo unitario sono descrittivi. Se entrambi sono presenti, il sistema **MAY** proporre:

```text
ImportoSuggerito = round_half_up(Quantità × ImportoUnitario, 2)
```

L'Importo salvato resta autoritativo. Se differisce dall'Importo suggerito, il sistema mostra un avviso prima del salvataggio.

Vincoli:

- una Riga Stima **MUST** avere Importo maggiore o uguale a zero;
- il sistema **MUST** consentire una Riga Effettivo con Importo negativo soltanto per rimborso, accredito o correzione, con Nota obbligatoria;
- una Riga con Importo zero è ammessa soltanto quando serve a preservare un'identità già materializzata o una decisione esplicita; una nuova Riga manuale a zero **SHOULD NOT** essere creata senza motivo.

## 8.2 Valori della Spesa

```text
StimaSpesa = somma Importi delle Righe Stima Attive
EffettivoSpesa = somma Importi delle Righe Effettivo Attive
ScostamentoSpesa = EffettivoSpesa - StimaSpesa
```

## 8.3 Spesa autonoma

```text
AllocatoCorrenteSpesaAutonoma = StimaSpesa
EffettivoSpesaAutonoma = EffettivoSpesa
```

## 8.4 Progetto

```text
AllocatoCorrenteProgetto = RiportoRicevuto + somma Stime delle Spese del Progetto nell'Esercizio
EffettivoProgetto = somma Effettivi delle Spese del Progetto nell'Esercizio
ScostamentoOperativoProgetto = EffettivoProgetto - AllocatoCorrenteProgetto
ResiduoProgetto = max(AllocatoCorrenteProgetto - EffettivoProgetto, 0)
DisponibilitàMassimaRiportabile = min(ResiduoProgetto, AllocatoCorrenteProgetto)
```

Poiché Allocato Corrente non è negativo, la disponibilità massima riportabile non è mai negativa.

## 8.5 Contratto

```text
AllocatoCorrenteContratto = Stima annuale generata dalle condizioni economiche
EffettivoContratto = somma Effettivi delle Spese manuali associate
ScostamentoOperativoContratto = EffettivoContratto - AllocatoCorrenteContratto
```

## 8.6 Totali di Esercizio

```text
AllocatoCorrenteEsercizio =
    somma Allocati delle Spese autonome
  + somma Allocati dei Progetti
  + somma Allocati dei Contratti

EffettivoEsercizio =
    somma Effettivi delle Spese autonome
  + somma Effettivi dei Progetti
  + somma Effettivi dei Contratti
```

Le Spese figlie non vengono sommate una seconda volta.

## 8.7 Misure di confronto

### Scostamento Operativo

```text
Effettivo - Allocato Corrente
```

### Allocato vs Budget

```text
Allocato Corrente - Allocato Approvato selezionato
```

### Budget vs Actual

```text
Effettivo selezionato - Allocato Approvato selezionato
```

### Correzioni tardive nette

```text
Effettivo a Conoscenza Corrente - Effettivo alla Chiusura
```

Il sistema **MUST NOT** chiamare tutte queste misure `Scostamento`.

---

# 9. Modello temporale di Progetti e Contratti

## 9.1 Principio

Lo stato storico non dipende dal giorno in cui l'utente apre una schermata o materializza una Snapshot.

Il sistema **MUST** poter calcolare:

```text
StatoProgettoAllaData(data)
StatoContrattoAllaData(data)
```

## 9.2 Date di riferimento obbligatorie

Per una vista annuale si definisce:

```text
DataRiferimentoEsercizio(E) =
    31 dicembre di E, se E è precedente all'anno locale corrente;
    data locale corrente, se E è l'anno locale corrente;
    1 gennaio di E, se E è successivo all'anno locale corrente.
```

Si applicano queste date:

- dettaglio globale corrente di un Progetto o Contratto: data locale corrente dell'Azienda;
- Situazione Corrente di uno specifico Esercizio: `DataRiferimentoEsercizio(E)`;
- Proposta: primo e ultimo giorno dell'Esercizio di destinazione, più tutte le transizioni proposte;
- Budget Approvato: stato al 1° gennaio, transizioni approvate nell'Esercizio e stato al 31 dicembre;
- Snapshot di Chiusura: stato al 31 dicembre dell'Esercizio;
- confronto fra Snapshot: stato materializzato in ciascuna Snapshot;
- report con una data esplicitamente selezionata: la data selezionata.

Per un Esercizio futuro, la Situazione Corrente mostra lo stato al 1° gennaio e separatamente tutte le transizioni già pianificate nell'Esercizio.

La data tecnica di approvazione o Chiusura **MUST NOT** sostituire la data economica di riferimento.

Se una sorgente non esisteva ancora alla data richiesta, la funzione restituisce il marcatore di lettura `Assente alla data`. Il marcatore non è uno stato di ciclo di vita e viene usato soltanto in Snapshot e confronti.

## 9.3 Eventi di stato del Progetto

Ogni Progetto possiede:

- stato iniziale;
- data di efficacia dello stato iniziale;
- zero o più transizioni con data di efficacia;
- stato della transizione: Pianificata, Efficace o Annullata;
- autore, data tecnica e motivo.

La data di efficacia di una transizione di Progetto è il primo giorno nel nuovo stato. Per esempio, una Chiusura efficace il 31 dicembre rende il Progetto Chiuso nella Snapshot del 31 dicembre; se deve restare Aperto per tutto il 31 dicembre, la Chiusura deve essere efficace il 1° gennaio successivo.

Regole:

1. non possono esistere due transizioni non Annullate con la stessa data di efficacia;
2. una transizione futura può essere Annullata o sostituita prima della sua efficacia, con audit;
3. una transizione già efficace non viene cancellata; viene seguita da una nuova transizione;
4. una transizione è incompatibile quando lo stato prodotto immediatamente prima della sua data non coincide con lo stato di origine richiesto dalla transizione oppure la coppia origine→destinazione non è ammessa dal §16.4;
5. una transizione incompatibile con una transizione futura deve prima sostituirla o annullarla;
6. prima della data di efficacia dello stato iniziale, `StatoProgettoAllaData` restituisce `Assente alla data`;
7. dalla data iniziale, applica in ordine cronologico tutte le transizioni non Annullate con data di efficacia minore o uguale alla data richiesta.

Un nuovo Progetto creato in una Proposta prima dell'inizio dell'Esercizio di destinazione riceve per default stato `Pianificato` con efficacia il 1° gennaio dell'Esercizio, salvo una data futura esplicitamente approvata. Se l'Esercizio è già iniziato, la data predefinita è la data di approvazione. Un Progetto urgente creato direttamente durante un Esercizio può ricevere stato iniziale `Aperto` con efficacia alla data di creazione.

## 9.4 Eventi di stato del Contratto

Il Contratto possiede eventi di:

- pianificazione;
- attivazione;
- cessazione;
- riattivazione;
- annullamento prima dell'attivazione;
- rinnovo contrattuale.

Per il Contratto:

- Data di inizio e Data di riattivazione sono il primo giorno Attivo;
- Data di cessazione e prossima scadenza senza rinnovo sono l'ultimo giorno Attivo;
- lo stato Cessato decorre dal giorno successivo;
- un evento conserva sia la data contrattuale dichiarata sia la data dalla quale cambia lo stato, quando sono differenti.

Regole:

1. non possono esistere due eventi non Annullati con la stessa data dalla quale cambia lo stato;
2. un evento è compatibile soltanto nei seguenti casi:
   - attivazione iniziale: stato precedente Pianificato;
   - riattivazione: stato precedente Cessato o Annullato;
   - cessazione: stato precedente Attivo;
   - annullamento prima dell'attivazione: stato precedente Pianificato e nessuna precedente attivazione efficace;
   - rinnovo: stato precedente Attivo e scadenza raggiunta con rinnovo automatico valido;
3. un evento che non soddisfa il punto 2 è incompatibile e viene bloccato;
4. un evento futuro può essere Annullato o sostituito prima dell'efficacia;
5. un evento efficace resta storico;
6. prima della Data di inizio, il risultato è Pianificato;
7. un evento di attivazione o riattivazione porta lo stato ad Attivo;
8. un evento di cessazione o una scadenza non rinnovata porta lo stato a Cessato dal giorno successivo alla data finale;
9. un annullamento prima della prima attivazione porta lo stato ad Annullato;
10. un rinnovo automatico mantiene lo stato Attivo;
11. gli eventi non Annullati vengono applicati in ordine cronologico fino alla data richiesta;
12. per ogni scadenza viene usata la configurazione di rinnovo valida a quella data, secondo il §18.5;
13. per una data futura, `StatoContrattoAllaData` include i rinnovi automatici proiettati dalle configurazioni già efficaci, salvo cessazioni o modifiche future già registrate; tali rinnovi sono pianificati e diventano eventi storici soltanto alla rispettiva scadenza.

## 9.5 Stato storico alla Chiusura

Esempio:

```text
Progetto Aperto al 31/12/2025
Progetto Chiuso il 15/03/2026
Chiusura del 2025 eseguita il 10/04/2026
```

La Snapshot di Chiusura 2025 **MUST** mostrare `Aperto`.

## 9.6 Compatibilità con Esercizi successivi

Una transizione efficace in `N` non riscrive automaticamente `N+1`.

- un Progetto Chiuso al 31 dicembre di `N` può essere riaperto in `N+1` con una transizione successiva;
- Effettivi presenti in `N+1` non dimostrano da soli che il Progetto fosse Aperto, perché possono essere tardivi o correttivi;
- Stime in `N+1` richiedono il Progetto Pianificato o Aperto secondo il §16.3; attività Effettive ordinarie richiedono il Progetto Aperto secondo il §16.5; in alternativa deve esistere la relativa transizione esplicita;
- una decisione di Chiusura di `N` che contraddice transizioni future già approvate deve essere bloccata finché la contraddizione non viene risolta.

---

# 10. Esercizi interessati e atomicità inter-esercizio

## 10.1 Insieme degli Esercizi interessati

Prima di applicare un'operazione, il sistema **MUST** calcolare l'insieme degli Esercizi Aperti i cui valori o stati cambierebbero.

Possono interessare più Esercizi:

- condizioni contrattuali senza termine;
- rinnovi, cessazioni e riattivazioni;
- transizioni future di Progetto;
- cambio Anno di una Spesa;
- riprogrammazione;
- consolidamento del Riporto;
- modifica di classificazioni future.

## 10.2 Regola atomica

Un'operazione che interessa più Esercizi Aperti **MUST**:

1. enumerare tutti gli Esercizi interessati;
2. mostrare all'utente l'impatto per ciascun Esercizio;
3. bloccare e rivalidare tutti gli Esercizi e le sorgenti interessate;
4. applicare tutte le modifiche in un'unica transazione logica;
5. non produrre effetti parziali;
6. registrare un evento con l'impatto per anno.

## 10.3 Esercizi Chiusi

Un Esercizio Chiuso **MUST NOT** essere ricalcolato.

Se un'operazione corrente avrebbe prodotto un valore diverso in un Esercizio Chiuso:

- il valore storico resta invariato;
- il sistema mostra la divergenza;
- registra sempre un evento di Timeline che descrive la divergenza;
- se l'utente dichiara che il valore storico era errato, registra anche un'Annotazione di errore storico;
- applica soltanto gli effetti sugli Esercizi Aperti.

## 10.4 Proposte concorrenti in altri Esercizi

Se un'operazione modifica una sorgente o un Esercizio usato da un'altra Proposta in Bozza:

- la Proposta non blocca automaticamente l'operazione ordinaria;
- la sorgente della Proposta viene marcata `Da riallineare`;
- l'approvazione della Proposta resta bloccata finché non viene riallineata.

## 10.5 Budget Approvati di altri Esercizi

L'operazione può cambiare l'Allocato Corrente di un Esercizio che possiede già un Budget Approvato.

In tal caso:

- il Budget Approvato resta invariato;
- la Situazione Corrente cambia;
- il report Allocato vs Budget mostra la differenza;
- la Timeline registra la causa.


---

# 11. Ciclo di vita dell'Esercizio

## 11.1 Unicità e stati

Per ogni Azienda può esistere un solo Esercizio per anno solare.

Gli stati sono:

- `Aperto`;
- `Chiuso`.

Il sistema **MUST** supportare più Esercizi Aperti contemporaneamente per consentire pianificazione anticipata.

## 11.2 Operazioni nell'Esercizio Aperto

Il sistema **MUST** consentire, nel rispetto dei permessi e degli altri vincoli:

- creazione e modifica di Stime;
- registrazione e correzione di Effettivi;
- cambio Anno di Spese manuali fra Esercizi Aperti;
- cambio di contenitore;
- riclassificazione annuale;
- ricalcolo delle Stime contrattuali;
- preparazione e approvazione di Proposte;
- Revisione del Budget;
- consolidamento del Riporto proveniente dall'anno precedente.

## 11.3 Effettivi futuri

Una Riga Effettivo rappresenta un importo già sostenuto.

L'Esercizio dell'Effettivo **MUST NOT** essere successivo all'anno della registrazione tecnica.

Poiché la baseline non possiede una Data Effettivo economica:

- il sistema può validare soltanto l'anno;
- all'interno dello stesso anno la dichiarazione dell'utente è autoritativa;
- il sistema non può garantire report mensili o impedire un Effettivo dichiarato per un mese futuro dello stesso anno.

## 11.4 Approvazione e stato

L'approvazione non chiude l'Esercizio e non congela gli oggetti vivi.

Un Esercizio può essere:

- Aperto senza Budget;
- Aperto con una o più versioni di Budget;
- Chiuso con Snapshot di Chiusura.

## 11.5 Ordine cronologico

Un Esercizio `N` **MUST NOT** essere Chiuso se esiste un Esercizio precedente non Chiuso.

`N+1` può esistere ed essere approvato prima della Chiusura di `N`; il Riporto proveniente da `N` resta provvisorio.

## 11.6 Nessuna riapertura

Un Esercizio Chiuso **MUST NOT** tornare Aperto.

Errori e omissioni seguono i §§14 e 24.

## 11.7 Creazione dell'Esercizio successivo

Alla Chiusura di `N`, se `N+1` non esiste, l'utente deve indicare se la gestione continua nell'anno successivo.

### Gestione continuata

Il sistema crea `N+1` Aperto nella stessa transazione di Chiusura.

Non crea automaticamente:

- Budget Approvato;
- Spese autonome;
- copie dello storico.

### Gestione terminata

Il sistema non crea `N+1`.

Precondizioni:

- tutti i Riporti devono essere zero;
- non deve esistere una Proposta o un Budget di `N+1`;
- l'utente deve possedere il permesso di Chiusura e confermare la terminazione della gestione.

Se `N+1` esiste già, la Chiusura non lo elimina. L'eventuale offboarding deve essere gestito esplicitamente sull'Esercizio esistente.

## 11.8 Inizializzazione di un nuovo Esercizio

La creazione manuale o automatica di un Esercizio:

- lo pone in stato Aperto;
- non crea un Budget Approvato;
- non copia Spese autonome;
- non copia Effettivi;
- inizializza le classificazioni annuali di Progetti e Contratti dall'ultima classificazione nota, lasciandole modificabili;
- calcola le Stime dei Contratti usando stato alla data, rinnovi, condizioni e Date di attribuzione;
- include condizioni e transizioni future già vive;
- non crea automaticamente Stime di Progetto;
- riceve Riporti soltanto secondo il §17.

Le Stime contrattuali vengono quindi generate senza che l'utente debba ricreare ogni anno il Contratto o le sue condizioni ricorrenti.

---

# 12. Preparazione della Proposta

## 12.1 Scopo

La Proposta prepara il piano senza modificare prematuramente la realtà e senza correggere o spostare Effettivi.

## 12.2 Cardinalità e stati

Per Azienda ed Esercizio può esistere al massimo una Proposta attiva in stato `Bozza`.

L'Esercizio principale di destinazione **MUST** essere Aperto.

Stati:

- `Bozza`;
- `Approvata`;
- `Scartata`.

Una Proposta Approvata o Scartata è immutabile.

## 12.3 Finalità

- `Budget iniziale` se non esiste ancora un Budget Approvato;
- `Revisione` se esiste almeno una versione approvata.

Le Revisioni **MUST** essere sempre consentite finché l'Esercizio è Aperto e l'utente possiede il permesso. Non esiste un'impostazione che le disabiliti.

## 12.4 Inizializzazione

La Proposta include automaticamente le sorgenti determinate dal §7.6.2.

Il sistema **MUST NOT** copiare automaticamente tutte le Spese autonome dell'anno precedente.

L'utente **MAY** selezionare una Spesa autonoma precedente e usare l'azione canonica `Copia nell'Esercizio`.

La copia:

- crea un nuovo Elemento di Proposta;
- **MUST** conservare `CopiedFromOriginKey`;
- riceve una nuova identità viva all'approvazione;
- non copia gli Effettivi;
- non condivide lo stato con la Spesa originaria.

Gli Effettivi già presenti nell'Esercizio vengono mostrati come realtà in sola lettura.

Per una Revisione:

- la base è la realtà viva corrente;
- l'ultima versione approvata viene usata soltanto come riferimento di confronto;
- il Budget precedente non viene clonato né riapplicato agli oggetti vivi;
- anche i valori ereditati e confermati entrano nella nuova Snapshot.

Il Riporto provvisorio iniziale è zero, salvo che esista già un valore vivo per l'Esercizio. Il sistema **MUST NOT** inizializzarlo automaticamente al massimo disponibile.

## 12.5 Elemento di Proposta

Ogni Elemento contiene almeno:

- `ProposalItemID`;
- Esercizio principale;
- tipo di sorgente;
- `OriginKey` opzionale;
- `CopiedFromOriginKey` quando applicabile;
- revisione base della sorgente;
- azioni di piano proposte;
- motivazione quando richiesta;
- valori pianificati risultanti;
- stato `Allineato`, `Da prendere in visione`, `Da riallineare` o `Incoerente`;
- data e autore dell'ultimo riallineamento;
- eventuali relazioni informative proposte.

La Proposta conserva azioni tipizzate, non un patch generico del database.

## 12.6 La Proposta modifica il piano, non gli Effettivi

La Proposta **MUST NOT**:

- creare Effettivi;
- modificare o eliminare Effettivi;
- cambiare l'Esercizio di un Effettivo;
- spostare una Spesa contenente Effettivi;
- riclassificare Effettivi tramite cambio di Centro di Costo;
- stornare una Spesa contenente Effettivi;
- correggere un'imputazione reale.

Gli Effettivi restano nella realtà operativa.

## 12.7 Azioni di piano sulla Spesa

La Proposta **MUST** supportare:

- creare una nuova Spesa pianificata;
- copiare una Spesa autonoma da un altro Esercizio;
- aggiungere, modificare, Annullare o ripristinare Righe Stima;
- azzerare l'Allocato pianificato;
- spostare una Spesa priva di Effettivi fra Spesa autonoma e Progetto;
- spostare una Spesa priva di Effettivi fra Progetti;
- cambiare Esercizio a una Spesa autonoma priva di Effettivi fra Esercizi Aperti;
- cambiare Esercizio a una Spesa di Progetto priva di Effettivi soltanto tramite la modalità `Riprogrammazione` del §16.10;
- cambiare Fornitore di una Spesa autonoma o di Progetto priva di Effettivi;
- cambiare Centro di Costo diretto di una Spesa autonoma priva di Effettivi;
- Stornare o ripristinare una Spesa priva di Effettivi;
- escludere un Elemento che esiste soltanto nella Proposta.

Per ogni spostamento verso un Progetto, il Progetto di destinazione deve essere Pianificato o Aperto nell'Esercizio oppure la Proposta deve includere una transizione valida a Pianificato/Aperto. Un Progetto Chiuso o Cancellato non può ricevere nuova pianificazione senza la relativa riapertura.

Se la Spesa contiene Effettivi, la Proposta può modificare soltanto le sue Righe Stima senza cambiare identità, contenitore, Esercizio, Fornitore o classificazione.

Per riposizionare il piano residuo:

1. gli Effettivi restano nella Spesa originaria;
2. le Stime originarie vengono ridotte o azzerate;
3. viene creata una nuova Spesa pianificata nel nuovo contenitore;
4. la Timeline spiega il riposizionamento del piano;
5. non viene creato matching fra le due Spese.

## 12.8 Azioni di piano sul Progetto

La Proposta **MUST** supportare:

- creare un nuovo Progetto Pianificato;
- ripristinare dall'Archivio un Progetto Chiuso o Cancellato come parte di una futura riapertura;
- continuare un Progetto;
- aggiungere o modificare Stime;
- pianificare apertura, Chiusura, cancellazione o riapertura;
- modificare il Centro di Costo dell'Esercizio soltanto se il Progetto non possiede Effettivi nell'Esercizio;
- scegliere `Riporto` oppure `Riprogrammazione` per il passaggio d'anno;
- indicare il Riporto provvisorio quando la modalità è Riporto;
- indicare l'Importo Riprogrammato e le Stime origine/destinazione coinvolte quando la modalità è Riprogrammazione;
- modificare relazioni informative.

Se il Progetto possiede Effettivi nell'Esercizio, un cambio di Centro di Costo riclassificherebbe gli Effettivi e **MUST NOT** essere eseguito dalla Proposta.

## 12.9 Azioni di piano sul Contratto

La Proposta **MUST** supportare:

- creare un nuovo Contratto Pianificato;
- ripristinare dall'Archivio un Contratto Cessato o Annullato come parte di una futura riattivazione;
- continuare il Contratto;
- aggiungere una condizione economica futura;
- modificare importo, ciclo o modalità di attribuzione secondo il §18;
- pianificare cessazione o riattivazione;
- modificare rinnovo, prossima scadenza, durata del rinnovo e preavviso;
- modificare il Centro di Costo dell'Esercizio soltanto se il Contratto non possiede Effettivi nell'Esercizio;
- modificare relazioni informative.

La Proposta **MUST NOT** modificare il Fornitore di un Contratto già economicamente utilizzato.

## 12.10 Nuovi oggetti collegati fra loro

Un nuovo Elemento di Proposta può riferirsi:

- a un `OriginKey` vivo;
- a un altro `ProposalItemID` della stessa Proposta.

È quindi ammesso, per esempio, creare una nuova Spesa pianificata appartenente a un nuovo Progetto ancora non vivo.

All'approvazione gli ID vivi vengono assegnati e tutte le relazioni vengono risolte atomicamente.

## 12.11 Riallineamento per sorgente

La Proposta non effettua merge automatico per singolo campo.

Ogni sorgente conserva una revisione base. Ai soli fini del riallineamento, una sorgente diventa `Da riallineare` quando cambia almeno uno degli elementi dell'elenco seguente:

- Stime;
- Effettivi;
- contenitore o Esercizio;
- Fornitore che appare nei report;
- Centro di Costo;
- stato o transizioni;
- Riporto;
- condizioni economiche;
- scadenza, rinnovo, cessazione o riattivazione del Contratto;
- Storno, ripristino o Archivio;
- aggiunta, Archivio, ripristino o modifica di una relazione informativa associata alla sorgente.

## 12.12 Azioni di riallineamento

Per una sorgente `Da riallineare`, l'utente deve scegliere:

### Ricarica realtà

- elimina le azioni proposte sulla sorgente;
- usa la realtà corrente come nuova base;
- richiede una nuova eventuale modifica.

### Mantieni proposta

- usa la realtà corrente come nuova base;
- riapplica le azioni di piano proposte;
- ricalcola tutti gli Esercizi interessati;
- resta bloccata se le azioni non sono più valide.

### Rivedi manualmente

- modifica o rimuove le azioni;
- conferma il nuovo risultato.

Ogni riallineamento registra autore, data, base precedente, base nuova e scelta.

## 12.13 Nuove sorgenti e presa visione

Una nuova sorgente viva inclusa automaticamente dopo l'inizializzazione entra nella Proposta come `Da prendere in visione`.

L'utente deve:

- mantenerla nella baseline;
- proporre una modifica di piano;
- oppure, se il dominio lo consente, escluderne il piano senza rimuovere eventuali Effettivi.

La presa visione non crea una modifica economica.

## 12.14 Incoerenze chiuse e verificabili

Una Proposta è `Incoerente` soltanto nei seguenti casi:

- Riporto superiore alla disponibilità massima riportabile;
- azione di Riprogrammazione non ancora eseguita con Importo superiore alla Disponibilità pre-operazione ricalcolata;
- riduzione dell'Allocato origine e incremento delle Stime destinazione non uguali all'Importo Riprogrammato;
- modalità Riporto e Riprogrammazione entrambe selezionate per lo stesso Progetto e passaggio d'anno;
- azione che modificherebbe o sposterebbe Effettivi;
- Spesa associata contemporaneamente a Progetto e Contratto;
- Stima manuale proposta dentro un Contratto;
- condizione contrattuale invalida o non applicabile;
- transizioni di stato incompatibili;
- azione su Esercizio Chiuso;
- riferimento a oggetto Archiviato senza ripristino quando è richiesta una nuova attività;
- dato obbligatorio mancante;
- azioni concorrenti non riallineate;
- relazione informativa non valida;
- operazione che produrrebbe effetti parziali su più Esercizi.

L'implementazione **MUST NOT** introdurre una categoria generica ulteriore senza definirne il predicato.

## 12.15 Approvabilità

Una Proposta **MUST NOT** essere approvata se contiene:

- sorgenti Da prendere in visione;
- sorgenti Da riallineare;
- incoerenze;
- dati obbligatori mancanti;
- azioni non rappresentabili;
- Esercizi interessati non Aperti.

## 12.16 Scarto

Lo scarto:

- non modifica la realtà;
- non annulla operazioni ordinarie;
- rende la Proposta immutabile;
- conserva contenuto, autore, data e motivo.

## 12.17 Sospensione, ripresa e nessun rollback globale

La Proposta in Bozza è persistente.

L'utente può interrompere la preparazione e riprenderla successivamente. Alla ripresa vengono ricalcolati gli stati Da prendere in visione, Da riallineare e Incoerente.

La Proposta **MUST NOT** essere implementata applicando modifiche reali provvisorie da annullare in seguito.

---

# 13. Approvazione e Revisioni del Budget

## 13.1 Natura

L'approvazione registra un accordo economico che può essere avvenuto fuori dal software.

Non è richiesto un workflow multilivello.

## 13.2 Precondizioni

Una Proposta può essere approvata soltanto se:

- è in stato Bozza;
- soddisfa il §12.15;
- l'Esercizio principale è Aperto;
- tutti gli Esercizi interessati sono Aperti;
- l'utente possiede `approva_budget` per l'Azienda.

## 13.3 Transazione atomica

L'approvazione **MUST**:

1. ricalcolare gli Esercizi interessati;
2. bloccare revisioni di Esercizi, insiemi sorgente e sorgenti interessate;
3. verificare che nessuna sorgente sia cambiata dall'ultimo riallineamento;
4. enumerare nuovamente le sorgenti da includere;
5. rivalidare azioni, stati, Riporti, condizioni e relazioni;
6. applicare tutte le azioni di piano agli oggetti vivi;
7. creare gli oggetti nuovi e risolvere i `ProposalItemID`;
8. creare la Snapshot di Budget dell'Esercizio principale;
9. aggiornare la Situazione Corrente degli altri Esercizi interessati senza riscriverne i Budget;
10. registrare Timeline e audit;
11. marcare la Proposta Approvata.

Se un passaggio fallisce, nessuna modifica viene persistita.

## 13.4 Versioni

La prima approvazione crea `v1`.

Ogni Revisione successiva crea `v2`, `v3` e così via.

Ogni versione:

- è immutabile;
- conserva il collegamento alla precedente;
- richiede una motivazione;
- non modifica gli Effettivi;
- non elimina versioni precedenti;
- diventa la versione approvata corrente.

## 13.5 Correzione di un Budget errato mentre l'Esercizio è Aperto

Un errore materiale o una decisione cambiata viene corretto mediante una nuova Revisione.

La versione errata resta leggibile e viene marcata dalla Timeline come superata dalla Revisione successiva.

## 13.6 Dopo la Chiusura

Dopo la Chiusura:

- non può essere approvata una nuova versione di Budget per l'Esercizio;
- il Budget errato non viene riscritto;
- l'errore viene dichiarato con Annotazione di errore storico;
- eventuali effetti su Esercizi Aperti vengono corretti mediante azioni esplicite in tali Esercizi.

## 13.7 Riferimenti nei report

Il sistema distingue:

- Budget iniziale approvato = `v1`;
- Budget approvato corrente = ultima versione;
- versione specifica selezionata.

Una vista **MUST NOT** mostrare `Budget vs Actual` senza indicare la versione.

## 13.8 Evidenza

L'approvazione registra almeno:

- timestamp;
- autore;
- soggetto o sede esterna dell'approvazione, opzionale;
- motivazione obbligatoria per le Revisioni;
- allegati opzionali;
- versione prodotta;
- Esercizi interessati;
- riepilogo degli impatti applicati.


---

# 14. Chiusura, errori successivi e immutabilità

## 14.1 Significato

La Snapshot di Chiusura rappresenta la situazione economica dell'Esercizio con:

- valori finali disponibili al momento della Chiusura;
- stato di Progetti e Contratti al 31 dicembre;
- decisioni di Riporto consolidate;
- classificazioni finali dell'Esercizio.

Non sostituisce il Budget Approvato.

## 14.2 Momento

Un Esercizio **MUST NOT** essere Chiuso prima della fine del relativo anno solare.

L'esistenza di un Budget Approvato non è prerequisito.

Se manca, la Snapshot registra `Budget Approvato assente` e i confronti che richiedono una baseline non sono disponibili.

## 14.3 Controlli bloccanti

Prima della Chiusura devono essere risolti:

- Esercizi precedenti non Chiusi;
- Proposte in Bozza per lo stesso Esercizio;
- Progetti Pianificati o Aperti con Residuo positivo e senza decisione di prosecuzione;
- modalità di rinvio non scelta nei casi definiti dal §14.5;
- Riporto non scelto quando la modalità è Riporto;
- Riporto superiore alla disponibilità massima riportabile;
- Riprogrammazione non ancora eseguita con Importo superiore alla Disponibilità pre-operazione;
- riduzione del piano origine e incremento del piano destinazione non quadrati con l'Importo Riprogrammato;
- Righe coinvolte in una Riprogrammazione già eseguita modificate indipendentemente senza riallineamento;
- Riporto diverso da zero per Progetto Chiuso o Cancellato;
- contemporanea presenza di Riporto e Riprogrammazione per lo stesso Progetto e passaggio d'anno;
- transizioni di stato incompatibili con decisioni future già approvate;
- condizioni contrattuali invalide;
- classificazioni mancanti quando la policy è Blocco;
- Note di Sovraspesa obbligatorie mancanti;
- errori tecnici di ricalcolo o consistenza.

## 14.4 Avvisi non bloccanti

Il sistema mostra almeno:

- ogni sorgente di primo livello con `Allocato > 0` e `HaEffettivi = false`;
- elementi Non classificati quando la policy è Avviso;
- Progetti Pianificati ma mai Aperti;
- differenze fra Riporto provvisorio approvato in `N+1` e massimo consolidabile;
- Contratti Attivi o Pianificati nell'Esercizio senza alcuna condizione economica Valida applicabile nell'Esercizio;
- rinnovi contrattuali che mantengono il Contratto Attivo ma non lasciano alcuna condizione economica Valida dopo la scadenza;
- sorgenti con Annotazioni di errore storico.

Gli avvisi **MUST NOT** dedurre fatture, rate o cause mancanti.

Non esiste un avviso separato `Progetto dormiente`.

## 14.5 Decisioni sui Progetti

Per ogni Progetto Pianificato o Aperto l'utente deve confermare lo stato al 31 dicembre.

Se il Progetto prosegue in `N+1`, l'utente deve scegliere espressamente una modalità:

- `Nessuna`, anche quando esiste una disponibilità positiva ma non si vuole trasferirla;
- `Riporto`, con importo maggiore di zero entro il massimo disponibile;
- `Riprogrammazione`, con importo maggiore di zero entro il massimo disponibile.

Quando nessun importo può o deve essere trasferito, la modalità è `Nessuna`.

### Progetto Pianificato

- resta Pianificato con modalità Nessuna, Riporto oppure Riprogrammazione;
- viene Cancellato con modalità Nessuna e Riporto zero.

### Progetto Aperto

- prosegue Aperto con modalità Nessuna, Riporto oppure Riprogrammazione;
- viene Chiuso con modalità Nessuna e Riporto zero;
- viene Cancellato con modalità Nessuna e Riporto zero.

Lo stato e la modalità sono decisioni distinte.

## 14.6 Conferma rafforzata

La Chiusura richiede una conferma finale che mostri almeno:

- Esercizio;
- valori totali;
- Progetti e decisioni di stato;
- Riporti consolidati;
- avvisi accettati;
- creazione o mancata creazione di `N+1`;
- dichiarazione che l'Esercizio non potrà essere riaperto.

## 14.7 Transazione atomica

La Chiusura **MUST**:

1. bloccare Esercizio, sorgenti e Riporti;
2. materializzare idempotentemente rinnovi, scadenze e transizioni contrattuali dovuti entro il 31 dicembre;
3. ricalcolare i valori finali;
4. valutare gli stati al 31 dicembre;
5. eseguire controlli e mostrare avvisi;
6. creare `N+1` se richiesto e assente;
7. applicare atomicamente soltanto le Riprogrammazioni non ancora eseguite e verificare l'idempotenza di quelle già applicate;
8. consolidare i Riporti;
9. creare la Snapshot di Chiusura;
10. marcare l'Esercizio Chiuso;
11. registrare Timeline e audit.

Un fallimento non produce effetti parziali.

## 14.8 Valori immutabili

Dopo la Chiusura non sono ammesse modifiche ordinarie a:

- Stime;
- contenitori;
- classificazioni;
- condizioni contrattuali storiche;
- scadenze storiche;
- stato storico al 31 dicembre;
- Riporto consolidato;
- Budget Approvati;
- Snapshot di Chiusura.

## 14.9 Errori scoperti dopo la Chiusura

L'Esercizio **MUST NOT** essere riaperto.

### Errore di importo o omissione

Segue il §24 e può modificare l'Effettivo a Conoscenza Corrente tramite Righe Effettivo append-only.

### Errore di imputazione

Un errore di:

- Centro di Costo;
- Fornitore;
- Progetto;
- Contratto;
- contenitore;
- Fornitore;
- Esercizio;
- stato storico;

**MUST NOT** essere riclassificato economicamente dopo la Chiusura.

Il sistema registra un'Annotazione di errore storico con:

- dato registrato;
- dato ritenuto corretto;
- autore;
- timestamp;
- motivo;
- allegati opzionali;
- sorgenti e Snapshot interessate.

I report a Conoscenza Corrente mantengono l'imputazione storica e mostrano l'annotazione.

### Errore di Riporto

Il Riporto storico resta invariato.

Se l'errore deve produrre un effetto in `N+1` Aperto, l'utente registra una modifica esplicita del piano di `N+1` con Nota obbligatoria. Tale importo:

- non viene etichettato come Riporto;
- non modifica la Snapshot di Chiusura di `N`;
- non modifica il Budget Approvato di `N+1` salvo successiva Revisione.

### Progetto erroneamente Chiuso o Cancellato

Lo stato storico resta invariato.

Per nuova attività in un Esercizio Aperto successivo, il Progetto viene riaperto con una nuova transizione efficace in quell'Esercizio prima di registrare nuova attività ordinaria.

### Esercizio chiuso accidentalmente

Resta Chiuso. Il sistema conserva l'Annotazione di errore storico. Ogni effetto successivo viene registrato nell'Esercizio Aperto il cui anno è dichiarato dall'utente secondo il §11.3, senza modificare l'Esercizio Chiuso.

---

# 15. Spese

## 15.1 Struttura

Ogni Spesa contiene almeno:

- ID;
- `CopiedFromOriginKey` quando deriva da una copia canonica;
- Descrizione obbligatoria;
- Note;
- Allegati;
- Esercizio;
- origine `Manuale` oppure `Sistema`;
- eventuale Progetto;
- eventuale Contratto;
- eventuale Fornitore;
- eventuale Centro di Costo diretto, solo se autonoma;
- una o più Righe;
- stato `Attiva` oppure `Stornata`;
- audit.

## 15.2 Spesa autonoma

Una Spesa autonoma:

- non appartiene a Progetto o Contratto;
- può contenere Stime ed Effettivi;
- può avere Fornitore opzionale;
- può avere Centro di Costo diretto;
- non produce Riporto.

Per dichiarare che una Stima e uno o più Effettivi riguardano la stessa sorgente autonoma, l'utente registra le Righe nella stessa Spesa.

Creare una nuova Spesa autonoma significa dichiarare una sorgente distinta. Il sistema non tenta di riconciliarla con altre Spese per titolo o importo.

## 15.3 Spesa di Progetto

Una Spesa di Progetto:

- alimenta il Progetto;
- può contenere Stime ed Effettivi;
- eredita il Centro di Costo annuale del Progetto;
- può avere Fornitore opzionale;
- non possiede Riporto autonomo.

## 15.4 Spesa di Contratto

Esistono due casi.

### Stima di sistema

- al massimo una per Contratto ed Esercizio;
- generata dalle condizioni;
- non modificabile manualmente;
- priva di Effettivi;
- Fornitore derivato dal Contratto;
- mantenuta a zero se era già materializzata e il ricalcolo diventa zero.

Una Spesa di sistema mai esistita non viene creata soltanto per rappresentare zero.

### Spesa manuale

- associata al Contratto;
- contiene esclusivamente Effettivi;
- eredita il Fornitore del Contratto;
- non contiene Stime manuali.

Un costo pianificato non rappresentabile dal motore contrattuale viene modellato come Spesa autonoma o di Progetto distinta.

## 15.5 Fornitore

- Spesa autonoma o di Progetto: Fornitore opzionale;
- Spesa di Contratto: Fornitore obbligatoriamente derivato;
- ingresso in un Contratto: un Fornitore differente viene sostituito dopo avviso;
- uscita da un Contratto: il Fornitore precedente può restare come Fornitore diretto opzionale.

## 15.6 Modifiche in Esercizio Aperto

Una Spesa manuale può essere modificata nel rispetto dei vincoli.

Il cambio di Fornitore di una Spesa autonoma o di Progetto riclassifica tutte le sue Stime ed Effettivi nell'Esercizio. Se la Spesa possiede Effettivi, il sistema deve mostrare l'impatto per Fornitore prima della conferma; l'operazione è ammessa soltanto nell'operatività viva di un Esercizio Aperto e non nella Proposta.

Dopo un Budget Approvato, richiedono motivo:

- nuova Spesa con Allocato o Effettivo diverso da zero;
- modifica della Descrizione;
- modifica di Stima;
- cambio Esercizio;
- cambio contenitore;
- cambio Fornitore;
- cambio Centro di Costo;
- Storno o ripristino.

## 15.7 Cambio Esercizio

È ammesso soltanto se:

- origine e destinazione sono Aperti;
- la Spesa non è una Stima di sistema;
- nessun Effettivo viene attribuito a un anno futuro rispetto alla registrazione tecnica;
- l'operazione mostra e applica atomicamente l'impatto sui due Esercizi.

Se la Spesa è di Progetto e non possiede Effettivi, lo spostamento del piano verso l'Esercizio successivo è una `Riprogrammazione` e deve rispettare il §16.10; non può essere eseguito come cambio Anno generico.

Se la Spesa contiene Effettivi, il cambio Esercizio è una riclassificazione integrale consentita soltanto nell'operatività viva, mai nella Proposta, con motivo obbligatorio. Non viene classificato come Riprogrammazione perché corregge l'Esercizio dell'intera Spesa, inclusi gli Effettivi.

## 15.8 Cambio contenitore

Per una Spesa manuale sono ammesse, in Esercizi Aperti:

- autonoma → Progetto;
- autonoma → Contratto, solo senza Stime;
- Progetto → autonoma;
- Progetto → altro Progetto;
- Progetto → Contratto, solo senza Stime;
- Contratto → autonoma;
- Contratto → Progetto;
- Contratto → altro Contratto.

Una Spesa Stima di sistema non cambia contenitore indipendentemente dal Contratto.

Per una Spesa priva di Effettivi:

- un Progetto di destinazione deve essere Pianificato o Aperto nell'Esercizio;
- se il Progetto è Chiuso o Cancellato, deve essere riaperto nella stessa operazione prima di ricevere nuova pianificazione;
- una Spesa con Stime non può entrare in un Contratto;
- l'associazione a un Contratto di una Spesa priva di Stime non crea automaticamente Effettivi o Stime.

## 15.9 Spostamento di una Spesa con Effettivi

Lo spostamento nell'operatività viva riclassifica integralmente tutte le Righe della Spesa.

Precondizioni:

- Esercizio Aperto;
- anteprima dei valori rimossi e aggiunti ai contenitori;
- Nota obbligatoria;
- contenitore di destinazione che soddisfa esattamente le regole di stato e tipo elencate sotto;
- nessuna Stima manuale in ingresso a un Contratto;
- aggiornamento atomico di contenitori, classificazione ereditata e report.

Destinazione Progetto:

- se Aperto, lo spostamento è ammesso;
- se Pianificato, deve essere inclusa una transizione ad Aperto;
- se Chiuso o Cancellato, è ammesso soltanto come attribuzione tardiva o correttiva, con Nota esplicita e senza cambiare lo stato.

Destinazione Contratto:

- se Attivo, lo spostamento è ammesso per soli Effettivi;
- se Cessato o Annullato, è ammesso soltanto per addebiti tardivi, costi di cessazione, rimborsi o correzioni, con Nota;
- se Pianificato, Effettivi ordinari non sono ammessi.

## 15.10 Centro di Costo nello spostamento

- entrando in Progetto o Contratto, la Spesa eredita il Centro di Costo annuale del contenitore;
- diventando autonoma, non conserva implicitamente il Centro di Costo ereditato;
- l'utente può sceglierne uno nella stessa operazione;
- in assenza di scelta diventa Non classificata.

## 15.11 Storno e ripristino

Una Spesa persistita **MUST NOT** essere eliminata fisicamente.

Una Spesa priva di Effettivi può essere Stornata in un Esercizio Aperto:

- viene esclusa dai calcoli correnti;
- conserva identità, Righe e storico;
- richiede motivo;
- può essere ripristinata finché l'Esercizio resta Aperto.

Una Spesa con `HaEffettivi = true` **MUST NOT** essere Stornata, anche quando la somma netta degli Effettivi è zero.

Una Spesa che possiede soltanto Righe Effettivo Annullate ha `HaEffettivi = false` e può essere Stornata, conservando comunque l'audit delle Righe Annullate.

In un Esercizio Aperto:

- una Riga Effettivo inserita per errore può essere corretta oppure Annullata con audit;
- un rimborso, accredito o rettifica reale usa una nuova Riga Effettivo negativa;
- le Righe Stima possono essere modificate oppure Annullate separatamente;
- nessuna Riga persistita viene eliminata fisicamente.

## 15.12 Spesa imprevista

Una Spesa con Effettivi e senza Stime è imprevista per effetto dei dati.

Non esiste un tipo `Imprevista`.

## 15.13 Preventivo

Un preventivo commerciale può originare una Stima.

Il sistema non gestisce versioni, accettazione, scadenza, firma o stato del preventivo.

## 15.14 Plafond come caso d'uso

Un Plafond è una normale Spesa:

- Stima = disponibilità concessa;
- Effettivi nella stessa Spesa = utilizzo;
- Scostamento negativo = disponibilità non utilizzata;
- Scostamento positivo = superamento.

Se gli acquisti vengono registrati come Spese autonome separate, il sistema li considera sorgenti distinte e non può dedurre che consumino il Plafond.

Non esistono entità, flag, stato o report automatico dedicati al Plafond.

---

# 16. Progetti

## 16.1 Dati

Un Progetto contiene almeno:

- ID;
- Titolo;
- Descrizione e Note;
- stato iniziale e relativa data di efficacia;
- transizioni di stato;
- Spese associate;
- classificazioni annuali;
- modalità di rinvio per ciascun passaggio d'anno;
- Riporti;
- relazioni informative;
- Archivio;
- Timeline.

## 16.2 Stati

- `Pianificato`;
- `Aperto`;
- `Chiuso`;
- `Cancellato`.

`Archiviato` è una proprietà di visibilità.

## 16.3 Significato

### Pianificato

Definito o approvato, ma non iniziato. Può avere Stime, ma non Effettivi ordinari.

### Aperto

In esecuzione. Può ricevere Stime ed Effettivi ordinari.

### Chiuso

Completato. Non riceve nuova pianificazione o nuova attività ordinaria finché non viene riaperto.

### Cancellato

Non verrà eseguito o è stato abbandonato. Gli Effettivi già presenti restano.

## 16.4 Transizioni ammesse

- Pianificato → Aperto;
- Pianificato → Cancellato;
- Aperto → Chiuso;
- Aperto → Cancellato, con motivo;
- Chiuso → Aperto, con motivo;
- Cancellato → Pianificato;
- Cancellato → Aperto, con motivo.

Si applicano le regole temporali del §9.

## 16.5 Effettivi

Un Effettivo ordinario richiede Progetto Aperto alla data locale della registrazione tecnica.

Poiché non esiste una Data Effettivo strutturata, il sistema non verifica retroattivamente lo stato del Progetto nel giorno in cui l'evento economico sarebbe avvenuto. L'utente deve dichiarare se l'inserimento è ordinario, tardivo o correttivo.

Se il Progetto è Pianificato, il sistema può includere l'apertura nella stessa operazione, con conferma.

Se il Progetto è Chiuso o Cancellato:

- nuova attività ordinaria richiede riapertura;
- una fattura tardiva, un rimborso o una correzione relativa ad attività precedente può essere registrata in un Esercizio Aperto con Nota obbligatoria;
- l'inserimento non modifica lo stato.

Le correzioni tardive di un Esercizio Chiuso seguono il §24.

## 16.6 Progetto senza Spese

Può esistere con valori economici zero.

## 16.7 Semantica dello Scostamento

La semantica usa `StatoProgettoAllaData` della data di riferimento della vista o del report secondo il §9.2.

### Pianificato o Aperto

- positivo → Sovraspesa;
- zero → Pareggio;
- negativo → Residuo.

### Chiuso

- positivo → Sovraspesa finale;
- zero → Pareggio;
- negativo → Risparmio.

### Cancellato

Uno Scostamento negativo è `Allocato non utilizzato per cancellazione`, non Risparmio operativo.

## 16.8 Avvisi di Sovraspesa

Il sistema avvisa quando:

- lo Scostamento passa da non positivo a positivo;
- una Sovraspesa positiva aumenta.

Non genera un nuovo avviso quando diminuisce.

## 16.9 Riapertura

La riapertura produce una nuova transizione efficace in un Esercizio Aperto.

Non modifica stati materializzati in Budget o Chiusure precedenti.

## 16.10 Modalità di rinvio esclusiva

La modalità canonica governa il trasferimento dal Progetto nell'Esercizio `N` allo stesso Progetto nell'Esercizio immediatamente successivo `N+1`.

Per ogni coppia `Progetto + Esercizio origine N + Esercizio destinazione N+1` deve esistere una sola modalità:

- `Nessuna`;
- `Riporto`;
- `Riprogrammazione`.

La modalità è `Nessuna` quando non viene trasferito alcun importo. Se viene trasferito un importo, la scelta vale per l'intero Progetto e per quel passaggio d'anno. Non è ammessa una ripartizione fra Riporto e Riprogrammazione.

### Modalità Nessuna

- Riporto e Importo Riprogrammato sono zero;
- nessun importo viene trasferito dall'Esercizio origine;
- eventuali nuove Stime nell'Esercizio destinazione sono nuove allocazioni indipendenti.

### Modalità Riporto

- le Stime dell'Esercizio origine restano dove sono;
- il futuro riceve un Riporto provvisorio e poi consolidato;
- l'Importo Riprogrammato è zero;
- la funzione canonica di copia/riprogrammazione delle Stime del Progetto verso l'Esercizio destinazione è bloccata;
- ogni nuova Stima del Progetto nell'Esercizio destinazione deve essere creata tramite un'azione `Nuova allocazione` con Nota e non come importo riprogrammato.

### Modalità Riprogrammazione

Al momento dell'esecuzione viene calcolata la disponibilità prima della modifica:

```text
DisponibilitàPreOperazione =
min(max(AllocatoOriginePreOperazione - EffettivoOrigine, 0),
    AllocatoOriginePreOperazione)

0 < ImportoRiprogrammato ≤ DisponibilitàPreOperazione
```

L'operazione atomica deve inoltre rispettare:

```text
RiduzioneAllocatoOrigine = ImportoRiprogrammato
IncrementoStimeDestinazione = ImportoRiprogrammato
Riporto = 0
```

L'azione conserva almeno:

- ID operazione idempotente;
- Allocato origine pre-operazione;
- Effettivo origine al momento dell'esecuzione;
- Importo Riprogrammato;
- Spese e Righe Stima origine interessate;
- nuove Spese e Righe Stima destinazione;
- autore, data e motivo.

- le Righe Stima origine vengono ridotte o Annullate, oppure le relative Spese prive di Effettivi vengono Stornate;
- le nuove Stime destinazione ricevono nuova identità;
- la copia canonica conserva `CopiedFromOriginKey`;
- eventuali ulteriori Stime destinazione devono essere azioni separate `Nuova allocazione`;
- il Budget Approvato dell'Esercizio origine resta invariato;
- Effettivi registrati dopo l'esecuzione non annullano retroattivamente la Riprogrammazione e possono produrre una Sovraspesa nell'origine.

Una Riprogrammazione viene applicata una sola volta, all'approvazione della Proposta oppure alla Chiusura se decisa in quella sede. La Chiusura rivalida le azioni non ancora applicate e non riapplica quelle già eseguite.

Il sistema non usa fuzzy matching per verificare Stime create manualmente. Blocca le operazioni canoniche incompatibili e richiede che ogni nuova Stima sia dichiarata come `Riprogrammazione` oppure `Nuova allocazione`.

### Modifica della modalità prima della Chiusura

Finché entrambi gli Esercizi sono Aperti, la modalità può essere modificata con un'operazione atomica e motivata.

- da Riporto a Riprogrammazione: il Riporto provvisorio viene portato a zero e viene eseguita la Riprogrammazione;
- da Riprogrammazione a Riporto o Nessuna: vengono ripristinate le sole riduzioni origine e Annullate/Stornate le sole Stime destinazione create dall'azione di Riprogrammazione, usando gli ID conservati; le nuove allocazioni indipendenti non vengono toccate;
- se una Riga origine o destinazione coinvolta è stata successivamente modificata da un'operazione indipendente, il cambio di modalità viene bloccato finché l'utente non riallinea esplicitamente il piano; il sistema **MUST NOT** sovrascrivere la modifica successiva;
- ogni Budget Approvato resta invariato;
- le Proposte interessate diventano Da riallineare.

Dopo la Chiusura dell'Esercizio origine, modalità, Riporto e Riprogrammazione non possono essere modificati.

## 16.11 Progetto pluriennale

Il Progetto mantiene la stessa identità.

Ogni Esercizio conserva separatamente:

- Stime;
- Effettivi;
- Allocato;
- classificazione;
- Riporto ricevuto;
- modalità di rinvio;
- stato materializzato nelle Snapshot.

## 16.12 Indicatori esclusi

Non sono richiesti BAC, ETC, EAC, earned value o indicatori equivalenti.

---

# 17. Riporto

## 17.1 Esclusività

Soltanto un Progetto può ricevere e produrre Riporto.

Non producono Riporto:

- Contratti;
- Spese autonome;
- Centri di Costo;
- Fornitori.

## 17.2 Riporto ricevuto

```text
AllocatoProgetto = RiportoRicevuto + StimeAnno
```

## 17.3 Riporto provvisorio

Quando `N` non è Chiuso, una Proposta per `N+1` può contenere un Riporto provvisorio se la modalità scelta è Riporto.

Deve valere:

```text
0 < RiportoProvvisorio ≤ DisponibilitàMassimaRiportabileCorrenteN
```

con:

```text
DisponibilitàMassimaRiportabileCorrenteN =
min(max(AllocatoCorrenteN - EffettivoCorrenteN, 0), AllocatoCorrenteN)
```

Se non si vuole trasferire alcun importo, la modalità deve essere `Nessuna`; la modalità `Riporto` non rappresenta un Riporto zero.

Il Riporto provvisorio:

- non è una previsione di fine anno;
- è una decisione temporanea per costruire `N+1`;
- non viene impostato automaticamente al massimo.

Se i dati di `N` riducono il massimo disponibile:

- una Proposta in Bozza diventa Incoerente e non può essere approvata finché il valore non viene ridotto;
- un Riporto provvisorio già applicato nel vivo di `N+1` non viene ridotto automaticamente e il relativo Budget non viene riscritto;
- la Situazione Corrente mostra l'avviso `Riporto provvisorio superiore al massimo corrente`;
- una successiva Revisione non può confermare un importo superiore al massimo corrente;
- la Chiusura di `N` sostituisce comunque il provvisorio con un Riporto consolidato valido.

## 17.4 Riporto consolidato

Alla Chiusura:

```text
0 < RiportoConsolidato ≤ DisponibilitàMassimaRiportabileAllaChiusura
```

Per un Progetto Chiuso o Cancellato, oppure con modalità `Nessuna` o `Riprogrammazione`:

```text
RiportoConsolidato = 0
```

Il sistema non imposta automaticamente il Riporto uguale al massimo. Un trasferimento zero viene rappresentato dalla modalità `Nessuna`, non da un Riporto a zero.

## 17.5 Effettivo negativo

Esempio:

```text
Allocato:                  0 €
Effettivo:            -1.000 €
Residuo:               1.000 €
Massimo riportabile:       0 €
```

Esempio:

```text
Allocato:             10.000 €
Effettivo:            -1.000 €
Residuo:              11.000 €
Massimo riportabile:  10.000 €
```

Un rimborso riduce l'Effettivo, ma non crea nuova allocazione riportabile oltre l'Allocato.

## 17.6 Consolidamento in `N+1`

La Chiusura di `N` sostituisce nel vivo di `N+1` il Riporto provvisorio con quello consolidato.

Se esiste una Proposta `N+1` in Bozza:

- non blocca la Chiusura di `N`;
- la sorgente viene marcata Da riallineare;
- la Proposta deve essere aggiornata prima dell'approvazione.

Se `N+1` possiede già un Budget Approvato:

- il Budget resta invariato;
- l'Allocato Corrente cambia;
- la Timeline registra `Consolidamento Riporto`;
- il report Allocato vs Budget mostra la differenza.

## 17.7 Parte non riportata

La parte non riportata:

- non viene trasferita;
- non genera Spesa;
- non genera Stima;
- non genera disimpegno;
- non richiede tracciamento negli anni successivi.

## 17.8 Nessuna ricostruzione o consumo

Il sistema **MUST NOT**:

- ricostruire da quali Stime originarie derivi il Riporto;
- distinguere Riporto recente e Riporto precedente;
- stabilire se gli Effettivi consumino prima il Riporto o le nuove Stime.

## 17.9 Correzioni tardive

Una correzione tardiva di `N` **MUST NOT** ricalcolare il Riporto consolidato verso `N+1`.


---

# 18. Contratti

## 18.1 Scopo

Il Contratto è un contenitore logico di Spese con:

- ciclo di vita contrattuale;
- scadenze informative;
- condizioni economiche ricorrenti che generano Stime annuali.

Non è un motore di fatturazione.

## 18.2 Dati del Contratto

Ogni Contratto contiene almeno:

- ID;
- Titolo;
- Fornitore obbligatorio;
- Note e Allegati;
- Data di inizio contrattuale;
- Data della prossima scadenza contrattuale, opzionale;
- `RinnovoAutomatico`, attivo per default;
- Durata del rinnovo in mesi, obbligatoria quando il rinnovo automatico è attivo e la prossima scadenza è definita;
- Preavviso di disdetta in giorni, opzionale;
- storico delle configurazioni di rinnovo con data di efficacia;
- eventi di stato e rinnovo;
- condizioni economiche;
- classificazioni annuali;
- relazioni informative;
- Archivio;
- Timeline.

Un nuovo Contratto **MUST** avere almeno una condizione Valida. Il `Valido dal` della prima condizione **MUST NOT** precedere la Data di inizio contrattuale.

Quando la prossima scadenza è definita:

```text
DataProssimaScadenza ≥ DataInizioContrattuale
```

Quando il rinnovo automatico è attivo e la prossima scadenza è definita, la Durata del rinnovo deve essere un numero intero di mesi maggiore di zero.

Quando il rinnovo automatico è attivo ma la prossima scadenza è assente:

- il Contratto può continuare a generare Stime tramite condizioni economiche aperte;
- non vengono calcolati rinnovi, Data limite di disdetta o promemoria futuri;
- la sezione Scadenze mostra `Scadenza non definita`.

Quando il rinnovo automatico è disattivo e la prossima scadenza è assente, il Contratto è considerato a durata indefinita e resta Attivo fino a una cessazione esplicita.

Una condizione deve rispettare:

```text
ValidoAl assente OR ValidoAl ≥ ValidoDal
```

## 18.3 Separazione dei concetti

Il sistema **MUST** distinguere:

```text
Ciclo economico
= frequenza con cui la condizione genera una Stima

Durata contrattuale
= intervallo fino alla scadenza contrattuale

Rinnovo automatico
= comportamento alla scadenza contrattuale

Scadenza contrattuale
= data informativa di termine o rinnovo

Data di attribuzione della Stima
= data usata soltanto per scegliere l'Esercizio della Stima
```

Il ciclo economico **MUST NOT** determinare la durata contrattuale.

Esempio:

```text
Durata contrattuale: 01/01/2026 → 31/12/2026
Ciclo economico: mensile
Rinnovo automatico: no
```

produce dodici cicli, non uno.

## 18.4 Stati

Gli stati sono:

- `Pianificato`;
- `Attivo`;
- `Cessato`;
- `Annullato`.

`Archiviato` è una proprietà di visibilità.

### Pianificato

Data di inizio futura e nessuna attivazione ancora efficace.

### Attivo

Contratto attivato e non terminato alla data richiesta.

### Cessato

Contratto precedentemente Attivo e terminato per scadenza senza rinnovo o cessazione esplicita.

### Annullato

Contratto mai entrato in vigore, annullato prima dell'attivazione.

Non esiste lo stato `Sospeso`.

Alla creazione:

- se la Data di inizio è futura, il Contratto è Pianificato e viene registrato un evento di attivazione con efficacia alla Data di inizio;
- se la Data di inizio è uguale o precedente alla data corrente e il Contratto non è Annullato o Cessato, è Attivo;
- prima della Data di inizio, `StatoContrattoAllaData` restituisce Pianificato;
- dalla Data di inizio applica in ordine gli eventi di attivazione, cessazione, riattivazione, annullamento e rinnovo definiti nel §9.4.

## 18.5 Rinnovo automatico

Il rinnovo automatico evita di ricreare manualmente il Contratto e le Stime a ogni scadenza.

Alla Data della prossima scadenza:

### Rinnovo automatico attivo

Se non esiste una cessazione efficace alla scadenza:

1. il Contratto resta Attivo;
2. viene registrato un evento di rinnovo;
3. la prossima scadenza viene avanzata secondo il calendario ancorato descritto sotto;
4. le condizioni economiche senza `Valido al` continuano;
5. le condizioni con un `Valido al` esplicito non vengono estese automaticamente;
6. gli Esercizi Aperti interessati vengono ricalcolati.

Il calendario dei rinnovi usa come ancora la scadenza approvata manualmente più recente:

```text
ScadenzaRinnovo(k) =
add_months_anchored(ScadenzaAncora, k × DurataRinnovoMesi)
```

La prossima scadenza è la prima `ScadenzaRinnovo(k)` successiva alla scadenza appena elaborata.

Se l'utente modifica manualmente la prossima scadenza, quella data diventa la nuova ScadenzaAncora per i rinnovi successivi.

Se il sistema rileva che sono trascorse più scadenze non ancora materializzate, ripete l'avanzamento in modo deterministico fino a ottenere la prima scadenza successiva alla data corrente, creando un evento distinto per ogni rinnovo e senza duplicati in caso di retry.

Nel calcolo di un Esercizio futuro Aperto, i rinnovi automatici previsti vengono considerati anche se la relativa scadenza non è ancora trascorsa. Questo consente di generare le Stime future; non equivale a dichiarare che la disdetta non potrà intervenire.

Se, dopo il rinnovo, nessuna condizione Valida copre il periodo successivo alla scadenza, il Contratto resta Attivo ma il sistema mostra l'avviso `Rinnovo senza condizione economica`; nessuna Stima viene inventata.

### Rinnovo automatico disattivo

- il Contratto resta Attivo fino alla scadenza inclusa;
- dal giorno successivo risulta Cessato;
- le condizioni Valide prive di `Valido al` vengono chiuse impostando la scadenza come ultimo giorno di validità;
- nessun ciclo economico può iniziare dopo la scadenza;
- non è necessario che il ciclo economico coincida con la durata contrattuale.

### Contratto senza scadenza definita

- non appare come `in scadenza`;
- il sistema mostra `Scadenza non definita`;
- il rinnovo automatico non produce avanzamenti finché non viene definita una scadenza.

### Modifica del rinnovo

I campi correnti di rinnovo e scadenza corrispondono all'ultima configurazione efficace.

Ogni modifica di Rinnovo automatico, Durata del rinnovo o prossima scadenza viene registrata come configurazione contrattuale con data di efficacia e audit.

La modifica:

- richiede anteprima delle scadenze e degli Esercizi Aperti interessati;
- viene applicata atomicamente;
- richiede Nota dopo un Budget Approvato;
- non riscrive rinnovi o scadenze già materializzati;
- marca Da riallineare le Proposte coinvolte.

Per ogni scadenza viene usata la configurazione che era efficace a quella data.

Se esistono scadenze già trascorse ma non ancora materializzate, il sistema deve prima elaborarle con la configurazione storicamente efficace; la nuova configurazione si applica soltanto alle scadenze successive alla propria data di efficacia.

Quando il rinnovo automatico viene attivato con una prossima scadenza definita, deve essere definita anche la Durata del rinnovo. Se la prossima scadenza resta assente, si applica il comportamento `Scadenza non definita`. Quando il rinnovo viene disattivato senza indicare una scadenza, il Contratto diventa a durata indefinita e potrà terminare soltanto tramite cessazione esplicita.

## 18.6 Preavviso e termine di disdetta

Se il Preavviso è presente:

```text
DataLimiteDisdetta = DataProssimaScadenza - PreavvisoGiorniCalendario
```

Il Preavviso deve essere un numero intero maggiore o uguale a zero. Si usano giorni di calendario, senza correzioni per festività o giorni lavorativi.

La data è informativa.

Il sistema **MUST NOT**:

- inviare automaticamente disdette;
- dedurre che una disdetta sia stata inviata;
- cessare il Contratto alla data limite;
- trattare la data come scadenza di pagamento.

## 18.7 Cessazione

La cessazione:

- richiede una data e una Nota;
- termina l'attività contrattuale alla data indicata;
- chiude alla stessa data le condizioni Valide prive di `Valido al`, senza rimuovere cicli già iniziati;
- impedisce l'avvio di nuovi cicli dopo tale data;
- non applica prorata;
- non rimuove un ciclo già iniziato;
- ricalcola atomicamente gli Esercizi Aperti interessati;
- non ricalcola Esercizi Chiusi.

Una cessazione può essere pianificata alla prossima scadenza contrattuale.

## 18.8 Riattivazione

La riattivazione:

- richiede una nuova data di inizio;
- crea un nuovo evento di attivazione;
- richiede una nuova prossima scadenza quando applicabile;
- richiede almeno una nuova condizione economica Valida;
- non riapre né estende automaticamente condizioni precedenti;
- lascia l'intervallo di cessazione privo di Stime.

## 18.9 Annullamento prima dell'attivazione

Un Contratto Pianificato mai entrato in vigore può essere Annullato se tutti gli Esercizi economicamente interessati sono Aperti.

L'annullamento:

- richiede motivo;
- annulla le condizioni future;
- porta l'Allocato Corrente a zero negli Esercizi Aperti;
- non modifica Budget Approvati;
- non riattiva condizioni precedenti.

Se un Esercizio interessato è Chiuso, il dato storico resta e viene annotato.

## 18.10 Condizioni economiche

Ogni condizione contiene:

- ID;
- stato `Valida` oppure `Annullata`;
- Ciclo economico;
- Modalità di attribuzione della Stima;
- Importo non negativo;
- `Valido dal`;
- `Valido al` opzionale;
- motivo della modifica o annullamento;
- audit.

Cicli ammessi:

- Mensile;
- Trimestrale;
- Semestrale;
- Annuale.

Modalità di attribuzione:

- `Inizio ciclo`;
- `Fine ciclo`.

Soltanto le condizioni Valide partecipano al calcolo.

Una nuova condizione Valida deve iniziare in una data nella quale il Contratto è Pianificato o Attivo, oppure nella stessa operazione che ne pianifica l'attivazione o la riattivazione. Non può iniziare durante un intervallo Cessato o Annullato senza una riattivazione esplicita.

## 18.11 Intervalli delle condizioni

`Valido dal` e `Valido al` sono inclusivi.

Le condizioni Valide dello stesso Contratto **MUST NOT** sovrapporsi.

Quando una nuova condizione sostituisce la precedente:

- la precedente termina il giorno prima della nuova;
- il nuovo calendario è ancorato alla nuova data di inizio;
- si applica il §18.13.

Le condizioni possono avere intervalli senza copertura. In tali intervalli non generano Stime, anche se il Contratto resta formalmente Attivo.

Il `Valido dal` di una nuova condizione Valida deve cadere in una data nella quale il Contratto è o diventerà Attivo secondo gli eventi già esistenti o approvati nella stessa operazione. Una condizione che inizi durante un periodo Cessato o Annullato senza riattivazione viene bloccata.

L'applicabilità economica di una condizione è comunque limitata dai periodi nei quali il Contratto è Attivo.

## 18.12 Modifiche economiche supportate

Cambio di:

- Importo;
- Ciclo economico;
- Modalità di attribuzione;

richiede una nuova condizione.

Il Fornitore può essere cambiato soltanto prima del primo utilizzo economico, definito come il primo dei seguenti eventi:

- generazione di una Stima viva diversa da zero;
- esistenza di almeno una Riga Effettivo Attiva;
- inclusione del Contratto in un Budget Approvato;
- inclusione in una Snapshot di Chiusura.

Dopo il primo utilizzo economico, una nuova controparte richiede un nuovo Contratto. Una ridenominazione dello stesso soggetto avviene sull'anagrafica del Fornitore.

### Correzione di un errore materiale in un Esercizio Aperto

La correzione di dati inseriti in modo errato **MUST** essere distinta da una modifica reale dell'accordo.

L'utente deve dichiarare che:

- il valore precedente non rappresentava l'accordo reale;
- la correzione non è una nuova decorrenza contrattuale;
- tutti gli Esercizi economicamente ricalcolati sono Aperti.

La correzione:

- può aggiornare la condizione originaria;
- richiede motivo e audit con valori precedenti e nuovi;
- mostra l'impatto per ogni Esercizio;
- viene applicata atomicamente;
- non modifica Budget Approvati;
- marca Da riallineare le Proposte coinvolte;
- non ricalcola Esercizi Chiusi.

Una modifica reale dell'accordo segue invece il §18.13 e richiede una nuova condizione.

## 18.13 Decorrenza delle modifiche economiche

Una modifica economica non può produrre prorata o due cicli integrali sovrapposti.

Questa sezione si applica alla modifica di una condizione esistente.

Non si applica:

- alla prima condizione di un nuovo Contratto;
- alla prima condizione di una riattivazione;
- alla sostituzione di una condizione futura il cui primo ciclo non è ancora iniziato.

Nei primi due casi la decorrenza segue rispettivamente la Data di inizio e la Data di riattivazione approvate. Nel terzo caso la nuova condizione può mantenere il medesimo `Valido dal` futuro della condizione sostituita, purché non produca sovrapposizioni e l'impatto venga mostrato e approvato.

### Data minima richiedibile

Per un'operazione ordinaria:

```text
DataMinimaRichiedibile = primo giorno del mese successivo alla conferma
```

Per una Proposta:

```text
DataMinimaRichiedibile = primo giorno del mese successivo all'approvazione
```

La Proposta può indicare una data richiesta futura, ma l'approvazione deve ricalcolarla.

Se l'utente non indica una Data richiesta:

```text
DataRichiesta = DataMinimaRichiedibile
```

### Data effettiva applicabile

Se esiste una condizione Valida corrente con un ciclo già iniziato:

```text
DataEffettivaApplicabile =
primo InizioCiclo della condizione corrente
maggiore o uguale a max(DataRichiesta, DataMinimaRichiedibile)
```

Se il Contratto è Attivo ma alla data minima non esiste alcuna condizione Valida corrente:

```text
DataEffettivaApplicabile = max(DataRichiesta, DataMinimaRichiedibile)
```

In questo secondo caso non esiste un ciclo da completare e la Data effettiva diventa l'ancora della nuova condizione.

La nuova condizione inizia alla Data effettiva applicabile e usa tale data come nuova ancora.

Se esiste una condizione corrente ma non esiste un suo confine di ciclo applicabile prima della cessazione o della scadenza non rinnovata, la modifica viene bloccata.

### Comunicazione obbligatoria

Prima del salvataggio o dell'approvazione il sistema **MUST** mostrare:

- data richiesta;
- data minima richiedibile;
- data effettiva applicabile;
- motivo dell'eventuale differimento;
- indicazione `Prorata applicato: no`;
- vecchio e nuovo importo;
- vecchio e nuovo ciclo;
- vecchia e nuova modalità di attribuzione;
- impatto per ogni Esercizio Aperto.

La data **MUST NOT** essere spostata silenziosamente.

L'utente deve confermare esplicitamente la Data effettiva applicabile.

Se, al momento dell'approvazione, la data calcolata differisce da quella precedentemente confermata nella Proposta, l'approvazione viene sospesa prima di produrre effetti. Il sistema mostra nuovamente data, motivo e impatti; l'utente deve confermare la nuova decorrenza oppure modificare la Proposta. La conferma può proseguire nella stessa operazione di approvazione, ma la data **MUST NOT** essere accettata implicitamente.

### Esempi

```text
Contratto mensile ancorato al giorno 1
Conferma: 18/05/2026
Data minima: 01/06/2026
Data effettiva: 01/06/2026
```

```text
Contratto mensile ancorato al giorno 15
Conferma: 18/05/2026
Data minima: 01/06/2026
Data effettiva: 15/06/2026
```

```text
Contratto annuale ancorato al 12/03
Conferma: 18/05/2026
Data minima: 01/06/2026
Data effettiva: 12/03/2027
```

## 18.14 Calcolo delle ricorrenze

Mesi per ciclo:

```text
Mensile = 1
Trimestrale = 3
Semestrale = 6
Annuale = 12
```

Per il ciclo `k`:

```text
InizioCiclo(k) = add_months_anchored(ValidoDal, k × MesiCiclo)
```

Se il giorno originario non esiste nel mese, viene usato l'ultimo giorno del mese.

La ricorrenza successiva è sempre calcolata dall'ancora originaria.

## 18.15 Ciclo eleggibile

Un ciclo è eleggibile quando:

```text
Condizione.Stato = Valida
AND ValidoDal ≤ InizioCiclo ≤ ValidoAl, se ValidoAl esiste
AND StatoContrattoAllaData(InizioCiclo) = Attivo
```

## 18.16 Data di attribuzione della Stima

Per ogni ciclo eleggibile:

```text
Inizio ciclo → DataAttribuzioneStima = InizioCiclo
Fine ciclo   → DataAttribuzioneStima = InizioCiclo del ciclo successivo
```

La Stima appartiene all'Esercizio della Data di attribuzione.

Questa data:

- non è una scadenza di pagamento;
- non rappresenta un debito;
- non implica l'esistenza di una fattura;
- serve esclusivamente ad assegnare la Stima all'Esercizio.

Un ciclo iniziato mentre il Contratto è Attivo resta previsto per l'intero importo, anche se la Data di attribuzione cade dopo la cessazione.

## 18.17 Nessun prorata

Il sistema **MUST NOT** ridurre automaticamente un ciclo parzialmente coperto.

Casi che richiederebbero prorata vengono bloccati dal §18.13 oppure rappresentati fuori dal motore contrattuale.

## 18.18 Aggregazione annuale

```text
StimaAnnuale = somma Importi dei cicli con DataAttribuzioneStima nell'Esercizio
```

Se positiva, il sistema crea o aggiorna l'unica Spesa Stima di sistema della coppia Contratto/Esercizio.

Se zero:

- una Spesa già esistente viene mantenuta a zero;
- una Spesa mai esistita non viene creata.

La composizione conserva:

- condizione di origine;
- inizio ciclo;
- Data di attribuzione della Stima;
- importo.

## 18.19 Effettivi

Gli Effettivi vengono inseriti manualmente tramite Spese di Contratto.

Il sistema **MUST NOT** collegarli ai cicli.

Un Effettivo ordinario richiede Contratto Attivo alla data locale della registrazione tecnica. Un Contratto Pianificato non può ricevere Effettivi ordinari.

Poiché non esiste una Data Effettivo strutturata, il sistema non verifica retroattivamente lo stato contrattuale nel giorno in cui l'evento economico sarebbe avvenuto. L'utente deve dichiarare se l'inserimento è ordinario, tardivo, di cessazione, di rimborso o correttivo.

Un Contratto Cessato o Annullato può ricevere Effettivi in un Esercizio Aperto per:

- addebiti tardivi;
- costi di cessazione;
- rimborsi;
- correzioni.

L'operazione richiede Nota e non riattiva il Contratto.

## 18.20 Nessun Riporto

Il Contratto non produce Riporto.

## 18.21 Controllo non causale

Alla Chiusura, se:

```text
AllocatoCorrenteContratto > 0 AND HaEffettivi(Contratto, Esercizio) = false
```

il sistema mostra l'avviso generale `Allocato presente, nessun Effettivo registrato`.

Non deduce fatture o rate mancanti.

## 18.22 Limiti del motore contrattuale

Non sono rappresentati nativamente:

- prorata;
- consumi variabili;
- minimo garantito più eccedenze;
- setup o una tantum dentro il Contratto;
- soglie e scaglioni;
- indicizzazioni automatiche;
- termini di pagamento come 30 o 60 giorni fine mese;
- fatture e rate.

Le componenti pianificate non rappresentabili vengono gestite come Spese autonome o di Progetto.

---

# 19. Sezione Scadenze dei Contratti

## 19.1 Scopo

Il sistema **MUST** offrire una sezione informativa delle scadenze contrattuali.

Non è uno scadenzario fatture.

## 19.2 Contenuto minimo

Per ogni Contratto non Archiviato mostra almeno:

- Titolo;
- Fornitore;
- stato corrente;
- Data di inizio;
- prossima scadenza, oppure `Scadenza non definita`;
- rinnovo automatico sì/no;
- durata del rinnovo;
- preavviso, se presente;
- Data limite di disdetta derivata, se calcolabile;
- cessazione pianificata, se presente;
- giorni mancanti alla scadenza e al termine di disdetta;
- Centro di Costo corrente;
- eventuale avviso `Rinnovo senza condizione economica`;
- collegamento al Contratto e alla Timeline.

## 19.3 Filtri minimi

- in scadenza entro un intervallo selezionato;
- termine di disdetta entro un intervallo selezionato;
- rinnovo automatico attivo;
- rinnovo automatico disattivo;
- scadenza non definita;
- Pianificato, Attivo, Cessato, Annullato;
- Fornitore;
- Centro di Costo.

## 19.4 Comportamento alla scadenza

La sezione mostra gli eventi derivati dalle regole del §18.5.

- rinnovo automatico → avanzamento della prossima scadenza e registrazione dell'evento;
- mancato rinnovo → cessazione dal giorno successivo alla scadenza;
- cessazione pianificata → evidenza della data;
- dato mancante → `Scadenza non definita`, senza inferenze.

## 19.5 Promemoria

Promemoria, email e notifiche automatiche sono una possibile estensione futura.

La baseline conserva già le date necessarie, ma **MUST NOT** promettere l'invio di promemoria finché la funzione non viene specificata e implementata.

---

# 20. Centri di Costo

## 20.1 Scopo

Il Centro di Costo è una classificazione annuale e non genera importi.

## 20.2 Cardinalità

Per Esercizio:

- una Spesa autonoma appartiene a zero o un Centro di Costo;
- un Progetto appartiene a zero o un Centro di Costo;
- un Contratto appartiene a zero o un Centro di Costo.

`Non classificato` è assenza di associazione.

## 20.3 Classificazione annuale

Progetti e Contratti possiedono una classificazione per Esercizio.

Il nuovo Esercizio viene inizializzato dall'ultima classificazione nota; l'utente può modificarla finché l'Esercizio resta Aperto.

Una modifica in un Esercizio Aperto riclassifica l'intero Esercizio, compresi gli Effettivi già presenti.

Non esiste una classificazione valida soltanto da un mese in avanti.

Il sistema **MUST** mostrare l'impatto prima della conferma.

## 20.4 Proposta e classificazione

La Proposta può modificare il Centro di Costo soltanto quando non riclassifica Effettivi esistenti.

Se esistono Effettivi, la riclassificazione deve avvenire nell'operatività viva dell'Esercizio Aperto, con Nota e audit.

## 20.5 Spese associate

Una Spesa associata eredita il Centro di Costo del contenitore e non ne possiede uno indipendente.

## 20.6 Modifica dalla vista della Spesa

Il sistema:

- informa che verrà modificato il contenitore;
- mostra tutte le Spese dell'Esercizio interessate;
- mostra Allocato ed Effettivo riclassificati;
- richiede conferma;
- registra motivo se esiste un Budget Approvato.

## 20.7 Ridenominazione

L'identità resta invariata.

Le Snapshot conservano la denominazione materializzata al momento.

## 20.8 Archivio

Un Centro di Costo referenziato viene Archiviato, non eliminato.

Resta disponibile:

- nei report storici;
- nelle Snapshot;
- nelle correzioni tardive che devono mantenere la classificazione storica.

Non è selezionabile per nuove classificazioni finché resta Archiviato.

## 20.9 Aggregazioni

```text
AllocatoCdC = somma Allocati delle sorgenti classificate
EffettivoCdC = somma Effettivi delle sorgenti classificate
ScostamentoCdC = EffettivoCdC - AllocatoCdC
```

Il Riporto mostrato per Centro di Costo è la somma dei Riporti dei Progetti classificati nell'Esercizio destinazione.

## 20.10 Limite

La baseline non supporta ripartizioni percentuali o molti-a-molti fra Centri di Costo.

---

# 21. Fornitori e Referenti

## 21.1 Fornitore

Ogni Fornitore contiene:

- ID;
- Ragione Sociale obbligatoria;
- Partita IVA opzionale;
- Note;
- zero o più Referenti;
- Archivio.

La Partita IVA è informativa e non obbligatoriamente univoca.

## 21.2 Referente

Ogni Referente può contenere:

- Nome;
- Cognome;
- telefono;
- email;
- Note;
- tag di ruolo opzionali, per esempio Commerciale, Tecnico, Amministrativo o Altro.

Nessun ruolo è obbligatorio. Il sistema **MUST NOT** costringere ad attribuire un ruolo falso.

## 21.3 Utilizzo

- ogni Contratto ha un Fornitore obbligatorio;
- una Spesa autonoma o di Progetto può avere Fornitore opzionale;
- una Spesa di Contratto eredita il Fornitore del Contratto.

## 21.4 Archivio

Un Fornitore referenziato viene Archiviato, non eliminato.

Resta visibile:

- negli oggetti esistenti;
- nelle Snapshot;
- nei report storici;
- nelle correzioni tardive relative a dati storici.

Non è selezionabile per nuovi Contratti o nuove Spese ordinarie.

I Contratti esistenti possono continuare a usarlo e ricevere Effettivi. Una nuova controparte richiede un nuovo Contratto.

## 21.5 Fuori ambito

Non sono richiesti:

- IBAN;
- PEC;
- codice destinatario;
- condizioni di pagamento del Fornitore;
- credenziali;
- ulteriori dati fiscali;
- gerarchie fra Referenti.


---

# 22. Timeline e audit

## 22.1 Scopo

La Timeline deve permettere di spiegare:

- cosa è cambiato;
- quando è diventato efficace;
- chi lo ha fatto;
- perché;
- quali Esercizi e valori sono stati interessati.

Non è sufficiente registrare `oggetto modificato`.

## 22.2 Struttura minima dell'evento

Ogni evento funzionale contiene almeno:

- ID;
- timestamp tecnico;
- autore;
- Azienda;
- tipo e ID oggetto;
- Esercizi interessati;
- tipo operazione;
- data o intervallo di efficacia;
- valori precedenti;
- valori nuovi;
- impatto su Allocato ed Effettivo per Esercizio;
- motivo quando richiesto;
- riferimento a Proposta, Budget, Chiusura, correzione o annotazione;
- allegati opzionali.

## 22.3 Eventi obbligatori della Spesa

- creazione;
- modifica della Descrizione dopo un Budget Approvato;
- copia fra Esercizi;
- modifica di Stima;
- modifica, aggiunta, Annullamento o ripristino di una Riga Effettivo in Esercizio Aperto;
- cambio Esercizio;
- cambio contenitore;
- cambio Fornitore;
- cambio classificazione diretta;
- Storno;
- ripristino;
- inserimento tardivo;
- Effettivo negativo;
- nuova sorgente dopo un Budget Approvato;
- creazione o aumento di Sovraspesa di Progetto.

## 22.4 Eventi obbligatori del Progetto

- creazione;
- modifica di Titolo o Descrizione dopo un Budget Approvato;
- pianificazione, efficacia, annullamento e sostituzione di transizioni;
- rinvio;
- scelta o cambio della modalità Riporto/Riprogrammazione;
- scelta del Riporto provvisorio;
- consolidamento del Riporto;
- modifica della classificazione annuale;
- Sovraspesa e suoi incrementi;
- Chiusura con Risparmio;
- cancellazione con Allocato non utilizzato;
- riapertura;
- modifica di relazioni informative.

## 22.5 Eventi obbligatori del Contratto

- creazione;
- modifica di Titolo o Note economicamente esplicative dopo un Budget Approvato;
- attivazione;
- rinnovo contrattuale;
- modifica di prossima scadenza, durata del rinnovo o preavviso;
- cessazione;
- riattivazione;
- annullamento;
- nuova condizione;
- annullamento o termine di condizione;
- decorrenza richiesta e decorrenza effettivamente applicata;
- ricalcolo delle Stime con impatto per Esercizio;
- modifica della classificazione annuale;
- informazione storica emersa senza ricalcolo di Esercizi Chiusi;
- modifica di relazioni informative.

## 22.6 Eventi obbligatori delle anagrafiche

- creazione;
- ridenominazione;
- Archivio;
- ripristino dall'Archivio;
- modifica dei Referenti;
- modifica dei tag di ruolo.

## 22.7 Eventi obbligatori della Proposta

- inizializzazione;
- azione di piano;
- inclusione di nuova sorgente;
- passaggio a Da prendere in visione;
- passaggio a Da riallineare;
- Ricarica realtà;
- Mantieni proposta;
- revisione manuale;
- risoluzione di incoerenza;
- scarto;
- approvazione;
- fallimento di approvazione con causa non sensibile.

## 22.8 Eventi obbligatori di Budget e Chiusura

- approvazione v1;
- ogni Revisione;
- avvio e conferma della Chiusura;
- Chiusura completata;
- fallimento della Chiusura;
- mancata creazione di `N+1` per terminazione della gestione;
- Annotazione di errore storico;
- correzione esplicita degli effetti in un Esercizio successivo.

## 22.9 Note obbligatorie

La Nota è obbligatoria per:

- Revisione del Budget;
- modifica di Titolo o Descrizione di una sorgente dopo un Budget Approvato;
- modifica di Stima dopo un Budget Approvato;
- nuova sorgente con Allocato o Effettivo non presente nel Budget corrente;
- cessazione, riattivazione o annullamento di Contratto;
- modifica economica contrattuale dopo un Budget Approvato;
- Chiusura, cancellazione o riapertura di Progetto;
- rinvio;
- cambio della modalità Riporto/Riprogrammazione;
- cambio di Centro di Costo dopo un Budget Approvato;
- spostamento di una Spesa con Effettivi;
- Storno o ripristino;
- Effettivo negativo;
- Effettivo su Progetto Chiuso/Cancellato;
- Effettivo su Contratto Cessato/Annullato;
- correzione tardiva;
- Annotazione di errore storico;
- riallineamento con Mantieni proposta;
- Sovraspesa quando richiesto dalle Impostazioni.

## 22.10 Immutabilità e non event sourcing

Gli eventi sono append-only.

Un evento errato viene corretto da un nuovo evento.

Lo stato corrente viene letto dagli oggetti vivi; il sistema **MUST NOT** dipendere dalla riproduzione completa della Timeline per funzionare.

Gli eventi tipizzati di transizione del Progetto, di stato del Contratto e di configurazione del rinnovo sono però autoritativi per calcolare il rispettivo `StatoAllaData`. Questa eccezione circoscritta non trasforma l'intero dominio in event sourcing.

---

# 23. Snapshot e storico

## 23.1 Tipi

Sono previsti esclusivamente:

- `Budget Approvato`;
- `Chiusura`.

Non esistono Snapshot di Forecast.

## 23.2 Proprietà comuni

Ogni Snapshot è:

- immutabile;
- materializzata;
- autonoma dagli oggetti vivi;
- confrontabile per OriginKey;
- non ripristinabile come database;
- leggibile dopo Archivio o modifiche successive;
- idempotente rispetto all'ID dell'operazione che la crea.

## 23.3 Cardinalità

Per Esercizio:

- zero o più Snapshot di Budget, con versione crescente univoca;
- nessuna Snapshot di Chiusura prima della Chiusura;
- esattamente una Snapshot di Chiusura dopo la Chiusura.

## 23.4 Schema della Snapshot di Budget

### Header

Contiene almeno:

- ID Snapshot;
- Azienda e denominazione;
- Esercizio;
- versione;
- data e ora di approvazione;
- autore;
- finalità Budget iniziale/Revisione;
- motivazione;
- Proposta di origine;
- versione precedente;
- totale Allocato Approvato;
- Esercizi Aperti modificati contestualmente.

### Riga di primo livello

Per ogni Elemento di Proposta efficace conserva almeno:

- ID riga Snapshot;
- tipo origine;
- OriginKey definitivo;
- ProposalItemID quando l'oggetto era nuovo;
- CopiedFromOriginKey quando applicabile;
- etichetta materializzata:
  - Descrizione per Spesa autonoma;
  - Titolo per Progetto o Contratto;
- descrizione sintetica opzionale;
- Fornitore materializzato per Spesa autonoma e Contratto; per un Progetto il campo è assente e i Fornitori sono conservati nelle Spese figlie;
- Centro di Costo materializzato o Non classificato;
- Stime approvate;
- Riporto approvato e stato Provvisorio/Consolidato;
- Allocato Approvato;
- stato al 1° gennaio, oppure `Assente alla data`;
- transizioni approvate nell'Esercizio;
- stato al 31 dicembre, oppure `Assente alla data`;
- tutte le condizioni che generano almeno una Stima approvata nell'Esercizio oppure sono create, modificate o Annullate da un'azione approvata;
- tutte le scadenze, cessazioni, riattivazioni, annullamenti e rinnovi oggetto di un'azione approvata o necessari a determinare lo stato al 1° gennaio e al 31 dicembre;
- azioni e motivazioni approvate;
- relazioni informative `Collegato a` attive;
- riferimenti agli eventi di approvazione.

### Dati esclusi dal Budget

La Snapshot di Budget **MUST NOT** contenere come valori della baseline:

- Effettivi;
- Scostamento Operativo;
- Residuo;
- Risparmio;
- Sovraspesa finale;
- correzioni tardive.

Gli Effettivi vengono selezionati separatamente nei report.

## 23.5 Dettaglio del Budget per Spesa

Per le Spese pianificate conserva:

- ID e Descrizione;
- Esercizio;
- origine Manuale/Sistema;
- contenitore;
- Fornitore;
- stato Attiva/Stornata;
- totale Stima approvato;
- Righe Stima Attive con Importo, dati descrittivi e Nota completa della Riga;
- azioni approvate di Annullamento o ripristino delle Righe Stima, senza includere le Righe Annullate nel totale approvato.

Non conserva Righe Effettivo come parte del piano approvato.

## 23.6 Dettaglio del Budget per Progetto

Conserva inoltre:

- stato iniziale e finale dell'Esercizio;
- transizioni approvate;
- modalità Nessuna/Riporto/Riprogrammazione;
- Riporto approvato;
- Importo Riprogrammato approvato;
- Stime di Progetto;
- relazioni informative `Collegato a`;
- motivi di rinvio, apertura, Chiusura o cancellazione proposti.

## 23.7 Dettaglio del Budget per Contratto

Conserva inoltre:

- stato iniziale e finale;
- Data di inizio;
- prossima scadenza;
- rinnovo automatico;
- durata rinnovo;
- preavviso e Data limite disdetta;
- condizioni economiche approvate;
- cicli e Date di attribuzione della Stima;
- composizione della Stima annuale;
- cessazioni o riattivazioni approvate.

## 23.8 Schema della Snapshot di Chiusura

### Header

Contiene almeno:

- ID Snapshot;
- Azienda e denominazione;
- Esercizio;
- data e ora tecnica della Chiusura;
- autore;
- Budget iniziale e corrente di riferimento, se esistenti;
- totale Allocato finale;
- totale Effettivo alla Chiusura;
- totale Scostamento Operativo;
- totale Riporto consolidato;
- avvisi accettati;
- indicazione di creazione o mancata creazione di `N+1`.

### Riga di primo livello

Per ogni sorgente inclusa dal §7.6.5 conserva almeno:

- ID riga;
- tipo e OriginKey;
- etichetta materializzata;
- Descrizione sintetica opzionale;
- Fornitore materializzato per Spesa autonoma e Contratto; per un Progetto il campo è assente e i Fornitori sono conservati nelle Spese figlie;
- Centro di Costo materializzato;
- stato al 31 dicembre;
- Stime finali;
- Riporto ricevuto;
- Allocato finale;
- Effettivo alla Chiusura;
- Scostamento Operativo;
- Residuo, Risparmio o Allocato non utilizzato secondo lo stato;
- modalità di rinvio;
- Importo Riprogrammato;
- Riporto consolidato;
- decisioni di Chiusura;
- tutte le condizioni con intervallo sovrapposto all'Esercizio o con almeno una Data di attribuzione nell'Esercizio;
- tutte le scadenze, cessazioni, riattivazioni, annullamenti e rinnovi con data di efficacia nell'Esercizio, oltre alla prossima scadenza nota alla Chiusura;
- relazioni informative `Collegato a` attive;
- riferimenti agli eventi esplicativi.

## 23.9 Dettaglio della Chiusura per Spesa

Conserva:

- ID e Descrizione;
- Esercizio;
- origine;
- contenitore;
- Fornitore;
- stato;
- totale Stima finale;
- totale Effettivo alla Chiusura;
- tutte le Righe Stima ed Effettivo esistenti alla Chiusura, con stato Attiva/Annullata; soltanto le Righe Attive contribuiscono ai totali.

## 23.10 Dettaglio della Chiusura per Progetto

Conserva inoltre:

- stato al 31 dicembre;
- transizioni efficaci nell'Esercizio;
- Residuo, Risparmio o Allocato non utilizzato;
- modalità di rinvio;
- Importo Riprogrammato;
- Riporto consolidato;
- decisione e motivo;
- relazioni informative `Collegato a`.

## 23.11 Dettaglio della Chiusura per Contratto

Conserva inoltre:

- stato al 31 dicembre;
- prossima scadenza nota alla Chiusura;
- rinnovo automatico;
- condizioni economiche applicate;
- cicli e Date di attribuzione;
- cessazioni, rinnovi o riattivazioni;
- composizione della Stima finale.

## 23.12 Dati esclusi dalla materializzazione obbligatoria

Non è obbligatorio copiare:

- contatti completi dei Referenti;
- credenziali;
- allegati generici non decisionali;
- campi tecnici privi di significato;
- intero storico applicativo.

Le evidenze associate ad approvazione, Revisione, Chiusura, correzione tardiva o Annotazione di errore storico **MUST** essere conservate in modo immutabile o versionato.

## 23.13 Confronto

Il confronto usa:

1. OriginKey per la stessa sorgente;
2. CopiedFromOriginKey per una derivazione esplicita senza identità condivisa;
3. presenza in un solo riferimento per Aggiunto o Rimosso.

Il sistema **MUST NOT** usare fuzzy matching per titolo, importo o Fornitore.

## 23.14 Nessun as-of arbitrario

Il sistema supporta:

- Situazione Corrente;
- Budget Approvati;
- Snapshot di Chiusura;
- Timeline.

Non è richiesto ricostruire lo stato completo a un timestamp arbitrario non materializzato.

---

# 24. Storno, Archivio e correzioni tardive

## 24.1 Nessuna eliminazione fisica

Dopo il primo salvataggio persistente:

- una Spesa non viene eliminata fisicamente;
- un Progetto non viene eliminato fisicamente;
- un Contratto non viene eliminato fisicamente;
- un Fornitore non viene eliminato fisicamente;
- un Centro di Costo non viene eliminato fisicamente.

Gli ID non vengono riutilizzati.

## 24.2 Storno della Spesa

Si applica il §15.11.

Lo Storno è economico: esclude una Spesa priva di Effettivi dai calcoli correnti.

## 24.3 Archivio

L'Archivio è di visibilità:

- Progetto: ammesso se Chiuso o Cancellato;
- Contratto: ammesso se Cessato o Annullato;
- Fornitore e Centro di Costo: ammesso quando non devono essere selezionati per nuove attività;
- ripristino sempre auditato.

L'Archivio non rimuove valori o storico.

## 24.4 Distinzione delle correzioni tardive

### Evento realmente appartenente all'Esercizio chiuso

Può essere aggiunto come Effettivo tardivo nell'Esercizio originario.

### Importo realmente sostenuto nell'Esercizio corrente

Appartiene all'Esercizio corrente, anche se riferibile commercialmente a un anno precedente.

Il sistema non applica competenza contabile.

## 24.5 Operazioni tardive ammesse

Un utente con `corregge_esercizio_chiuso` può aggiungere:

- una nuova Spesa manuale tardiva con soli Effettivi;
- una nuova Riga Effettivo a una Spesa manuale storica compatibile;
- una Riga Effettivo compensativa positiva o negativa.

Una correzione tardiva **MUST NOT** essere inserita nella Spesa Stima di sistema di un Contratto.

## 24.6 Spesa storica compatibile

Una Spesa è compatibile per una nuova Riga tardiva quando:

- è Manuale;
- appartiene all'Esercizio chiuso corretto;
- appartiene alla stessa sorgente di primo livello alla quale l'importo era storicamente imputato;
- non è Stornata;
- il suo tipo consente Effettivi;
- il Fornitore storico è disponibile, anche se Archiviato.

Se non è compatibile, il sistema crea una nuova Spesa manuale tardiva nello stesso contenitore storico.

## 24.7 Requisiti della correzione tardiva

Richiede:

- motivo;
- dichiarazione che l'importo apparteneva realmente all'Esercizio;
- autore e timestamp;
- riferimento alla Riga originaria quando noto;
- allegato opzionale.

## 24.8 Vincoli

Una correzione tardiva **MUST NOT**:

- aggiungere o modificare Stime;
- cambiare condizioni o scadenze contrattuali storiche;
- cambiare Budget;
- cambiare Snapshot di Chiusura;
- cambiare Riporto;
- cambiare stato storico;
- cambiare Centro di Costo, Progetto, Contratto, Fornitore, contenitore o Esercizio storico;
- richiedere riapertura.

## 24.9 Correzione di importo

Un Effettivo di un Esercizio Chiuso non viene modificato o eliminato.

Si aggiunge una Riga compensativa.

Esempio:

```text
Effettivo errato:      1.200 €
Importo corretto:      1.000 €
Correzione tardiva:     -200 €
```

## 24.10 Errore di imputazione

Gli errori di imputazione seguono il §14.9:

- nessun trasferimento compensativo;
- nessuna riclassificazione del Consuntivo a Conoscenza Corrente;
- Annotazione di errore storico visibile nei report.

Questa è una limitazione deliberata.

## 24.11 Report delle correzioni

Quando esistono correzioni, il sistema mostra:

- Effettivo alla Chiusura;
- correzioni tardive positive;
- correzioni tardive negative;
- correzioni tardive nette;
- Effettivo a Conoscenza Corrente;
- Annotazioni di errore storico separate, anche quando non modificano il totale.

Il solo saldo netto **MUST NOT** nascondere l'esistenza delle singole correzioni.


---

# 25. Reportistica

## 25.1 Principio

Ogni report **MUST** indicare esplicitamente:

- Azienda;
- Esercizio;
- riferimento temporale utilizzato;
- versione del Budget, se presente;
- se l'Effettivo è Corrente, alla Chiusura oppure a Conoscenza Corrente;
- data e ora di generazione;
- eventuali filtri applicati.

Il sistema **MUST NOT** mostrare confronti fra grandezze diverse senza nominarle.

## 25.2 Riferimenti confrontabili

Il sistema **MUST** poter confrontare:

- Budget Approvato selezionato ↔ Situazione Corrente;
- Budget Approvato selezionato ↔ Snapshot di Chiusura;
- Budget Approvato selezionato ↔ Effettivo a Conoscenza Corrente;
- Budget Approvato ↔ Budget Approvato;
- Snapshot di Chiusura ↔ Effettivo a Conoscenza Corrente;
- Esercizio ↔ Esercizio usando la stessa misura.

## 25.3 Granularità canonica di `Previsto` e `Non previsto`

Le etichette `Previsto`, `Non previsto` e `Previsto e non avvenuto` si applicano esclusivamente alle sorgenti economiche di primo livello:

- Spesa autonoma;
- Progetto;
- Contratto.

Non si applicano autonomamente alle Spese figlie di Progetti o Contratti.

Questa regola evita di classificare come non prevista una nuova Spesa Effettiva interna a un Progetto o Contratto già previsto.

### Progetti e Contratti

Per un Progetto o Contratto, tutte le Spese figlie vengono aggregate nella stessa sorgente di primo livello.

La separazione tecnica fra:

- Stima di sistema del Contratto;
- Spese Effettive manuali del Contratto;

**MUST NOT** produrre le etichette `Previsto e non avvenuto` e `Non previsto` sulle singole Spese.

Nel drill-down, le Spese figlie usano soltanto fatti strutturali neutrali:

- `Presente in entrambi i riferimenti`;
- `Solo nel riferimento iniziale`;
- `Solo nel riferimento finale`;
- `Modificata`.

Questi fatti non cambiano la classificazione economica del Progetto o Contratto e non vengono conteggiati come sorgenti di primo livello.

### Spese autonome

Per una Spesa autonoma, l'identità è una dichiarazione dell'utente:

- aggiungere una Riga Effettivo alla stessa Spesa significa dichiarare che riguarda la stessa sorgente;
- creare una nuova Spesa autonoma significa dichiarare una sorgente distinta.

Il sistema **MUST NOT** unire automaticamente Spese autonome per titolo, importo, Fornitore o Nota.

## 25.4 Presenza nel Budget e previsione economica

Sono concetti distinti.

### Presente nel Budget

Una sorgente è presente nel Budget selezionato quando possiede una riga nella relativa Snapshot.

### Prevista economicamente

Una sorgente è prevista economicamente quando:

```text
AllocatoApprovatoSelezionato > 0
```

Una sorgente può essere presente nel Budget con Allocato zero, per esempio perché già esistente o perché oggetto di una decisione di stato. In tal caso non è economicamente prevista.

## 25.5 Categoria primaria del confronto

Ogni sorgente riceve esattamente una categoria primaria:

- `Invariato`;
- `Aggiunto`;
- `Rimosso`;
- `Modificato`.

### Invariato

La sorgente esiste in entrambi i riferimenti e tutte le dimensioni confrontate sono uguali.

### Aggiunto

La sorgente non esiste nel riferimento iniziale ed esiste nel riferimento finale.

### Rimosso

La sorgente esiste nel riferimento iniziale e non esiste nel riferimento finale.

Lo Storno o l'azzeramento di una sorgente che continua a essere materializzata nel riferimento finale non la rende automaticamente `Rimossa`: viene classificata `Modificata` e riceve l'etichetta secondaria appropriata.

### Modificato

La sorgente esiste in entrambi i riferimenti e almeno una dimensione confrontata è diversa.

I conteggi esecutivi usano esclusivamente la categoria primaria, così ogni sorgente viene contata una sola volta.

## 25.6 Dimensioni della modifica

Per una sorgente `Modificata`, il sistema indica una o più dimensioni:

- Stima o Allocato;
- Effettivo;
- Riporto;
- Centro di Costo;
- Fornitore;
- contenitore, quando applicabile;
- stato o transizioni;
- condizioni economiche;
- scadenza, rinnovo o cessazione contrattuale;
- Archivio o Storno;
- relazioni informative.

Le dimensioni possono sovrapporsi e non alterano il conteggio della categoria primaria.

## 25.7 Etichette semantiche secondarie

Una sorgente può ricevere zero o più etichette:

- `Non previsto`;
- `Previsto e non avvenuto`;
- `Senza Effettivi`;
- `Stornato`;
- `Cancellato`;
- `Rinviato`;
- `Correzione tardiva`;
- `Riporto variato`;
- `Imputazione storica contestata`;
- `Scadenza contrattuale entro l'intervallo selezionato`;
- `Scadenza non definita`.

Le etichette possono sovrapporsi e **MUST NOT** essere sommate come se fossero categorie esclusive.

L'etichetta `Scadenza contrattuale entro l'intervallo selezionato` viene applicata soltanto quando il report o il filtro dichiara esplicitamente un intervallo di date e la prossima scadenza ricade in tale intervallo. Non esiste una soglia implicita di “imminenza”.

## 25.8 Non previsto

Rispetto a un Budget selezionato, una sorgente è `Non prevista` quando:

```text
AllocatoApprovatoSelezionato = 0
```

o la sorgente è assente dal Budget, e nel riferimento finale possiede almeno uno fra:

- Allocato diverso da zero;
- `HaEffettivi = true`.

La presenza con Allocato zero in una Revisione non trasforma automaticamente un costo in previsto. Per renderlo previsto economicamente, la Revisione deve approvare un Allocato positivo.

## 25.9 Previsto e non avvenuto

Una sorgente è `Prevista e non avvenuta` quando:

1. `AllocatoApprovatoSelezionato > 0`;
2. `HaEffettivi` nel riferimento finale selezionato è falso;
3. ricorre almeno una delle seguenti condizioni:
   - l'Esercizio è Chiuso;
   - la Spesa autonoma è Stornata;
   - il Progetto è Chiuso o Cancellato alla data del riferimento;
   - il Contratto è Cessato o Annullato alla data del riferimento.

In un Esercizio Aperto, una sorgente ancora operativa con Allocato positivo e `HaEffettivi = false` riceve soltanto l'etichetta `Senza Effettivi`.

Il sistema non deduce la causa del mancato avvenimento.

## 25.10 Vista annuale esecutiva

La vista annuale mostra almeno:

- Budget iniziale approvato;
- Budget approvato corrente;
- Allocato Corrente;
- Effettivo Corrente;
- Scostamento Operativo;
- Variazione Allocato vs Budget selezionato;
- Varianza Budget vs Actual selezionato;
- Effettivo alla Chiusura, se presente;
- correzioni tardive positive, negative e nette;
- Effettivo a Conoscenza Corrente;
- conteggio per categoria primaria;
- conteggio per etichette principali;
- totale Non classificato;
- Annotazioni di errore storico.

## 25.11 Drill-down

Ogni totale deve essere approfondibile per:

- Centro di Costo;
- Progetto;
- Contratto;
- Spesa autonoma;
- Spese figlie;
- Righe;
- condizioni e cicli contrattuali;
- Riporti;
- eventi della Timeline;
- Annotazioni di errore storico.

## 25.12 Spiegazione della variazione

Per ogni differenza il sistema mostra:

1. riferimento iniziale;
2. riferimento finale;
3. valore iniziale;
4. valore finale;
5. delta;
6. categoria primaria;
7. dimensioni modificate;
8. etichette secondarie;
9. eventi e motivazioni disponibili;
10. allegati decisionali pertinenti.

Se gli eventi disponibili non spiegano il delta, il sistema mostra:

```text
Variazione non sufficientemente spiegata
```

## 25.13 Esercizio Chiuso e correzioni successive

Per un Esercizio Chiuso:

- Residuo, Risparmio, Allocato non utilizzato e Riporto restano quelli materializzati nella Snapshot di Chiusura;
- le correzioni tardive aggiornano soltanto l'Effettivo a Conoscenza Corrente e le varianze che lo usano;
- il sistema **MUST NOT** presentare un nuovo Residuo, Risparmio o Riporto ricalcolato come se fosse una nuova decisione di Chiusura;
- il report mostra separatamente l'impatto delle correzioni tardive rispetto all'Allocato finale alla Chiusura.

## 25.14 Report Budget vs Actual

Il report richiede una versione di Budget e un riferimento Effettivo.

```text
VarianzaBudgetActual =
EffettivoSelezionato - AllocatoApprovatoSelezionato
```

Il riferimento Effettivo può essere:

- Corrente;
- alla Chiusura;
- a Conoscenza Corrente.

## 25.15 Report Budget vs Allocato Corrente

```text
VariazioneAllocatoBudget =
AllocatoCorrente - AllocatoApprovatoSelezionato
```

Questo report mostra come il piano vivo è cambiato rispetto al piano approvato.

## 25.16 Report Scostamento Operativo

```text
ScostamentoOperativo =
EffettivoCorrente - AllocatoCorrente
```

Descrive la situazione viva e non il confronto con il Budget.

## 25.17 Confronto fra versioni di Budget

Il sistema mostra almeno:

- sorgenti Aggiunte;
- sorgenti Rimosse;
- sorgenti Modificate;
- variazioni di Allocato;
- riclassificazioni;
- cambi di stato;
- cambi contrattuali;
- cambi di Riporto;
- motivazione della Revisione.

Gli Effettivi non fanno parte delle baseline confrontate.

## 25.18 Confronto fra Esercizi

Il confronto anno su anno usa la stessa misura su entrambi gli anni, per esempio:

- Budget iniziale vs Budget iniziale;
- Budget corrente vs Budget corrente;
- Allocato finale vs Allocato finale;
- Effettivo alla Chiusura vs Effettivo alla Chiusura;
- Effettivo a Conoscenza Corrente vs Effettivo a Conoscenza Corrente.

Progetti e Contratti persistenti vengono correlati tramite OriginKey.

Spese copiate vengono mostrate come derivate tramite CopiedFromOriginKey, non come la stessa Spesa.

Il sistema **MUST NOT** inferire continuità per somiglianza.

## 25.19 Report Riporti

Per ogni Progetto mostra:

- Esercizio origine e destinazione;
- modalità Nessuna/Riporto/Riprogrammazione;
- Importo Riprogrammato;
- Allocato;
- Effettivo;
- Residuo;
- disponibilità massima riportabile;
- Riporto provvisorio;
- Riporto consolidato;
- differenza fra provvisorio e consolidato;
- decisione e motivo;
- eventuali correzioni tardive successive, senza ricalcolo del Riporto.

## 25.20 Report Contratti

Mostra almeno:

- stato alla data selezionata;
- Allocato;
- Effettivo;
- Scostamento Operativo;
- condizioni che producono la Stima;
- cicli e Date di attribuzione;
- prossima scadenza;
- rinnovo automatico;
- Data limite di disdetta;
- cessazioni, riattivazioni, annullamenti e rinnovi;
- Contratti con Allocato positivo e `HaEffettivi = false`;
- variazioni di importo, ciclo o modalità;
- relazioni informative;
- Annotazioni di errore storico.

## 25.21 Report Progetti

Mostra almeno:

- stato alla data selezionata;
- Allocato;
- Effettivo;
- Scostamento;
- Residuo, Risparmio o Allocato non utilizzato secondo lo stato;
- modalità di rinvio;
- Riporto;
- evoluzione pluriennale;
- Progetti previsti ma non iniziati;
- Progetti non previsti;
- transizioni ed eventi;
- Annotazioni di errore storico.

## 25.22 Report Fornitori

Il report per Fornitore mostra almeno:

- Allocato;
- Effettivo;
- Scostamento Operativo;
- Contratti;
- Spese autonome;
- Spese di Progetto;
- sorgenti senza Fornitore;
- variazioni rispetto al Budget selezionato.

L'aggregazione usa le Spese, non il totale del Progetto, perché le Spese dello stesso Progetto possono avere Fornitori differenti:

- una Spesa autonoma usa il proprio Fornitore;
- una Spesa di Progetto usa il proprio Fornitore opzionale;
- le Spese e la Stima di un Contratto usano il Fornitore del Contratto;
- una Spesa senza Fornitore confluisce in `Senza Fornitore`;
- il Riporto del Progetto non viene attribuito a un Fornitore e confluisce in `Riporto senza Fornitore`.

Ogni importo viene aggregato una sola volta. Il report **MUST NOT** sommare anche il totale del Progetto sopra le relative Spese.

## 25.23 Report storico dopo Archivio

I report storici usano i dati materializzati nelle Snapshot e non dipendono dalla selezionabilità dell'oggetto vivo.

## 25.24 Esportazione e precisione semantica

Un'esportazione **MUST** conservare:

- intestazione del riferimento;
- versione Budget;
- tipo di Effettivo;
- categorie e relative definizioni;
- indicazione degli importi netti IVA in EUR.

Un'esportazione **MUST NOT** omettere queste informazioni rendendo ambiguo il significato dei valori.

La personalizzazione del PDF può selezionare soltanto blocchi e colonne realmente applicabili ricostruiti lato server. Anteprima e download devono rappresentare lo stesso documento; la configurazione resta effimera e non può introdurre HTML, CSS, URL, path o JavaScript utente.


---

# 26. Impostazioni e permessi

## 26.1 Principio

Le Impostazioni devono essere poche, per Azienda e motivate da un comportamento obbligatorio.

Una modifica delle Impostazioni:

- non riscrive Budget o Chiusure precedenti;
- si applica alle operazioni successive;
- produce un evento di audit;
- viene materializzata nelle approvazioni e Chiusure che la utilizzano.

## 26.2 Impostazioni previste

### Nota di Sovraspesa obbligatoria

- Tipo: booleano.
- Default: `false`.

Se attiva, l'operazione che crea o aumenta una Sovraspesa viene bloccata finché non viene inserita una Nota.

### Policy Non classificato alla Chiusura

- Valori: `Avviso`, `Blocco`.
- Default: `Avviso`.

Determina il comportamento del controllo pre-Chiusura.

### Fuso orario aziendale

- Tipo: identificatore IANA obbligatorio.
- Nessun default implicito: deve essere scelto alla creazione dell'Azienda.

Una modifica del fuso:

- non cambia date economiche già salvate;
- non modifica Snapshot;
- cambia la conversione dei timestamp tecnici e la determinazione della data locale corrente per le operazioni successive;
- richiede audit e anteprima dell'impatto su eventi previsti nella data corrente.

## 26.3 Revisioni del Budget

Le Revisioni non sono configurabili.

Finché l'Esercizio è Aperto, il sistema **MUST** consentire una Revisione a un utente autorizzato.

Questa regola garantisce un percorso correttivo per errori materiali o nuove decisioni senza sovrascrivere le versioni precedenti.

## 26.4 Comportamenti fissi

Non sono Impostazioni:

- EUR;
- importi netti IVA;
- anno solare;
- assenza di Forecast;
- nessun matching;
- nessun prorata;
- nessun Riporto per Contratti o Spese autonome;
- modalità Riporto/Riprogrammazione esclusiva;
- limite del Riporto rispetto all'Allocato;
- nessuna riapertura globale;
- correzioni tardive append-only;
- nessuna riclassificazione economica dopo la Chiusura;
- ordine cronologico delle Chiusure;
- immutabilità delle Snapshot;
- stato di Chiusura valutato al 31 dicembre;
- atomicità inter-Esercizio;
- nessuna cancellazione fisica ordinaria.

## 26.5 Capacità per Azienda

Ogni capacità viene assegnata per una specifica Azienda.

Sono previste almeno:

- `visualizza`;
- `modifica_operativita`;
- `gestisce_proposte`;
- `approva_budget`;
- `chiude_esercizio`;
- `corregge_esercizio_chiuso`;
- `gestisce_anagrafiche`;
- `gestisce_impostazioni`;
- `gestisce_permessi`.

Un'autorizzazione su un'Azienda **MUST NOT** implicare la stessa autorizzazione su un'altra Azienda.

## 26.6 Ambito delle capacità

### visualizza

Consente lettura di dati, report, Timeline, Snapshot e scadenze dell'Azienda.

### modifica_operativita

Consente modifiche ordinarie in Esercizi Aperti, incluse Stime, Effettivi, spostamenti, stati e condizioni, salvo operazioni riservate.

### gestisce_proposte

Consente creare, modificare, riallineare e scartare Proposte.

### approva_budget

Consente approvare Budget iniziali e Revisioni.

### chiude_esercizio

Consente eseguire la Chiusura, decidere i Riporti e confermare l'eventuale terminazione della gestione.

### corregge_esercizio_chiuso

Consente aggiungere correzioni tardive di importo e Annotazioni di errore storico.

### gestisce_anagrafiche

Consente gestire Fornitori, Referenti, Centri di Costo e Archivio delle anagrafiche.

### gestisce_impostazioni

Consente modificare le Impostazioni aziendali.

### gestisce_permessi

Consente assegnare e revocare capacità per l'Azienda.

## 26.7 Nessun maker-checker obbligatorio

La stessa persona può possedere più capacità e può preparare, approvare e chiudere.

Il sistema non impone separazione dei ruoli.

## 26.8 Audit delle autorizzazioni

Devono essere registrati almeno:

- assegnazione di una capacità;
- revoca;
- autore;
- beneficiario;
- Azienda;
- timestamp;
- motivo opzionale;
- valore precedente e nuovo.

## 26.9 Audit delle Impostazioni

Ogni modifica registra:

- Impostazione;
- valore precedente;
- valore nuovo;
- autore;
- timestamp;
- Azienda.

Ogni approvazione o Chiusura conserva i valori delle Impostazioni applicati durante l'operazione.

## 26.10 Operazioni sempre auditabili

Devono essere auditabili almeno:

- approvazioni e Revisioni;
- Chiusure;
- correzioni tardive;
- Annotazioni di errore storico;
- Storni e ripristini;
- modifiche contrattuali;
- rinnovi e scadenze;
- transizioni di Progetto;
- cambi di Centro di Costo;
- spostamenti di Spese;
- riallineamenti di Proposta;
- cambi di Impostazioni;
- cambi di permessi.

## 26.11 Approvazione esterna

L'accordo economico può essere raggiunto fuori dal software.

Il sistema registra il fatto e le evidenze disponibili, senza richiedere firme elettroniche, voti o escalation.


---

# 27. Casistiche operative e stress test

Questa sezione è esplicativa. I comportamenti derivano dalle sezioni normative precedenti.

## 27.1 Preventivo superiore al costo reale

```text
Stima:      5.000 €
Effettivo:  4.200 €
```

Risultato:

- Progetto Pianificato/Aperto → Residuo 800 €;
- Progetto Chiuso → Risparmio 800 €;
- Spesa autonoma o Contratto → Scostamento Operativo -800 € senza deduzione della causa.

Il preventivo non diventa un'entità autonoma.

## 27.2 Preventivo inferiore al costo reale

```text
Stima:      5.000 €
Effettivo:  6.500 €
```

Risultato:

```text
Scostamento Operativo: +1.500 €
```

Il sistema registra una Sovraspesa quando la sorgente è un Progetto e richiede la Nota secondo le Impostazioni.

## 27.3 Spesa improvvisa

Un guasto urgente non presente nel Budget può essere rappresentato come:

- nuova Spesa autonoma;
- nuova Spesa dentro un Progetto esistente;
- nuovo Progetto Aperto;
- Effettivo senza Stima, se non esisteva pianificazione.

A livello della sorgente di primo livello viene etichettato `Non previsto` rispetto al Budget selezionato.

## 27.4 Fattura o costo conosciuto in ritardo durante l'Esercizio Aperto

L'utente registra l'Effettivo nell'Esercizio dichiarato.

Il sistema:

- aggiorna i totali;
- non deduce che la fattura fosse in ritardo;
- non richiede numero o data fattura;
- non collega l'Effettivo a una specifica Stima;
- richiede Nota se la sorgente è nuova rispetto al Budget.

## 27.5 Omissione scoperta dopo la Chiusura

Il 2026 è Chiuso con:

```text
Effettivo alla Chiusura: 100.000 €
```

Nel 2027 si scopre un costo di 2.000 € realmente appartenente al 2026.

Con il permesso corretto si registra una correzione tardiva 2026:

```text
Effettivo alla Chiusura:         100.000 €
Correzioni tardive positive:       2.000 €
Effettivo a Conoscenza Corrente: 102.000 €
```

Budget, Snapshot di Chiusura e Riporto restano invariati.

## 27.6 Pagamento sostenuto nell'anno successivo

Un importo relativo commercialmente al 2026 viene realmente sostenuto nel 2027.

Risultato:

- Effettivo 2027;
- Nota che può richiamare il 2026;
- nessuna correzione del 2026;
- nessuna competenza contabile.

## 27.7 Pagamenti o registrazioni parziali

```text
Stima:             10.000 €
Primo Effettivo:    3.000 €
Secondo Effettivo:  2.000 €
```

Risultato:

```text
Effettivo totale: 5.000 €
```

Il sistema non stabilisce quali quote della Stima siano state coperte e non determina se manchino rate o fatture.

## 27.8 Effettivo autonomo inserito in una nuova Spesa

Budget:

```text
Spesa autonoma NAS prevista
Stima: 1.000 €
```

Situazione corrente:

```text
Spesa NAS prevista
Stima: 1.000 €

Nuova Spesa autonoma fattura NAS
Effettivo: 1.000 €
```

Poiché l'utente ha creato due Spese autonome, ha dichiarato due sorgenti distinte:

- la prima può risultare `Prevista e non avvenuta`;
- la seconda può risultare `Non prevista`.

Per dichiarare che Stima ed Effettivo riguardano la stessa sorgente autonoma, l'Effettivo deve essere inserito nella stessa Spesa.

Il sistema non effettua riconciliazioni automatiche.

## 27.9 Effettivi separati dentro un Progetto o Contratto

Se la Stima è in una Spesa figlia e l'Effettivo in un'altra Spesa dello stesso Progetto o Contratto:

- la sorgente di primo livello resta unica;
- `Previsto` e `Non previsto` vengono valutati sul Progetto o Contratto;
- le Spese figlie vengono mostrate con fatti strutturali neutrali;
- non esiste matching fra le Spese.

## 27.10 Contratto annuale fatturato mensilmente senza rinnovo

```text
Durata:              01/01/2026 → 31/12/2026
Ciclo economico:     Mensile
Rinnovo automatico:  No
```

Il sistema genera dodici cicli economici nel 2026.

Dal 01/01/2027 il Contratto risulta Cessato e non genera nuovi cicli.

## 27.11 Contratto con rinnovo automatico

```text
Prossima scadenza:    31/12/2026
Rinnovo automatico:   Sì
Durata rinnovo:       12 mesi
Ciclo economico:      Mensile
```

Alla scadenza, in assenza di cessazione:

- il Contratto resta Attivo;
- viene registrato il rinnovo;
- la prossima scadenza diventa 31/12/2027;
- le condizioni correnti continuano;
- gli Esercizi Aperti vengono ricalcolati.

Non è necessario ricreare manualmente il Contratto o le Stime annuali.

## 27.12 Contratto cessato e successivamente ripreso

```text
Attivo fino al:       31/03/2026
Riattivato dal:       10/06/2026
```

Il sistema conserva:

- cessazione;
- intervallo senza attività contrattuale;
- nuova attivazione;
- nuova scadenza;
- nuove condizioni economiche.

L'intervallo 01/04–09/06 non genera Stime.

Non esiste uno stato `Sospeso`.

## 27.13 Modifica economica nel mezzo del ciclo

Contratto annuale ancorato al 12 marzo.

La modifica viene confermata il 18 maggio 2026 con richiesta dal 1° giugno.

Il sistema mostra:

```text
Data richiesta:               01/06/2026
Data minima richiedibile:     01/06/2026
Data effettiva applicabile:   12/03/2027
Motivo: il ciclo corrente è già iniziato
Prorata applicato:            No
```

L'utente deve confermare la data effettiva.

Il sistema non crea due cicli annuali e non applica prorata.

## 27.14 Contratto pianificato ma mai attivato

Budget 2027:

```text
Contratto SOC
Allocato Approvato: 12.000 €
Stato previsto: Pianificato
```

Prima dell'attivazione l'accordo non entra in vigore.

Negli Esercizi Aperti:

- il Contratto viene Annullato con motivo;
- le condizioni future vengono Annullate;
- l'Allocato Corrente diventa zero;
- il Budget resta a 12.000 €;
- il report mostra la variazione e applica `Previsto e non avvenuto` quando il riferimento finale è la Chiusura oppure mostra il Contratto Annullato, secondo il §25.9.

## 27.15 Effettivo contrattuale inferiore alla Stima

```text
Allocato Contratto: 12.000 €
Effettivo:           11.000 €
```

Il sistema mostra:

```text
Scostamento Operativo: -1.000 €
```

Non deduce che manchi una mensilità, una fattura o un pagamento.

## 27.16 Canone più setup o consumo variabile

```text
Canone ricorrente: 300 €/mese
Setup iniziale:    1.500 €
Consumo:           variabile
```

Il motore del Contratto rappresenta il canone.

Setup e componente variabile pianificata vengono registrati come Spese autonome o di Progetto distinte.

Il report del Contratto non include automaticamente tali sorgenti, salvo una relazione informativa senza effetto economico.

## 27.17 Progetto approvato ma mai iniziato

Budget:

```text
Progetto DLP
Stato: Pianificato
Allocato: 15.000 €
```

Alla Chiusura l'utente può:

- mantenerlo Pianificato e usare Riporto;
- mantenerlo Pianificato e usare Riprogrammazione;
- Cancellarlo con Riporto zero.

Se viene Cancellato senza Effettivi, riceve `Previsto e non avvenuto` e `Cancellato`.

## 27.18 Progetto non previsto

Durante l'anno nasce un intervento urgente:

```text
Nuovo Progetto Aperto
Stima:      5.000 €
Effettivo:  6.200 €
```

Risultato rispetto al Budget selezionato:

- `Non previsto`;
- Scostamento Operativo +1.200 €;
- Nota obbligatoria per la nuova sorgente post-Budget;
- nessuna modifica automatica del Budget.

## 27.19 Rinvio tramite Riporto

```text
Progetto Wi-Fi 2026
Stime:      10.000 €
Effettivi:       0 €
Modalità:   Riporto
```

Il sistema:

- mantiene le Stime nel 2026;
- consente un Riporto provvisorio entro 10.000 €;
- blocca la copia/riprogrammazione canonica delle stesse Stime verso il 2027;
- consolida alla Chiusura un importo scelto entro il massimo disponibile.

## 27.20 Rinvio tramite Riprogrammazione

```text
Progetto Wi-Fi 2026
Stime vive: 10.000 €
Modalità:   Riprogrammazione
```

Il sistema:

- richiede un Importo Riprogrammato non superiore alla disponibilità massima riportabile;
- riduce l'Allocato 2026 esattamente di tale importo, tramite riduzione o Annullamento delle Righe Stima oppure Storno di Spese prive di Effettivi;
- crea nuove Stime 2027 dello stesso importo con nuove identità;
- conserva CopiedFromOriginKey quando usa la copia canonica;
- imposta il Riporto a zero;
- lascia invariato l'eventuale Budget 2026 già approvato.

## 27.21 Ripartizione fra Riporto e Riprogrammazione

La richiesta:

```text
4.000 € come Riporto
6.000 € come Riprogrammazione
```

sullo stesso Progetto e passaggio d'anno viene bloccata.

L'utente deve scegliere una sola modalità per l'intero Progetto.

## 27.22 Rimborso e limite del Riporto

```text
Allocato:             10.000 €
Effettivo:            -1.000 €
Residuo:              11.000 €
Massimo riportabile:  10.000 €
```

Il rimborso riduce l'Effettivo ma non crea disponibilità riportabile oltre l'Allocato.

Con Allocato zero, il massimo riportabile è zero.

## 27.23 Cambio di Centro di Costo durante l'anno

Un Progetto viene riclassificato a luglio da `Infrastruttura` a `Applicazioni`.

Poiché la classificazione è annuale:

- tutto l'Esercizio viene riclassificato;
- anche gli Effettivi già registrati cambiano aggregazione;
- il sistema mostra l'impatto completo;
- richiede motivo dopo un Budget Approvato;
- non esiste una ripartizione gennaio–giugno/luglio–dicembre.

## 27.24 Spostamento di una Spesa con Effettivi in un Esercizio Aperto

```text
Spesa:
Stima:      8.000 €
Effettivo:  3.000 €
Da Progetto A a Progetto B
```

Nell'operatività viva il sistema:

- trasferisce integralmente Stime ed Effettivi;
- mostra valori rimossi da A e aggiunti a B;
- verifica lo stato di B;
- applica la classificazione di B;
- richiede motivo;
- aggiorna tutto atomicamente.

La stessa operazione non è consentita nella Proposta.

## 27.25 Riposizionamento del solo piano nella Proposta

La Spesa possiede:

```text
Stima:      8.000 €
Effettivo:  3.000 €
Progetto A
```

Nella Proposta si vuole pianificare il residuo su Progetto B.

Il sistema:

- lascia i 3.000 € Effettivi in A;
- riduce o azzera la Stima in A;
- crea una nuova Spesa pianificata in B;
- non crea matching fra le due Spese;
- registra la motivazione del riposizionamento.

## 27.26 Errore storico di Centro di Costo

Il 2025 Chiuso mostra:

```text
10.000 € → Amministrazione
```

Successivamente si ritiene corretto:

```text
10.000 € → Produzione
```

Il sistema:

- non riclassifica i valori;
- non crea Effettivi compensativi;
- mantiene Chiusura e Conoscenza Corrente su Amministrazione;
- registra un'Annotazione di errore storico;
- mostra l'annotazione nei report.

## 27.27 Errore di Riporto scoperto dopo la Chiusura

Riporto consolidato 2026→2027:

```text
Registrato: 4.000 €
Ritenuto corretto: 6.000 €
```

Il sistema:

- mantiene il Riporto storico a 4.000 €;
- annota l'errore;
- consente, nel 2027 Aperto, una modifica esplicita del piano di +2.000 €;
- non etichetta i 2.000 € come Riporto;
- richiede una Revisione se si vuole aggiornare il Budget Approvato 2027.

## 27.28 Esercizio chiuso accidentalmente

L'Esercizio resta Chiuso.

Il sistema:

- registra l'Annotazione di errore storico;
- consente solo correzioni tardive ammesse;
- non riapre Stime, classificazioni o Riporti;
- registra le nuove decisioni nell'Esercizio Aperto il cui anno è dichiarato dall'utente secondo il §11.3.

## 27.29 Condizione che interessa più anni

Sono Aperti 2027 e 2028.

Una nuova condizione dal 01/07/2027 genera Stime in entrambi.

Il sistema:

- mostra l'impatto 2027 e 2028;
- blocca e rivalida entrambi;
- applica atomicamente;
- lascia invariati i Budget già approvati;
- marca Da riallineare eventuali Proposte aperte coinvolte.

## 27.30 Realtà modificata mentre la Proposta è aperta

La Proposta modifica il piano di un Contratto.

Nel frattempo, nella realtà viene aggiunto un Effettivo o modificata una condizione.

L'intero Contratto diventa `Da riallineare`.

L'utente deve scegliere Ricarica realtà, Mantieni proposta o Rivedi manualmente.

Non esiste merge automatico per campo.



## 27.31 Progetto con Effettivi nell'anno successivo mentre il precedente è Aperto

Il Progetto ha attività ordinaria nel 2027 mentre il 2026 non è ancora Chiuso.

Alla Chiusura 2026:

- una decisione di Chiusura o cancellazione efficace al 31/12/2026 è ammessa soltanto se esiste in 2027 una transizione a Pianificato/Aperto per le Stime e ad Aperto per l'attività Effettiva ordinaria;
- in assenza della transizione richiesta, la Chiusura viene bloccata;
- Effettivi 2027 dichiarati tardivi, di cessazione o correttivi possono restare associati a un Progetto Chiuso o Cancellato secondo il §16.5, con Nota.

Lo stato è valutato per data, non come valore globale unico.

## 27.32 Sorgente Archiviata mentre la Proposta è aperta

L'Archivio non rimuove economicamente la sorgente.

Se la sorgente soddisfa il predicato di inclusione automatica della Proposta del §7.6.2:

- resta visibile nella Proposta in sola lettura;
- la sorgente diventa Da riallineare;
- non viene trattata come assente;
- per nuove azioni ordinarie deve prima essere ripristinata dall'Archivio.

## 27.33 Sorgente con soli Effettivi prima di una Revisione

Una sorgente non prevista possiede Effettivi e viene inclusa automaticamente nella Proposta di Revisione.

Se la Revisione la mantiene con Allocato zero:

- la sorgente è presente nella nuova Snapshot di Budget;
- resta `Non prevista` economicamente rispetto a quella versione;
- l'Effettivo non entra nella baseline.

Per renderla prevista nella nuova versione, deve essere approvato un Allocato positivo.

## 27.34 Plafond semplice

```text
Spesa autonoma Accessoristica
Stima:      2.000 €
Effettivi:    330 €
```

Risultato:

```text
Allocato:    2.000 €
Effettivo:     330 €
Scostamento: -1.670 €
```

Il sistema non calcola quanto verrà utilizzato a fine anno.

Se gli acquisti sono registrati in Spese autonome separate, non vengono automaticamente sottratti da questa disponibilità.

## 27.35 Importo unitario inferiore a un centesimo

```text
Quantità:          1.000
Importo unitario:  0,005 €
Importo totale:    5,00 €
```

L'Importo autoritativo di 5,00 € è rappresentabile.

Quantità e unitario restano descrittivi e possono avere precisione tecnica sufficiente senza determinare il valore economico salvato.

## 27.36 Effettivo dichiarato per un momento futuro dello stesso anno

A febbraio 2027 l'utente inserisce un Effettivo nell'Esercizio 2027 che in realtà non è ancora avvenuto.

La baseline non possiede una Data Effettivo e non può rilevarlo.

La dichiarazione annuale dell'utente è autoritativa.

Questa è una limitazione deliberata e impedisce analisi mensili affidabili.

## 27.37 Documento esterno riferito a più sorgenti

Una fattura esterna contiene:

```text
5.000 € → Progetto Firewall
4.000 € → Contratto Licenze
3.000 € → Spesa autonoma Hardware
```

Il sistema registra tre Spese economiche distinte.

Il numero o riferimento del documento può essere riportato nelle Note, ma:

- non esiste un'entità Fattura;
- non esiste deduplicazione automatica;
- il sistema non conserva un'unità documentale strutturata.

## 27.38 Contratto Cessato con addebito tardivo

Dopo la cessazione arriva un costo finale o un rimborso.

Il sistema consente un Effettivo manuale sul Contratto Cessato:

- con Nota obbligatoria;
- senza riattivazione;
- senza nuova Stima automatica;
- con normale aggiornamento di Effettivo e Scostamento.

## 27.39 Rinnovi automatici non materializzati per più periodi

Un Contratto annuale con rinnovo automatico ha prossima scadenza 31/12/2024, ma il sistema viene elaborato nel 2027.

Il sistema:

- registra in ordine i rinnovi 2024→2025, 2025→2026 e 2026→2027;
- avanza la prossima scadenza fino alla prima data futura;
- non duplica gli eventi in caso di retry;
- ricalcola soltanto gli Esercizi Aperti;
- non riscrive Esercizi Chiusi.

## 27.40 Terminazione del rapporto con il cliente

Alla Chiusura di `N`, `N+1` non esiste e la gestione non continuerà.

L'utente seleziona `Gestione terminata`.

Il sistema:

- richiede Riporti zero;
- non crea `N+1`;
- registra l'evento;
- conserva tutti i dati storici.

Se `N+1` esiste già, non viene eliminato automaticamente.

## 27.41 Acquisto in valuta estera

Un servizio è quotato in USD.

Il sistema registra:

- Stima manuale in EUR;
- Effettivo in EUR netto IVA dichiarato dall'utente.

Non conserva strutturalmente:

- importo USD;
- cambio;
- differenza cambio;
- conversione automatica.

## 27.42 Contributo, voucher o credito

```text
Costo Progetto: 20.000 €
Contributo atteso: 8.000 €
```

La baseline governa la spesa lorda.

Un rimborso realmente ottenuto può essere registrato come Effettivo negativo quando produce un effetto reale.

Una Stima negativa del contributo non è ammessa e il sistema non governa crediti fiscali futuri.

## 27.43 Costo condiviso fra reparti

Un Contratto di 24.000 € serve più reparti.

La baseline consente un solo Centro di Costo per Contratto ed Esercizio.

L'utente deve scegliere:

- un Centro di Costo responsabile principale;
- oppure dividere la realtà in sorgenti economiche distinte fuori dal Contratto, accettando la perdita dell'unità originaria.

Non sono supportate percentuali o allocazioni molti-a-molti.

## 27.44 Progetto con Contratti collegati

Un Progetto di migrazione costa 15.000 € e un Contratto collegato costa 24.000 €.

Il totale aziendale include entrambe le sorgenti una sola volta.

La relazione informativa consente navigazione, ma il report del Progetto non somma automaticamente il Contratto.

Non esiste un TCO automatico dell'iniziativa.

## 27.45 Nuova Spesa dopo il Budget senza spiegazione

La creazione di una nuova sorgente con Allocato o Effettivo diverso da zero dopo l'approvazione viene bloccata finché non è inserito un motivo.

Il report può quindi mostrare `Non previsto` insieme all'evento esplicativo.

## 27.46 Fornitore Archiviato in una correzione tardiva

Una Spesa storica usa un Fornitore oggi Archiviato.

La correzione tardiva:

- mantiene il Fornitore storico;
- può creare una nuova Spesa tardiva nello stesso contenitore con quel Fornitore;
- non rende il Fornitore selezionabile per nuove attività ordinarie.

## 27.47 Sorgente a zero ma oggetto di una decisione

Un Progetto Pianificato a zero oppure un Contratto con Stima zero può essere incluso in una Proposta e in un Budget quando esiste una decisione di stato, scadenza o condizione.

La sorgente:

- non contribuisce ai totali;
- resta materializzata per spiegare la decisione;
- non è prevista economicamente finché l'Allocato approvato è zero.

## 27.48 Due alternative di Budget

La baseline consente una sola Proposta attiva per Azienda ed Esercizio.

Due alternative parallele non possono essere confrontate nativamente.

L'utente deve:

- modificare la stessa Bozza;
- oppure gestire un'alternativa fuori dal sistema.

## 27.49 Scadenza contrattuale e scadenza fattura

```text
Prossima scadenza Contratto: 31/12/2027
Data limite disdetta:        30/09/2027
```

Queste date appaiono nella sezione Scadenze.

Il sistema non mostra:

- fatture da pagare;
- rate scadute;
- termini 30/60 giorni;
- insoluti.

## 27.50 Promemoria futuro

La presenza di prossima scadenza e Data limite di disdetta rende possibile una futura funzione di promemoria.

Finché tale funzione non viene specificata:

- il sistema mostra le date;
- offre filtri;
- non promette notifiche automatiche;
- non considera l'assenza di una notifica un errore del dominio corrente.

## 27.51 Residuo positivo non trasferito

```text
Allocato Progetto:   10.000 €
Effettivo:             7.000 €
Residuo:               3.000 €
```

Il Progetto prosegue, ma l'utente non vuole trasferire alcun importo.

Risultato:

```text
Modalità: Nessuna
Riporto:  0 €
Importo Riprogrammato: 0 €
```

La parte non trasferita non genera altri movimenti.

## 27.52 Progetto temporaneamente fermo

Un Progetto Aperto è temporaneamente fermo in attesa di una decisione o di un fornitore.

La baseline non introduce lo stato `Sospeso`:

- se il Progetto resta in esecuzione, rimane Aperto e la pausa viene annotata;
- se viene formalmente concluso, viene Chiuso e potrà essere riaperto con una nuova transizione;
- l'assenza di movimenti non modifica automaticamente lo stato.

## 27.53 Un costo da ripartire fra più sorgenti

Un documento esterno o una fornitura comprende costi attribuibili a più Progetti, Contratti o Centri di Costo.

L'utente crea Spese distinte per le quote economicamente attribuite. Il sistema:

- non consente una singola Spesa con più contenitori;
- non conserva una ripartizione percentuale strutturata;
- può conservare il riferimento comune nelle Note;
- somma ogni quota una sola volta.

## 27.54 Contratto esistente registrato tardivamente nel software

Un Contratto corrente è iniziato in un anno già Chiuso ma viene censito solo oggi.

Il sistema:

- conserva la Data di inizio contrattuale reale;
- calcola stato, scadenze e rinnovi correnti;
- genera o ricalcola Stime soltanto negli Esercizi Aperti;
- non inserisce retroattivamente il Contratto in Budget o Snapshot di Chiusura già esistenti;
- registra nella Timeline la data di censimento;
- se l'assenza storica deve essere spiegata, consente un'Annotazione di errore storico senza modificare le Snapshot.

Lo stesso principio si applica a un Progetto esistente censito tardivamente: identità e stato corrente possono essere registrati, ma Esercizi e Snapshot Chiusi non vengono riscritti.

## 27.55 Duplicazione tecnica e duplicazione amministrativa

Un retry della stessa operazione con lo stesso ID **MUST NOT** creare una seconda Riga o un secondo evento.

Due inserimenti distinti effettuati dall'utente, invece, sono due fatti distinti per il sistema anche se hanno stesso importo, Fornitore e Descrizione. Senza un identificativo documentale strutturato il software non può stabilire che rappresentino la stessa fattura o pagamento.

---

# 28. Invarianti

## 28.1 Tipi economici

```text
TipoRiga ∈ {Stima, Effettivo}
```

## 28.2 Presenza di Effettivi

```text
HaEffettivi = esiste almeno una Riga Effettivo Attiva con Importo diverso da zero
```

Il totale netto zero non implica assenza di Effettivi.

## 28.3 Importo autoritativo

```text
ValoreEconomicoRiga = Riga.Importo
```

Quantità e Importo unitario non sostituiscono l'Importo salvato.

## 28.4 Appartenenza esclusiva

```text
NOT (Spesa.Progetto != null AND Spesa.Contratto != null)
```

## 28.5 Sorgenti di primo livello

```text
SorgentePrimoLivello ∈ {SpesaAutonoma, Progetto, Contratto}
```

## 28.6 Nessun matching

```text
Stima !→ Effettivo
CicloContratto !→ Effettivo
Riporto !→ Effettivo
```

## 28.7 Allocato della Spesa autonoma

```text
AllocatoSpesaAutonoma = somma Stime della Spesa
```

## 28.8 Allocato del Progetto

```text
AllocatoProgetto = RiportoRicevuto + somma StimeAnno
```

## 28.9 Allocato del Contratto

```text
AllocatoContratto = StimaAnnualeGenerata
```

## 28.10 Scostamento Operativo

```text
ScostamentoOperativo = Effettivo - AllocatoCorrente
```

## 28.11 Residuo e disponibilità riportabile

```text
ResiduoProgetto = max(AllocatoProgetto - EffettivoProgetto, 0)

DisponibilitàMassimaRiportabile =
min(ResiduoProgetto, AllocatoProgetto)
```

## 28.12 Riporto entro il limite

```text
Se Modalità = Riporto:

0 < RiportoProvvisorio ≤ DisponibilitàMassimaRiportabileCorrente
0 < RiportoConsolidato ≤ DisponibilitàMassimaRiportabileAllaChiusura

Se Modalità ∈ {Nessuna, Riprogrammazione}:

RiportoProvvisorio = 0
RiportoConsolidato = 0
```

## 28.13 Effettivo negativo

Un Effettivo negativo **MUST NOT** rendere riportabile un importo superiore all'Allocato.

## 28.14 Riporto esclusivo del Progetto

```text
RiportoContratto = 0
RiportoSpesaAutonoma = 0
```

## 28.15 Modalità di rinvio

Per ogni `Progetto + N + N+1`:

```text
Modalità ∈ {Nessuna, Riporto, Riprogrammazione}
```

Deve inoltre valere:

```text
Modalità = Nessuna          → Riporto = 0 AND ImportoRiprogrammato = 0
Modalità = Riporto          → ImportoRiprogrammato = 0
Modalità = Riprogrammazione → Riporto = 0
```

Per la Riprogrammazione, al momento dell'esecuzione:

```text
0 < ImportoRiprogrammato ≤ DisponibilitàPreOperazione
RiduzioneAllocatoOrigine = IncrementoStimeDestinazione = ImportoRiprogrammato
```

L'operazione è idempotente e non viene invalidata retroattivamente da Effettivi successivi.

## 28.16 Progetto terminale

Per un Progetto Chiuso o Cancellato al 31 dicembre:

```text
Modalità = Nessuna
RiportoConsolidato = 0
```

## 28.17 Budget immutabile

Ogni versione approvata è immutabile.

`v1` non viene sovrascritta né eliminata.

## 28.18 Revisioni

Finché l'Esercizio è Aperto, una Revisione **MUST** essere possibile per un utente autorizzato.

## 28.19 Proposta isolata

Una Proposta in Bozza non modifica la realtà.

## 28.20 Proposta solo sul piano

La Proposta **MUST NOT** creare, modificare, spostare o riclassificare Effettivi.

## 28.21 Nuovi oggetti proposti

Un nuovo oggetto esiste soltanto come ProposalItem fino all'approvazione.

## 28.22 Riallineamento per sorgente

Qualsiasi modifica elencata nel §12.11 invalida la revisione base dell'intera sorgente nella Proposta.

Non esiste merge automatico per campo.

## 28.23 Approvazione atomica

L'approvazione è interamente applicata a tutti gli Esercizi Aperti interessati oppure non produce effetti.

## 28.24 Stato alla data

```text
StatoStorico = StatoAllaData(DataRiferimento)
```

La data tecnica dell'operazione non sostituisce la data economica.

## 28.25 Stato alla Chiusura

```text
DataRiferimentoStatoChiusura = 31 dicembre dell'Esercizio
```

## 28.26 Nessuna riapertura

```text
Esercizio.Chiuso !→ Esercizio.Aperto
```

## 28.27 Chiusura atomica

Una Chiusura è interamente applicata oppure non produce effetti.

## 28.28 Chiusura cronologica

Un Esercizio non può essere Chiuso se ne esiste uno precedente Aperto.

## 28.29 Correzioni tardive

Le correzioni tardive aggiungono soltanto Righe Effettivo append-only.

## 28.30 Nessuna riclassificazione storica

Dopo la Chiusura non vengono modificati:

- Centro di Costo;
- Progetto;
- Contratto;
- contenitore;
- Fornitore;
- Esercizio;
- stato storico.

Gli errori vengono annotati.

## 28.31 Riporto storico invariato

Correzioni tardive e Annotazioni di errore storico non ricalcolano il Riporto consolidato.

## 28.32 Durata e ciclo contrattuale distinti

```text
DurataContratto != CicloEconomico
```

## 28.33 Rinnovo automatico

Con rinnovo automatico e scadenza definita, in assenza di cessazione:

- il Contratto resta Attivo;
- la prossima scadenza viene avanzata della Durata del rinnovo;
- non è richiesta ricreazione manuale annuale.

## 28.34 Condizioni non sovrapposte

Le condizioni Valide dello stesso Contratto non si sovrappongono.

## 28.35 Decorrenza delle modifiche contrattuali

Per una modifica ordinaria:

```text
DataEffettivaApplicabile ≥ primo giorno del mese successivo
```

e coincide con un InizioCiclo della condizione corrente.

## 28.36 Nessun differimento silenzioso

Data richiesta, minima ed effettiva devono essere mostrate e confermate.

## 28.37 Ricorrenze ancorate

Ogni ricorrenza è calcolata dal `Valido dal` originario della propria condizione.

## 28.38 Data di attribuzione

La Data di attribuzione della Stima determina soltanto l'Esercizio della Stima e non rappresenta una scadenza di pagamento.

## 28.39 Nessun prorata

Il motore contrattuale non applica prorata automatici.

## 28.40 Stima di Contratto

Per Contratto ed Esercizio esiste al massimo una Spesa Stima di sistema.

Una Spesa già materializzata può restare a zero; una nuova Spesa non viene creata soltanto per zero.

## 28.41 Spese manuali di Contratto

Contengono soltanto Effettivi.

## 28.42 Classificazione annuale

Una sorgente ha al massimo un Centro di Costo per Esercizio.

Un cambio riclassifica l'intero Esercizio Aperto.

## 28.43 Ereditarietà del Centro di Costo

Una Spesa associata eredita il Centro di Costo annuale del contenitore.

## 28.44 Archivio non economico

L'Archivio non rimuove importi, non cambia stato economico e non modifica Snapshot.

## 28.45 Nessuna cancellazione fisica ordinaria

Un oggetto persistito del dominio non viene eliminato fisicamente tramite operazioni ordinarie.

## 28.46 Identità non riutilizzabile

Gli ID non vengono riutilizzati.

## 28.47 Snapshot autonome

La leggibilità di una Snapshot non dipende dall'esistenza o selezionabilità della sorgente viva.

## 28.48 Schema Budget

La Snapshot di Budget non contiene Effettivi, Residuo o Scostamento come valori della baseline.

## 28.49 Schema Chiusura

La Snapshot di Chiusura contiene Allocato finale, Effettivo alla Chiusura, stato al 31 dicembre e decisioni di Riporto.

## 28.50 Previsto e Non previsto

Le etichette si applicano soltanto alle sorgenti di primo livello.

## 28.51 Categorie del confronto

Ogni sorgente ha esattamente una categoria primaria.

Le etichette secondarie possono sovrapporsi senza modificare il conteggio primario.

## 28.52 Nessun doppio conteggio

Ogni importo contribuisce una sola volta ai totali e alle aggregazioni.

## 28.53 Valuta e IVA

```text
Valuta = EUR
IVA gestita = No
Importi = netti IVA
```

## 28.54 Annualità dell'Effettivo

L'Effettivo appartiene all'Esercizio dichiarato dall'utente entro i controlli annuali del sistema.

## 28.55 Copia fra Esercizi

Una copia canonica:

- riceve nuova identità;
- conserva CopiedFromOriginKey;
- non copia Effettivi.

## 28.56 Scadenze contrattuali

Le scadenze dei Contratti sono informative e distinte dalle scadenze di fatture o pagamenti.

## 28.57 Permessi per Azienda

Ogni capacità è assegnata per Azienda e non si propaga automaticamente ad altre Aziende.

## 28.58 Esercizio successivo

Alla Chiusura `N+1` viene creato soltanto se la gestione continua e non esiste già.

## 28.59 Nessun Forecast

```text
Forecast = non supportato
PrevisioneAFinire = non supportata
```

## 28.60 Relazioni informative

La relazione informativa `Collegato a` non trasferisce valori, stato, classificazione o Riporto.

## 28.61 Plafond

Il Plafond non introduce entità, tipo, flag, stato o report dedicato.


---

# 29. Indice dei Requisiti Funzionali

Questa sezione è un indice di tracciabilità. Le regole normative sono esclusivamente quelle delle sezioni richiamate.

| ID | Requisito indicizzato | Fonte normativa |
|---|---|---|
| FR-001 | Realtà operativa unica | §5.1 |
| FR-002 | Soli tipi Stima ed Effettivo | §5.2 |
| FR-003 | Importo autoritativo della Riga | §8.1 |
| FR-004 | Nessun matching | §5.3 |
| FR-005 | Appartenenza esclusiva della Spesa | §7.2 |
| FR-006 | Sorgenti economiche di primo livello | §5.6 |
| FR-007 | Nessun doppio conteggio | §§5.6, 8.6 |
| FR-008 | Identità stabile e OriginKey | §5.4 |
| FR-009 | Nessuna cancellazione fisica ordinaria | §§5.7, 24.1 |
| FR-010 | Archivio non economico | §§5.8, 24.3 |
| FR-011 | Budget Approvato immutabile | §§1.2, 13 |
| FR-012 | Versione iniziale v1 preservata | §13.4 |
| FR-013 | Revisioni sempre disponibili in Esercizio Aperto | §§12.3, 13.4, 26.3 |
| FR-014 | Versione Budget esplicita nei report | §§13.7, 25 |
| FR-015 | Proposta isolata | §§1.3, 12.1 |
| FR-016 | Una Proposta attiva per Esercizio | §12.2 |
| FR-017 | Proposta limitata al piano | §12.6 |
| FR-018 | Inizializzazione deterministica della Proposta | §§7.6.2, 12.4 |
| FR-019 | Copia con nuova identità e lineage obbligatoria | §12.4 |
| FR-020 | Azioni di piano sulla Spesa | §12.7 |
| FR-021 | Azioni di piano sul Progetto | §12.8 |
| FR-022 | Azioni di piano sul Contratto | §12.9 |
| FR-023 | Relazioni tra nuovi ProposalItem | §12.10 |
| FR-024 | Riallineamento dell'intera sorgente | §§12.11–12.12 |
| FR-025 | Presa visione delle nuove sorgenti | §12.13 |
| FR-026 | Incoerenze della Proposta definite in modo chiuso | §12.14 |
| FR-027 | Precondizioni di approvazione | §§12.15, 13.2 |
| FR-028 | Approvazione atomica su tutti gli Esercizi interessati | §13.3 |
| FR-029 | Applicazione futura senza riscrivere Esercizi Chiusi | §§10, 13.3 |
| FR-030 | Scarto senza rollback della realtà | §12.16 |
| FR-031 | Più Esercizi Aperti | §11.1 |
| FR-032 | Divieto di Effettivi in un anno futuro | §11.3 |
| FR-033 | Dichiarazione annuale autoritativa dell'Effettivo | §§6.4, 11.3 |
| FR-034 | Chiusura cronologica | §11.5 |
| FR-035 | Nessuna riapertura globale | §§11.6, 14.9 |
| FR-036 | Stato di Chiusura al 31 dicembre | §§9.2, 14.1 |
| FR-037 | Controlli bloccanti di Chiusura | §14.3 |
| FR-038 | Avviso generale Allocato positivo e assenza di Effettivi | §14.4 |
| FR-039 | Chiusura atomica | §14.7 |
| FR-040 | Creazione condizionale di N+1 | §§11.7, 14.7 |
| FR-041 | Chiusura senza Budget | §14.2 |
| FR-042 | Correzione tardiva append-only | §24 |
| FR-043 | Distinzione Chiusura e Conoscenza Corrente | §§6.10–6.12, 24.11 |
| FR-044 | Nessuna riclassificazione economica storica | §§14.9, 24.10 |
| FR-045 | Errori post-Chiusura annotati e corretti solo negli anni Aperti | §14.9 |
| FR-046 | Struttura della Spesa | §15.1 |
| FR-047 | Correlazione manuale della Spesa autonoma | §§15.2, 25.3 |
| FR-048 | Spese manuali di Contratto con soli Effettivi | §15.4 |
| FR-049 | Unica Stima annuale di sistema del Contratto | §§15.4, 18.18 |
| FR-050 | Cambio Esercizio della Spesa | §15.7 |
| FR-051 | Cambio contenitore | §15.8 |
| FR-052 | Riclassificazione integrale di una Spesa con Effettivi | §15.9 |
| FR-053 | Storno soltanto senza Effettivi | §15.11 |
| FR-054 | Nessun tipo Imprevista, Preventivo o Plafond | §§15.12–15.14 |
| FR-055 | Stati del Progetto | §16.2 |
| FR-056 | Effettivi compatibili con lo stato del Progetto | §16.5 |
| FR-057 | Transizioni del Progetto valutate alla data | §§9.3, 16.4 |
| FR-058 | Avvisi di Sovraspesa | §16.8 |
| FR-059 | Modalità Nessuna, Riporto o Riprogrammazione mutuamente esclusive | §16.10 |
| FR-060 | Formule del Riporto | §§8.4, 17 |
| FR-061 | Effettivo negativo non genera Riporto oltre l'Allocato | §§17.5, 28.13 |
| FR-062 | Dati contrattuali, rinnovo e scadenza | §18.2 |
| FR-063 | Separazione durata, ciclo e rinnovo | §18.3 |
| FR-064 | Stato del Contratto alla data | §§9.4, 18.4 |
| FR-065 | Rinnovo automatico e avanzamento della scadenza | §18.5 |
| FR-066 | Preavviso e termine di disdetta informativi | §18.6 |
| FR-067 | Cessazione | §18.7 |
| FR-068 | Riattivazione | §18.8 |
| FR-069 | Annullamento prima dell'attivazione | §18.9 |
| FR-070 | Condizioni economiche del Contratto | §18.10 |
| FR-071 | Condizioni non sovrapposte | §18.11 |
| FR-072 | Fornitore del Contratto immutabile dopo uso economico | §18.12 |
| FR-073 | Decorrenza al primo confine di ciclo utile | §18.13 |
| FR-074 | Comunicazione e conferma della data effettiva | §18.13 |
| FR-075 | Ricorrenze ancorate | §18.14 |
| FR-076 | Data di attribuzione della Stima | §18.16 |
| FR-077 | Nessun prorata | §18.17 |
| FR-078 | Effettivi contrattuali manuali senza matching | §18.19 |
| FR-079 | Centro di Costo annuale | §20 |
| FR-080 | Cambio Centro di Costo sull'intero Esercizio | §20.3 |
| FR-081 | Ereditarietà del Centro di Costo | §20.5 |
| FR-082 | Fornitore e Referenti | §21 |
| FR-083 | Fornitore Archiviato utilizzabile nello storico | §§21.4, 24.6 |
| FR-084 | Timeline esplicativa append-only | §22 |
| FR-085 | Schemi separati delle Snapshot | §23 |
| FR-086 | Snapshot autonome | §§23.2, 23.13 |
| FR-087 | Previsto e Non previsto solo al primo livello | §§25.3–25.4 |
| FR-088 | Categoria primaria e etichette sovrapponibili | §§25.5–25.7 |
| FR-089 | Report con riferimenti espliciti | §§25.1–25.2 |
| FR-090 | Scadenze informative dei Contratti | §19 |
| FR-091 | Impostazioni minime per Azienda | §26 |
| FR-092 | Permessi assegnati per Azienda | §§26.5–26.6 |
| FR-093 | Audit di permessi e Impostazioni | §§26.8–26.10 |
| FR-094 | Operazioni inter-Esercizio atomiche | §10 |
| FR-095 | `Collegato a` senza effetto economico | §7.3 |
| FR-096 | Report separato delle correzioni e annotazioni | §§24.11, 25.13 |
| FR-097 | Approvazione economica esterna ammessa | §26.11 |
| FR-098 | EUR, netto IVA e anno solare | §4.3 |
| FR-099 | Evoluzione del dominio tramite categorie A–E | §3 |
| FR-100 | Nessun Forecast | §§1.4, 28.59 |
| FR-101 | Presenza di Effettivi distinta dal totale netto | §§6.4, 28.2 |

---

# 30. Requisiti di implementazione, esclusioni, limiti e verifica finale

## 30.1 Separazione fra modello logico e schema fisico

La specifica non impone:

- tabelle o documenti;
- database;
- linguaggio;
- framework;
- normalizzazione;
- caching;
- tecnologia degli allegati;
- presentazione grafica.

Qualunque scelta tecnica **MUST** preservare il comportamento osservabile, le formule, le transizioni, l'atomicità, l'immutabilità e l'audit.

## 30.2 Revisioni tecniche e concorrenza

Ogni sorgente usata da una Proposta **MUST** esporre un token di revisione monotono o meccanismo equivalente.

Ogni Esercizio **MUST** esporre una revisione dell'insieme delle sorgenti o meccanismo equivalente, modificata quando una sorgente entra o esce dai predicati di inclusione.

Un'operazione inter-Esercizio **MUST** verificare:

- revisioni di tutti gli Esercizi interessati;
- revisioni di tutte le sorgenti interessate;
- insieme delle sorgenti;
- stato delle Proposte concorrenti.

Non è richiesta una revisione per singolo campo.

## 30.3 Calcolo dello stato alla data

L'implementazione **MUST** fornire funzioni deterministiche equivalenti a:

```text
StatoProgettoAllaData(data)
StatoContrattoAllaData(data)
```

I test devono includere:

- transizione passata;
- transizione futura;
- transizione Annullata;
- sostituzione di una transizione futura;
- Chiusura eseguita in ritardo;
- riapertura nell'Esercizio successivo;
- cessazione e riattivazione;
- rinnovo automatico;
- scadenza senza rinnovo.

## 30.4 Piano d'impatto prima del salvataggio

Ogni operazione che può modificare più sorgenti o Esercizi **MUST** produrre prima del salvataggio un piano d'impatto con:

- Esercizi interessati;
- sorgenti interessate;
- Allocato precedente e nuovo;
- Effettivo riclassificato, quando applicabile;
- stato precedente e nuovo;
- Budget che resteranno invariati;
- Proposte che diventeranno Da riallineare;
- avvisi e blocchi.

Il piano confermato viene collegato all'evento di Timeline.

## 30.5 Calcolo delle date contrattuali

`add_months_anchored` **MUST** usare:

- giorno originario dell'ancora;
- ultimo giorno del mese quando il giorno non esiste;
- nessuna propagazione dello slittamento.

I test minimi includono:

- 28 e 29 febbraio;
- giorno 30;
- giorno 31;
- cambio anno;
- ciclo mensile, trimestrale, semestrale e annuale;
- modalità Inizio ciclo e Fine ciclo;
- data minima del mese successivo;
- successivo confine di ciclo;
- cessazione prima del confine;
- scadenza contrattuale prima del confine;
- prima condizione di un nuovo Contratto;
- prima condizione dopo riattivazione.

## 30.6 Rinnovo automatico idempotente

Il processo che materializza i rinnovi **MUST** essere idempotente.

Deve:

- identificare univocamente ogni scadenza rinnovata;
- non creare duplicati in caso di retry;
- gestire più rinnovi trascorsi;
- avanzare la prossima scadenza in ordine;
- ricalcolare soltanto Esercizi Aperti;
- non dipendere dal fatto che l'utente abbia aperto la schermata delle Scadenze.

Lo scheduling tecnico è libero, purché lo stato e le date visualizzati siano deterministici.

## 30.7 Stime di Contratto

La Spesa Stima di sistema **MUST** essere distinguibile dalle Spese manuali.

Il ricalcolo deve essere idempotente e può usare:

- rigenerazione;
- aggiornamento transazionale;
- hash del calcolo;
- altra tecnica equivalente.

La modifica manuale è vietata.

## 30.8 Snapshot materializzate

Le Snapshot **MUST** essere materializzate e autosufficienti.

Non possono risolvere dinamicamente:

- nomi correnti;
- classificazioni correnti;
- stati correnti;
- condizioni correnti;
- importi correnti.

I dati derivati possono essere persistiti per rendere il documento storico leggibile.

## 30.9 Allegati storici

Gli allegati decisionali devono usare storage immutabile o versionato.

La rimozione di un allegato dall'oggetto vivo **MUST NOT** rimuovere l'evidenza conservata in:

- approvazione;
- Revisione;
- Chiusura;
- correzione tardiva;
- Annotazione di errore storico.

## 30.10 Totali derivati e consistenza

Allocato, Effettivo, Scostamento, Residuo e disponibilità massima riportabile possono essere persistiti per performance.

Devono restare verificabili dalle sorgenti.

L'implementazione **MUST** impedire divergenze silenziose mediante ricalcolo idempotente, vincoli o controlli di consistenza.

## 30.11 Atomicità applicativa

Approvazione, Chiusura, Riprogrammazione, cambio della modalità di rinvio, spostamento di una Spesa con Effettivi e operazioni inter-Esercizio richiedono atomicità applicativa anche con più servizi o datastore.

Ogni Riprogrammazione e sua eventuale inversione **MUST** possedere un identificativo idempotente e riferimenti sufficienti a modificare esclusivamente le Righe create o ridotte da quella specifica operazione.

Notifiche e attività secondarie possono usare outbox o meccanismo equivalente, ma lo stato economico **MUST NOT** risultare parzialmente applicato.

## 30.12 Idempotenza dei comandi

Ogni comando mutativo **MUST** possedere un identificativo di operazione o un meccanismo equivalente che impedisca duplicazioni in caso di retry tecnico.

L'idempotenza è obbligatoria almeno per:

- creazione o modifica di Righe;
- approvazione e Revisione;
- Chiusura;
- Riprogrammazione e Riporto;
- rinnovi contrattuali;
- correzioni tardive;
- Storno, ripristino e Archivio.

Questa idempotenza tecnica non costituisce deduplicazione semantica di fatture, preventivi o pagamenti esterni.

## 30.13 Fuso orario

Le date economiche sono date locali dell'Azienda.

I timestamp tecnici sono conservati in UTC e visualizzati nel fuso aziendale.

Una data di scadenza o efficacia **MUST NOT** cambiare per effetto della conversione del timestamp tecnico.

## 30.14 Messaggi di dominio

Ogni blocco o differimento deve spiegare il motivo.

Esempi:

- una Spesa non può appartenere a Progetto e Contratto perché produrrebbe imputazione ambigua;
- una Stima manuale non può essere inserita in un Contratto perché le Stime contrattuali derivano dalle condizioni;
- una Proposta non può spostare una Spesa con Effettivi perché modificherebbe la realtà;
- una modifica contrattuale è differita al confine di ciclo per evitare prorata e sovrapposizioni;
- una Chiusura fuori ordine è vietata perché il Riporto dipende dall'anno precedente;
- una riclassificazione storica è vietata perché l'Esercizio è Chiuso;
- una sorgente deve essere riallineata perché la realtà è cambiata dopo la base della Proposta;
- Riporto e Riprogrammazione non possono coesistere per lo stesso passaggio d'anno.

## 30.15 Comportamenti espressamente esclusi

Salvo nuova decisione di dominio, il sistema **MUST NOT** introdurre:

- Forecast o Previsione a Finire;
- contabilità generale;
- competenza economica;
- ratei e risconti;
- impegni contabili;
- ordini di acquisto;
- fatture come oggetti obbligatori;
- scadenzario fatture;
- procurement;
- ledger o journal;
- multi-valuta;
- IVA;
- conversioni valutarie;
- tipi Preventivo, Imprevista, Plafond, Rettifica o Forecast;
- matching Stima → Effettivo;
- matching pagamento → ciclo;
- FIFO o LIFO;
- consumo del Riporto;
- Riporto automatico uguale al massimo;
- Riporto di Contratti o Spese autonome;
- ripartizione parziale Riporto/Riprogrammazione;
- prorata;
- rate o Effettivi automatici dei Contratti;
- stato contrattuale Sospeso;
- termini di pagamento 30/60 giorni;
- propagazione economica delle relazioni informative;
- ripartizioni molti-a-molti fra Centri di Costo;
- gerarchie obbligatorie di Centro di Costo;
- scenari paralleli;
- branching completo del dominio;
- merge per campo della Proposta;
- rollback globale della Proposta;
- riapertura globale di un Esercizio;
- riclassificazione economica dopo la Chiusura;
- ripristino del database da Snapshot;
- event sourcing;
- stato arbitrario a ogni timestamp;
- cancellazione fisica ordinaria degli oggetti persistiti;
- approval workflow multilivello obbligatorio;
- maker-checker obbligatorio;
- indicatori BAC, ETC, EAC o earned value;
- report automatico dei Plafond;
- TCO automatico Progetto più Contratti collegati;
- deduplicazione di documenti esterni;
- promemoria automatici finché non specificati.

## 30.16 Limiti noti e conseguenze concrete

### Effettivi annuali

Il sistema non distingue strutturalmente pagamento, fatturazione e maturazione: registra l'Esercizio dichiarato dall'utente secondo la convenzione operativa dell'Azienda.

Senza Data Effettivo strutturata non sono affidabili:

- Actual mensili;
- andamento trimestrale;
- ordinamento economico giornaliero;
- controllo di un evento futuro nello stesso anno.

### Documenti esterni

Senza entità e identificativi strutturati non sono supportati:

- deduplicazione fatture;
- stato della fattura;
- scadenza;
- rate;
- collegamento univoco a un pagamento.

### Contratti non esprimibili

Componenti variabili, setup, consumo, soglie, scaglioni e conguagli restano sorgenti separate.

Il report del Contratto non rappresenta necessariamente il costo complessivo dell'accordo commerciale.

### Scadenze senza promemoria

La baseline mostra e filtra scadenze e termini di disdetta, ma non invia notifiche.

### Centro di Costo unico

Un costo condiviso non può essere ripartito percentualmente.

### Multi-valuta e IVA

L'utente determina esternamente l'importo EUR netto IVA.

### Esercizio solare

Esercizi fiscali non solari richiedono una nuova decisione di dominio.

### Esercizio futuro già creato durante l'offboarding

Se `N+1` esiste già quando termina la gestione, non viene eliminato automaticamente. Deve restare nello storico e seguire il normale ciclo di vita; la baseline non introduce uno stato speciale di annullamento dell'Esercizio.

### Una sola Proposta

Non è possibile confrontare più alternative in Bozza.

### Nessun as-of arbitrario

La storia completa è disponibile nei punti materializzati e nella Timeline, non a ogni istante.

### Nessuna riclassificazione storica

Un errore di imputazione scoperto dopo la Chiusura resta nei valori e viene soltanto annotato.

### Nessuna riapertura

Una Chiusura errata non viene annullata. Gli effetti successivi vengono corretti negli Esercizi Aperti.

### Rinnovo senza scadenza

Un Contratto senza prossima scadenza non può generare un evento di rinnovo o un termine di disdetta calcolabile. Viene mostrato come `Scadenza non definita`.

### Relazione informativa `Collegato a`

Non produce TCO, inclusione economica o propagazione automatica.

### Plafond

Senza metadato dedicato non è possibile elencare automaticamente tutti i Plafond.

Una Spesa possiede un solo Fornitore diretto opzionale. Un Plafond utilizzato presso più Fornitori può:

- mantenere tutti gli Effettivi nella stessa Spesa, perdendo il dettaglio strutturato per Fornitore;
- oppure usare Spese separate, che il sistema non riconosce come utilizzi del Plafond.

### Quantità e unitario

Sono descrittivi e non sostituiscono l'Importo autoritativo.

### Controllo della duplicazione nel rinvio

Il sistema blocca le operazioni canoniche incompatibili, ma non può riconoscere una Stima manuale intenzionalmente ricreata con titolo o importo simile. La Nota e l'audit restano necessari.

Riporto e Riprogrammazione operano soltanto verso l'Esercizio immediatamente successivo. Un rinvio oltre `N+1` deve essere gestito nuovamente nel passaggio successivo oppure come nuova allocazione indipendente, con Nota esplicita.

## 30.17 Decisioni tecniche ancora libere

Restano liberamente selezionabili:

- schema fisico;
- database;
- indici;
- caching;
- codebase;
- API;
- tecnologia di storage;
- scheduler;
- strategia di locking;
- interfaccia grafica;
- formato delle esportazioni;
- meccanismo futuro di notifica.

Queste scelte **MUST NOT** cambiare il comportamento normativo.

## 30.18 Checklist di Definition of Done

Prima dell'implementazione la specifica supera la DoD soltanto se:

- [ ] tutte le domande supportate del §2.1 hanno una regola;
- [ ] tutte le domande escluse del §2.2 hanno una conseguenza esplicita;
- [ ] ogni stato è valutabile alla data;
- [ ] ogni operazione multi-Esercizio è atomica;
- [ ] Proposta, Budget, realtà e Chiusura non sono confusi;
- [ ] la Proposta non modifica Effettivi;
- [ ] Budget e Chiusura hanno schemi separati;
- [ ] Previsto e Non previsto sono deterministici;
- [ ] Riporto e Riprogrammazione sono esclusivi;
- [ ] un Effettivo negativo non crea Riporto oltre l'Allocato;
- [ ] rinnovo, durata e ciclo contrattuale sono distinti;
- [ ] una modifica contrattuale non produce prorata o differimenti silenziosi;
- [ ] gli errori dopo la Chiusura hanno un comportamento esplicito;
- [ ] Archivio, Storno e immutabilità non si contraddicono;
- [ ] ogni termine che blocca un'operazione ha un predicato;
- [ ] gli invarianti sono traducibili in test;
- [ ] gli FR rinviano a una sola fonte normativa;
- [ ] nessuna decisione economica è lasciata all'implementatore.

## 30.19 Formula corretta di una revisione indipendente

Una revisione può concludere correttamente:

> Non sono stati individuati casi appartenenti al perimetro dichiarato che violino gli invarianti, risultino privi di comportamento deterministico o richiedano una decisione economica non definita. Gli ulteriori casi individuati risultano varianti narrative, componibili con le primitive esistenti, informativi oppure esplicitamente fuori perimetro.

Non è corretta l'affermazione assoluta:

> Nessun nuovo caso potrà mai essere immaginato.


---

# Appendice A — Registro delle decisioni consolidate

Questa appendice riepiloga le decisioni che hanno chiuso i principali punti aperti. Le regole normative restano nelle sezioni richiamate.

| N. | Decisione | Riferimento |
|---:|---|---|
| 1 | La Snapshot di Chiusura usa lo stato valido al 31 dicembre dell'Esercizio | §§9.2, 14.1 |
| 2 | Le operazioni che interessano più Esercizi Aperti sono calcolate, mostrate, bloccate, rivalidate e applicate atomicamente | §10 |
| 3 | Una modifica economica contrattuale non è applicata prima del mese successivo e, se il ciclo è già iniziato, decorre dal primo confine di ciclo utile; la data effettiva è comunicata e confermata | §18.13 |
| 4 | Per ogni Progetto e passaggio d'anno si sceglie una sola modalità: Nessuna, Riporto oppure Riprogrammazione; Riporto e Riprogrammazione non possono coesistere | §§16.10, 17 |
| 5 | La Proposta modifica il piano e non corregge o sposta Effettivi | §12.6 |
| 6 | Un errore storico di imputazione dopo la Chiusura viene annotato e non riclassificato economicamente | §§14.9, 24.10 |
| 7 | Un Esercizio Chiuso non viene riaperto; gli effetti successivi vengono gestiti negli Esercizi Aperti | §§11.6, 14.9 |
| 8 | Un Effettivo negativo non genera Riporto superiore all'Allocato | §§8.4, 17.5 |
| 9 | Il rinnovo automatico resta nel modello, distinto dal ciclo economico, e alimenta una sezione informativa delle scadenze | §§18.3–18.6, 19 |
| 10 | Il software non risponde a quanto si pensa di spendere a fine anno | §§1.4, 2.2 |

---

# Appendice B — Consolidamento rispetto alla versione 3.0

## B.1 Decisioni preservate

| Decisione | Esito nella v4 |
|---|---|
| Una sola realtà operativa | Preservata |
| Soli tipi Stima ed Effettivo | Preservata |
| Spesa come oggetto economico elementare | Preservata |
| Nessun matching | Preservata |
| Appartenenza Progetto XOR Contratto | Preservata |
| Budget Approvato immutabile e versionato | Preservata |
| Proposta isolata | Preservata e semplificata |
| Progetti Pianificati, Aperti, Chiusi e Cancellati | Preservati |
| Riporto esclusivo dei Progetti | Preservato e reso verificabile |
| Contratto come generatore di Stime, non fatturazione | Preservato |
| Ricorrenze ancorate e nessun prorata | Preservati |
| Centro di Costo annuale | Preservato e chiarito |
| EUR netto IVA | Preservato |
| Timeline append-only | Preservata |
| Budget e Chiusura immutabili | Preservati |
| Correzioni tardive di importo | Preservate |
| Plafond come normale Spesa | Preservato come solo caso d'uso |
| Nessun warning dedicato al Progetto dormiente | Preservato |

## B.2 Elementi eliminati

| Elemento v3 | Motivo |
|---|---|
| Forecast e Previsione Corrente | Il prodotto non deve prevedere la chiusura dell'anno |
| Previsione a Finire | Dipendeva dal Forecast |
| Snapshot di Forecast | Non necessarie |
| Budget vs Forecast e Forecast vs Actual | Fuori dallo scopo |
| Conflitto e merge per singolo campo | Sostituiti dal riallineamento dell'intera sorgente |
| Cancellazione fisica e tombstone ordinari | Sostituiti da Storno, stati e Archivio |
| Quantità × Importo unitario come unico valore | Sostituito da Importo autoritativo |
| Impostazione per disabilitare le Revisioni | Impediva un percorso correttivo coerente |
| Ruolo obbligatorio Commerciale/Tecnico | Poteva imporre informazioni false |
| Rinnovo automatico legato a un solo ciclo economico | Confondeva durata e fatturazione |

## B.3 Elementi aggiunti o formalizzati

| Elemento | Motivo |
|---|---|
| Stato alla data | Rendere deterministiche Snapshot e Chiusure tardive |
| Atomicità inter-Esercizio | Gestire condizioni e transizioni pluriennali |
| Predicati distinti di inclusione | Eliminare l'ambiguità di “partecipa all'Esercizio” |
| Schemi separati Budget/Chiusura | Evitare Effettivi e Residui nella baseline |
| Riallineamento per sorgente | Ridurre complessità senza perdere sicurezza |
| Proposta esplicitamente limitata al piano | Evitare modifiche indirette agli Effettivi |
| Modalità Nessuna/Riporto/Riprogrammazione esclusiva | Rendere verificabile il rinvio e rappresentare esplicitamente il mancato trasferimento |
| Limite del Riporto all'Allocato | Evitare disponibilità creata dai rimborsi |
| Annotazione di errore storico | Rendere espliciti errori non correggibili dopo la Chiusura |
| Scadenza, rinnovo e preavviso contrattuali | Supportare governo e futura promemoria senza gestire fatture |
| Decorrenza contrattuale al confine di ciclo | Evitare prorata e sovrapposizioni |
| Categoria primaria e etichette dei report | Evitare conteggi sovrapposti |
| Permessi per Azienda | Rendere corretto il modello MSP |
| Creazione condizionale di N+1 | Gestire l'offboarding |

---

# Appendice C — Risposte sintetiche alle domande fondamentali

| N. | Domanda | Risposta canonica |
|---:|---|---|
| 1 | Cos'è il Budget? | Una Snapshot immutabile del piano approvato. |
| 2 | Cos'è l'Allocato Corrente? | La somma corrente delle Stime e, per i Progetti, del Riporto ricevuto. |
| 3 | Cos'è l'Effettivo? | L'importo che l'utente dichiara realmente sostenuto nell'Esercizio. |
| 4 | Il sistema prevede la spesa finale? | No. |
| 5 | Cos'è la Proposta? | Uno spazio isolato per modificare il piano senza modificare gli Effettivi. |
| 6 | Come reagisce la Proposta a una modifica viva? | L'intera sorgente diventa Da riallineare. |
| 7 | Come si approva? | Applicazione atomica delle azioni di piano e creazione di una nuova versione di Budget. |
| 8 | Si può correggere v1? | Con una Revisione finché l'Esercizio è Aperto; v1 resta immutabile. |
| 9 | Si può riaprire un Esercizio? | No. |
| 10 | Come si corregge un importo omesso dopo la Chiusura? | Con un Effettivo tardivo append-only. |
| 11 | Come si corregge un Centro di Costo storico errato? | Non viene riclassificato; viene annotato. |
| 12 | Quale stato mostra la Chiusura? | Lo stato valido al 31 dicembre. |
| 13 | Come si gestiscono effetti su più anni? | Con un'unica operazione atomica su tutti gli Esercizi Aperti interessati. |
| 14 | Cos'è il Riporto provvisorio? | Una decisione temporanea per costruire N+1, non una previsione. |
| 15 | Quanto si può riportare? | Al massimo il minore fra Residuo e Allocato. |
| 16 | Un rimborso aumenta il Riporto? | Non oltre l'Allocato. |
| 17 | Si possono usare insieme Riporto e Riprogrammazione? | No, per lo stesso Progetto e passaggio d'anno. |
| 18 | Il Contratto genera fatture? | No, genera soltanto Stime annuali. |
| 19 | Rinnovo automatico e fatturazione sono la stessa cosa? | No. |
| 20 | Come si modifica il prezzo a metà ciclo? | La modifica decorre dal primo confine di ciclo utile e viene comunicata. |
| 21 | La scadenza contrattuale è una scadenza di pagamento? | No. |
| 22 | Il sistema mostra le scadenze dei Contratti? | Sì, in una sezione informativa dedicata. |
| 23 | Invia promemoria? | Non nella baseline; è un'estensione futura. |
| 24 | Come viene trattato un preventivo? | Come origine descrittiva di una Stima. |
| 25 | Come viene trattata una spesa improvvisa? | Come nuova sorgente o nuovo Effettivo, senza tipo speciale. |
| 26 | Come viene trattato un Plafond? | Come normale Spesa, senza flag. |
| 27 | Come sono determinati Previsto e Non previsto? | Solo a livello di sorgente di primo livello e rispetto all'Allocato approvato. |
| 28 | L'Archivio rimuove valori? | No. |
| 29 | Si eliminano fisicamente oggetti salvati? | No, nelle operazioni ordinarie. |
| 30 | I permessi sono globali? | No, sono assegnati per Azienda. |
| 31 | Il software gestisce fatture in ritardo? | Registra l'effetto economico, ma non governa lo stato della fattura. |
| 32 | Il software deduplica fatture? | No. |
| 33 | Supporta Actual mensili? | Non in modo affidabile senza Data Effettivo. |
| 34 | Supporta costi condivisi fra più Centri di Costo? | Non con ripartizioni percentuali. |
| 35 | Supporta multi-valuta? | No, registra EUR netto IVA. |
| 36 | Crea sempre N+1? | No, può non crearlo se la gestione termina e i Riporti sono zero. |
| 37 | Come viene spiegata una variazione? | Categoria primaria, dimensioni, etichette, Timeline e motivazioni. |
| 38 | Cosa resta libero all'implementatore? | Soltanto dettagli tecnici equivalenti che non cambiano il comportamento. |

---

# Appendice D — Risoluzione dei rilievi della verifica indipendente

## D.1 Lacune strutturali

| Rilievo | Risoluzione v4 |
|---|---|
| 1.1 Previsto/Non previsto e assenza di matching | Granularità al primo livello; identità manuale per Spese autonome; §§25.3–25.9 |
| 1.2 Modello temporale incompleto | StatoAllaData, data di riferimento e transizioni; §9 |
| 1.3 Effetti su più Esercizi | Enumerazione, blocco, rivalidazione e atomicità; §10 |
| 1.4 “Partecipa all'Esercizio” | Termine eliminato e sostituito da predicati e matrice; §7.6–7.7 |
| 1.5 Snapshot non deterministiche | Schemi Budget e Chiusura separati; §23 |
| 1.6 Rinnovo, cambio infraciclo e data pagamento | Durata separata, rinnovo corretto, confine di ciclo, Data di attribuzione; §18 |
| 1.7 Riporto/riprogrammazione non verificabile | Modalità esclusiva per intero Progetto e passaggio d'anno; §16.10 |
| 1.8 Perimetro della Proposta | Azioni complete sul piano e divieto di modificare Effettivi; §12 |
| 1.9 Correzioni delle imputazioni | Decisione esplicita: Annotazione senza riclassificazione; §§14.9, 24.10 |
| 1.10 Errori di Budget e Chiusura | Revisione sempre in Aperto; nessuna riapertura; effetti espliciti negli anni Aperti; §§13.5–13.6, 14.9 |
| 1.11 Categorie sovrapposte | Categoria primaria esclusiva, dimensioni ed etichette sovrapponibili; §§25.5–25.7 |
| 1.12 Termini decisionali vaghi | Predicati, elenchi chiusi e definizioni; §§7.6, 12.14, 23, 30.13 |

## D.2 Contraddizioni normative

| Rilievo | Risoluzione v4 |
|---|---|
| Uso improprio di MAY | Definizione normativa e formulazioni corrette; §0 |
| CopiedFromOriginKey facoltativo/obbligatorio | Obbligatorio nella copia canonica; §12.4 |
| Mancanza di gerarchia normativa | Gerarchia esplicita; §0.1 |
| Regole duplicate divergenti | FR trasformati in indice di rinvio; §29 |

## D.3 Semplificazioni

| Rilievo | Risoluzione v4 |
|---|---|
| Conflitto per singolo campo | Riallineamento dell'intera sorgente; §§12.11–12.12 |
| Eliminazione, tombstone, Storno e Archivio | Nessuna cancellazione fisica ordinaria; distinzione netta Storno/Archivio; §§5.7–5.8, 24 |
| Quantità e unitario obbligatori | Importo autoritativo; quantità e unitario opzionali; §8.1 |
| Relazione informativa indefinita | Semantica, cardinalità e ciclo di vita di `Collegato a` definiti; §7.3 |
| Plafond come concetto canonico | Ridotto a caso d'uso della Spesa; §15.14 |
| Referenti obbligatoriamente Commerciali/Tecnici | Tag opzionali; §21.2 |

## D.4 Casi P1

| Rilievo | Risoluzione v4 |
|---|---|
| Data Effettivo | Baseline annuale e dichiarazione autoritativa esplicite; §11.3 |
| Sorgenti con Allocato e assenza di Effettivi | Avviso generale non causale; §14.4 |
| Cambio Centro di Costo durante l'anno | Riclassifica l'intero Esercizio; §20.3 |
| Spostamento di Spese con Effettivi | Riclassificazione integrale con anteprima e Nota; §15.9 |
| Nuova sorgente post-Budget senza motivo | Nota obbligatoria; §§15.6, 22.9 |
| Fornitore Archiviato nelle correzioni | Disponibile per lo storico; §§21.4, 24.6 |
| Effettivo negativo e Riporto | Massimo minore fra Residuo e Allocato; §17.5 |
| Permessi non limitati all'Azienda | Capacità per Azienda e audit; §26 |
| Creazione obbligatoria di N+1 | Creazione condizionale e offboarding; §11.7 |
| Contratti misti o variabili | Limite e rappresentazione esterna al Contratto; §§18.22, 27.16 |

## D.5 Stress test specifici

| Caso | Risoluzione |
|---|---|
| NAS previsto, Effettivo in nuova Spesa | §27.8 |
| Plafond con acquisti separati | §§15.14, 27.34 |
| Contratto annuale fatturato mensilmente senza rinnovo | §27.10 |
| Cambio prezzo a metà ciclo | §§18.13, 27.13 |
| Chiusura tardiva dopo cessazione o Chiusura di sorgente | §§9.5, 27.31 |
| Effettivi in N+1 mentre N è Aperto | §27.31 |
| Contratto Cessato da riattivare | §§7.6.3, 18.8 |
| Sorgente Archiviata con Proposta aperta | §27.32 |
| Sorgente con soli Effettivi prima di v2 | §27.33 |
| Riporto parziale più Stime copiate | §§16.10, 27.21 |
| Errore di Centro di Costo post-Chiusura | §27.26 |
| Errore di Riporto | §27.27 |
| Budget errato | §§13.5–13.6 |
| Spesa spostata su Progetto Chiuso | §15.9 |
| Prezzo unitario sotto il centesimo | §27.35 |
| Offboarding | §27.40 |
| Correzioni tardive a saldo netto zero | §24.11 |
| Condizione 2027 che modifica 2028 | §27.29 |

---

# Appendice E — Valutazione critica della versione 4.0

## E.1 Problemi risolti

La versione 4.0 risolve in modo deterministico:

- distinzione fra piano approvato, piano vivo, Effettivi e Chiusura;
- preparazione del futuro senza modificare gli Effettivi;
- modifiche della realtà mentre una Proposta è aperta;
- approvazione e Revisioni senza perdita delle versioni precedenti;
- stato storico di Progetti e Contratti alla data corretta;
- operazioni che interessano più Esercizi Aperti;
- Contratti con rinnovo automatico distinto dal ciclo economico;
- scadenze e termini di disdetta informativi;
- modifiche contrattuali senza prorata e senza differimenti silenziosi;
- Riporto provvisorio senza Forecast;
- esclusività fra Nessuna, Riporto e Riprogrammazione;
- limite del Riporto in presenza di Effettivi negativi;
- errori e omissioni scoperti dopo la Chiusura;
- granularità di Previsto e Non previsto;
- contenuto distinto delle Snapshot di Budget e Chiusura;
- Archivio, Storno e assenza di cancellazione fisica ordinaria;
- permessi per Azienda;
- spiegazione delle variazioni tramite Timeline e categorie deterministiche.

## E.2 Complessità essenziale mantenuta

Restano intenzionalmente complessi perché necessari al perimetro:

1. stato alla data di Progetti e Contratti;
2. atomicità delle operazioni inter-Esercizio;
3. riallineamento delle Proposte rispetto alla realtà viva;
4. materializzazione autonoma delle Snapshot;
5. rinnovi e scadenze contrattuali;
6. Riporto e Riprogrammazione dei Progetti;
7. correzioni tardive senza riapertura;
8. report versionati con riferimenti temporali espliciti.

Rimuovere uno di questi elementi cambierebbe un requisito già dichiarato o lascerebbe comportamenti incompatibili all'implementatore.

## E.3 Complessità deliberatamente esclusa

Il modello rinuncia consapevolmente a:

- Forecast di fine anno;
- fatture, rate, scadenze di pagamento e deduplicazione documentale;
- competenza contabile;
- Actual mensili affidabili;
- ripartizioni percentuali fra Centri di Costo;
- contratti a consumo o con formule economiche variabili;
- prorata;
- scenari paralleli;
- riclassificazione economica dopo la Chiusura;
- riapertura degli Esercizi;
- multi-valuta e IVA;
- TCO automatico fra Progetti e Contratti collegati.

Questi limiti non sono lacune interne: sono confini eseguibili descritti nel §30.15.

## E.4 Rischi residui accettati

### Qualità della dichiarazione degli Effettivi

Senza Data Effettivo e senza documenti amministrativi, il sistema dipende dalla dichiarazione annuale dell'utente. Non può verificare giorno, mese, fattura o duplicazione amministrativa.

### Identità delle Spese autonome

L'utente decide se una Stima e un Effettivo appartengono alla stessa Spesa autonoma. Due Spese separate restano sorgenti separate anche se descrivono economicamente lo stesso acquisto.

### Contratti non rappresentabili dal motore

Un accordo con setup, consumo, conguaglio, indicizzazione o prorata richiede sorgenti esterne al Contratto. Il report del Contratto non coincide necessariamente con il costo commerciale complessivo.

### Errori storici di imputazione

Dopo la Chiusura vengono annotati ma non riclassificati. La conoscenza corrente può quindi mantenere un Centro di Costo, Progetto o Contratto riconosciuto come errato.

### Operazioni manuali che eludono la semantica

Il sistema può bloccare le operazioni canoniche incompatibili, ma non può riconoscere automaticamente che una nuova Stima manuale duplichi intenzionalmente una quota già riprogrammata o riportata.

### Scadenze senza promemoria

Le date sono presenti e filtrabili; l'assenza di notifiche automatiche resta un limite della baseline.

## E.5 Condizioni che riaprono il dominio

Il dominio deve essere riaperto soltanto se emerge un requisito concreto che richiede, per esempio:

- notifiche e promemoria con regole di consegna;
- fatture o pagamenti strutturati;
- Actual mensili;
- ripartizioni multi-Centro di Costo;
- contratti variabili o prorata;
- correzione economica delle imputazioni storiche;
- scenari paralleli;
- multi-valuta;
- esercizi fiscali non solari.

L'estensione deve superare il test del §3.3 e non deve essere introdotta soltanto perché una nuova storia può essere immaginata.

## E.6 Giudizio di chiusura

La versione 4.0 può essere considerata chiusa rispetto al perimetro dichiarato quando i test derivati dagli Invarianti e dalla checklist del §30.17 risultano superati.

La formulazione corretta è:

> Non risultano casi appartenenti al perimetro dichiarato privi di comportamento deterministico o lasciati alla scelta dell'implementatore. I casi ulteriori devono essere classificati secondo il §3 prima di modificare il dominio.

La specifica non afferma che nessun nuovo requisito o processo esterno potrà emergere.


# 31. Tenant Azienda e separazione dal dominio Azienda

## 31.0 Natura normativa e prevalenza

Questa sezione è **normativa**.

Ai fini della gerarchia del §0.1, la presente §31 deve essere considerata parte delle sezioni normative.

In caso di contrasto con formulazioni precedenti, la presente sezione prevale esclusivamente per quanto riguarda:

* distinzione fra `Tenant Azienda` e `Azienda`;
* accesso e isolamento del Tenant;
* Archivio e ripristino del Tenant;
* eliminazione definitiva del Tenant;
* ruolo del Super Admin;
* sospensione dei processi durante l'Archivio;
* rapporto fra ciclo di vita del Tenant e ciclo di vita degli Esercizi.

Le restanti regole economiche e funzionali della specifica restano invariate.

---

## 31.1 Separazione dei concetti

Il sistema **MUST** distinguere:

### Tenant Azienda

Il `Tenant Azienda` è il contenitore di piattaforma che governa:

* isolamento dei dati;
* disponibilità e accessibilità dell'ambiente;
* associazioni di accesso degli utenti;
* ciclo di vita del Tenant;
* Archivio;
* ripristino;
* eliminazione definitiva.

Il Tenant Azienda **non è una sorgente economica** e non possiede un ciclo di vita economico.

### Azienda

L'`Azienda` è la radice del dominio funzionale MP2.

Contiene o governa almeno:

* denominazione;
* fuso orario IANA;
* Impostazioni di dominio;
* Esercizi;
* Spese e Righe;
* Progetti;
* Contratti;
* Fornitori e Referenti;
* Centri di Costo;
* Proposte;
* Budget Approvati;
* Snapshot di Chiusura;
* Riporti e Riprogrammazioni;
* classificazioni;
* relazioni informative;
* Timeline;
* Annotazioni di errore storico;
* allegati ed evidenze del dominio.

Il ciclo di vita dell'Azienda **MUST NOT** essere usato per rappresentare disponibilità, sospensione, Archivio o eliminazione del Tenant.

---

## 31.2 Relazione fra Tenant Azienda e Azienda

Ogni `Tenant Azienda` contiene una sola `Azienda`.

Ogni `Azienda` appartiene a un solo `Tenant Azienda`.

Deve valere:

```text
TenantAzienda 1 ── 1 Azienda
```

La separazione è concettuale e funzionale.

La specifica **MUST NOT** imporre che Tenant Azienda e Azienda corrispondano a specifiche tabelle, database o strutture fisiche.

L'implementazione tecnica resta libera secondo il §30.1.

---

## 31.3 Creazione

Durante la creazione iniziale, prima che il Tenant Azienda e la relativa Azienda siano stati persistiti, l'utente può usare `Annulla`.

`Annulla`:

* interrompe la creazione;
* non crea il Tenant Azienda;
* non crea l'Azienda;
* non crea dati di dominio da conservare;
* non costituisce eliminazione.

Una volta completata la creazione persistente, il Tenant Azienda entra nello stato:

```text
Attivo
```

Da quel momento non si applica più l'abbandono della creazione.

---

## 31.4 Stati del Tenant Azienda

Il Tenant Azienda possiede esclusivamente gli stati:

* `Attivo`;
* `Archiviato`.

Deve valere:

```text
StatoTenant ∈ {Attivo, Archiviato}
```

`Eliminato` **MUST NOT** essere uno stato.

L'eliminazione definitiva comporta la cessazione dell'esistenza del Tenant Azienda.

Non sono previsti stati:

* Sospeso;
* Terminato;
* Disabilitato;
* In chiusura;
* Eliminato.

---

## 31.5 Tenant Azienda Attivo

Quando il Tenant Azienda è `Attivo`:

* l'Azienda può essere utilizzata dagli utenti autorizzati;
* si applicano normalmente le capacità previste dal §26;
* le operazioni manuali possono essere eseguite secondo le regole del dominio;
* i processi automatici previsti dalla specifica possono essere eseguiti;
* Esercizi, Contratti, Progetti e altri oggetti seguono autonomamente i propri cicli di vita.

Lo stato `Attivo` del Tenant **MUST NOT** implicare uno specifico stato di Esercizi, Progetti o Contratti.

---

## 31.6 Archivio del Tenant Azienda

Soltanto il `Super Admin` della piattaforma può Archiviare un Tenant Azienda.

L'Archivio del Tenant Azienda:

* porta il Tenant da `Attivo` ad `Archiviato`;
* conserva integralmente l'Azienda e tutti i relativi dati;
* non modifica alcun dato economico;
* non modifica Esercizi;
* non chiude Esercizi Aperti;
* non modifica Budget o Snapshot;
* non modifica Proposte;
* non modifica Spese, Progetti o Contratti;
* non cambia stati o transizioni;
* non modifica classificazioni;
* non modifica Riporti o Riprogrammazioni;
* non archivia automaticamente gli oggetti interni;
* non revoca le associazioni di accesso;
* non sposta o modifica date economiche o contrattuali.

L'Archivio del Tenant è quindi una proprietà di **disponibilità della piattaforma**, non una modifica della realtà economica.

---

## 31.7 Inaccessibilità del Tenant Archiviato

Un Tenant Azienda `Archiviato` **MUST NOT** essere accessibile tramite l'operatività ordinaria.

Durante l'Archivio:

* gli utenti del Tenant non possono accedere all'Azienda;
* le capacità assegnate per l'Azienda non conferiscono accesso;
* non possono essere eseguite operazioni manuali;
* non possono essere consultati dati, report, Timeline o Snapshot attraverso il normale accesso al Tenant;
* non possono essere eseguiti comandi applicativi sul dominio;
* API o percorsi diretti **MUST NOT** aggirare l'Archivio.

Il Super Admin può identificare il Tenant nell'amministrazione della piattaforma esclusivamente per eseguire le operazioni di ciclo di vita previste dalla presente sezione.

La possibilità di identificare un Tenant Archiviato nell'amministrazione della piattaforma **MUST NOT** essere interpretata come accesso operativo ai suoi dati di dominio.

---

## 31.8 Sospensione dei processi automatici

Quando il Tenant Azienda è `Archiviato`, i processi automatici relativi al Tenant **MUST NOT** modificare il dominio.

Durante l'Archivio:

```text
OperazioniManuali = sospese
ProcessiAutomatici = sospesi
DatiDominio = invariati
```

La sospensione riguarda l'elaborazione applicativa.

L'Archivio **MUST NOT** essere interpretato come sospensione del tempo reale o economico.

In particolare, l'Archivio:

* non sposta scadenze;
* non prolunga Contratti;
* non cambia date di efficacia;
* non modifica gli anni degli Esercizi;
* non trasla condizioni economiche;
* non crea un calendario alternativo del Tenant.

---

## 31.9 Ripristino del Tenant Azienda

Soltanto il `Super Admin` della piattaforma può ripristinare un Tenant Azienda Archiviato.

Il ripristino:

```text
Archiviato → Attivo
```

Il ripristino:

* rende nuovamente accessibile il Tenant secondo le normali autorizzazioni;
* non ricrea dati perché i dati non sono stati eliminati;
* non modifica stati o valori economici;
* non sposta date;
* non riapre Esercizi Chiusi;
* non modifica Budget o Snapshot;
* non ricostruisce una realtà alternativa al periodo di Archivio.

Le associazioni di accesso conservate tornano ad avere effetto secondo le normali regole di autorizzazione.

### Ripresa dei processi automatici

Dopo il ripristino, i processi automatici riprendono usando le **date reali** e le normali regole del dominio.

Se durante l'Archivio sono trascorse date che avrebbero richiesto elaborazioni automatiche, il sistema le valuta al ripristino secondo le regole già previste per il relativo processo.

L'Archivio **MUST NOT** causare lo spostamento in avanti degli eventi semplicemente perché il Tenant non era attivo.

Esempio:

```text
Contratto:
Prossima scadenza: 31/12/2026
Rinnovo automatico: Sì

Tenant Archiviato: 01/10/2026
Tenant Ripristinato: 10/02/2027
```

Durante l'Archivio il rinnovo non viene materializzato.

Dopo il ripristino, il sistema applica le normali regole del rinnovo automatico, incluse quelle relative a scadenze trascorse e idempotenza.

---

## 31.10 Eliminazione definitiva del Tenant Azienda

Il Tenant Azienda può essere eliminato definitivamente.

L'eliminazione è un'operazione di **amministrazione della piattaforma**, non una normale cancellazione di un oggetto del dominio Azienda.

### Autorizzazione

Soltanto il `Super Admin` della piattaforma può eliminare definitivamente un Tenant Azienda.

Nessuna capacità assegnata per Azienda può autorizzare questa operazione.

In particolare, non la consentono:

* `gestisce_permessi`;
* `gestisce_impostazioni`;
* `chiude_esercizio`;
* `gestisce_anagrafiche`;
* qualsiasi combinazione delle capacità del §26.

### Stato di partenza

L'eliminazione può essere eseguita sia da:

```text
Attivo
```

sia da:

```text
Archiviato
```

Non è obbligatorio Archiviare prima il Tenant.

### Nessuna precondizione economica

L'eliminazione definitiva **MUST NOT** dipendere dalla presenza o assenza di:

* Esercizi Aperti;
* Esercizi Chiusi;
* Proposte in Bozza;
* Budget Approvati;
* Snapshot di Chiusura;
* Spese;
* Effettivi;
* Progetti;
* Contratti;
* Riporti;
* Riprogrammazioni;
* Fornitori;
* Centri di Costo;
* dati storici.

L'eliminazione del Tenant prevale sul ciclo di vita dei dati contenuti.

---

## 31.11 Doppia conferma dell'eliminazione

L'eliminazione definitiva richiede **due conferme intenzionali distinte**.

### Prima conferma

Deve comunicare almeno che:

* verrà eliminato il Tenant Azienda;
* verrà eliminata l'Azienda;
* verranno eliminati tutti i dati appartenenti al Tenant.

### Seconda conferma

Deve comunicare almeno che:

* l'operazione è definitiva;
* i dati non saranno più disponibili nell'applicazione;
* il Tenant non potrà essere ripristinato.

Le due conferme **MUST** essere due azioni distinte.

Un singolo click, anche ripetuto accidentalmente, **MUST NOT** soddisfare entrambe le conferme.

La specifica non impone:

* digitazione della denominazione;
* OTP;
* password aggiuntiva;
* codice di conferma;
* altra procedura specifica.

Tali meccanismi possono essere scelti a livello di interfaccia o sicurezza purché non modifichino la regola delle due conferme distinte.

---

## 31.12 Portata dell'eliminazione totale

L'eliminazione definitiva del Tenant Azienda comporta l'eliminazione di:

```text
Tenant Azienda
+
Azienda
+
ogni dato applicativo posseduto direttamente o indirettamente dal Tenant
```

La regola è basata sull'**appartenenza al Tenant**, non su un elenco chiuso di tipi tecnici.

Sono quindi eliminati, quando appartenenti al Tenant, anche:

* Esercizi;
* Spese e Righe;
* Progetti e transizioni;
* Contratti, condizioni, scadenze ed eventi;
* Fornitori e Referenti;
* Centri di Costo;
* Proposte ed Elementi di Proposta;
* Budget Approvati;
* Snapshot di Chiusura;
* Riporti e Riprogrammazioni;
* classificazioni;
* relazioni informative;
* Timeline;
* Annotazioni di errore storico;
* Impostazioni;
* associazioni e autorizzazioni relative al Tenant;
* audit appartenente al Tenant;
* allegati ed evidenze appartenenti al Tenant;
* dati derivati o ausiliari appartenenti esclusivamente al Tenant.

L'eliminazione **MUST NOT**:

* conservare una copia applicativa ripristinabile del Tenant;
* trasformare il Tenant in Archiviato;
* creare uno stato Eliminato;
* lasciare parti del dominio accessibili;
* lasciare oggetti orfani appartenenti al Tenant.

Il ciclo di vita degli eventuali account utente globali della piattaforma non è definito da questa sezione.

L'eliminazione delle associazioni fra tali account e il Tenant eliminato è invece obbligatoria.

---

## 31.13 Atomicità dell'eliminazione

L'eliminazione definitiva **MUST** essere atomica dal punto di vista del comportamento osservabile dell'applicazione.

Deve valere:

```text
o il Tenant esiste integralmente
o il Tenant non esiste più
```

Il sistema **MUST NOT** lasciare uno stato osservabile nel quale:

* l'Azienda esiste ma parte dei dati è stata eliminata;
* il Tenant è stato eliminato ma alcuni suoi oggetti restano accessibili;
* nuove operazioni possano creare dati nel Tenant durante l'eliminazione.

La strategia tecnica necessaria a ottenere questa proprietà è libera.

---

## 31.14 Distinzione dall'Archivio degli oggetti di dominio

L'Archivio del Tenant Azienda **MUST NOT** essere confuso con l'Archivio degli oggetti interni previsto dai §§5.8 e 24.3.

### Archivio di un oggetto interno

Per Progetti, Contratti, Fornitori e Centri di Costo:

* modifica visibilità o selezionabilità;
* conserva l'accessibilità storica prevista dalla specifica;
* non rimuove valori;
* non modifica Snapshot.

### Archivio del Tenant Azienda

Per il Tenant:

* rende indisponibile l'intero ambiente;
* impedisce l'accesso al dominio;
* sospende operazioni manuali;
* sospende processi automatici;
* conserva integralmente i dati;
* è reversibile esclusivamente dal Super Admin.

Le due operazioni condividono il termine `Archivio`, ma hanno ambito e comportamento differenti.

---

## 31.15 Regola di non cancellazione fisica degli oggetti interni

Le regole dei §§5.7 e 24.1 e l'Invariante §28.45 continuano ad applicarsi agli oggetti persistiti **all'interno di un Tenant esistente**.

Pertanto, durante la normale vita di un Tenant:

* una Spesa non viene eliminata fisicamente;
* un Progetto non viene eliminato fisicamente;
* un Contratto non viene eliminato fisicamente;
* un Fornitore non viene eliminato fisicamente;
* un Centro di Costo non viene eliminato fisicamente;
* Budget e Snapshot restano immutabili;
* Timeline e audit restano append-only secondo le rispettive regole.

L'eliminazione definitiva del Tenant Azienda **non costituisce una cancellazione ordinaria di questi singoli oggetti**.

Costituisce la distruzione del contenitore di piattaforma al quale appartiene l'intero dominio.

Di conseguenza, le regole di immutabilità e conservazione degli oggetti interni valgono fintanto che il Tenant Azienda esiste.

---

## 31.16 Super Admin e capacità per Azienda

Il `Super Admin` è un ruolo di amministrazione della piattaforma.

Il Super Admin **MUST NOT** essere rappresentato come una combinazione delle capacità per Azienda del §26.

Le operazioni riservate al Super Admin definite da questa sezione sono:

* Archivio del Tenant Azienda;
* ripristino del Tenant Azienda;
* eliminazione definitiva del Tenant Azienda.

Le capacità del §26 continuano a governare esclusivamente le operazioni all'interno di un Tenant Azienda Attivo.

---

## 31.17 Separazione dal ciclo di vita dell'Esercizio

Il ciclo di vita del Tenant Azienda e il ciclo di vita degli Esercizi sono indipendenti.

Archiviare un Tenant **MUST NOT**:

* Chiudere un Esercizio;
* creare un Esercizio;
* eliminare un Esercizio;
* modificare un Budget;
* consolidare un Riporto;
* applicare una Riprogrammazione.

Analogamente, la Chiusura di un Esercizio **MUST NOT**:

* Archiviare il Tenant;
* ripristinare il Tenant;
* eliminare il Tenant;
* dichiarare implicitamente conclusa la gestione del cliente.

---

## 31.18 Semplificazione della creazione di `N+1`

Il concetto `Gestione terminata` del §11.7 viene sostituito dalla sola decisione relativa alla creazione dell'Esercizio successivo.

Alla Chiusura di `N`, se `N+1` non esiste, l'utente deve scegliere esclusivamente fra:

* `Crea N+1`;
* `Non creare N+1`.

### Crea N+1

Il sistema crea `N+1` Aperto secondo le normali regole di inizializzazione del §11.8.

### Non creare N+1

Il sistema non crea `N+1`.

Devono essere zero tutti i Riporti che richiederebbero `N+1` come Esercizio destinazione.

La scelta `Non creare N+1`:

* non Archivia il Tenant;
* non modifica lo stato del Tenant;
* non costituisce offboarding;
* non impedisce una successiva creazione manuale di `N+1` se il Tenant resta Attivo.

Se `N+1` esiste già, la Chiusura di `N` non lo elimina e non richiede alcuna regola speciale di offboarding.

---

## 31.19 Offboarding

La cessazione della gestione di un cliente **MUST NOT** essere rappresentata tramite:

* uno stato speciale dell'Azienda;
* uno stato speciale dell'Esercizio;
* la mancata creazione di `N+1`;
* la Chiusura di tutti gli Esercizi;
* la cessazione automatica di Progetti o Contratti.

Quando si vuole rendere non più operativo il cliente nella piattaforma, il Super Admin Archivia il relativo Tenant Azienda.

L'Archivio del Tenant costituisce quindi il meccanismo canonico di offboarding della piattaforma.

L'eventuale successivo ritorno del cliente viene rappresentato mediante il ripristino dello stesso Tenant Azienda.

---

## 31.20 Casistiche deterministiche

### Azienda creata per errore

Dopo la creazione persistente il Super Admin può:

* Archiviare il Tenant;
* oppure eliminarlo definitivamente con doppia conferma.

Non esiste una regola diversa basata sulla quantità di dati presenti.

### Tenant con Esercizi Aperti

Può essere Archiviato.

Gli Esercizi restano Aperti ma non sono elaborabili durante l'Archivio.

Al ripristino restano negli stessi stati.

### Tenant con Proposta in Bozza

Può essere Archiviato.

La Proposta resta in Bozza e invariata.

Al ripristino si applicano le normali regole di riallineamento già previste dalla specifica.

### Tenant con Budget e Snapshot

Può essere Archiviato.

Budget e Snapshot vengono conservati ma non sono accessibili durante l'Archivio.

Può inoltre essere eliminato definitivamente dal Super Admin; in tal caso Budget e Snapshot vengono eliminati insieme al Tenant.

### Contratto con rinnovo durante l'Archivio

Il rinnovo non viene elaborato mentre il Tenant è Archiviato.

Al ripristino vengono applicate le normali regole temporali e di recupero dei rinnovi trascorsi.

### Tenant con `N+1` già esistente al momento dell'offboarding

Non richiede alcun trattamento speciale.

Il Super Admin Archivia il Tenant e `N+1` resta conservato insieme agli altri dati.

### Ripristino dopo più anni

Il ripristino non sposta gli anni né le date.

Gli Esercizi mantengono i propri stati.

I processi automatici riprendono valutando le date reali secondo le proprie regole.

Il sistema **MUST NOT** fingere che il periodo di Archivio non sia trascorso.

---

## 31.21 Invarianti del Tenant Azienda

### Separazione

```text
TenantAzienda != Azienda
```

### Cardinalità

```text
TenantAzienda 1 ── 1 Azienda
```

### Stati

```text
StatoTenant ∈ {Attivo, Archiviato}
```

### Archivio

```text
StatoTenant = Archiviato
→ AccessoOrdinario = false
→ OperazioniManuali = sospese
→ ProcessiAutomatici = sospesi
→ DatiDominio = invariati
```

### Ripristino

```text
Archiviato → Attivo
solo Super Admin
```

Il ripristino non modifica il calendario del dominio.

### Eliminazione

```text
EliminaTenant
→ solo Super Admin
→ doppia conferma
→ eliminazione totale dei dati appartenenti al Tenant
→ nessun ripristino applicativo
```

### Ciclo di vita indipendente

```text
StatoTenant
!=
StatoEsercizio
!=
StatoProgetto
!=
StatoContratto
```

### Nessun offboarding implicito

```text
NonCreare(N+1)
!→ ArchivioTenant
```

e:

```text
ChiusuraEsercizio
!→ ArchivioTenant
```

---

## 31.22 Adeguamento delle regole precedenti

Ai fini della coerenza dell'intera specifica, le seguenti formulazioni devono essere lette secondo la presente sezione.

### §§5.7, 24.1, 26.4, 28.45, FR-009 e §30.15

Il divieto di cancellazione fisica ordinaria riguarda gli oggetti persistiti **interni a un Tenant esistente**.

Non vieta l'eliminazione definitiva del Tenant Azienda da parte del Super Admin.

### §§5.8, 24.3 e 28.44

La semantica generale dell'Archivio descritta in tali sezioni riguarda gli oggetti interni del dominio.

L'Archivio del Tenant Azienda segue esclusivamente la presente §31.

### §7.4

Le appartenenze economiche restano riferite all'Azienda.

Le associazioni di accesso e il ciclo di vita della disponibilità appartengono invece al Tenant Azienda.

### §11.7

Le formulazioni `Gestione continuata` e `Gestione terminata` sono sostituite dalla decisione `Crea N+1` / `Non creare N+1` descritta al §31.18.

### §23.2 e storico dopo Archivio

La leggibilità delle Snapshot dopo Archivio continua a valere quando viene Archiviato un oggetto interno.

Quando viene Archiviato l'intero Tenant Azienda, le Snapshot vengono conservate ma non sono accessibili fino al ripristino del Tenant.

### §26

Le capacità per Azienda non governano Archivio, ripristino o eliminazione del Tenant.

Queste operazioni appartengono esclusivamente al Super Admin.

### §27.40 e §30.16

Il caso precedentemente descritto come terminazione del rapporto con il cliente viene rappresentato tramite Archivio del Tenant Azienda.

L'esistenza di un Esercizio futuro non costituisce più un caso speciale di offboarding.

### Appendici

Ogni formulazione esplicativa delle Appendici relativa a:

* impossibilità assoluta di eliminazione;
* `Gestione terminata`;
* offboarding tramite Chiusura;
* accessibilità dopo Archivio;

deve essere interpretata secondo le regole normative della presente §31.

---

## 31.23 Decisione consolidata

Il modello canonico è:

```text
PIATTAFORMA

Tenant Azienda
├── Stato: Attivo | Archiviato
├── Accessi
├── Ciclo di vita del Tenant
│   ├── Archivio
│   ├── Ripristino
│   └── Eliminazione definitiva
│
└── Azienda
    ├── Impostazioni di dominio
    ├── Esercizi
    ├── Spese
    ├── Progetti
    ├── Contratti
    ├── Fornitori
    ├── Centri di Costo
    ├── Proposte
    ├── Budget
    ├── Snapshot
    ├── Timeline
    └── restante dominio MP2
```

Il `Tenant Azienda` governa **se il dominio esiste ed è utilizzabile nella piattaforma**.

L'`Azienda` governa **il dominio funzionale ed economico di MP2**.

I due concetti **MUST NOT** essere nuovamente sovrapposti.

# 32. Rimozione della relazione informativa `Sostituisce`

## 32.1 Natura normativa e prevalenza

La presente sezione è **normativa**.

La relazione informativa `Sostituisce` viene rimossa dal dominio canonico di MP2.

Ogni precedente riferimento alla relazione `Sostituisce`, alla relazione inversa `Sostituito da`, all'etichetta `Sostituito`, al relativo Esercizio di efficacia o a regole specifiche di sostituzione fra sorgenti deve essere interpretato secondo la presente sezione.

Le altre regole relative alle relazioni informative restano valide esclusivamente dove applicabili alla relazione `Collegato a`.

## 32.2 Relazione informativa ammessa

La sola relazione informativa canonica è:

`Collegato a`

Essa resta ammessa esclusivamente fra Progetto e Contratto e conserva le regole già definite dal §7.3: relazione simmetrica e non direzionale, cardinalità molti-a-molti, nessun effetto economico, Archivio e ripristino con audit.

La rimozione di `Sostituisce` **MUST NOT** modificare il comportamento di `Collegato a`, delle Proposte, delle Snapshot, della Timeline o dell'audit relativo alle relazioni informative ancora ammesse.

## 32.3 Nessuna sostituzione strutturata fra sorgenti

MP2 **MUST NOT** creare, memorizzare, dedurre o utilizzare una relazione strutturata che rappresenti che una sorgente economica ha sostituito un'altra.

Il sistema **MUST NOT** reintrodurre lo stesso concetto mediante:

`Predecessore`, `Successore`, `Sostituito da`, flag, stato, etichetta, tipo di relazione equivalente o utilizzo improprio di `Collegato a`.

Quando una sorgente termina e una nuova sorgente ne prende operativamente il posto, le due sorgenti restano oggetti distinti.

L'eventuale motivazione può essere descritta tramite Note, Timeline o allegati, ma tale informazione resta descrittiva e non costituisce una relazione strutturale interrogabile.

## 32.4 Identità e confronti

La correlazione fra sorgenti nei confronti usa esclusivamente:

1. `OriginKey`, quando si tratta della stessa sorgente;
2. `CopiedFromOriginKey`, quando esiste una derivazione esplicita prevista dal dominio;
3. presenza in un solo riferimento, producendo `Aggiunto` oppure `Rimosso` secondo le normali regole del confronto.

Il sistema **MUST NOT** usare titolo, Descrizione, importo, Fornitore, Note o altra somiglianza per dedurre continuità o sostituzione.

Pertanto, se una sorgente `A` termina e viene creata una nuova sorgente `B` priva di `OriginKey` o `CopiedFromOriginKey` che le conferiscano continuità canonica, il confronto rappresenta:

`A → Rimosso`

`B → Aggiunto`

anche quando una Nota o un evento di Timeline dichiara che `B` ha preso operativamente il posto di `A`.

## 32.5 Reporting e Snapshot

L'etichetta secondaria `Sostituito` viene rimossa dal dominio e **MUST NOT** essere assegnata o esposta nei report.

Budget Approvati e Snapshot di Chiusura **MUST NOT** materializzare relazioni di sostituzione.

Continuano invece a materializzare le relazioni `Collegato a` quando richiesto dalle normali regole delle relazioni informative.

La rimozione di `Sostituisce` non modifica importi, Allocato, Effettivi, Riporto, Riprogrammazione, categorie primarie dei confronti o qualsiasi altra regola economica.

## 32.6 Adeguamento dei riferimenti precedenti

Ai fini della coerenza dell'intera specifica:

* il §7.3 ammette esclusivamente `Collegato a`;
* il §23.13 non utilizza più `Sostituisce` come criterio di correlazione;
* ogni riferimento a `Sostituito` nei report è eliminato;
* l'Invariante §28.60 e FR-095 continuano a riguardare le relazioni informative senza effetto economico, ma nel dominio corrente si applicano esclusivamente a `Collegato a`;
* le regole generiche di Proposta, riallineamento, Snapshot, Timeline, Archivio e audit delle relazioni informative continuano a valere per `Collegato a`.

La rimozione di `Sostituisce` **MUST NOT** essere interpretata come rimozione generale del concetto di relazione informativa.
