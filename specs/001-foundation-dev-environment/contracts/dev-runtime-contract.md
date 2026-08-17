# Contract: Development Runtime and Bootstrap

## Entry point

The repository provides:

```text
scripts/bootstrap-dev.sh
```

Running it from a clean checkout is the supported S0 bootstrap.

## Required behavior

In order:

1. validate Linux, Docker and Docker Compose availability;
2. create `.env` from `.env.example` when missing;
3. set host `WWWUSER` / `WWWGROUP`;
4. install Composer dependencies through a Docker Composer image if `vendor/` is
   absent;
5. start the Sail application and MySQL containers;
6. wait for MySQL readiness with a bounded retry;
7. generate `APP_KEY` only when absent;
8. persist `DEV_ADMIN_PASSWORD` equal to `DEV_ADMIN_EMAIL` as explicitly requested;
9. run forward migrations against development DB;
10. invoke `mp2:ensure-dev-admin`;
11. clear only safe framework caches if required;
12. verify the `/admin/login` route responds;
13. print local URL, detectable LAN URL, email and password.

## Forbidden behavior

Bootstrap MUST NOT:

- use `migrate:fresh`;
- drop the development database;
- remove the MySQL volume;
- truncate user/domain tables;
- use a development password different from the explicitly requested login email;
- create duplicate development admins;
- seed fake domain objects;
- install npm packages;
- run a Vite build;
- enable MFA;
- install Shield;
- operate when configured as production.

## Idempotency

Two consecutive successful bootstrap runs against unchanged code/configuration must
result in equivalent application state and identical configured admin credentials.

## LAN access

The application is published on host port `9000`.

The script attempts to discover a usable LAN IPv4 address and prints:

```text
Local: http://127.0.0.1:9000/admin
LAN:   http://<host-lan-ip>:9000/admin
```

LAN-IP discovery failure is informational; it does not invalidate an otherwise
working local bootstrap.

The script does not modify host firewall rules and does not create an Internet tunnel.

## Permission safety

Dependency bootstrap uses the host UID/GID when writing the project tree.

Normal S0 commands must not leave project files owned by root.
