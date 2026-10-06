---
title: "API reference generation"
description: "Contract and inputs for bin/docs-openapi, including the Gateway route list outside the checkout."
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

The contract is that `bin/docs-openapi` produces the same `docs/openapi.json` on every machine with the same checkout. The bytes match. The clock, the hostname, the locale, the absolute checkout path, directory order, `$ORBIT_HOME`, and which extensions a Gateway reports as enabled are not inputs to that contract.

One input still reads outside the checkout. `php artisan route:list` loads the Gateway `.env`, the installed Gateway dependencies, and the PHP binary on `PATH`. The file matches only when that [pinned environment](#pinned-environment) matches too.

## Inputs

`bin/docs-openapi` reads each input in this table. Fixed schema text in the script is an input too. The route-list row also depends on the pinned environment.

| Input | What the generator keeps |
| --- | --- |
| `php apps/gateway/artisan route:list --json --path=api` | Method, URI, route name, and controller action for each `api/` route. The command runs in `apps/gateway`. |
| `apps/gateway/app/Http/Controllers/Api/*.php` | The form request, the data class, and whether the action returns HTTP 201. |
| `apps/gateway/app/Http/Requests/**/*.php` | The `rules()` body of each form request, including shared database update rules and their regex constants. |
| `apps/gateway/app/Data/**/*.php` | Constructor properties, snake-case mapping, `toArray()` keys, and property docblocks. |
| `apps/gateway/app/**/*.php` | The quoted string cases of each enum under this tree. |
| `packages/php-sdk/src/Requests/**/*.php` | Method, endpoint, constructor, JSON body, and query of each request class. |
| `packages/php-sdk/src/Responses/**/*.php` | Constructor properties of each response class. |
| `apps/cli/app/Commands/**/*.php` | Command name, description, help, and each argument and option description. |
| `docs/docs.json` | The navigation outside the API groups. The generator rewrites those groups and does not use this file to build `docs/openapi.json`. |

The generator reads every command class, including commands the CLI hides at runtime. It does not run `orbit list`, and it does not load `apps/cli/vendor`.

`bin/docs-openapi` also contains fixed schema text that it does not parse from the rows above, such as the annotation record and the deployment event stream. It also stores operation summaries and descriptions, including `tool:scan`, `tool:adopt`, `tasks:create`, `tasks:update`, and `tasks:complete`. Those descriptions stay in the script so the API and MCP keep the operation contracts rather than only the short CLI summaries. That text is part of the checkout. Editing the script changes the document in the same way on every machine.

The `tasks:create` and `tasks:update` descriptions follow the [Tasks contract](/reference/tasks#tasks-and-subtasks): a task belongs to a Project, and an external ADE plans the work. The descriptions keep the status, subtask, deliverable, and Coder notification requirements. The completion description includes the ended watched pull request path, its durable receipt, and the `tasks.not_settling` and `tasks.subtask_interrupt_failed` errors.

The script marks `instance:deploy` and `instance:rollback` with `x-orbit-task-action: true`. A [task definition](/reference/tasks#subtask-definitions) action may name only a marked operation. A form-request rule `present` marks that property required, as `required` does. The task definition response lists the fields the Gateway always returns, and its parameters, phases, and subtasks use the same item schemas as the write.

Requests that inspect raw JSON also declare their body fields in `rules()`. The generated bodies include the Instance branch, deploy-step fields, database connection patches, and Node settings. A keyed array rule such as `array:path` publishes a closed JSON object, and nullable objects and members keep their null type. The generator resolves the shared database rules' regex constants and translates their whole-string anchors for JSON Schema, so invalid connection fields remain invalid in OpenAPI and MCP. The raw JSON checks still enforce object shape and the domain rules at runtime.

The Project development deploy step operations publish typed step responses from the SDK response fields. Create publishes HTTP 201 for a new step and HTTP 422 for a duplicate name, including an exact retry; it has no HTTP 200 retry response. Their generated write schemas include the name pattern, timeout bounds and default, and `required: true` default on create. The [development deploy contract](/reference/deployments#development-deploy-steps) owns the list limits and refusal rules.

[Project Documents](/reference/project-documents#api-contract) publish typed entry and version schemas, numeric entry IDs, cursor metadata, and the same JSON text/base64 bodies for HTTP and MCP. Their generated create schema distinguishes folders from files and requires exactly one content field for files. Write has the same content choice; metadata update requires a name or parent. These constraints remain on MCP tools when the body already has shared object properties. Create returns only HTTP 201, not an upsert retry response.

A request field the API validates as a boolean is a JSON `boolean` in `docs/openapi.json`. [MCP](/reference/mcp) keeps that type in the tool schema.

The Instance rename body has optional `branch` and `domain` fields, but requires at least one. Its schema sets `minProperties: 1` and rejects extra fields. The PHP example supplies a branch rather than sending an empty body; that branch must already be checked out on the Node.

### Deliverable probe and check-read schemas

The catalogue publishes `tasks:deliverable:probe` as `POST /api/v1/task-groups/{group}/tasks/{task}/deliverables/{deliverable}/probe` and `tasks:check:show` as `GET /api/v1/task-groups/{group}/tasks/{task}/checks/{check}`. Their operation IDs are `tasks-deliverable-probe` and `tasks-check-show`, which also name their generated [MCP tools](/reference/mcp#deliverable-probes-and-check-reads). The [deliverable probe contract](/reference/tasks#deliverable-probes) owns execution rules; [CLI Tasks](/cli/tasks#orbit-tasksdeliverableprobe) owns the command inputs and output.

The generator gives `group`, `task`, and `check` numeric ID schemas and `deliverable` the declared ID pattern and length limit. The probe Form Request publishes only the optional JSON boolean `base`. Its raw JSON validation rejects unknown body fields, including free-form filters and extra command arguments; the generated MCP input schema also rejects additional properties.

Both responses use `TaskCheckData`, including `id`, `kind`, `status`, nullable `exit_code`, `deliverable_evidence`, `output_tail`, and `receipt`, alongside the existing check fields. The check-read action returns the probe's stored receipt. Its explicit controller response mapping keeps this typed schema in the generated catalogue even though the controller delegates the read to an action. Non-probe checks have a null receipt. The probe creation schema advertises HTTP 201 only, not idempotent HTTP 200 reuse: every admitted POST creates a reservation and consumes quota. It publishes HTTP 429 `tasks.probe_limit` and HTTP 502 `tasks.probe_start_pending`, whose error details require a positive integer `check_id` for inspection and recovery. Clients inspect that retained check rather than retrying the POST.

`DeliverableProbeApiTest` checks HTTP and MCP execution, numeric check IDs, probe evidence and receipt fields, retained-start error correlation, creation/recovery response schemas, rejected body inputs, and presence of both tools in the generated manifest. `ToolManifestCoverageTest` and `McpToolSchemaTest` protect route coverage and served input schemas. After changing this slice, regenerate OpenAPI, the MCP manifest, and web API types, then run their freshness checks.

### Feedback fixups keep the Tasks schema

[GitHub feedback fixups](/reference/tasks#review-fixup-lifecycle) add the `review:{reviewer_id}` identity to the existing `fixup_problem` string and put provenance and findings in the existing `brief`. They add no public field, input, endpoint, or merge operation. Regenerate OpenAPI, then check the recorded review-fixup response with `bin/api-fixtures --check`, regenerate MCP/task-action manifests, and regenerate web API types. Unchanged schema output is a checked result, not a reason to skip the generators.

## Pinned environment

`php artisan route:list` loads the Gateway `.env` and the installed Gateway dependencies, and it runs on the PHP binary on `PATH`. The same checkout produces the same `docs/openapi.json` only when these three match.

| Input | What the route list reads |
| --- | --- |
| `apps/gateway/.env` | Environment file Laravel loads for the route list. Continuous integration copies `.env.example` to `.env` when the example file exists. |
| `apps/gateway/vendor` | Installed PHP packages the route list loads. |
| PHP version | The interpreter for the route list. The API reference job uses PHP 8.5. |

## Why it works this way

`bin/docs-openapi --check` compares the committed document with a fresh run. That comparison means the checkout changed only when the pinned route-list environment matches. A different Gateway `.env` or a different PHP version changes the route list while the Git tree stays the same.

Command text does not. `orbit list` hides `tasks` and `proxycli` commands unless the active Gateway profile reports those extensions as enabled, and the command fails when `apps/cli/vendor` is absent. A machine with the extensions on would add request-field descriptions such as the task title and the proxycli cache connection, and continuous integration would leave them out. Reading the command classes keeps those descriptions in the document on every machine. Framework options such as `--version` are not part of the signature, so they are not copied onto a request field.

After changing an operation description or response, run `bin/mcp-tools` to refresh the Gateway MCP manifest and `bun run types` from `apps/web` to refresh its TypeScript API types. The web app builds its TypeScript API types from `docs/openapi.json`. A stable document keeps those types stable until an input changes. [Web app](/reference/web-app) describes that generation.
