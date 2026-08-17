# Research: Company Access and Settings

**Date:** 2026-08-17  
**Status:** Complete — no unresolved technical clarification

## Native Filament tenancy

**Decision:** Use Filament 5 native tenant routing with `Company` as the tenant model,
a custom tenant registration page, and `User` implementing Filament's `HasTenants`
contract.

**Rationale:** The installed public API already provides tenant registration, tenant
switching, `getTenants()`, and a direct-route `canAccessTenant()` guard. This gives S1
one explicit company context without a second panel or custom route middleware.

**Alternatives considered:**

- A custom company selector stored in session: rejected because every page and direct
  URL would need bespoke context validation.
- One panel per company: rejected because companies are runtime data, not static app
  deployments.

**Reference:** `https://filamentphp.com/docs/5.x/users/tenancy` and the installed
Filament 5 public interfaces.

## Capability storage and Shield evaluation

**Decision:** Store one application-owned row per user, company, and canonical
capability; enforce it through Laravel policies. Do not install Filament Shield or
Spatie Permission in S1.

**Rationale:** Shield 4 supports Filament 5 and multi-tenancy, but its primary model is
generated resource/page permissions and roles. MP2 instead has exactly nine domain-
named capabilities assigned directly per company, requires assignment-level audit,
and deliberately has no maker-checker role hierarchy. Shield would still require
custom tenancy setup, direct-permission rules, audit actions, and a replacement UI,
while adding package migrations/configuration and an authorization vocabulary that
is not canonical.

**Alternatives considered:**

- Shield + Spatie teams: compatible but disproportionate for nine fixed direct
  capabilities and does not remove MP2's audit/mutation code.
- Role records containing capabilities: rejected because the domain assigns
  capabilities, defines no roles, and allows arbitrary combinations.
- Boolean columns per capability: rejected because every new capability would alter
  schema and assignment queries; one row per closed enum value remains direct.

**Reference:** `https://filamentphp.com/plugins/bezhansalleh-shield` (checked
2026-08-17; Shield 4 lists Filament 4.x/5.x compatibility and MIT licensing).

## Company settings storage

**Decision:** Store the three canonical settings as typed columns on `companies`.

**Rationale:** There are exactly three settings with distinct validation and behavior.
Direct columns provide constraints, casts, simple forms, and clear previous/new audit
values without a generic key/value settings framework.

**Alternatives considered:**

- Spatie Settings or a generic settings table: rejected because it adds indirection
  and a package for three closed fields.
- Configuration files: rejected because values are per company and mutable at runtime.

## Append-only audit model

**Decision:** Introduce one application-owned `audit_events` table with typed S1
events and explicit S1 fields. Current state remains in live tables.

**Rationale:** This follows canonical §22.10: events are append-only but MP2 is not
event sourced. S1 events need author, company, beneficiary or setting, previous/new
values, timestamp, and optional reason. Later slices can add their own nullable event
dimensions through forward migrations as their event taxonomy appears.

**Alternatives considered:**

- A generic model activity-log package: rejected because ordinary dirty-attribute
  logs do not express canonical author/beneficiary/company semantics or atomic domain
  outcomes.
- Separate permission and setting history tables: valid but rejected because both are
  canonical company Timeline events shown in the same history.
- Event sourcing: explicitly prohibited by the canonical model.

## Platform administrator representation

**Decision:** Add `is_platform_admin` to users, set it only for the S0 development
administrator during its existing provisioning, and use it solely for company
creation.

**Rationale:** The user explicitly selected the S0 platform administrator as the
creator of all companies. A separate boolean expresses that one global bootstrap
authority without pretending it is a per-company capability or granting a policy
bypass inside existing companies.

**Alternatives considered:**

- Email comparison to `DEV_ADMIN_EMAIL`: rejected because authorization would depend
  on mutable environment configuration rather than persisted identity.
- A global role package: rejected because there is one global authority and no global
  role model.
- Automatically allowing any authenticated user: rejected by the user's decision.

## Administrative user provisioning

**Decision:** Add one dedicated command accepting name, unique email and a hidden or
explicit password; it creates ordinary non-platform users and rejects duplicates.

**Rationale:** The user selected out-of-band provisioning. A small command avoids
inventing invitations, email delivery, password reset, or a user-management UI while
still providing real beneficiaries for capability assignment.

**Alternatives considered:**

- Filament user-management resource: explicitly excluded by the selected scope.
- Email invitations: explicitly excluded and would require mail/invitation lifecycle.
- Reusing `mp2:ensure-dev-admin`: rejected because that command is local-development
  specific, environment-driven, and idempotently synchronizes one privileged user.

## Concurrency and idempotency

**Decision:** Serialize mutable company state with database row locks inside
transactions, enforce unique capability assignments at database level, and represent
no-op submissions without new audit events.

**Rationale:** This keeps previous/new audit values accurate under concurrent requests,
prevents duplicate assignments, and satisfies the S1 atomic/no-misleading-event
requirements using MySQL primitives already present.

**Alternatives considered:**

- Optimistic version fields: unnecessary for the current small forms and adds a
  user-visible conflict flow not required by S1.
- Queueing mutations: prohibited by proportionality; all work is short and local.

