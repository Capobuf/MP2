# Implementation Plan: Company Access and Settings

**Branch:** `002-company-access-settings` | **Date:** 2026-08-17 | **Spec:** [spec.md](spec.md)

**Input:** Feature specification from `specs/002-company-access-settings/spec.md`

## Summary

S1 introduces the company boundary used by every later slice: the S0 platform
administrator creates companies, each creation grants that administrator the nine
canonical per-company capabilities, authorized users manage company settings and
capabilities, and all resulting changes create typed append-only audit events.

The implementation uses Filament's native tenant routing and registration page,
Laravel policies, application-owned capability assignments, three ordinary company
setting columns, and explicit transactional actions. Additional users are created by
one administrative command. No permission, settings, or activity-log package is
added because the native framework plus four small application-owned tables map more
directly to the canonical model.

## Technical Context

**Language/Version:** PHP 8.3  
**Primary Dependencies:** Laravel 13, Filament 5; no new package  
**Storage:** MySQL 8.4 family; persistent `mp2`, isolated `testing`  
**Testing:** Pest 4 feature and unit tests, Filament/Livewire component tests, existing
test-database guard  
**Target Platform:** Linux-hosted web application through the existing Sail stack  
**Project Type:** Server-rendered Filament web application plus an administrative CLI  
**Performance Goals:** Company creation and permission/setting mutations complete in
one normal request; tenant authorization queries remain bounded and indexed  
**Constraints:** Company isolation, append-only audit, atomic mutation plus audit,
valid IANA time zones, no implicit company setting defaults beyond the two canonical
defaults, no S2+ objects, no frontend build  
**Scale/Scope:** Nine fixed capabilities, three fixed settings, four S1 UI surfaces,
one user-provisioning command; no production scale claim is introduced

## Constitution Check

*GATE: evaluated before research and re-checked after design.*

| Principle | Gate result |
|---|---|
| Canonical domain is sole functional authority | PASS — S1 maps FR-091–093, §7.4, §22 and invariant 28.57 without adding economic behavior. |
| Category A–E discipline | PASS — onboarding questions were identified before planning and resolved explicitly by the user. |
| Smallest proportional design | PASS — native tenancy, policies and direct models replace candidate plugin stacks. |
| Slice boundary | PASS — no master data, exercise, expense, project, contract, proposal, budget, closing or report is introduced. |
| Dependency boundary | PASS — no dependency source modification and no new package. |
| Domain mutation boundaries | PASS — company creation, capability synchronization and setting changes use explicit transactional actions outside Filament callbacks. |
| Forward-only migrations | PASS — new migrations extend the S0 schema; the existing users migration is not rewritten. |
| Behavior-focused tests | PASS — cross-company rejection, atomic audit, no-op idempotency and direct-URL tenancy are planned. |
| Persistent development safety | PASS — automated tests continue to target only `testing`; no destructive development reset is planned. |

## Project Structure

### Documentation (this feature)

```text
specs/002-company-access-settings/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── command.md
│   └── ui.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/
│   ├── CreateCompany.php
│   ├── ProvisionUser.php
│   ├── SyncCompanyCapabilities.php
│   └── UpdateCompanySettings.php
├── Console/Commands/
│   └── ProvisionUserCommand.php
├── Domain/Company/
│   ├── AuditEventType.php
│   ├── Capability.php
│   ├── ClosingUnclassifiedPolicy.php
│   └── Setting.php
├── Filament/
│   └── Pages/
│       ├── CompanyAccess.php
│       ├── CompanyAudit.php
│       ├── CompanySettings.php
│       └── Tenancy/RegisterCompany.php
├── Models/
│   ├── AuditEvent.php
│   ├── Company.php
│   ├── CompanyCapability.php
│   └── User.php
└── Policies/
    └── CompanyPolicy.php

database/
├── factories/
│   ├── CompanyFactory.php
│   └── UserFactory.php
└── migrations/
    ├── *_add_platform_admin_to_users_table.php
    ├── *_create_companies_table.php
    ├── *_create_company_capabilities_table.php
    └── *_create_audit_events_table.php

tests/
├── Feature/Company/
├── Feature/Console/
└── Unit/Domain/Company/
```

**Structure Decision:** Keep the existing single Laravel application. Models own
persistence and relationships, small enums own closed canonical value sets, actions
own transactional mutations, one policy owns company-aware authorization, and
Filament pages only gather input and present action results. No generic repository,
service layer, role hierarchy, or event-sourcing layer is introduced.

## Design Decisions

### Tenant context and authorization

- Configure the existing admin panel with `Company` as its tenant model.
- `User::getTenants()` returns only companies where the user has `visualizza`.
- `User::canAccessTenant()` performs the same company-scoped check so a guessed URL
  cannot bypass the tenant switcher.
- `User::canAccessPanel()` allows the platform administrator (needed to create the
  first company) or a user with `visualizza` in at least one company.
- `CompanyPolicy::create()` allows only `is_platform_admin`; custom policy methods
  require the corresponding capability for the exact company.
- Platform administration does not bypass company policies after creation.

### Mutation and audit transactions

- `CreateCompany` creates the company, all nine assignments for the platform
  administrator, one company-created event, and nine capability-assigned events in a
  single transaction.
- `SyncCompanyCapabilities` locks the affected current assignments, computes the set
  difference, applies additions/removals, and appends one event per real change.
- `UpdateCompanySettings` locks the company, detects real setting changes, requires a
  reviewed impact preview when the time zone changes, writes the new values, and
  appends one event per changed setting.
- Re-submitting an effective state is a successful no-op with no audit event.
- Audit models expose no update/delete operation and have no `updated_at` column.
- Every S1 event materializes the complete §22.2 event envelope. Because S1 has no
  Exercises or economic values, the affected-Exercise and per-Exercise impact fields
  are explicit empty collections, while the effective date is the company's local
  operation date.

### Time-zone preview

S1 has no planned domain events yet. The UI still requires an explicit preview step
for a time-zone change and reports an empty affected-event list truthfully. Later
slices extend the preview query when they introduce event-bearing entities; they do
not change the S1 confirmation contract.

## Post-Design Constitution Check

PASS. The Phase 1 design retains the pre-research gate results. It adds no dependency,
keeps mutations outside Filament callbacks, uses forward-only schema changes, makes
company isolation enforceable at both navigation and direct-route boundaries, and
leaves later slice entities absent.

## Complexity Tracking

No constitution violation requires justification.
