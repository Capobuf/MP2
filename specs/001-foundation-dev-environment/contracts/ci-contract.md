# Contract: S0 GitHub Actions

## Triggers

The quality workflow runs on:

- pull requests;
- pushes to maintained branches.

## Runtime

- Ubuntu runner;
- PHP 8.3;
- MySQL 8.4-family service;
- Composer v2;
- no Node setup;
- no Selenium/Dusk service in S0.

## Test environment

CI uses:

```text
APP_ENV=testing
DB_DATABASE=testing
```

It never connects to a developer's persistent database.

## Required gates

In this order where practical:

1. checkout;
2. PHP/extensions setup;
3. `composer validate --strict`;
4. locked Composer install;
5. `composer audit --locked --no-interaction`;
6. prepare test database;
7. migrate test database;
8. `vendor/bin/pint --test`;
9. Larastan;
10. Pest;
11. application/bootstrap smoke verification appropriate to CI.

## Performance

The workflow is designed for normal completion around three minutes.

A persistent duration above five minutes is a maintenance signal, not an automatic
reason to weaken unique test coverage.

## Failure artifacts

Upload logs only when useful and only on failure. S0 does not create browser
screenshot artifacts because it does not run Dusk.
