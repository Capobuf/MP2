# Administrative Command Contract

## Provision an ordinary user

```text
php artisan mp2:provision-user {name} {email}
```

The command always asks for the password through hidden input and asks for
confirmation. The password is never accepted as a command-line argument or printed.

### Preconditions

- name is non-empty and at most 255 characters;
- email is syntactically valid, at most 255 characters, and not already registered;
- password is at least 12 characters;
- the application database is reachable.

### Success

- creates exactly one authenticated user;
- the new user is not a platform administrator;
- prints a concise Italian success message containing the email but not the password;
- exits with status `0`.

### Rejection

- invalid input or an existing email creates or changes no user;
- prints a concise Italian reason;
- exits non-zero.

This command does not assign company capabilities. A user becomes able to access the
panel only after receiving `visualizza` for at least one company.
