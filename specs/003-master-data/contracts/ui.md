# Filament UI Contract: Master Data

All user-facing labels, validation messages, filters, and notifications are Italian.

## Shared access rules

- `visualizza` for the current Company permits read-only resource lists, detail
  views, archived records, Contacts, and Timeline events.
- `gestisce_anagrafiche` for the same Company permits create, edit, Archive, restore,
  and Contact create/edit actions.
- Management capability does not imply tenant access when `visualizza` is absent.
- Resource and nested-record URLs are resolved only inside the current tenant.
- Every Livewire submission and Action re-checks authorization; an opened form is not
  continuing authority.
- No Supplier, Contact, or Cost Center screen exposes delete, force-delete, bulk
  delete, Contact Archive, or Contact restore actions.

## Suppliers resource

**Navigation label:** `Fornitori`  
**Default table:** active Suppliers for the current Company.

Table content:

- Ragione Sociale;
- Partita IVA when present;
- active/archived state;
- Contact count;
- last update.

Filters:

- `Attivi` (default);
- `Archiviati`;
- `Tutti`.

Create/edit fields:

- Ragione Sociale — required;
- Partita IVA — optional and not unique;
- Note — optional.

Detail behavior:

- read-only viewers can inspect active or archived Supplier details and Contacts;
- managers can edit descriptive values;
- Archive is available only for active Suppliers;
- restore is available only for archived Suppliers;
- repeated/already-effective Actions report a no-op and append no event;
- there is no uniqueness warning that implies duplicate records are forbidden.

## Supplier Contacts relation manager

**Placement:** Supplier detail/edit context.  
**Fields:** Nome, Cognome, Telefono, Email, Note, Tag di ruolo.

Behavior:

- every field and all role tags are optional;
- zero role tags is a valid state;
- tags are descriptive free values; examples may be suggested but are not a closed or
  mandatory enum;
- managers may add and edit Contacts;
- viewers may read Contacts;
- no remove, delete, Archive, restore, primary-contact, or hierarchy action exists;
- a Contact URL/action cannot change the parent Supplier or Company.

## Cost Centers resource

**Navigation label:** `Centri di Costo`  
**Default table:** active Cost Centers for the current Company.

Table content:

- Denominazione;
- active/archived state;
- last update.

Filters:

- `Attivi` (default);
- `Archiviati`;
- `Tutti`.

Create/edit fields:

- Denominazione — required and not unique.

Behavior:

- read-only viewers can inspect active or archived identities;
- managers can create, rename, Archive, and restore;
- no Exercise selector, hierarchy, allocation, percentage, amount, or economic total
  appears.

## Company Timeline extension

The existing `Timeline`/audit page remains read-only and company scoped. It gains
Italian labels and rendering for all S2 event types.

Each row shows at least:

- technical timestamp converted to Company time zone;
- actor;
- operation type;
- affected Supplier, Contact, or Cost Center identity;
- previous and new materialized values;
- Company-local effective date.

There are no mutation actions on Timeline rows.
