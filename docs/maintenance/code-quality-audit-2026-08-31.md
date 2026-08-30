# Code quality audit — 2026-08-31

This document is the persistent record of the bounded maintenance work performed on
2026-08-31. Findings are included only when supported by repository evidence; areas
not inspected are not implicitly considered defect-free.

## Baseline

- Initial commit: `5a0925b6c9e9254073e0bff0648c6daf53c45923`
  (`Implementato Import`, 2026-08-30 23:47:53 +0200).
- Dedicated branch: `refactor/code-quality-2026-08-31`, created from the fetched
  `origin/main` at the same commit.
- Baseline started: 2026-08-31 00:41:35 CEST; branch created at 00:43:07 CEST.
- `npm run build`: passed (Vite 8.2.2, 4 modules, 905 ms).
- `COMPOSER_ALLOW_SUPERUSER=1 composer validate --no-check-publish`: passed with the
  pre-existing warning about the exact `relayercore/laravel-installer` constraint.
- `COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked --no-interaction`: passed; no
  security advisories were reported.
- Host `composer quality`: Pint and PHPStan passed, then the host Composer 2.7.1
  treated `@no_additional_args` as an Artisan argument. This is not the documented
  project runtime: `README.md` requires Sail for Composer commands.
- `./vendor/bin/sail composer quality`: passed (Pint, PHPStan, 804 Pest tests and
  5,880 assertions; 404.49 s).
- A direct host `vendor/bin/pest` attempt was stopped after it confirmed that the host
  cannot resolve the Compose-only `mysql` service name. Those connection failures are
  environmental and are not application failures.

## Scope actually analyzed

Inspected:

- repository instructions, testing/dependency policies, Composer/npm scripts and the
  complete current CI workflow;
- repository structure and broad searches for exception swallowing, fallback paths,
  TODO/legacy markers, large implementation files and completed Spec Kit artifacts;
- the V1 business-backup workbook contract, validator and its focused validation
  tests;
- documentation entry points and references to the current CI workflow and completed
  Spec Kit packages.

Partially inspected:

- proposal readiness/approval, closing, attachment authorization and selected
  duplicated operation actions, to determine whether broad catches and similar code
  represented real defects. No change is proposed without stronger evidence.

Not systematically inspected:

- the remainder of the domain/action/model/Filament implementation;
- migrations beyond references needed by the inspected ownership rules;
- browser rendering and interaction behavior;
- dependency internals.

## Findings

### CQ-001 — Non-canonical timestamps accepted by V1 backup validation

- Category: confirmed validation bug.
- Location: `app/BusinessBackup/V1/BusinessBackupValidator.php`, `validTimestamp()`.
- Current behavior: the method delegates to permissive `CarbonImmutable::parse()` and
  only checks for a trailing offset. A direct probe confirmed that values such as
  `tomorrowZ` and `2026-02-30T00:00:00+00:00` are accepted and normalized.
- Why this is a problem: the V1 contract requires an unambiguous ISO 8601 timestamp
  with offset. Relative strings are time-dependent, and impossible dates silently
  change the imported historical value.
- Concrete impact: a corrupt package can pass pre-write validation with a different
  timestamp meaning from its serialized text.
- Expected behavior: accept only the exact canonical second-resolution ISO 8601 form
  emitted by `BusinessBackupCollector`, with an explicit offset; reject invalid or
  normalized dates.
- Remediation: replace permissive parsing with format-and-round-trip validation.
- Verification: add a workbook mutation regression case and run
  `tests/Feature/BusinessBackup/BusinessBackupValidatorTest.php`.
- Status: resolved.
- Resolution commit: `3a7d60c`.

### CQ-002 — V1 “visible” sheets may be hidden

- Category: confirmed validation bug / unused parameter.
- Location: `app/BusinessBackup/V1/BusinessBackupValidator.php`,
  `readUnknownSchema()` and `readExact()`.
- Current behavior: visible sheets are passed to `readUnknownSchema(..., false)`, but
  its `$hidden` argument is unused. Only machine sheets are checked for `veryHidden`.
- Why this is a problem: the V1 contract names eleven visible, consultable sheets.
  A modified workbook can hide them and still pass validation because visibility is
  absent from checksums.
- Concrete impact: a package that violates its human-readable backup contract may be
  accepted for restore.
- Expected behavior: each declared visible sheet is `SHEETSTATE_VISIBLE`; each machine
  sheet remains `SHEETSTATE_VERYHIDDEN` as emitted and currently required.
- Remediation: make the existing state argument authoritative in one place and remove
  the duplicate machine-only check.
- Verification: add a hidden-visible-sheet workbook mutation and run the focused
  validator test.
- Status: resolved.
- Resolution commit: `3a7d60c`.

### CQ-003 — Expense-line index rebuilt for every late correction

- Category: accidental complexity / repeated transformation.
- Location: `app/BusinessBackup/V1/BusinessBackupValidator.php`,
  `assertCorrections()`.
- Current behavior: the complete expense-line reference map is rebuilt inside the
  loop for every late-correction row, although the source rows never change.
- Why this is a problem: it obscures the actual validation flow and turns a single
  indexing pass into repeated work proportional to corrections × lines.
- Concrete impact: unnecessary validation cost for large portable business graphs.
- Expected behavior: build the immutable line index once alongside the existing
  exercise, closing and expense indexes.
- Remediation: move the index construction before the correction loop without
  changing validation rules.
- Verification: focused validator test, Pint and PHPStan.
- Status: resolved.
- Resolution commit: `3a7d60c`.

### CQ-004 — Manifest and imported Company identity can diverge

- Category: confirmed domain/validation bug.
- Location: `app/BusinessBackup/V1/BusinessBackupValidator.php`, between manifest and
  machine-structure validation; `app/Actions/BusinessBackup/ImportBusinessBackup.php`
  consumes the machine Company row directly.
- Current behavior: `company_name` and `company_timezone` drive the preview but are
  not compared with `_MP2_company`; the machine timezone is not checked against IANA
  identifiers before direct database insertion.
- Why this is a problem: canonical §3 requires an IANA timezone, while FR-BDB-033 and
  FR-BDB-041 require coherent full validation before the import transaction.
- Concrete impact: preview/name-collision information can describe a different
  Company from the one restored, or restore can persist a timezone unusable by later
  company-local date calculations.
- Expected behavior: manifest identity exactly matches the single machine Company;
  its name fits the persisted boundary and its timezone is a real IANA identifier.
- Remediation: validate identity coherence and Company fields before preview.
- Verification: add mismatched-manifest and invalid-timezone workbook mutations to
  the focused validator regression test.
- Status: resolved.
- Resolution commit: `3a7d60c`.

### CQ-005 — Testing policy points to a removed CI filename

- Category: misleading documentation.
- Location: `docs/testing-policy.md:133`.
- Current behavior: the policy calls `.github/workflows/quality.yml` the executable
  source of truth, but the repository contains `.github/workflows/ci.yml`.
- Concrete impact: maintainers and agents are directed to a nonexistent file when
  establishing the required quality gate.
- Expected behavior/remediation: reference the current workflow path.
- Verification: repository-wide reference search and link/path existence check.
- Status: resolved.
- Resolution commit: pending.

### CQ-006 — Completed Spec Kit packages lack an explicit historical entry point

- Category: documentation structure / procedural residue.
- Location: `specs/001-*` through `specs/015-*`; `README.md` links directly to the
  completed S0 `quickstart.md` as the general validation procedure.
- Current behavior: all slice task lists are completed, while plans, checklists and
  task files remain directly exposed under `specs/` without a package-level status
  explanation. `AGENTS.md` already treats these artifacts as historical, but a reader
  entering through `specs/` or `README.md` does not see that boundary.
- Concrete impact: completed procedural artifacts can be mistaken for active work or
  current operational documentation.
- Expected behavior: current operating guidance points to `docs/` and CI; `specs/`
  clearly identifies the roadmap package as current traceability and completed slice
  packages as historical evidence.
- Remediation: add a concise `specs/README.md` entry point and replace the stale
  README quickstart link. A broader conversion must not be started unless it can be
  completed atomically with all references updated.
- Verification: review all resulting entry-point references and run a path check.
- Status: resolved.
- Resolution commit: pending.

## Structural changes

- V1 backup timestamps now have one strict canonical validation path rather than a
  permissive parse followed by a partial suffix check.
- Worksheet visibility is validated in the shared sheet reader; the separate
  machine-only check was removed.
- Late-correction validation builds the immutable expense-line index once, and an
  unnecessary Budget row alias was removed.

## Documentation alignment

- `README.md` now describes the implemented application instead of the long-completed
  S0-only state and points operational quality guidance to current docs and CI.
- `docs/testing-policy.md` now references `.github/workflows/ci.yml`.
- `specs/README.md` separates active roadmap traceability from historical slice
  procedure and states the source hierarchy for current work.
- No Spec Kit file was moved or deleted. The historical packages remain intact until
  rationale can be consolidated and all references updated in one atomic pass.

## Unresolved

- A full repository audit is impossible to infer from the inspected subset; areas not
  listed in scope remain unassessed.
- Broad exception catches inspected in attachment authorization, proposal readiness,
  approval auditing and batch processing have plausible fail-closed, validation or
  best-effort audit semantics. They remain unchanged because no incorrect behavior
  has been demonstrated.
- Wholesale Spec Kit conversion has not been started; a partial move would create
  broken references and duplicate authority.

## Final verification

Checkpoint verification completed so far:

- `./vendor/bin/sail artisan test tests/Feature/BusinessBackup/BusinessBackupValidatorTest.php`:
  passed, 1 test / 28 assertions (94.91 s).
- `./vendor/bin/sail composer format:test`: passed.
- `./vendor/bin/sail composer analyse`: passed, no errors.
- `git diff --check`: passed.

The final repository-wide gate remains pending after all planned checkpoints.
