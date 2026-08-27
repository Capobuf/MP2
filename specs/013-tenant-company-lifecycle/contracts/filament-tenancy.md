# Contract: Filament tenancy and platform management

## Operational panel (`admin`)

### Tenant model

- Native Filament tenant model: `App\Models\TenantCompany`.
- Route key: `company_id`; existing numeric tenant URLs remain stable.
- Tenant display name: related `Company.name`.
- Registration page returns a `TenantCompany` produced by the atomic creation Action.
- Ownership relationship configured at panel level: `tenantCompany`.

### User contract

```php
User::getTenants(Panel $panel): Collection<TenantCompany>
User::canAccessTenant(Model $tenant): bool
User::canAccessPanel(Panel $panel): bool
```

- For panel ID `admin`, `getTenants()` returns only active TenantCompany records whose Company grants `visualizza` to the user.
- `canAccessTenant()` returns true only for `TenantCompany`, state `active`, and `visualizza` on its Company.
- `canAccessPanel()` for `admin` requires either platform admin (so authorized registration remains reachable without a Tenant) or at least one `visualizza` capability on an active Tenant; capabilities that exist only on archived Tenant do not grant panel access.
- Platform admin alone is insufficient to enter a Company in the operational panel.
- No archived Tenant may be exposed by tenant switching, direct URL, breadcrumbs, relation managers, global search, widgets, or registration redirect.

### Resource ownership

Each operational Resource model exposes:

```php
public function tenantCompany(): BelongsTo
{
    return $this->belongsTo(TenantCompany::class, 'company_id', 'company_id');
}
```

`TenantCompany` exposes the matching model-derived plural relationships: `budgetSnapshots`, `closingSnapshots`, `contracts`, `costCenters`, `exercises`, `expenses`, `projects`, `proposals`, `suppliers`. Automatic Filament creation must assign the record's `company_id` via these relationships. Existing explicit Resource query scopes continue to constrain records to `$tenantCompany->company`.

### Current Company resolution

Every operational page/widget/component that currently treats `Filament::getTenant()` as `Company` must instead:

1. require a `TenantCompany`;
2. load/resolve its required `company` relation;
3. use that `Company` for domain Action, query, timezone and policy inputs;
4. fail explicitly if the required relation is missing rather than selecting another Company.

### Persistent middleware

An active-tenant middleware is registered as persistent tenant middleware. It rejects an archived/missing Tenant before Livewire requests continue. This is an early boundary; it does not replace policy/Gate checks for routes outside the panel.

## Platform panel (`platform`)

- Path: `/platform`.
- No tenant model configured.
- Uses the existing Laravel guard/login/session.
- `User::canAccessPanel()` returns true for this panel only when `is_platform_admin === true`.
- Discovers only platform-specific resources/pages; it must not auto-discover operational `app/Filament/Resources`.

### TenantCompany Resource

List columns:

- Company name;
- Company/Tenant identifier;
- status (`Attivo`, `Archiviato`);
- last technical update time.

Filters: explicit status filter with active/archived/all. No metrics or invented lifecycle dates.

Actions:

- active: `Archivia`, `Elimina definitivamente`;
- archived: `Ripristina`, `Elimina definitivamente`;
- no create/edit/delete bulk actions;
- registration remains the existing authorized flow, not a second creation workflow.

Each visible action delegates to an application Action and repeats authorization/state validation server-side. Visibility is not authorization.

## Route and record security assertions

- Guessing `/admin/{archived-company-id}/...` is denied.
- Guessing another active Tenant ID without `visualizza` is denied.
- Passing a record ID from Company B under Tenant A yields not found/denied without B data.
- `/platform` and its Livewire requests are denied to non-platform users.
- Archived data remains inaccessible through Attachment, Budget Evidence and PDF report routes outside Filament.

## Filament test setup

Tests explicitly set/boot the current panel and current `TenantCompany`; they do not pass a `Company` to `Filament::setTenant()`. Platform tests boot the platform panel with no current tenant.
