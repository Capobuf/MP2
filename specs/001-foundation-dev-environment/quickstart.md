# Quickstart and Manual Acceptance — S0

This document is both the developer quickstart and the manual acceptance procedure.

## Prerequisites on Linux host

Required:

- Git;
- Docker Engine;
- Docker Compose plugin.

Host PHP, Composer, Node and MySQL are not required.

## Bootstrap

From repository root:

```bash
scripts/bootstrap-dev.sh
```

Expected final output includes:

```text
MP2 è pronto.
Local: http://127.0.0.1:9000/admin
LAN:   http://<detected-ip>:9000/admin
Email: admin@mp2.local
Password: admin@mp2.local
```

If LAN IP detection is unavailable, the script may omit the LAN line while still
succeeding locally.

## Login acceptance

1. Open the local URL.
2. Verify unauthenticated access shows/redirects to Filament login.
3. Sign in with the printed credentials.
4. Verify the standard Filament Dashboard loads.
5. Verify no domain demo data/resources were created.

Verified on 2026-08-17 with an automated Chromium session: the Italian login form
accepted `admin@mp2.local`, navigated to `/admin`, rendered the standard Dashboard,
and reported no browser console errors or framework error overlay.

## Credential persistence acceptance

Record:

```bash
grep '^DEV_ADMIN_EMAIL=' .env
grep '^DEV_ADMIN_PASSWORD=' .env
```

Rerun:

```bash
scripts/bootstrap-dev.sh
```

Verify the values did not change, password and login email are equal as requested,
and the same credentials still log in.

## Database persistence acceptance

Stop and restart without deleting volumes:

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
```

Verify the administrator still exists and login works.

Verified on 2026-08-17: the administrator ID and password hash were unchanged after
`sail stop` / `sail up -d`, and `/admin/login` returned HTTP 200.

Never use `docker compose down -v` as a normal stop command; `-v` intentionally
deletes the persistent database volume.

## LAN acceptance

On the Linux host, ensure the printed LAN IP is reachable according to local firewall
policy.

From another device on the same LAN open:

```text
http://<host-lan-ip>:9000/admin
```

This S0 environment is not intended for direct Internet exposure.

Verified on 2026-08-17 from an isolated second Docker network namespace through the
host LAN address `10.0.0.30:9000`: `/admin/login` returned HTTP 200. Docker inspection
also confirmed publication on `0.0.0.0:9000` and `[::]:9000`.

## Tests

Run:

```bash
./vendor/bin/sail test
```

or the project test wrapper established during implementation.

Before and after tests, verify the development administrator remains present and its
credentials still work.

Tests must report `APP_ENV=testing` and use DB `testing`.

## Quality checks

Run the same logical gates as CI:

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit --locked --no-interaction
./vendor/bin/sail pint --test
./vendor/bin/sail php vendor/bin/phpstan analyse
./vendor/bin/sail test
```

Exact wrappers may be consolidated in `composer.json` after implementation, provided
they preserve the same gates.

## Failure recovery

Safe operations:

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan optimize:clear
```

Do not solve ordinary development problems with:

```text
migrate:fresh
database truncation
volume deletion
password rotation
```

unless the owner explicitly chooses to discard development state.
