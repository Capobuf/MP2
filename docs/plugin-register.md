# Filament Plugin Register

This is a decision register, not an installation list.

Plugin compatibility and maintenance status MUST be rechecked at the start of the
slice that plans to install one.

| Capability | Candidate | Planned slice | Current decision |
|---|---|---:|---|
| Per-Azienda capabilities | Filament Shield + Spatie Permission | S1 | Evaluate with a tenancy/capability POC; not installed in S0 |
| Attachments/media | Filament Spatie Media Library integration | first slice that needs managed attachments | Candidate; verify immutable/versioned evidence requirements separately |
| Settings UI/storage | Spatie Settings / Filament integration | S1 | Deferred; three company settings may be simpler as normal application data |
| Advanced tables/saved views | Advanced Tables class of plugins | S11 | Deferred until native Filament tables prove insufficient |
| Advanced export | Filament export plugins | S11 | Evaluate against semantic export requirements before adoption |
| Generic activity log | Activity-log plugins | cross-cutting | MUST NOT replace the canonical Timeline; may only assist if canonical event schema remains authoritative |
| Right-click/context menus | UI plugins | none | Not a domain need; do not install by default |

## Shield acceptance gate

Before adopting Shield in S1, prove:

1. one user belongs to two Aziende;
2. capabilities differ between the two;
3. switching Azienda changes authorization correctly;
4. direct URL access cannot bypass company scope;
5. custom Filament actions use the same authorization rules;
6. the canonical capability names remain authoritative.

If the plugin requires MP2 to distort the domain permission model, reject it and use
a simpler application-owned authorization model.

## Timeline rule

The domain requires explicit append-only events including previous/new values,
effective dates, affected years, economic impact, reasons, and references.

A generic model-change log is insufficient by itself.
