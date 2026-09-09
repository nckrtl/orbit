# AppInstance environment variables

This page tells an operator how the Gateway reads, stores, and safely replaces an AppInstance environment file. [ADR 0044](../decisions/0044-own-appinstance-environment-configuration-in-orbit.md) owns the environment-configuration boundary.

## Select an AppInstance

The import and update endpoints accept either a positive numeric AppInstance ID or an exact Route hostname in `{instance}`. A selector that matches no AppInstance returns HTTP 404. A Route hostname that has multiple AppInstance targets returns HTTP 409 with `env.target_ambiguous`.

The Gateway accepts an active AppInstance only after its recorded placement is complete and no source migration or Route hostname change is pending. It also enforces access from the active peer to the owning Node before it reads the environment file or stored configuration. Import requires the owning Node to be active. A stored update does not contact the Node and can succeed while that Node is unreachable.

## Import an environment file

Use the Gateway API to import the existing file from the recorded placement:

```http
POST /api/v1/instances/{instance}/environment/import
Content-Type: application/json

{}
```

The request accepts one optional boolean field. Unknown, duplicate, malformed, or wrongly typed fields return HTTP 422 before a change. Without replacement, a file key that is already stored returns HTTP 409 with `env.import_conflict` and stores nothing. Replacement updates matching keys, adds new keys, and retains stored keys that the file omits.

| Field | Default | Effect |
| --- | --- | --- |
| `replace` | `false` | Select whether the import can replace stored keys. |

For development, the Gateway reads `.env` from the recorded checkout. For production, it reads `.env` from the recorded application-user home. The Gateway never falls back to `.env.example`. It refuses an absent or oversized file, unsafe path, non-regular file, wrong owner, unusable recorded execution identity, unreadable file, or failed trusted Secure Shell (SSH) connection before it reads file contents. The production check runs as the recorded application user and does not require an interactive login shell.

The importer accepts blank lines, comments, quoted values, escaped values, multiline quoted values, and variable expansion from keys in the same file. It does not read variables from the Gateway process environment. Duplicate keys, unresolved expansion, invalid dotenv syntax, or a file larger than 1 MiB reject the complete import. Orbit stores parsed keys and values, without comments or original formatting.

For an AppInstance recorded as Laravel, import replaces an encountered `APP_URL` with `https://{{app_instance.hostname}}` or adds it when absent. It keeps `APP_KEY` and every other imported literal unchanged. Other application types receive no Laravel-specific key.

## Update one stored value

Use the Gateway API to add or replace one key:

```http
PUT /api/v1/instances/{instance}/environment/{key}
Content-Type: application/json

{"value":"example"}
```

The body contains exactly one string `value`. Empty text, `false`, and `0` are distinct string values when sent as `""`, `"false"`, and `"0"`. An identical stored value succeeds with `changed: false`. For Laravel, an `APP_URL` update accepts only `https://{{app_instance.hostname}}`.

## Validate stored configuration

The Gateway validates the complete result before it stores any part of an import or update.

| Limit | Contract |
| --- | --- |
| Key | 1 to 255 ASCII characters; starts with `A-Z`, `a-z`, or `_`; remaining characters can also contain `0-9` |
| Value | Valid UTF-8 string of at most 65,536 bytes without a null byte |
| Keys per AppInstance | At most 1,024 |
| Generated file | At most 1 MiB after conservative escaping and maximum placeholder expansion |
| Placeholders | Only `{{app_instance.hostname}}` and `{{app_instance.environment}}`, including as part of a longer value |

Malformed or unknown placeholder expressions fail validation. Database-path placeholders, `{{instance.hostname}}`, and `{{app_instance.url}}` are not supported.

## Replace a remote environment file

The Gateway can install a supplied complete environment file through a protected internal operation. For development, it selects `.env` in the recorded checkout. For production, it selects `.env` in the recorded application-user home and runs as that user even when the account has a disabled login shell. An App or AppInstance web root such as `public` does not change this location. Callers cannot override the Node, runtime user, directory, or filename.

Before a write, the Gateway checks trusted SSH access, the recorded execution identity, path containment, directory write access, destination type and ownership, replacement permission, read-only storage, and conservative required capacity. A missing parent, unsafe symlink, special file, wrong owner, failed or malformed observation, or insufficient capacity stops the operation before it changes `.env`. This preflight reads no environment values and does not parse or decrypt configuration. Import uses the same boundary with read checks and needs no write permission or replacement capacity.

The writer checks the recorded boundary again, writes the supplied bytes through protected input to a mode-`0600` candidate owned by the runtime user, and atomically replaces `.env`. It does not parse or import the old file. A missing `.env` is created. A matching protected file remains the same file and reports `changed: false`; different bytes or a different mode produce a complete protected replacement and report `changed: true`.

A confirmed candidate-write, protection, or rename failure leaves the previous `.env` unchanged and removes only the failed attempt's candidate. When the Gateway loses the final acknowledgement, it reports an unconfirmed result because the replacement might have completed. A retry repeats placement and boundary checks, then accepts an already matching protected file or installs the supplied complete file.

The remote operation does not expose supplied bytes or raw remote output in results, errors, exception chains, logs, or normal debugging. It changes no source file outside `.env`, Git metadata, database file, framework cache, service, or application process. It runs without an installed framework, application dependencies, or an application database.

## Read results and recover encrypted values

A successful import or update returns only the AppInstance ID, operation, whether stored configuration changed, the total stored key count, and request-ID metadata. Responses, activity, logs, diagnostics, and model serialization omit environment values.

The Gateway encrypts every literal and placeholder expression with its application encryption key before database storage. Recovery of stored configuration depends on retaining that Gateway key material. Orbit does not generate or delete an application key through these endpoints, and it never displays plaintext stored values.

Import and update change only stored Gateway configuration. They do not write the workload `.env`, run application code, refresh framework caches, restart services, or require an application database or installed framework dependencies.
