# Contract: Local Environment

`.env` is local and MUST NOT be committed.

`.env.example` documents non-secret defaults and the explicitly requested local-only
development credentials.

## Required S0 variables

```dotenv
APP_NAME=MP2
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:9000
APP_PORT=9000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=mp2
DB_USERNAME=sail
DB_PASSWORD=password

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

DEV_ADMIN_NAME=Administrator
DEV_ADMIN_EMAIL=admin@mp2.local
DEV_ADMIN_PASSWORD=admin@mp2.local

WWWUSER=
WWWGROUP=
```

The exact Laravel scaffold may contain additional framework variables. They may
remain only when they are actually used or are standard harmless defaults.

## Generated values

Bootstrap owns:

- `APP_KEY` when blank;
- `DEV_ADMIN_PASSWORD`, synchronized to `DEV_ADMIN_EMAIL` for local development;
- `WWWUSER` and `WWWGROUP` from the Linux host user when blank or stale for the
  current checkout.

## Development password

By explicit owner instruction, the S0 local password equals the login email. It MUST
remain stable during normal bootstrap reruns, and the provisioning command MUST still
refuse production.

## Testing environment

`phpunit.xml` or equivalent test configuration MUST override:

```text
APP_ENV=testing
DB_DATABASE=testing
```

The test harness MUST fail before DB reset if these conditions are not true.
