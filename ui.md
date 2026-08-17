# UX/UI Reference

**Project:** MP2 — IT Spend Governance  
**Stack di riferimento:** Laravel + Filament  
**Stato:** riferimento visuale e comportamentale vincolante per l'implementazione UI  
**Versione:** 1.0  
**Data:** 17 agosto 2026

---

## 0. Scopo del documento

Questo documento definisce il linguaggio UI/UX che ogni agent deve seguire per mantenere l'applicazione coerente, leggibile e visivamente premium.

Non sostituisce la Specifica Canonica di dominio. In caso di conflitto:

1. la **Specifica Canonica di dominio prevale sempre** su questo documento;
2. questo documento decide **come presentare** un comportamento già ammesso dal dominio;
3. la UI **MUST NOT** introdurre nuovi concetti economici, stati, workflow o inferenze non previsti dal dominio;
4. in caso di dubbio funzionale, l'agent **MUST NOT inventare**: deve fermarsi alla presentazione del dato realmente disponibile.

Le immagini approvate nella conversazione sono riferimento visuale. Le regole qui sotto ne cristallizzano lo stile e correggono gli elementi del mockup che erano soltanto illustrativi o non coerenti con il dominio.

---

# 1. Principi non negoziabili

## 1.1 Obiettivo visivo

L'interfaccia deve apparire:

- professionale;
- sobria;
- molto leggibile;
- densa ma non affollata;
- moderna senza effetti decorativi inutili;
- coerente fra dashboard, tabelle, form, dettagli, report e flussi operativi;
- adatta a un prodotto B2B premium usato quotidianamente.

L'aspetto premium deve derivare da:

- gerarchia tipografica chiara;
- spaziature coerenti;
- ottimo allineamento;
- superfici pulite;
- colori controllati;
- feedback di stato precisi;
- interazioni prevedibili;
- assenza di rumore visivo.

Non deve derivare da gradienti, animazioni vistose, ombre pesanti o decorazioni.

## 1.2 Semplicità prima della personalizzazione

Quando Filament offre un componente nativo adeguato, l'agent **SHOULD** usare quello e adattarlo tramite il tema applicativo.

L'agent **MUST NOT** creare un componente custom solo per ottenere una differenza estetica minima.

L'agent **MUST NOT** modificare direttamente file sotto `/vendor` o file appartenenti ai plugin. Le personalizzazioni devono vivere nel codice e nel tema dell'applicazione, usando i punti di estensione pubblici disponibili.

## 1.3 Nessuna semantica inventata

Un elemento grafico non può creare una semantica che il dominio non possiede.

Esempi vincolanti:

- una **Spesa non ha un tipo economico unico**: `Stima` ed `Effettivo` appartengono alle **Righe**;
- un Contratto non deve mostrare `Prossima fattura`, `Stato pagamento`, `Importo fattura` o concetti equivalenti;
- non deve esistere un workflow visuale obbligatorio `Budget Owner → Direttore IT → CFO` se non specificato nel dominio;
- non deve esistere una metrica globale chiamata genericamente `Disponibilità` se il significato non è definito;
- non devono essere presentati Actual mensili come affidabili, perché la baseline non possiede una Data Effettivo economica strutturata;
- `Riporto` e `Riprogrammazione` devono essere presentati come modalità distinte e mutuamente esclusive;
- Archivio, Storno e stato di ciclo di vita devono restare visualmente distinti.

---

# 2. Identità visuale

## 2.1 Logo e branding

Per il momento:

- **nessun logo**;
- nessun wordmark;
- nessuna area vuota riservata a un futuro logo;
- nessun elemento decorativo che simuli un marchio.

La sidebar inizia direttamente con la navigazione.

Quando in futuro verrà definita un'identità visiva, il logo potrà essere introdotto senza modificare la struttura del layout.

## 2.2 Profilo utente

Non deve essere presente nella sidebar un blocco persistente con:

- avatar;
- nome;
- ruolo;
- menu personale espanso.

È rumore visivo e occupa spazio utile.

Se sono necessarie azioni account/logout, usare un controllo discreto nella toolbar globale o nel menu utente standard di Filament, senza trasformarlo in un elemento primario dell'interfaccia.

## 2.3 Palette principale

I colori seguenti derivano dal linguaggio visuale del mockup approvato e costituiscono i token di riferimento.

| Token | Valore | Uso |
|---|---:|---|
| `sidebar-bg` | `#01162D` | Sidebar |
| `primary` | `#0057F5` | Navigazione attiva, CTA primaria, link principali |
| `primary-hover` | `#004BD6` | Hover CTA primaria |
| `primary-soft` | `#F2F6FE` | Riga selezionata, superfici informative leggere |
| `page-bg` | `#FAFBFC` | Background principale |
| `surface` | `#FFFFFF` | Card, pannelli, drawer, tabelle |
| `surface-muted` | `#F8FAFC` | Header tabella, superfici secondarie |
| `border` | `#E5EAF1` | Bordi standard |
| `border-strong` | `#D7DEE8` | Bordi di controllo e separatori importanti |
| `text-primary` | `#081625` | Titoli e valori principali |
| `text-secondary` | `#475569` | Testi descrittivi |
| `text-muted` | `#64748B` | Metadata e helper |
| `success` | `#16A34A` | Stato positivo semanticamente certo |
| `success-soft` | `#EAF8EF` | Badge success |
| `warning` | `#F59E0B` | Attenzione |
| `warning-soft` | `#FFF7E6` | Badge warning |
| `danger` | `#DC2626` | Errori, blocchi, sovraspesa quando semanticamente corretta |
| `danger-soft` | `#FEF2F2` | Badge danger |
| `info` | `#2563EB` | Informazioni e stato neutro informativo |
| `info-soft` | `#EFF6FF` | Badge info |

### Regola

L'agent **MUST NOT** aggiungere nuovi colori principali per singole pagine.

Nuovi colori sono ammessi solo quando rappresentano una semantica necessaria non coperta dai token esistenti.

## 2.4 Colori economici

I colori non devono semplificare eccessivamente il dominio.

Regole:

- `Stima` → blu;
- `Effettivo` → verde;
- valore neutro/informativo → testo standard;
- Sovraspesa certa → rosso;
- warning o dato da verificare → ambra;
- `Risparmio` può essere verde **solo** quando il dominio lo qualifica realmente come Risparmio;
- uno Scostamento negativo non deve essere colorato automaticamente di verde in ogni contesto;
- un valore positivo non deve essere colorato automaticamente di verde: per uno Scostamento Operativo positivo può significare Sovraspesa.

Il significato testuale prevale sempre sul colore.

## 2.5 Tipografia

Usare la font stack sans-serif standard configurata globalmente da Filament/Tailwind.

Non introdurre font differenti per singole pagine.

Gerarchia:

| Elemento | Dimensione indicativa | Peso |
|---|---:|---:|
| Titolo pagina | 24–28 px | 700 |
| Titolo dettaglio principale | 22–26 px | 700 |
| Titolo sezione/card | 14–16 px | 600–700 |
| Valore KPI | 22–28 px | 700 |
| Corpo | 14 px | 400–500 |
| Tabella | 13–14 px | 400–600 |
| Metadata/helper | 12–13 px | 400–500 |
| Badge | 11–12 px | 500–600 |

Regole:

- evitare tutto maiuscolo nei contenuti;
- le label dei gruppi sidebar possono usare maiuscolo leggero e dimensione ridotta;
- importi e numeri devono essere facilmente scansionabili;
- non usare font-weight 700 su intere tabelle.

---

# 3. Spaziatura, bordi e superfici

## 3.1 Griglia di spaziatura

Usare una griglia base di **4 px**, con preferenza per multipli di 8.

Token principali:

- `4 px` → micro-gap;
- `8 px` → gap fra elementi strettamente correlati;
- `12 px` → gap piccolo;
- `16 px` → padding compatto;
- `20 px` → padding card standard;
- `24 px` → gap fra blocchi principali;
- `32 px` → separazione macro;
- `40–48 px` → solo fra sezioni molto distinte.

L'agent **MUST NOT** scegliere spaziature arbitrarie diverse per ogni pagina.

## 3.2 Border radius

| Componente | Radius |
|---|---:|
| Input / select / button | 7–8 px |
| Badge | 999 px oppure pill |
| Card | 10–12 px |
| Drawer / pannello importante | 12 px |
| Tooltip | 6–8 px |

Evitare radius molto grandi sulle card.

## 3.3 Ombre

Le ombre devono essere minime.

Card ordinarie:

```css
box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
```

Pannelli elevati / drawer:

```css
box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
```

Il bordo deve restare il principale elemento di separazione.

## 3.4 Card

Ogni card standard usa:

- background bianco;
- border 1 px;
- radius 10–12 px;
- padding 20 px;
- titolo allineato in alto a sinistra;
- eventuale azione secondaria in alto a destra;
- nessuna decorazione gratuita.

Le card KPI possono usare un'icona discreta, ma non è obbligatoria.

---

# 4. Layout globale

## 4.1 Struttura desktop

Desktop è il target primario.

Layout:

```text
┌───────────────┬──────────────────────────────────────────────┐
│ Sidebar       │ Page header                                  │
│ 216–224 px    │ ──────────────────────────────────────────── │
│               │ Contenuto fluido                             │
│               │                                              │
└───────────────┴──────────────────────────────────────────────┘
```

Regole:

- sidebar fissa a sinistra;
- contenuto principale fluido;
- niente contenitore centrale eccessivamente stretto;
- padding pagina desktop: 24 px;
- gap fra sezioni: 24 px;
- il contenuto deve sfruttare bene schermi 1440–1920 px;
- evitare card enormi con grandi aree vuote.

## 4.2 Sidebar

Dimensione desktop: **216–224 px**.

Background: `sidebar-bg`.

Navigazione proposta:

```text
PANORAMICA
Dashboard

PIANIFICAZIONE
Budget
Proposte

OPERATIVITÀ
Spese
Progetti
Contratti
Scadenze

ANAGRAFICHE
Fornitori
Centri di Costo

REPORTING
Report

AMMINISTRAZIONE
Impostazioni
Utenti e Permessi
```

### Regole della sidebar

- niente logo;
- niente blocco utente;
- gruppi chiaramente separati;
- una sola voce attiva;
- voce attiva: fondo `primary`, testo e icona bianchi;
- hover non attivo: fondo bianco con opacità molto bassa;
- icone lineari coerenti, stessa famiglia;
- icona 18–20 px;
- altezza item circa 40 px;
- label gruppo 10–11 px;
- supportare collasso della sidebar;
- in modalità collassata mostrare tooltip sul passaggio del mouse;
- `Riduci menu` può stare in fondo;
- non creare voci `Analisi`, `Esportazioni` o altre sezioni solo per riempire il menu: devono esistere soltanto se la funzione è realmente implementata e distinta.

## 4.3 Page header

Ogni pagina usa lo stesso schema.

A sinistra:

- titolo;
- sottotitolo opzionale di una riga;
- breadcrumb solo sui dettagli profondi.

A destra:

- contesto Azienda quando necessario;
- **selettore globale dell'Esercizio**;
- azioni secondarie;
- azione primaria.

Esempio:

```text
Spese
Elenco e dettaglio delle spese

                              [Azienda Demo] [2025 ▾] [Esporta] [+ Nuova spesa]
```

Non inserire toolbars differenti per ogni Resource senza necessità.

---

# 5. Selettore globale dell'Esercizio

Il selettore globale dell'anno è un elemento strutturale dell'applicazione.

## 5.1 Posizione

Deve stare:

- nella parte alta del contenuto;
- allineato a destra;
- nella stessa posizione su tutte le pagine;
- prima delle azioni di pagina.

Non deve essere duplicato dentro widget o filtri locali, salvo una funzione che confronti esplicitamente più Esercizi.

## 5.2 Comportamento

La selezione dell'Esercizio:

- modifica il contesto annuale di tutte le viste annuali;
- deve essere preservata durante la navigazione;
- deve essere evidente;
- non deve cambiare silenziosamente;
- deve riflettersi nei titoli o metadata dei report quando il riferimento annuale è rilevante.

Quando l'utente apre una pagina non strettamente annuale, l'Esercizio selezionato resta comunque il contesto per i riepiloghi economici annuali eventualmente presenti.

## 5.3 Azienda

L'Azienda e l'Esercizio sono contesti distinti.

Se l'utente può accedere a più Aziende:

- mostrare un selettore Azienda compatto vicino al selettore dell'Esercizio;
- il cambio Azienda deve aggiornare il contesto completo;
- non usare il vecchio blocco utente/avatar come selettore Azienda.

Se l'utente ha accesso a una sola Azienda, il selettore può diventare una label non interattiva o essere omesso quando il contesto è inequivocabile.

---

# 6. Pulsanti e azioni

## 6.1 Gerarchia

Sono ammessi tre livelli principali.

### Primary

Uso:

- Nuova Spesa;
- Nuovo Progetto;
- Nuovo Contratto;
- Approva Proposta;
- conferma finale di un'operazione.

Stile:

- background `primary`;
- testo bianco;
- icona opzionale;
- una sola CTA primaria dominante per area.

### Secondary

Uso:

- Esporta;
- Salva bozza;
- azioni non distruttive secondarie.

Stile:

- background bianco;
- border;
- testo scuro.

### Tertiary / text

Uso:

- link contestuali;
- `Vedi dettagli`;
- `Annulla`;
- azioni leggere.

## 6.2 Menu Azioni

Usare un menu `Azioni` quando:

- esistono più di 2–3 azioni secondarie;
- le azioni sono contestuali all'oggetto;
- alcune azioni sono rare.

Non nascondere nel menu l'azione principale della pagina.

## 6.3 Azioni distruttive o irreversibili

Storno, Archivio, Chiusura e altre operazioni importanti devono:

- avere label esplicita;
- mostrare conseguenze;
- chiedere conferma quando previsto;
- mostrare eventuale Nota obbligatoria;
- non affidarsi al solo colore rosso;
- non usare testi vaghi come `Conferma` senza specificare cosa verrà confermato.

---

# 7. Badge e stati

## 7.1 Stile

Badge:

- piccoli;
- pill;
- background soft;
- testo semantico;
- mai saturi al punto da dominare la riga.

## 7.2 Regole

Ogni stato deve avere:

- label testuale;
- colore coerente;
- stesso colore in tutta l'app.

Non usare due colori differenti per lo stesso stato in pagine differenti.

Esempi:

- `Aperto` → verde;
- `Pianificato` → blu;
- `Chiuso` → neutro;
- `Cancellato` → rosso o neutro-danger;
- `Cessato` → neutro;
- `Annullato` → rosso/neutro-danger;
- `Da riallineare` → ambra;
- `Incoerente` → rosso;
- `Da prendere in visione` → blu/ambra a seconda della severità informativa definita dal componente;
- `Archiviato` → grigio;
- `Stornata` → grigio/rosso tenue.

La proprietà Archivio non deve far sparire lo stato economico o di ciclo di vita.

---

# 8. Tabelle

Le tabelle sono un elemento primario del prodotto e devono essere progettate per uso intensivo.

## 8.1 Aspetto

- header `surface-muted`;
- altezza riga circa 46–52 px;
- separatori sottili;
- niente zebra striping marcato;
- hover leggero;
- riga selezionata `primary-soft`;
- testo 13–14 px;
- importi allineati a destra;
- azioni allineate a destra;
- checkbox solo quando esistono azioni bulk reali.

## 8.2 Filtri

I filtri più usati devono essere visibili sopra la tabella.

Pattern:

```text
[Cerca........................] [CdC ▾] [Stato ▾] [Fornitore ▾] [Contenitore ▾] [Filtri]
```

Sotto possono esserci quick filter/chip realmente utili.

Non mostrare dieci select permanenti.

Filtri avanzati meno frequenti devono stare nel pannello `Filtri`.

## 8.3 Ricerca

Ricerca:

- sempre visibile nelle pagine elenco principali;
- placeholder specifico;
- non usare `Cerca...` quando può essere più chiaro.

Esempio:

`Cerca per codice o descrizione…`

## 8.4 Ordinamento

Le colonne principali ordinabili devono mostrare un indicatore discreto.

Non mostrare icone di ordinamento su colonne non ordinabili.

## 8.5 Paginazione

Footer tabella:

- risultati totali;
- numero righe per pagina;
- intervallo corrente;
- paginazione.

Mantenere questo schema costante.

---

# 9. Pattern master/detail

Per entità con dettaglio ricco, il pattern preferito su desktop è:

```text
┌───────────────────────────────┬───────────────────────┐
│ Tabella / elenco              │ Drawer dettaglio      │
│                               │                       │
│                               │                       │
└───────────────────────────────┴───────────────────────┘
```

È il pattern di riferimento della pagina **Spese**.

## 9.1 Drawer

Larghezza desktop indicativa:

- 380–480 px per dettagli semplici;
- fino a 520 px quando contiene una tabella di righe.

Il drawer:

- non deve sovrapporsi in modo opaco a tutta la pagina se c'è spazio;
- deve poter essere chiuso chiaramente;
- deve avere header sticky quando il contenuto scorre;
- mantiene le azioni oggetto in alto;
- usa sezioni ben separate.

Su schermi piccoli diventa full-screen.

## 9.2 Navigazione

Click sulla riga:

- apre il drawer di dettaglio;
- non deve richiedere di cliccare un minuscolo link.

Per una pagina di dettaglio completa, prevedere un'azione `Apri dettaglio completo`.

---

# 10. Pagina Spese — riferimento principale

Questa pagina è il riferimento visuale più importante per le Resource tabellari.

## 10.1 Header

```text
Spese
Elenco e dettaglio delle spese

                    [Esercizio globale 2025 ▾] [Esporta] [+ Nuova Spesa] [Azioni ▾]
```

## 10.2 KPI superiori

Massimo 4 card.

Valori consigliati, se disponibili per il contesto selezionato:

- Totale Allocato delle Spese rappresentate;
- Totale Stime;
- Totale Effettivi;
- Spese senza Fornitore oppure altra metrica realmente definita e utile.

L'agent deve verificare che non vi sia doppio conteggio.

Se una metrica non ha una definizione chiara nel dominio, **non mostrarla**.

## 10.3 Tabella Spese

La Spesa può contenere sia Stime sia Effettivi. Perciò **MUST NOT** esistere una colonna `Tipo` a livello Spesa che la classifichi come `Stima` oppure `Effettivo`.

Colonne di default:

1. selezione, solo se servono azioni bulk;
2. Codice / ID leggibile;
3. Descrizione;
4. Contenitore;
5. Centro di Costo;
6. Fornitore;
7. Stima;
8. Effettivo;
9. Scostamento;
10. Stato Spesa;
11. azioni.

Su viewport ridotto, le colonne meno importanti possono essere nascoste progressivamente, ma Stima ed Effettivo non devono essere fusi in un singolo `Importo`.

### Contenitore

Mostrare una label esplicita:

- `Autonoma`;
- nome Progetto;
- nome Contratto.

La natura del contenitore può essere indicata da una piccola icona o metadata, senza aggiungere una colonna ridondante se il nome è sufficiente.

## 10.4 Espansione delle Righe

L'utente deve poter vedere le Righe **senza abbandonare l'elenco**.

Ogni Spesa deve avere un controllo expand/collapse.

Riga espansa:

```text
Spesa
└── Righe
    ├── [Stima]      Descrizione | Quantità | Unitario | Importo | Stato
    ├── [Effettivo]  Descrizione | Quantità | Unitario | Importo | Stato
    └── ...
```

Regole:

- le Righe sono visualmente subordinate;
- fondo leggermente differente dalla riga padre;
- indentazione chiara;
- `Tipo` compare qui perché appartiene alla Riga;
- `Importo` è il valore autoritativo;
- Quantità e Importo unitario sono mostrati solo quando presenti;
- una Riga Annullata resta leggibile ma non deve sembrare attiva;
- le Righe non devono essere conteggiate una seconda volta nei totali della tabella.

## 10.5 Drawer Dettaglio Spesa

Ordine delle sezioni:

### A. Header

- Codice;
- Descrizione;
- badge Attiva/Stornata;
- menu Azioni.

### B. Riepilogo

Sinistra:

- Esercizio;
- Contenitore;
- Centro di Costo;
- Fornitore.

Destra:

- Totale Stima;
- Totale Effettivo;
- Scostamento.

### C. Note

Mostrare Note solo se presenti; in assenza non lasciare grande spazio vuoto.

### D. Righe della Spesa

Tabella compatta:

- Tipo;
- Descrizione Riga;
- Quantità;
- Importo unitario;
- Importo;
- Stato;
- azioni.

CTA: `+ Aggiungi riga`, solo quando consentita dal dominio e dai permessi.

### E. Timeline

Mostrare gli eventi più recenti.

Ogni evento:

- timestamp;
- azione;
- autore;
- breve descrizione;
- eventuale motivo.

Link: `Vedi Timeline completa`.

## 10.6 Creazione/modifica

Una Spesa semplice può essere creata in slide-over o pagina compatta.

La struttura deve distinguere:

1. dati Spesa;
2. contenitore;
3. classificazione;
4. Fornitore;
5. Righe.

Non chiedere un `Tipo Spesa = Stima/Effettivo`.

Il tipo viene scelto su ogni Riga.

---

# 11. Dashboard

La Dashboard deve essere una sintesi, non una seconda area di reportistica completa.

## 11.1 Header

- titolo `Dashboard`;
- sottotitolo breve;
- selettore Azienda quando necessario;
- selettore globale Esercizio.

## 11.2 KPI

Massimo 4 KPI principali.

Base raccomandata:

1. Budget Approvato selezionato;
2. Allocato Corrente;
3. Effettivo;
4. Scostamento Operativo.

Se non esiste Budget:

- mostrare chiaramente `Budget assente`;
- non convertire il valore in zero se ciò altererebbe il significato.

Se l'Esercizio è Chiuso, i riferimenti alla Chiusura devono essere espliciti.

## 11.3 Grafico principale

Il linguaggio visuale approvato è il grafico lineare pulito con:

- linea blu per il riferimento di piano;
- linea verde per l'Effettivo;
- griglia molto leggera;
- punti piccoli;
- legenda essenziale;
- nessun effetto 3D.

**Vincolo funzionale:** non usare questo grafico per mostrare Actual mensili o trimestrali come fatti economici affidabili finché il dominio non dispone di una Data Effettivo strutturata.

Uso corretto nella baseline:

- confronto annuale fra Esercizi usando la stessa misura;
- serie temporali basate su dati realmente disponibili e semanticamente espliciti.

Esempio valido:

`Budget corrente / Allocato / Effettivo per Esercizio` su più anni.

Il grafico deve dichiarare il riferimento usato.

## 11.4 Secondo grafico

Non usare donut/pie come visualizzazione principale della ripartizione per Centro di Costo.

Pattern preferito:

- barre orizzontali;
- una riga per Centro di Costo;
- Allocato;
- Effettivo;
- percentuale solo se definita chiaramente;
- ordinamento coerente, preferibilmente per Allocato decrescente.

## 11.5 Blocchi inferiori

Massimo 3–4 card compatte:

- Stato Progetti;
- Alert e Scadenze;
- Esercizi;
- eventuali controlli/attenzioni realmente definiti.

Non inserire widget solo per riempire la griglia.

---

# 12. Pagina Progetti

## 12.1 Elenco

Struttura coerente con Spese.

Colonne raccomandate:

- Titolo;
- Stato alla data del contesto;
- Centro di Costo;
- Allocato;
- Effettivo;
- Scostamento;
- modalità di rinvio per il passaggio rilevante, quando applicabile;
- azioni.

## 12.2 Dettaglio

Header:

- Titolo;
- stato;
- Esercizio globale;
- azioni.

Tab consigliate:

1. Panoramica;
2. Spese;
3. Rinvio;
4. Timeline;
5. Allegati;
6. Relazioni.

Non mostrare tab vuote.

## 12.3 Riepilogo economico

Mostrare distintamente:

- Riporto ricevuto;
- Stime;
- Allocato Corrente;
- Effettivo;
- Scostamento;
- Residuo solo quando semanticamente applicabile;
- Riporto/Riprogrammazione quando presenti.

Non fondere `Residuo`, `Risparmio` e `Allocato non utilizzato`: il significato dipende dallo stato del Progetto.

---

# 13. Pagina Contratti

## 13.1 Elenco

Colonne raccomandate:

- Titolo;
- Fornitore;
- Stato;
- Centro di Costo;
- Allocato;
- Effettivo;
- Scostamento;
- Prossima scadenza;
- Rinnovo automatico;
- azioni.

## 13.2 Dettaglio

Tab:

1. Panoramica;
2. Condizioni economiche;
3. Scadenze e rinnovi;
4. Spese Effettive;
5. Timeline;
6. Allegati;
7. Relazioni.

## 13.3 Panoramica

Card `Dettagli Contratto`:

- Fornitore;
- stato;
- Data di inizio;
- prossima scadenza oppure `Scadenza non definita`;
- rinnovo automatico;
- durata rinnovo;
- preavviso;
- Data limite disdetta, se calcolabile.

Card `Riepilogo economico — {anno}`:

- Allocato;
- Effettivo;
- Scostamento;
- condizione economica corrente o link alle condizioni.

### Vietato

Non mostrare:

- prossima fattura;
- importo fattura;
- stato pagamento;
- rata;
- insoluto;
- scadenza pagamento.

## 13.4 Timeline scadenze

La timeline grafica delle scadenze è ammessa e raccomandata.

Deve distinguere:

- oggi;
- data limite di disdetta;
- prossima scadenza;
- rinnovo previsto quando derivabile;
- cessazione pianificata.

Le label devono essere testuali: il colore da solo non basta.

---

# 14. Pagina Scadenze

La sezione Scadenze è informativa e dedicata ai Contratti.

## 14.1 Vista

Preferire tabella ordinabile con:

- Contratto;
- Fornitore;
- stato;
- prossima scadenza;
- rinnovo automatico;
- durata;
- preavviso;
- Data limite disdetta;
- giorni mancanti;
- Centro di Costo;
- warning `Rinnovo senza condizione economica`, quando presente.

## 14.2 Filtri

Minimo:

- intervallo scadenza;
- intervallo termine disdetta;
- rinnovo automatico sì/no;
- scadenza non definita;
- stato;
- Fornitore;
- Centro di Costo.

Non chiamare questa pagina `Fatture`, `Pagamenti` o `Scadenzario` senza specificare `Contratti`.

---

# 15. Proposte

La Proposta è uno spazio di lavoro sul piano.

## 15.1 Header

Mostrare:

- `Proposta Budget {anno}` oppure `Revisione Budget {anno}`;
- stato;
- ultimo riallineamento;
- azioni.

Azioni tipiche:

- Salva bozza;
- Scarta;
- Approva, se l'utente possiede il permesso e la Proposta è approvabile.

Non introdurre un pulsante `Invia per approvazione` se non esiste un relativo stato/processo nel dominio.

## 15.2 Tab

1. Panoramica;
2. Azioni proposte;
3. Riallineamento;
4. Confronto;
5. Note e Allegati.

## 15.3 Panoramica

Card superiori:

- Allocato corrente di riferimento;
- Allocato risultante proposto;
- delta;
- numero azioni;
- stato di approvabilità.

Tabella per sorgente:

- tipo sorgente;
- nuove;
- modificate;
- escluse/rimosse dal piano quando semanticamente corretto;
- impatto.

## 15.4 Stato di approvabilità

Al posto di un workflow approvativo multilivello, mostrare un pannello `Stato approvabilità`:

- Da prendere in visione;
- Da riallineare;
- Incoerenze;
- dati obbligatori mancanti;
- Esercizi interessati;
- esito finale `Approvabile` / `Non approvabile`.

Ogni blocco deve essere cliccabile per raggiungere il problema.

## 15.5 Riallineamento

Per ogni sorgente:

- stato;
- ultima revisione base;
- motivo del riallineamento;
- CTA:
  - `Ricarica realtà`;
  - `Mantieni proposta`;
  - `Rivedi manualmente`.

Le tre azioni devono essere spiegate in UI; evitare etichette senza contesto.

---

# 16. Budget e Snapshot

## 16.1 Elenco Budget

Mostrare le versioni come oggetti immutabili.

Colonne:

- versione;
- finalità;
- data approvazione;
- autore;
- totale Allocato Approvato;
- motivazione;
- azioni `Visualizza`, `Confronta`, `Esporta`.

Non offrire `Modifica`.

## 16.2 Dettaglio Budget

Header:

- Esercizio;
- versione;
- badge `Immutabile`;
- data approvazione;
- riferimento alla Proposta.

Riepilogo:

- Allocato Approvato;
- versione precedente;
- motivazione;
- Esercizi interessati.

Dettaglio sorgenti in tabella con drill-down.

## 16.3 Chiusura

La Snapshot di Chiusura deve avere una presentazione distinta dal Budget.

Non riutilizzare la stessa pagina cambiando soltanto il titolo.

Mostrare chiaramente:

- `Snapshot di Chiusura`;
- data tecnica di Chiusura;
- stato valutato al 31 dicembre;
- Allocato finale;
- Effettivo alla Chiusura;
- Scostamento;
- Riporto consolidato;
- avvisi accettati.

---

# 17. Report

## 17.1 Struttura

La landing `Report` usa card semplici e omogenee, non una dashboard parallela.

Categorie minime coerenti con il dominio:

- Situazione annuale;
- Budget vs Actual;
- Budget vs Allocato Corrente;
- Scostamento Operativo;
- Progetti;
- Contratti;
- Riporti;
- Fornitori;
- confronto versioni Budget;
- confronto Esercizi;
- correzioni tardive.

## 17.2 Header report

Ogni report mostra sempre in alto:

- Azienda;
- Esercizio;
- riferimento temporale;
- versione Budget, se applicabile;
- tipo Effettivo;
- data/ora generazione;
- filtri.

Queste informazioni non devono essere nascoste in tooltip.

## 17.3 Numeri

Nei KPI e grafici è ammessa una rappresentazione abbreviata quando serve leggibilità.

Nelle tabelle e nei dettagli:

- usare importo completo;
- separatore migliaia locale;
- due decimali;
- simbolo `€`;
- valori negativi con segno `-`.

Esempio:

`€ 18.450,00`

---

# 18. Form

## 18.1 Layout

Form semplici:

- 1–2 colonne desktop;
- 1 colonna mobile;
- label sopra il campo;
- helper text solo quando utile.

Form complessi:

- raggruppare per significato;
- non mettere 20 campi in un'unica card;
- usare sezioni collassabili solo per contenuto secondario.

## 18.2 Input economici

Importo:

- allineamento numerico coerente;
- valuta EUR visibile;
- due decimali nell'output;
- la UI deve distinguere Importo autoritativo da Quantità e Importo unitario.

Quando Quantità e Importo unitario sono presenti, l'eventuale importo suggerito deve essere presentato come suggerimento, non come valore autoritativo implicito.

## 18.3 Note obbligatorie

Quando il dominio richiede una Nota:

- il campo deve essere visibile nel flusso;
- deve spiegare perché è richiesto;
- il submit deve essere bloccato senza Nota;
- non chiedere la Nota in un secondo popup scollegato quando può essere parte del form principale.

## 18.4 Validazione

Errori:

- inline sul campo;
- messaggio di dominio leggibile;
- summary in alto solo per form lunghi o errori multipli.

Non usare messaggi tecnici come:

`Validation failed`.

Usare:

`La Spesa non può essere spostata perché contiene Effettivi e la Proposta non può riclassificarli.`

---

# 19. Piano d'impatto

Per operazioni che modificano più Esercizi o più sorgenti, la UI deve mostrare il piano d'impatto prima della conferma.

Pattern:

```text
Impatto dell'operazione

Esercizio 2026
Allocato: € 10.000 → € 8.000
Effettivo: invariato
Budget approvato: invariato

Esercizio 2027
Allocato: € 4.000 → € 6.000
Proposta: diventerà Da riallineare
```

Regole:

- usare valori prima → dopo;
- evidenziare delta;
- mostrare blocchi e warning separatamente;
- non nascondere effetti su anni diversi in un testo generico;
- la CTA finale deve specificare l'azione.

---

# 20. Timeline e audit

## 20.1 Aspetto

Timeline verticale, compatta.

Ogni item:

- marker;
- timestamp;
- nome evento;
- autore;
- descrizione;
- motivo quando presente.

## 20.2 Colore marker

Il colore è secondario.

Usare:

- blu → evento informativo/modifica;
- verde → completamento/approvazione;
- ambra → warning/riallineamento;
- rosso → errore/blocco/annullamento;
- grigio → evento storico neutro.

Non usare una timeline multicolore decorativa senza significato.

---

# 21. Grafici

## 21.1 Stile

Tutti i grafici devono avere:

- background trasparente dentro una card;
- griglia molto leggera;
- assi discreti;
- label leggibili;
- tooltip coerente;
- legenda piccola;
- niente 3D;
- niente ombre sulle serie;
- niente gradienti forti;
- niente animazioni lunghe.

## 21.2 Line chart

È il pattern preferito per:

- trend temporali realmente supportati;
- confronto anno-su-anno;
- serie ordinate.

Il grafico lineare dell'ultima proposta è riferimento estetico.

## 21.3 Bar chart

Preferire barre orizzontali per:

- Centri di Costo;
- Fornitori;
- ranking;
- confronti fra molte categorie.

## 21.4 Pie/donut

Uso molto limitato.

Non usarlo quando:

- servono confronti precisi;
- ci sono molte categorie;
- le label diventano piccole;
- la stessa informazione è più leggibile con barre.

Il donut presente in una proposta precedente **non è** il pattern di riferimento.

---

# 22. Empty, loading, error e success states

## 22.1 Empty state

Deve spiegare:

- cosa manca;
- perché la pagina è vuota;
- cosa può fare l'utente.

Esempio:

```text
Nessuna Spesa nel 2027
Non risultano Spese per l'Esercizio selezionato.
[Nuova Spesa]
```

Non mostrare illustrazioni decorative grandi.

## 22.2 Loading

Preferire skeleton coerenti con il layout.

Evitare spinner centrali su pagine intere quando è possibile mantenere visibile la struttura.

## 22.3 Errori

Errori di dominio:

- inline/persistent;
- vicino all'operazione;
- con motivazione.

Toast da solo non è sufficiente per un blocco importante.

## 22.4 Success

Toast discreto per operazioni riuscite.

Non mostrare modali celebrative.

---

# 23. Responsive

Il prodotto è desktop-first, ma non deve rompersi su viewport inferiori.

## 23.1 Breakpoint concettuali

### Desktop ampio — ≥ 1440 px

- sidebar completa;
- tabelle ricche;
- master/detail affiancato;
- KPI 4 colonne.

### Desktop / laptop — 1024–1439 px

- sidebar completa o collassabile;
- KPI 2–4 colonne secondo spazio;
- drawer più stretto;
- alcune colonne secondarie nascoste.

### Tablet — 768–1023 px

- sidebar collassata/off-canvas;
- KPI 2 colonne;
- drawer overlay;
- tabelle con scroll orizzontale.

### Mobile — < 768 px

- sidebar off-canvas;
- 1 colonna;
- drawer full-screen;
- filtri in pannello;
- tabelle con colonne essenziali;
- azioni importanti sempre raggiungibili.

## 23.2 Regola

Responsive non significa trasformare ogni tabella in decine di card.

Mantenere la semantica tabellare quando è utile e usare scroll/colonne adattive.

---

# 24. Accessibilità

Minimo richiesto:

- contrasto WCAG AA per testo e controlli;
- focus visibile;
- navigazione da tastiera;
- label associate agli input;
- icone con accessible name quando sono azioni;
- stato mai comunicato solo tramite colore;
- target click adeguati;
- tooltip non necessari per capire funzioni essenziali;
- `aria-expanded` per righe espandibili;
- drawer con focus trap corretto;
- modali con titolo e focus iniziale sensato.

---

# 25. Localizzazione e formato dati

Lingua UI baseline: italiano.

## 25.1 Date

Formato visuale:

`dd/mm/yyyy`

Timestamp:

`dd/mm/yyyy HH:mm`

Se serve distinguere fuso o data tecnica, indicarlo esplicitamente.

## 25.2 Importi

Formato:

`€ 1.250.000,00`

Grafici molto densi possono abbreviare:

`€ 1,25 M`

ma tooltip e drill-down devono mostrare il valore completo.

## 25.3 Percentuali

Usare massimo una cifra decimale salvo motivo reale.

## 25.4 Terminologia

Usare sempre i termini canonici:

- Esercizio;
- Budget Approvato;
- Allocato Corrente;
- Effettivo;
- Scostamento Operativo;
- Proposta;
- Snapshot di Chiusura;
- Riporto;
- Riprogrammazione;
- Stima;
- Effettivo;
- Centro di Costo;
- Fornitore.

Non sostituirli liberamente con sinonimi come `consuntivo`, `forecast`, `spesa prevista`, `budget residuo` se il significato cambia.

---

# 26. Filament — regole di implementazione UI

## 26.1 Principio

Filament deve restare riconoscibile come base tecnica, ma l'app non deve apparire come un pannello admin generico non personalizzato.

La personalizzazione deve essere ottenuta con:

- tema applicativo;
- token condivisi;
- configurazione globale;
- componenti Filament;
- view applicative solo dove realmente necessarie.

## 26.2 Preferenze

Usare, quando adatti:

- Resources per anagrafiche ed entità CRUD;
- Tables per gli elenchi;
- Forms per edit/create;
- Infolists o equivalenti per dettagli read-only;
- Actions per operazioni contestuali;
- Widgets per KPI e dashboard;
- Pages per Proposte, Budget, Chiusura e report complessi.

## 26.3 Evitare

- CSS inline;
- colori hardcoded dentro singole Resource;
- radius diversi per pagina;
- copie locali dello stesso componente;
- modali custom quando un'Action standard è sufficiente;
- grandi blocchi Blade custom solo per aggirare Filament;
- patch di `/vendor`;
- override non documentati di plugin.

## 26.4 Tema unico

Tutti i valori visuali riutilizzabili devono essere centralizzati nel tema/app stylesheet.

L'agent deve poter cambiare un token globale senza cercare valori sparsi nel progetto.

---

# 27. Pattern di interazione

## 27.1 Regola di densità

Il prodotto deve favorire la scansione rapida.

Preferire:

- una tabella ben progettata;
- drawer;
- dettagli progressivi;
- filtri persistenti;
- drill-down.

Evitare:

- un'enorme pagina verticale per ogni oggetto;
- modali annidate;
- wizard per operazioni semplici;
- card per ogni riga di dati su desktop.

## 27.2 Progressive disclosure

Mostrare per primo ciò che serve alla decisione.

Esempio Spesa:

1. riepilogo;
2. Righe;
3. Timeline;
4. allegati/dettagli secondari.

Non mostrare audit tecnico prima del contenuto economico.

## 27.3 Persistenza del contesto

Quando l'utente:

- apre un dettaglio;
- torna alla lista;
- cambia pagina;
- apre un report;

preservare, per quanto possibile:

- Azienda;
- Esercizio;
- filtri;
- ordinamento;
- pagina tabella;
- ricerca.

---

# 28. Pagine che non devono essere inventate

Un agent **MUST NOT** aggiungere automaticamente:

- Forecast;
- Fatture;
- Pagamenti;
- Ordini;
- Procurement;
- Cash Flow;
- IVA;
- Scadenzario fatture;
- Approval workflow multilivello;
- Plafond come entità;
- dashboard di TCO automatico;
- riconciliazione Stima → Effettivo;
- Actual mensili.

Una nuova voce di menu richiede una funzione reale prevista dal dominio o approvata successivamente.

---

# 29. Anti-pattern visuali

Sono vietati:

- logo provvisorio generato dall'agent;
- avatar/nome/ruolo persistenti nella sidebar;
- sidebar con 15 voci allo stesso livello;
- gradienti decorativi;
- glassmorphism;
- neumorphism;
- ombre pesanti;
- border radius eccessivo;
- card colorate senza motivo;
- donut per ogni aggregazione;
- troppi badge;
- icone di colori casuali;
- tabelle con troppe colonne senza gerarchia;
- `Tipo Spesa = Stima/Effettivo`;
- valori economici senza label esplicita;
- CTA primarie multiple in competizione;
- azioni distruttive nascoste o ambigue;
- tooltip come unico modo per capire un dato;
- modali dentro modali;
- toast usati come unico messaggio per errori di dominio.

---

# 30. Checklist obbligatoria per ogni nuova pagina

Prima di considerare una pagina UI completata, l'agent deve verificare:

## Struttura

- [ ] usa la sidebar globale senza logo e senza profilo persistente;
- [ ] usa il page header standard;
- [ ] il selettore globale Esercizio è presente quando il contesto lo richiede;
- [ ] l'Azienda è inequivocabile;
- [ ] non esistono duplicazioni del selettore annuale.

## Visual

- [ ] usa i token colore condivisi;
- [ ] usa spacing coerente;
- [ ] usa radius e bordi standard;
- [ ] non introduce un nuovo stile di card;
- [ ] non introduce una nuova famiglia di icone;
- [ ] mantiene la stessa gerarchia tipografica.

## Contenuto

- [ ] usa terminologia canonica;
- [ ] non introduce dati non previsti dal dominio;
- [ ] non presenta inferenze come fatti;
- [ ] Budget, Allocato, Effettivo e Scostamento sono distinti;
- [ ] lo stato visualizzato è accompagnato dal riferimento temporale quando necessario.

## Tabelle

- [ ] ricerca e filtri sono proporzionati;
- [ ] importi sono allineati e formattati;
- [ ] colonne superflue sono state rimosse;
- [ ] il click sulla riga ha un comportamento coerente;
- [ ] paginazione e numero risultati sono presenti;
- [ ] nessun doppio conteggio viene suggerito dalla UI.

## Form e azioni

- [ ] esiste una sola CTA primaria dominante;
- [ ] motivazioni obbligatorie sono richieste nel punto corretto;
- [ ] blocchi di dominio hanno messaggi comprensibili;
- [ ] le operazioni multi-Esercizio mostrano l'impatto;
- [ ] le azioni irreversibili hanno conferma esplicita;
- [ ] la UI non consente operazioni che il dominio vieta.

## Responsive/accessibilità

- [ ] funziona a 1024 px;
- [ ] non rompe sotto 768 px;
- [ ] focus da tastiera visibile;
- [ ] stato non comunicato solo tramite colore;
- [ ] drawer/modali sono accessibili;
- [ ] tabelle restano utilizzabili.

---

# 31. Checklist specifica Spese

- [ ] nessun `Tipo` a livello Spesa;
- [ ] Stima ed Effettivo sono colonne separate;
- [ ] ogni Spesa può espandere le proprie Righe;
- [ ] sulle Righe è visibile il Tipo `Stima/Effettivo`;
- [ ] Importo Riga è il valore autoritativo;
- [ ] Quantità e unitario sono secondari e opzionali;
- [ ] una Riga Annullata resta visibile nello storico;
- [ ] il drawer mostra riepilogo + Righe + Timeline;
- [ ] il drawer non duplica i totali della pagina in modo ambiguo;
- [ ] Contenitore distingue Autonoma, Progetto e Contratto;
- [ ] il Centro di Costo ereditato non è presentato come campo indipendente modificabile quando non lo è.

---

# 32. Checklist specifica Contratti

- [ ] nessuna fattura o rata;
- [ ] nessuno stato pagamento;
- [ ] prossima scadenza contrattuale correttamente nominata;
- [ ] rinnovo automatico separato dal ciclo economico;
- [ ] preavviso e Data limite disdetta distinti;
- [ ] condizioni economiche in tab dedicata;
- [ ] Effettivi mostrati tramite Spese manuali;
- [ ] nessun matching Effettivo → ciclo;
- [ ] `Scadenza non definita` quando appropriato;
- [ ] warning `Rinnovo senza condizione economica` se presente nel dominio.

---

# 33. Checklist specifica Proposte

- [ ] nessun workflow multilivello inventato;
- [ ] nessun Effettivo modificabile;
- [ ] stato di approvabilità esplicito;
- [ ] `Da prendere in visione`, `Da riallineare`, `Incoerente` sono distinguibili;
- [ ] i problemi portano direttamente alla sorgente interessata;
- [ ] le tre azioni di riallineamento sono disponibili quando previste;
- [ ] l'impatto su più Esercizi è visibile;
- [ ] approvazione è bloccata quando il dominio lo richiede.

---

# 34. Criterio finale di accettazione visuale

Una pagina è coerente con il prodotto solo se, osservandola accanto alla pagina Spese di riferimento:

- sembra appartenere alla stessa applicazione;
- usa la stessa densità;
- usa gli stessi allineamenti;
- usa lo stesso sistema di card;
- usa la stessa gerarchia di azioni;
- usa gli stessi badge;
- usa gli stessi filtri;
- usa gli stessi pattern di dettaglio;
- non introduce nuovi concetti funzionali;
- non richiede una spiegazione speciale per capire come interagire.

Se per ottenere una pagina è necessario creare eccezioni visuali locali, l'agent deve prima verificare se la differenza è realmente richiesta dal contenuto. In caso contrario deve riutilizzare i pattern di questo documento.

---

# 35. Sintesi operativa per l'agent

Quando implementi una nuova UI:

1. determina l'Azienda e l'Esercizio di contesto;
2. identifica quali dati sono realmente previsti dal dominio;
3. scegli il pattern già esistente più vicino;
4. usa il layout globale;
5. usa i token condivisi;
6. mantieni una sola CTA primaria;
7. usa tabelle per dati densi;
8. usa drawer per dettaglio rapido;
9. usa pagina completa solo per flussi o dettagli complessi;
10. mostra Stima ed Effettivo separatamente;
11. mostra sempre il riferimento del Budget quando lo confronti;
12. non inventare indicatori, stati o workflow;
13. verifica i messaggi di dominio;
14. verifica responsive e accessibilità;
15. esegui le checklist di questo documento prima di chiudere il task.

**In caso di dubbio: meno elementi, più chiarezza, nessuna assunzione.**
