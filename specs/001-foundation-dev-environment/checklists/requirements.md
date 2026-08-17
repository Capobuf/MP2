# S0 Requirements Quality Checklist

## Specification quality

- [x] User-visible goals are separated from technical implementation.
- [x] All user stories are independently testable.
- [x] No economic domain rule is invented.
- [x] S0 scope explicitly excludes S1+ domain entities.
- [x] Development persistence and test isolation are explicit.
- [x] LAN access is distinguished from Internet exposure.
- [x] Credentials have deterministic lifecycle rules.
- [x] No open clarification remains.
- [x] MySQL selection is documented as a technical choice from two user-approved
  alternatives.

## Constitution alignment

- [x] Canonical domain remains authoritative.
- [x] No speculative infrastructure.
- [x] No starter kit.
- [x] No plugin installed before its slice.
- [x] No vendor/plugin-source modification.
- [x] Test policy is proportional.
- [x] Development data is persistent.
- [x] Implementation can be bounded by phase.

## S0 acceptance

- [x] Clean checkout bootstrap succeeds.
- [x] `.env` contains the stable explicitly requested development credentials.
- [x] `/admin` login works.
- [x] Filament Dashboard loads.
- [x] No domain demo data exists.
- [x] LAN access works.
- [x] Normal stop/start preserves DB.
- [x] Bootstrap rerun preserves credentials and DB.
- [x] Automated tests use only test DB.
- [x] Test safety guard rejects dev DB.
- [ ] CI is green.
- [x] No Node/Vite build is required.
- [x] No Dusk is running without an identified need.
- [x] No dependency source was modified.

## Evidence — 2026-08-17

- Clean-copy bootstrap started without `.env` or `vendor/`, installed Composer through
  `laravelsail/php83-composer`, migrated with Docker, and left files host-writable.
- Bootstrap rerun kept e-mail/password unchanged and one administrator row.
- Chromium login reached `/admin`, rendered Dashboard in Italian, and showed no
  console errors or framework overlay.
- `sail stop` / `sail up -d` preserved administrator ID and password hash.
- An isolated Docker client reached `10.0.0.30:9000/admin/login` with HTTP 200;
  publication is on `0.0.0.0:9000` and `[::]:9000`.
- Composer validation/audit, Pint, Larastan, and 9 Pest tests (30 assertions) pass.
- A deliberately unsafe `APP_ENV=local`, `DB_DATABASE=mp2` invocation failed at the
  central guard before Laravel test setup; the development credential remained valid
  after the normal suite.
- `.github/workflows/quality.yml` parses successfully and contains no Node, npm,
  Vite, Selenium, or Dusk setup. Remote CI is pending because the empty GitHub origin
  has no branch/HEAD and these changes have not been published.
