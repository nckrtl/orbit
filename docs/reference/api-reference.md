---
title: "API reference generation"
description: "Contract and inputs for bin/docs-openapi, including the environment outside the checkout."
covers:
  - bin/docs-openapi
---

# API reference generation

`bin/docs-openapi` writes `docs/openapi.json`, the OpenAPI document for the Gateway API. The documentation site renders that document on the [API tab](/api/overview). The same run rewrites the generated API groups in `docs/docs.json` so the sidebar lists those operations.

Run the generator from the repository root:

```bash
bin/docs-openapi
```

`composer docs-openapi` runs the same script. `bin/docs-openapi --check` fails when `docs/openapi.json` or those API groups differ from a fresh run. Continuous integration runs the check.

## Same checkout, same file

The contract is that `bin/docs-openapi` produces the same `docs/openapi.json` on every machine with the same checkout. The bytes match. The clock, the hostname, the locale, the absolute checkout path, and directory order are not inputs to that contract.

The generator does not meet the contract from the checkout alone. `php artisan route:list` and `php orbit list` read outside it. The file matches only when the [pinned environment](#pinned-environment) matches too.

## Inputs

`bin/docs-openapi` reads each input in this table. Fixed schema text in the script is an input too. The PHP rows also depend on the pinned environment.

| Input | What the generator keeps |
| --- | --- |
| `php apps/gateway/artisan route:list --json --path=api` | Method, URI, route name, and controller action for each `api/` route. The command runs in `apps/gateway`. |
| `apps/gateway/app/Http/Controllers/Api/*.php` | The form request, the data class, and whether the action returns HTTP 201. |
| `apps/gateway/app/Http/Requests/**/*.php` | The `rules()` body of each form request. |
| `apps/gateway/app/Data/**/*.php` | Constructor properties, snake-case mapping, `toArray()` keys, and property docblocks. |
| `apps/gateway/app/**/*.php` | The quoted string cases of each enum under this tree. |
| `packages/php-sdk/src/Requests/**/*.php` | Method, endpoint, constructor, JSON body, and query of each request class. |
| `packages/php-sdk/src/Responses/**/*.php` | Constructor properties of each response class. |
| `php apps/cli/orbit list --format=json` | The description of each command argument and option. `TaskCommand` and `ProxyCliCommand` set `isHidden()` through `ExtensionCommandVisibility` and `GatewayExtensionState::isEnabled()`, which reads `$ORBIT_HOME/config.json` and the extension state the configured Gateway reports. Hidden commands omit `cache_connection`, `cliproxy_url`, `disabled`, `title`, `brief`, and `status`. |
| `docs/docs.json` | The navigation outside the API groups. The generator rewrites those groups and does not use this file to build `docs/openapi.json`. |

`orbit list` runs in `apps/cli`. During that listing, `ExtensionCommandVisibility` is on. `TaskCommand` and `ProxyCliCommand` then hide the command unless `GatewayExtensionState::isEnabled()` is true for `tasks` or `proxycli`. Discovery reads the active profile in `$ORBIT_HOME/config.json` and asks that Gateway which extensions are enabled. `ORBIT_HOME` defaults to `$HOME/.orbit`, as [Gateway trust](/reference/gateway-trust) describes. `tasks:status` sets `isHidden()` to false and stays in the list. When the other commands are hidden, their option text is absent from `docs/openapi.json`.

`bin/docs-openapi` also contains fixed schema text that it does not parse from the rows above, such as the annotation record and the deployment event stream. That text is part of the checkout. Editing the script changes the document in the same way on every machine.

## Pinned environment

Both PHP commands load the project `.env` and the installed dependencies, and they run on the PHP binary on `PATH`. The same checkout produces the same `docs/openapi.json` only when these three match.

| Input | What the PHP commands read |
| --- | --- |
| `apps/gateway/.env` and `apps/cli/.env` | Environment files Laravel loads for the route list and the CLI list. Continuous integration copies `.env.example` to `.env` when the example file exists. |
| `apps/gateway/vendor` and `apps/cli/vendor` | Installed PHP packages both commands load. |
| PHP version | The interpreter for both commands. The API reference job uses PHP 8.5. |

## Why it works this way

`bin/docs-openapi --check` compares the committed document with a fresh run. That comparison means the checkout changed only when the pinned environment matches. A different Gateway profile, a different `.env`, or a different PHP version changes the file while the Git tree stays the same.

The web app builds its TypeScript API types from `docs/openapi.json`. A stable document keeps those types stable until an input changes. [Web app](/reference/web-app) describes that generation.
