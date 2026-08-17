# Filament UI Contract

All user-facing labels and messages are Italian.

## Company registration

**Audience:** authenticated platform administrator only.  
**Fields:** denomination, IANA time zone.  
**Result:** redirects into the new company tenant after atomic creation and initial
capability grants.

The page is unavailable to ordinary users. Invalid input leaves no company,
assignment, or audit event.

## Tenant selection and direct access

- The tenant switcher lists only companies where the current user has `visualizza`.
- A tenant identifier entered directly in the URL is checked against the same rule.
- Losing `visualizza` removes subsequent access to that tenant.
- A platform administrator has no implicit tenant visibility; creation grants that
  administrator explicit per-company capabilities.

## Access management page

**Audience:** tenant user with both tenant access and `gestisce_permessi`.  
**Inputs:** existing beneficiary, nine canonical capability checkboxes, optional
reason.  
**Display:** beneficiaries with at least one current capability and their capability
labels.

Submitting synchronizes the beneficiary's exact requested set. The page reports a
successful no-op when the set is already effective. Unauthorized submissions are
rejected without state or audit changes.

An authorized manager may change their own assignments, including revoking their last
`gestisce_permessi`. The submitted operation completes and is audited; the resulting
authorization applies to subsequent requests.

## Company settings page

**Audience:** tenant user with both tenant access and `gestisce_impostazioni`.  
**Fields:** required overspend note, unclassified-at-closing policy, IANA time zone.

For a changed time zone:

1. the user requests preview;
2. S1 displays the old and new zones and reports no currently representable affected
   planned events;
3. only an explicit confirmation may submit the change.

Other setting changes may be confirmed directly. No fixed canonical behavior appears
as a setting.

## Company audit page

**Audience:** tenant user with `visualizza`.  
**Behavior:** read-only, newest first, scoped to the current company.

Rows show the event type, timestamp, author, affected beneficiary/capability or
setting, previous value, new value, and optional reason. There are no edit, delete,
bulk-delete, or restore actions.
