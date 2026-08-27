# Data Model: Tenant Azienda e ciclo di vita

## Relationship overview

```text
User (globale)
  └── CompanyCapability ──> Company (radice dominio)
                              ║ 1:1, stessa chiave
                              ▼
                         TenantCompany
                         status: active|archived

Company ──owns──> tutte le tabelle gestionali, storiche, audit e file metadata

PendingFileDeletion (globale, tecnica, nessuna FK alla Company)
  └── disk + path da eliminare dopo il commit distruttivo
```

Lo stato del Tenant non viene copiato nella Company o nelle tabelle figlie. `CompanyCapability` resta legata alla Company; l'accesso effettivo richiede sia capability sia Tenant attivo.

## TenantCompany

**Table**: `tenant_companies`

| Field | Type | Rules |
|---|---|---|
| `company_id` | unsigned bigint | Primary key; FK unique/required a `companies.id`; route key Filament |
| `status` | enum | `active`, `archived`; default `active`; not null |
| `created_at` | timestamp | Laravel timestamp |
| `updated_at` | timestamp | Laravel timestamp; cambia su Archive/Restore |

**Relationships**: `belongsTo Company`; inverse verso i nove model Resource usando `company_id` come foreign/local key e i nomi predefiniti derivati dai model Filament: `budgetSnapshots`, `closingSnapshots`, `contracts`, `costCenters`, `exercises`, `expenses`, `projects`, `proposals`, `suppliers`.

**Invariants**:

1. una Company ha esattamente un TenantCompany dopo il backfill;
2. un TenantCompany non può esistere senza Company;
3. stato solo `active|archived`;
4. nessun dato economico risiede nel TenantCompany;
5. `company_id` non cambia mai.

**State transitions**:

```text
active   --ArchiveTenantCompany--> archived
archived --RestoreTenantCompany--> active
active   --DestroyTenantCompany--> record assente
archived --DestroyTenantCompany--> record assente
```

Ogni altra transizione fallisce. La cancellazione non produce uno stato `deleted`.

## Company and CompanyCapability

La tabella e l'identità Company restano invariate; viene aggiunta `hasOne TenantCompany`. Company resta la radice referenziale. La cancellazione è ammessa solo dalla Action globale dedicata; policy e model guard continuano a vietare delete individuali.

Lo schema `CompanyCapability (company_id, user_id, capability)` resta invariato. L'autorizzazione effettiva è:

```text
allowed = tenant_company.status == active
          AND company_capability exists for user + company + requested capability
```

`is_platform_admin` autorizza soltanto panel/Action globali e creazione; non modifica questa formula.

## PendingFileDeletion

**Table**: `pending_file_deletions`

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned bigint | Primary key |
| `operation_id` | UUID | Identifica la distruzione; indexed |
| `storage_disk` | varchar(64) | Nome esatto del filesystem Laravel |
| `storage_path` | varchar | Percorso file esatto, mai directory |
| `attempts` | unsigned integer | Default 0; incrementato per ogni fallimento |
| `last_attempted_at` | timestamp nullable | Ultimo tentativo fallito |
| `last_error` | text nullable | Messaggio tecnico sanificato dell'ultimo fallimento |
| `created_at` / `updated_at` | timestamp | Inserimento e aggiornamento tecnico |

**Constraints**: unique `(storage_disk, storage_path)`; nessuna FK verso Company/Tenant; successo o file già assente elimina la riga; fallimento conserva e aggiorna la riga. Il manifest non conserva nome Company, dati applicativi o contenuti file.

## ClosingSnapshot disposition

| Value | Meaning |
|---|---|
| `created` | N+1 non esisteva ed è stato creato dalla Chiusura |
| `already_existed` | N+1 esisteva già ed è stato referenziato |
| `not_created` | N+1 non esisteva e l'utente ha scelto di non crearlo |

Mapping migration: `not_created_management_terminated` → `not_created`. Ordine `up()`: eliminare `closing_snapshots_next_exercise_shape`; ampliare temporaneamente l'enum per accettare entrambi i valori; aggiornare le righe; restringere l'enum ai tre valori finali; ricreare il check usando `not_created` con `next_exercise_id IS NULL`. Il `down()` esegue lo stesso ordine inverso con un enum temporaneamente compatibile e ricrea la forma storica. Nessun valore implica `TenantCompany.status`.

## Ownership and delete graph

### Direct Company-owned tables

La FK `company_id -> companies.id` diventa `ON DELETE CASCADE` per:

- `company_capabilities`, `audit_events`, `suppliers`, `cost_centers`, `exercises`, `expenses`, `projects`;
- `project_transitions`, `project_exercise_classifications`, `contracts`, `contract_renewal_configurations`, `contract_lifecycle_facts`, `contract_conditions`, `contract_exercise_classifications`;
- `project_contract_links`, `attachments`, `proposals`, `budget_snapshots`, `project_deferrals`;
- `closing_snapshots`, `closing_source_rows`, `late_corrections`, `historical_error_annotations`, `tenant_companies`.

Le tabelle che hanno `company_id` ma dipendono tramite FK composite da un parent tenant-owned (`proposal_items`, `proposal_actions`, `budget_source_rows`, `budget_evidence`) seguono il parent con cascade; non viene aggiunta ownership alternativa.

### Indirect owned tables

| Child | Owner FK | Delete action |
|---|---|---|
| `supplier_contacts` | `supplier_id -> suppliers` | CASCADE |
| `expense_lines` | `expense_id -> expenses` | CASCADE |
| `proposal_items` | `(proposal_id, company_id) -> proposals` | CASCADE |
| `proposal_actions` | `(proposal_id, company_id) -> proposals` | CASCADE |
| `budget_source_rows` | `(budget_snapshot_id, company_id) -> budget_snapshots` | CASCADE |
| `budget_evidence` | `(budget_snapshot_id, company_id) -> budget_snapshots` | CASCADE |

### Cross-links within the same Tenant

I link tra record tenant-owned usano CASCADE. SET NULL è riservato alle sole FK semplici opzionali che spezzano un ciclo; non viene applicato a FK composite contenenti `company_id NOT NULL`:

| Reference | Action | Reason |
|---|---|---|
| `proposals.reference_budget_id` | SET NULL | interrompe ciclo Proposal revision → prior Budget |
| `budget_snapshots.previous_budget_id` | SET NULL | interrompe catena autoreferenziale |
| composite `closing_snapshots.(initial_budget_id|current_budget_id|next_exercise_id, company_id)` | CASCADE | `company_id` non è nullable; snapshot appartiene allo stesso Tenant |
| composite `budget_evidence.(attachment_id, company_id)` | CASCADE | `company_id` non è nullable; Evidence appartiene allo stesso Tenant |
| link parent/owner di Attachment | CASCADE | l'Attachment segue l'owner |
| link classificazioni/deferral/contract/project/expense/proposal | CASCADE | estremi tenant-owned eliminati insieme |
| `late_corrections.expense_line_id/original_expense_line_id` | CASCADE | correzioni e righe dello stesso Tenant |

L'inventario fisico completo e le azioni per nome sono in [contracts/delete-foreign-key-matrix.md](contracts/delete-foreign-key-matrix.md). La migration deve confrontarlo con `information_schema` e verificarlo su MySQL prima di procedere.

### Compatibilità verificata su MySQL 8.4.11

L'inventario dei nomi coincide con lo schema `testing`. MySQL rifiuta le azioni cascade quando `expenses.exercise_id`, `expenses.contract_id` e `project_contract_links.contract_id` sono colonne base delle rispettive generated column `STORED`. La migration `000200` sostituisce quindi `generated_exercise_id`, `generated_contract_id` e `active_contract_id` con generated column `VIRTUAL` equivalenti, conservando espressioni e indici univoci. Questa forma è stata verificata con l'intera matrice CASCADE/SET NULL, con delete Company sul grafo completo e con rollback a `STORED`/`RESTRICT`; non cambia i valori calcolati né gli invarianti di unicità.

### Global references unchanged

Tutte le FK verso `users` (`actor`, `beneficiary`, `creator`, `approver`, `uploader`, `detacher`, `recorder`, `withdrawer`, `discarder`, `closer`) restano `RESTRICT` dal lato User. Eliminare la Company elimina i record figli, non il User parent.

## File ownership snapshot

Prima del delete, query distinte leggono:

1. `attachments` per `company_id` con `storage_disk`, `storage_path`;
2. `budget_evidence` per `company_id` con entrambi i campi non null;
3. le stesse due fonti per Company diverse dal bersaglio, limitatamente alle coppie candidate.

Le coppie bersaglio vengono unite e deduplicate. Una coppia referenziata anche da un altro Tenant non entra nel manifest e il file resta per l'altro Tenant; i metadata del bersaglio vengono comunque eliminati col dominio. I file esclusivi sono toccati solo dopo il commit. Nessun prefisso directory viene eliminato ricorsivamente: soltanto i path censiti.

## Validation rules

- Archive: actor platform admin; Tenant esistente; stato `active`.
- Restore: actor platform admin; Tenant esistente; stato `archived`.
- Destroy: actor platform admin; Tenant in uno dei due stati; due conferme booleane distinte e vere, presentate in passi sequenziali nel contesto server-side del record bersaglio; `operation_id` UUID generato internamente solo dopo la validazione.
- Register: actor autorizzato; nome/timezone validi; coppia/capability/audit in una transazione.
- Manifest: disk/path non vuoti e provenienti solo dal Tenant locked; inserimento idempotente per disk/path.

## Migration validation queries

```sql
SELECT COUNT(*) FROM companies;
SELECT COUNT(*) FROM tenant_companies;

SELECT c.id
FROM companies c
LEFT JOIN tenant_companies tc ON tc.company_id = c.id
WHERE tc.company_id IS NULL;

SELECT company_id, COUNT(*)
FROM tenant_companies
GROUP BY company_id
HAVING COUNT(*) <> 1;

SELECT status, COUNT(*)
FROM tenant_companies
GROUP BY status;
```

I primi due conteggi devono coincidere subito dopo il backfill; le query di anomalie devono restituire zero righe; ogni record migrato deve risultare `active`.
