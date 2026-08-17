# Dependency Policy

## Default

A new package is not the default solution.

Before adding a Composer or npm dependency, document:

1. the current slice requirement;
2. why Laravel/Filament core is insufficient;
3. current framework compatibility;
4. maintenance activity;
5. license;
6. security implications;
7. what custom code it removes;
8. what happens if it must later be removed.

## Forbidden dependency edits

MP2 MUST NOT modify dependency source under:

- `/vendor/**`;
- `/node_modules/**`;
- plugin package directories.

Configuration, published project-owned files, supported subclassing, hooks, events,
service providers, macros, and documented public APIs are allowed.

Published migrations/configuration become MP2-owned only after review.

## Lockfiles

- `composer.lock` MUST be committed.
- If npm is introduced later, its lockfile MUST be committed.
- Dependency updates occur through package-manager commands, never manual lockfile edits.

## Starter kits

No external starter kit is part of the baseline.

The project itself becomes the verified foundation after S0.

## Plugin lifecycle

Plugin adoption belongs to the slice that needs it.

A plugin is not installed in advance merely because a future roadmap item might use
it.

Before each slice, review the Filament plugin catalog for a mature solution that
removes meaningful custom code while preserving the canonical domain.
