---
title: "Gateway environment"
description: "Where the Gateway reads its own .env settings, how to apply a change, and what each key in the example file does."
covers:
  - apps/gateway/.env.example
  - bin/env-docs
---

# Gateway environment

The Gateway is a Laravel application, so it reads its own settings from a `.env` file. This page covers that file. [Instance environment variables](/reference/environment-variables) covers the `.env` files that the Gateway manages for Instances.

## Where the file lives

The file's path depends on how the Gateway runs.

| Gateway | File |
| --- | --- |
| Installed Gateway | `/home/orbit/shared/gateway.env`. Every release links to it. |
| Development checkout | `apps/gateway/.env`. Copy it from `apps/gateway/.env.example`. |

An installed Gateway caches its configuration, so an edit takes effect only after [Apply an env change](/reference/gateway-recovery#apply-an-env-change).

## Keys in the example file

`apps/gateway/.env.example` lists the keys a new Gateway needs, with development defaults. Keys that start with `ORBIT_` are Orbit settings. The other keys are standard [Laravel configuration](https://laravel.com/docs/configuration).

| Key | Meaning |
| --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | Laravel application name, environment, debug mode, and URL. |
| `APP_KEY` | Encryption key. When it is empty, the Gateway reads `gateway.app-key` in `ORBIT_HOME`, so a replaced checkout keeps reading the settings it encrypted. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Laravel locales. |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Where Laravel records maintenance mode. |
| `ORBIT_HOME` | Directory for the Gateway's durable state. It defaults to `$HOME/.orbit`. [Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set) lists what it holds. |
| `ORBIT_GATEWAY_CHECKOUT` | Stable path of the Gateway checkout. The [release layout](/reference/gateway-recovery#release-layout) and the Gateway's Node agent units use it. It defaults to `/home/orbit/orbit/apps/gateway`. |
| `ORBIT_HIBERNATION_IDLE_SECONDS`, `ORBIT_HIBERNATION_SWEEP_SECONDS`, `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` | Development runtime hibernation timing. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation). |
| `PHP_CLI_SERVER_WORKERS` | Number of workers for `php artisan serve` in local development. |
| `BCRYPT_ROUNDS` | Laravel password hashing cost. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_LEVEL` | Laravel logging. [Gateway request logs](/reference/gateway-recovery#gateway-request-logs) describes the files. |
| `DB_CONNECTION` | Laravel database connection. The Gateway uses SQLite. |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | Laravel sessions. The API is stateless, so the example uses the `array` driver. |
| `FILESYSTEM_DISK`, `QUEUE_CONNECTION`, `CACHE_STORE` | Laravel storage, queue, and cache drivers. |
| `ORBIT_WEBSOCKET_REPOSITORY`, `ORBIT_WEBSOCKET_REF`, `ORBIT_WEBSOCKET_INSTALL_PATH`, `ORBIT_WEBSOCKET_PORT` | Defaults for the `websocket` Node role. See [Realtime events with Reverb](/solutions/realtime-reverb). |
| `TYPESAFE_API_KEY` | Key for the Jev classifier in [Tasks](/reference/tasks). |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | GitHub logins requested as reviewers on a task pull request. See [Tasks](/reference/tasks). |

Other `ORBIT_` keys belong to one feature, and the page for that feature documents them.

## Drift check

`bin/env-docs` runs in CI. It fails when:

- Gateway, CLI, or PHP SDK code reads an `ORBIT_` key that no docs page mentions.
- `apps/gateway/.env.example` lists a key that this page does not mention.
- `apps/gateway/.env.example` lists a key that neither Gateway code nor Laravel reads.

The check finds keys read through `env()`, including `env(key: ...)`, and through `getenv()`, `Env::get()`, `$_SERVER`, and `$_ENV`. ADRs do not count as documentation. Keys without the `ORBIT_` prefix belong to Laravel, PHP, or the operating system, and their own docs cover them.
