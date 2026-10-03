---
title: "Gateway response fixtures"
description: "Recorded Gateway responses are the contract that CLI tests replay, so a response change shows which commands it reaches before it ships."
covers:
  - packages/php-sdk/fixtures/**
  - apps/gateway/tests/Support/ResponseFixtures.php
  - apps/cli/tests/Helpers/GatewayFixtures.php
  - bin/api-fixtures
  - bin/cli-contract
  - apps/web/fixtures/**
---

# Gateway response fixtures

This page tells a contributor how a Gateway response change reaches the CLI tests. A fixture is one recorded Gateway response under `packages/php-sdk/fixtures/<family>/<command>/<case>.json`. The Gateway test suite records it and the [API reference](/api/overview) validates it. The CLI test suite replays it through a Saloon mock and compares the complete command output with a stored expectation.

Tool scan and adoption fixtures distinguish formula, cask, and Vite+ global identities, registered and informational packages, dependencies, unsupported artifacts, per-manager scan states, inspection time, and failed scans. Scan fixtures also record authorization and unmanaged-node refusals. Adoption fixtures record a created Tool, the same intent, a repaired failure, constraint conflicts and violations, a busy scope, a missing or unsupported package, and authorization failure. The CLI replays those responses for `tool:adopt`.

Doctor fixtures include informational-only healthy reports and mixed reports with drift or unverifiable findings. Node fixtures include an enrolled Mac with empty roles. Web fixtures preserve registered Tools while a live inventory request fails.

Task fixtures include a GitHub feedback fixup with `fixup_problem: review:{reviewer_id}` and an immutable findings packet in `brief`. Record that response from the Gateway fixture test with example identities and findings. Its `review-findings` deliverable and optional `project-check` use the existing response schema. Replaying the fixture must preserve source provenance without interpreting review text as an action. The [Tasks contract](/reference/tasks#review-fixup-lifecycle) owns the meaning of those fields.

Process response fixtures include `user`, which is null when the Process uses its derived account. When an explicit account is selected, the response includes that name both in `user` and in `runtime_config.user`; see [Node accounts](/reference/processes-and-schedules#node-account).

Instance create, list, and show fixtures include `annotator_port` and `annotator_url`. Both are null without an assigned annotator port; an Instance with a port but no Route still has a null URL. Re-record these fixtures when either field changes, regenerate the OpenAPI schema, and replay the Instance CLI contracts. Human detail output shows the annotator properties only when a port is assigned, while JSON retains the nullable fields. The web API types must also be regenerated from the same OpenAPI schema.

## Record a fixture

A Gateway fixture test sends a request with deterministic data and calls `record_fixture()` from `apps/gateway/tests/Support/ResponseFixtures.php` with the fixture name, the SDK request class that sends the route, and the route.

```php
record_fixture($this->getJson('/api/v1/nodes')->assertOk(), 'nodes/node-list/default', ListNodesRequest::class, 'GET /api/v1/nodes');
```

A normal test run asserts that the response still equals the recorded file. A run with `ORBIT_FIXTURES=record` rewrites the file. Use the request id `fixture_request_id()` so recorded and expected output stay stable.

```bash
cd apps/gateway && ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures
```

The recorded file holds the request class, the route, the status, and the body. It holds no secrets, because fixture tests use example values. Project fixtures include source defaults and task settings such as `task_check`; update the corresponding CLI expectations when those response fields change. Task fixtures share one id sequence with their subtasks. A subtask id is greater than its task id, and creating another task does not reuse a subtask id.

The `tasks/tasks-show/watched` fixture records a running task whose branch pull request was opened outside Orbit. It has `watched_pr_url`, `watched_pr_number`, and `watched_pr_state`, while `pr_url` remains null. The default task fixtures keep the watched fields null. The SDK and CLI replay both cases so a watched pull request cannot become the reviewed pull request by accident. When these fields change, re-record every response that includes a task and update its CLI JSON expectation. The [branch watch](/reference/tasks#watch-the-branch-while-subtasks-are-open) owns their meaning.

## Validate a fixture

`bin/api-fixtures --check` validates every fixture body against the response schema for its route and status in `docs/openapi.json`. The `apps/docs` checks run it, so a fixture whose body differs from the API reference fails continuous integration. Regenerate the reference with `composer docs-openapi` when a response shape changes on purpose. The inputs that command reads are on [API reference generation](/reference/api-reference).

## Replay a fixture in the CLI

A CLI contract test loads a fixture by name with `gateway_fixture_mock()` from `apps/cli/tests/Helpers/GatewayFixtures.php`, runs the command, and compares the output with `apps/cli/tests/Expected/<family>/<command>/<case>.human.txt` or `.json` through `expect_output()`.

```php
MockClient::global(gateway_fixture_mock('nodes/node-list/default'));
expect(Artisan::call('node:list'))->toBe(0);
expect_output(Artisan::output(), 'nodes/node-list/default.human.txt');
```

A normal run asserts the exact output. A run with `ORBIT_EXPECTED=update` rewrites the expected files after a reviewed change.

```bash
cd apps/cli && ORBIT_EXPECTED=update vendor/bin/pest --filter=Contract
```

## Find the commands a change reaches

`bin/cli-contract` runs only the CLI tests that replay the named fixtures. With `--changed`, it takes the fixtures that differ from `origin/main`. With `--preview`, it renders every contract case into a temporary directory and lists the commands whose output differs from `tests/Expected`, with a diff per file. That list is the map of the commands a rendering change reaches. With `--coverage`, it lists the product commands that have no expected output yet.

```bash
bin/cli-contract nodes/node-add/created
bin/cli-contract --changed
bin/cli-contract --preview
bin/cli-contract --coverage
```

The change cycle is: change the Gateway, re-record the fixtures, review the fixture diff, run `bin/cli-contract --changed`, fix or accept each command's output, and update the expected files.

## Web app fixtures

The web app's demo mode and its tests run against the files under `apps/web/fixtures/fleet`. These files use the same format without a request class. They are written by hand as one coherent fleet, because a recorded fixture covers one route and the web app needs records that refer to each other. `bin/api-fixtures --check` validates them together with the recorded fixtures.

The demo fleet has one tool inventory for each active Node. Node 1 is an empty Linux read: `brew` completed with no packages, `brew-cask` unsupported, and `vp` absent. Node 2 is a partial Linux read: `brew` incomplete, `brew-cask` unsupported, and `vp` conflicting, so none of those empty arrays is an inventory. Node 3 is unreachable and has no inventory fixture; the demo refuses a scan with `tool.node_inactive`.

Node 4 is the macOS read: registered packages with a newer observed version, the `openssl@3` dependency, unsupported casks, a formula and a cask both named `visual-studio-code`, and `ghost`, which the demo lists as supported and then refuses on adopt. The demo adopt, update, and remove handlers change only that in-memory fleet.

## Families with fixtures

The directories under `packages/php-sdk/fixtures` list the families that have recorded fixtures and contract tests. Add a family by recording from its Gateway tests with `record_fixture()` and writing its CLI contract test in the same change. Extension responses are replayed by the `extension:list`, `extension:enable`, and `extension:disable` command contracts; do not leave a changed extension fixture without a CLI test that names it. `bin/cli-contract --coverage` shows the commands that still have no expected output.
