# Quickstart: Validate Company Access and Settings

## Prerequisites

- S0 stack is running and healthy on port 9000.
- `.env` contains the S0 administrator credentials.
- The administrator has been synchronized after the S1 migration so it is marked as
  the platform administrator.
- Commands run through Sail; host PHP or Composer is not required.

## Apply S1 forward migrations

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan mp2:ensure-dev-admin
```

Do not use `migrate:fresh` or remove the development volume.

## Create a beneficiary

```bash
./vendor/bin/sail artisan mp2:provision-user "Operatore MP2" operatore@mp2.local
```

Enter and confirm a password of at least 12 characters when prompted. The command
must not print it.

## Demonstrate the vertical flow

1. Open `http://localhost:9000/admin` and sign in as the S0 administrator.
2. Create an Azienda with a denomination and an explicit IANA zone such as
   `Europe/Rome`.
3. Confirm that the new company opens and the administrator can see its Dashboard.
4. Open access management and assign `visualizza` plus one different capability to
   `operatore@mp2.local`.
5. Sign in as the operator and confirm only that company is available.
6. As the administrator, create a second company and do not assign the operator.
7. Confirm the operator cannot see or open the second company, including by entering
   its URL directly.
8. Change the first company's unclassified policy from `Avviso` to `Blocco`.
9. Preview a time-zone change, observe the explicit empty affected-event result, and
   confirm it.
10. Open company audit and verify company creation, nine initial grants, later
    permission changes, and setting changes are immutable and complete.

## Focused tests

```bash
./vendor/bin/sail composer test -- --filter=Company
./vendor/bin/sail artisan test tests/Feature/Console/ProvisionUserCommandTest.php
```

The existing test guard must show that the suite uses the `testing` database.

## Full quality gate

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail composer format:test
./vendor/bin/sail composer analyse
./vendor/bin/sail composer test
```

## Persistence check

```bash
./vendor/bin/sail stop
./vendor/bin/sail up -d
```

After restart, both companies, capability differences, settings, and audit events
must remain. The S0 administrator credentials must remain unchanged.

