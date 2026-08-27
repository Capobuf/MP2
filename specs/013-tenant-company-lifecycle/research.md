# Research: Tenant Azienda e ciclo di vita

## Scope and sources inspected

La ricerca ha letto integralmente `docs/domain/Specifica_Canonica_Semplificata_v4.md`, incluso il nuovo §31, e ha ricostruito i percorsi attuali da panel provider, model, policy, Action, command, route, controller, Resource, widget, Livewire, migration, factory e test pertinenti. Sono state consultate le API e il sorgente installato di Filament 5.7.6, oltre alla documentazione ufficiale Filament 5:

- [Multi-tenancy](https://filamentphp.com/docs/5.x/users/tenancy)
- [Testing resources in a tenant context](https://filamentphp.com/docs/5.x/testing/testing-resources)
- [MySQL 8.4 foreign-key referential actions](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html)

Non sono stati usati documenti storici Spec Kit come fonte di comportamento.

## Decision 1 - Identity-preserving one-to-one model

**Decision**: `tenant_companies.company_id` è sia primary key sia foreign key verso `companies.id`; non viene introdotto un secondo ID tecnico.

**Rationale**: rende strutturalmente uno-a-uno la coppia, preserva ID e segmenti URL correnti, evita di aggiungere `tenant_company_id` a tutte le tabelle di dominio, mantiene `Company` come proprietaria tramite gli attuali `company_id` e consente a Filament di associare le Resource tramite una `BelongsTo` con chiave condivisa.

**Alternatives rejected**:

- ID autonomo per `TenantCompany`: richiederebbe route key custom o cambierebbe URL e introdurrebbe mapping ridondante.
- Rendere `Company` ancora il model tenant aggiungendole uno stato: non separa le responsabilità richieste.
- Aggiungere `tenant_company_id` a ogni record: duplica ownership e crea rischio di incoerenza.

## Decision 2 - Native Filament tenancy with explicit ownership relationship

**Decision**: il pannello `admin` usa `->tenant(TenantCompany::class, ownershipRelationship: 'tenantCompany')`. I nove model esposti come Resource definiscono `tenantCompany()` usando il loro `company_id`; `TenantCompany` espone le relazioni inverse richieste. Pagine e widget risolvono la Company con `Filament::getTenant()->company`.

**Rationale**: Filament applica scope e associazione automatica tramite le relazioni di tenancy, e `HasTenants`/`canAccessTenant()` protegge anche l'indovinamento dell'URL. Mantenere le query `whereBelongsTo($company, 'company')` esistenti, adattate alla Company collegata, offre una verifica esplicita coerente con l'attuale difesa in profondità.

**Alternatives rejected**:

- `stancl/tenancy` e TomatoPHP Filament Tenancy: aggiungono un framework/pacchetto alternativo quando la tenancy nativa è già presente e obbligatoria.
- Guidance Tenant Members: introduce membership/ruoli paralleli incompatibili con la conservazione minima di `CompanyCapability`.
- Multi-database, domain o subdomain tenancy: non richiesti dal modello MP2 e ampliano storage, routing e operazioni senza valore osservabile per questa feature.
- Framework tenant proprietario: duplicherebbe risoluzione, scoping e associazione già forniti da Filament.
- Scope globale custom su tutti i model: duplica la tenancy nativa e rischia di colpire command/report fuori panel.
- Riscrivere il dominio per appartenere al Tenant tecnico: contraddice il §31.

## Decision 3 - Active state as independent authorization prerequisite

**Decision**: `User::hasCapability(Company, Capability)` restituisce true solo se la capacità esiste e la Company ha un Tenant `active`. `getTenants()` restituisce `TenantCompany` attivi con `visualizza`; `canAccessTenant()` verifica tipo, stato e capacità. Un middleware tenant persistente anticipa il rifiuto nelle route panel, ma policy/Gate resta l'autorità anche per route custom e Action dirette.

**Rationale**: tutte le policy aziendali esistenti convergono su `hasCapability()`. Il controllo centrale preserva le righe senza aggiungere una condizione diversa a ogni policy e protegge download/report fuori panel. Il platform admin non riceve alcun bypass perché `hasCapability()` non consulta il flag globale.

**Alternatives rejected**: cancellare/sospendere capability violerebbe la conservazione; controllare solo il panel lascerebbe aperte altre superfici; una nuova capability mescolerebbe lifecycle e autorizzazione.

## Decision 4 - Separate platform panel

**Decision**: aggiungere un pannello Filament nativo `platform` su `/platform`, con login condiviso e accesso soltanto per `is_platform_admin`. Il pannello scopre solo la Resource globale `TenantCompanyResource` e non configura tenancy.

**Rationale**: nel sorgente Filament 5 installato, le Resource di un panel tenant-enabled sono registrate dentro il route group `{tenant}`. Disabilitare lo scope di una Resource evita il global scope ma non rende la route tenantless. La gestione deve restare accessibile con tutti i Tenant archiviati e non può selezionare un archiviato nel pannello operativo.

**Alternatives rejected**: una Resource unscoped nello stesso panel resta dipendente dal segmento tenant; rendere selezionabili archiviati viola il blocco operativo; un controller/Blade custom ricrea funzioni native Filament.

## Decision 5 - Lock order and lifecycle concurrency

**Decision**: tutte le Action lifecycle acquisiscono `SELECT ... FOR UPDATE` prima su `companies`, poi su `tenant_companies`. Ogni mutazione tenant-owned deve acquisire il lock Company e rivalidare la Gate dopo il lock. Archive/Restore/Destroy rivalidano anche lo stato sotto lock.

**Rationale**: le Action complesse correnti già bloccano la Company prima dei record specifici e ripetono la Gate nella transazione. Lo stesso ordine serializza una mutazione in volo con l'Archivio: o la mutazione committa prima e poi l'Archivio, oppure vede il Tenant archiviato e fallisce. Un ordine unico evita deadlock introdotti dalla feature.

**Alternatives rejected**: il solo controllo pre-transazione ammette race; un lock globale è sproporzionato; trigger su ogni tabella duplicherebbero le policy.

**Verified mutation boundary**: le Action pubbliche tenant-owned con actor attualmente censite in `Closing`, `LateCorrections`, `MasterData`, `Operations`, `Proposals`, `SyncCompanyCapabilities` e `UpdateCompanySettings` acquisiscono già lock e Gate nei propri confini transazionali. I file Proposal senza Gate (`ApplyContractPlan`, `ApplyExpensePlan`, `ApplyProjectDeferral`, `ApplyProjectPlan`, `ApplyProposalRelations`, `MarkProposalItemsToRealign`, `MaterializeBudgetSnapshot`) sono helper interni invocati da Action pubbliche già bloccate/autorizzate; `NormalizeClosingInput`, `PrepareExerciseClosing`, `ProjectExpenseOpening`, `BuildReport` e `ReviewExerciseClosing` non costituiscono nuove mutazioni autonome, mentre `ProvisionUser` opera sull'account globale. Non vanno aggiunti controlli duplicati negli helper: va impedito che un nuovo command/job li usi come confine applicativo senza una Action autorizzata.

## Decision 6 - Database-owned cascade graph for permanent deletion

**Decision**: una migrazione forward cambia le FK esclusivamente tenant-owned a `ON DELETE CASCADE`; soltanto i riferimenti opzionali su FK semplice che chiudono cicli diventano `ON DELETE SET NULL`. Le FK composite che includono `company_id NOT NULL` non possono usare SET NULL e usano CASCADE. Le FK verso `users` restano `RESTRICT`. La Action elimina la `Company` radice sotto transazione; `TenantCompany` e grafo seguono le FK.

**Rationale**: oggi FK `RESTRICT` e model hook impediscono la cancellazione interna. La cancellazione totale è l'eccezione canonica. La responsabilità nello schema copre anche i figli indiretti ed evita una sequenza applicativa fragile. Le normali policy e model hook continuano a vietare delete individuali.

**Cycle breaks**:

- `proposals.reference_budget_id` → `SET NULL`, mentre `budget_snapshots.proposal_id` segue l'ownership;
- `budget_snapshots.previous_budget_id` → `SET NULL`;
- riferimenti composite di `closing_snapshots` a Budget/N+1 → `CASCADE`, perché comprendono `company_id NOT NULL` e lo snapshot appartiene comunque allo stesso Tenant;
- riferimento composite di `budget_evidence` ad Attachment → `CASCADE`, perché comprende `company_id NOT NULL` e l'Evidence appartiene allo stesso Tenant;
- link proprietari non opzionali e allegati verso il proprio owner → `CASCADE`.

**Alternatives rejected**: disabilitare FK può lasciare orfani; delete Eloquent attiva guard e richiede un ordine manuale; soft delete introduce uno stato non richiesto.

## Decision 7 - Database transaction plus durable post-commit file cleanup

**Decision**: prima della cancellazione DB la Action legge e deduplica `(storage_disk, storage_path)` da `attachments` e `budget_evidence`. Per ogni coppia verifica eventuali riferimenti di Company diverse: un path ancora referenziato da un altro Tenant non è esclusivamente posseduto dal bersaglio, quindi ne viene eliminato il metadata bersaglio ma il file fisico non entra nel manifest. Le sole coppie esclusive vengono inserite in `pending_file_deletions` nella stessa transazione, quindi viene eliminata la Company. Dopo il commit la Action prova subito ogni file. Una riga viene rimossa solo se il file è già assente o la cancellazione riesce. Errori e tentativi restano sulla riga; un command schedulato riprova.

**Rationale**: MySQL e storage non condividono una transazione. Il manifest chiude il crash window fra commit e chiamata storage e rende il residuo osservabile. La deduplica evita doppia rimozione quando Evidence e Attachment condividono il path. La tabella non ha FK alla Company perché deve sopravvivere alla sua eliminazione.

**Ordering guarantee**:

1. fallimento prima/durante commit: rollback DB, manifest assente, nessun file toccato;
2. commit riuscito: Tenant non più esistente/accessibile;
3. pulizia immediata best effort;
4. eventuali righe residue vengono ritentate idempotentemente.

**Alternatives rejected**: file prima del DB perde file su rollback; file dopo DB senza manifest perde il lavoro su crash; queue/Redis non esistono e non servono; dichiarare atomicità DB+storage è falso.

## Decision 8 - Atomic registration preserves the Company action contract

**Decision**: `CreateCompany` crea `TenantCompany(active)` nella stessa transazione di Company, audit e capability e continua a restituire `Company`, preservando i caller esistenti. `RegisterCompany` risolve e restituisce `Company::tenantCompany`, model richiesto dalla tenancy Filament.

**Rationale**: un'unica Action evita coppie orfane. La policy `Company::create` già limita al platform admin e viene preservata.

**Alternative rejected**: un model event `Company::created` nasconderebbe una mutazione importante e coinvolgerebbe factory/seeder non autorizzati.

## Decision 9 - Automatic process inventory and filtering

**Decision**: il solo processo automatico tenant-owned presente è `contracts:process-renewals`, schedulato giornalmente. Il command aggiunge un filtro `whereHas('company.tenantCompany', status=active)` prima di iterare. L'Action continua ad autorizzare sotto lock, così un Archivio concorrente viene rivalidato.

**Rationale**: il filtro evita lavoro e warning sugli archiviati; la Gate protegge anche l'invocazione diretta. La review finale ripete la ricerca di scheduler, job e listener.

**Alternative rejected**: sospendere globalmente lo scheduler colpirebbe Tenant attivi.

## Decision 10 - N+1 terminology is persisted, not only relabeled

**Decision**: rinominare `not_created_management_terminated` in `not_created`, l'input `management_continues` in `create_next_exercise`, l'audit reason `management_terminated` in `next_exercise_not_requested` e codici/messaggi correlati. Una migrazione rimappa i dati storici, aggiorna l'enum e ricrea il check MySQL `closing_snapshots_next_exercise_shape` con il nuovo valore senza cambiare l'esito.

**Rationale**: lasciare il concetto nel database o nell'audit conserverebbe la falsa semantica eliminata dal §31. `created` e `already_existed` restano validi.

**Alternatives rejected**: cambiare solo le etichette mantiene il significato errato; collegare la scelta all'Archivio è vietato.

## Decision 11 - No new lifecycle audit or deletion record

**Decision**: Archive e Restore cambiano soltanto stato/timestamp tecnico del Tenant e non aggiungono un nuovo AuditEvent di dominio. L'audit della distruzione non viene conservato in una nuova area globale. Le conferme e l'esito sono osservabili nella richiesta/UI; il manifest conserva solo lavoro tecnico pendente.

**Rationale**: il requisito impone che Archive/Restore preservino integralmente il dominio e che la distruzione elimini anche l'audit tenant-owned; non autorizza nuovi eventi o un registro globale contenente dati del Tenant. Aggiungerli inventerebbe semantica, retention e governance.

## Verified inventory

### Filament operational resources

`BudgetSnapshot`, `ClosingSnapshot`, `Contract`, `CostCenter`, `Exercise`, `Expense`, `Project`, `Proposal`, `Supplier`. Tutte le Resource hanno già uno scope esplicito per Company; pagine, schema, table, widget e Livewire elencati nel piano usano direttamente il tenant corrente e devono essere adattati.

### Non-panel tenant surfaces

- download Attachment;
- download Budget Evidence;
- report PDF;
- relative policy/Action e binding di record.

Queste superfici non ricevono automaticamente lo scope Filament e dipendono dal controllo centrale `hasCapability()` più test di ownership del record.

### Automatic processes

- `routes/console.php`: `contracts:process-renewals` giornaliero;
- nessun Job `ShouldQueue`, listener mutante o altro scheduler tenant-owned trovato in `app/`, `routes/`, `bootstrap/` e `config/` al 2026-08-26.

### File sources

- `attachments.storage_disk/storage_path`, creati oggi sotto `attachments/{company_id}/{uuid.ext}`;
- `budget_evidence.storage_disk/storage_path`, che può copiare un Attachment o contenere evidenza materializzata.

### Factories and seeding

- `database/factories/CompanyFactory.php` oggi crea direttamente una Company ed è transitivamente usata dalle factory dei model tenant-owned e da centinaia di fixture; dopo la migration deve creare automaticamente una sola coppia attiva per evitare Company persistite orfane nei test.
- Non esiste oggi una `TenantCompanyFactory`; non viene introdotta perché una seconda factory creatrice renderebbe possibile generare una seconda coppia o una ricorsione con `CompanyFactory`.
- `database/seeders/DatabaseSeeder.php` crea soltanto l'utente globale di sviluppo e non richiede un cambiamento Tenant.

### Global data explicitly preserved

`users`, credenziali, sessioni/password-reset globali e ogni relazione dello stesso User con Aziende diverse. Le FK actor/creator/uploader/approver verso `users` non cambiano.

## Implementation finding - MySQL cascade compatibility

La prova reale su MySQL 8.4.11 nello schema isolato `testing` ha confermato i nomi e le regole correnti dell'intero inventario, ma ha rifiutato la matrice durante l'aggiunta di `expenses_exercise_company_foreign` con `ON DELETE CASCADE` (`SQLSTATE HY000`, errore 1215).

Il conflitto non dipende da dati orfani: la tabella era vuota e la stessa FK viene ricreata correttamente con `RESTRICT`. `expenses.exercise_id` è però una colonna base della colonna generated stored `generated_exercise_id`; analogamente `expenses.contract_id` è base di `generated_contract_id`. MySQL 8.4 vieta azioni referenziali `CASCADE`, `SET NULL` o `SET DEFAULT` su una foreign key che usa una colonna base di una stored generated column. La prova completa ha individuato lo stesso vincolo su `project_contract_links.contract_id`, base di `active_contract_id`.

Su autorizzazione esplicita del proprietario, è stata verificata su MySQL isolato la sostituzione delle tre generated column `STORED` con generated column `VIRTUAL` aventi le stesse espressioni e gli stessi indici univoci. MySQL consente gli indici univoci sulle colonne virtuali e accetta quindi l'intera matrice FK. La migration `000200` esegue questa sostituzione prima delle FK, verifica la matrice nominativa con `information_schema`, conserva tutte le FK User `RESTRICT` e ripristina colonne `STORED` e regole `RESTRICT` nel `down()`. Non vengono disabilitate foreign key né introdotti delete manuali.
