---
title: "Gateway response fixtures"
description: "Recorded Gateway responses are the contract that CLI tests replay, so a response change shows which commands it reaches before it ships."
---

# Gateway response fixtures

This page tells a contributor how a Gateway response change reaches the CLI tests. A fixture is one recorded Gateway response under `packages/php-sdk/fixtures/<family>/<command>/<case>.json`. The Gateway test suite records it, the [API reference](/api/overview) validates it, and the CLI test suite replays it through a Saloon mock and compares the complete command output with a stored expectation.

## Record a fixture

A Gateway fixture test sends a request with deterministic data and calls `record_fixture()` from `apps/gateway/tests/Support/ResponseFixtures.php` with the fixture name, the SDK request class that sends the route, and the route.

```php
record_fixture($this->getJson('/api/v1/nodes')->assertOk(), 'nodes/node-list/default', ListNodesRequest::class, 'GET /api/v1/nodes');
```

A normal test run asserts that the response still equals the recorded file. A run with `ORBIT_FIXTURES=record` rewrites the file. Use the request id `fixture_request_id()` so recorded and expected output stay stable.

```bash
cd apps/gateway && ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures
```

The recorded file holds the request class, the route, the status, and the body. It holds no secrets, because fixture tests use example values.

## Validate a fixture

`bin/api-fixtures --check` validates every fixture body against the response schema for its route and status in `docs/openapi.json`. The `apps/docs` checks run it, so a fixture whose body differs from the API reference fails continuous integration. Regenerate the reference with `composer docs-openapi` when a response shape changes on purpose.

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

`bin/cli-contract` runs only the CLI tests that replay the named fixtures. With `--changed`, it takes the fixtures that differ from `origin/main`.

```bash
bin/cli-contract nodes/node-add/created
bin/cli-contract --changed
```

The change cycle is: change the Gateway, re-record the fixtures, review the fixture diff, run `bin/cli-contract --changed`, fix or accept each command's output, and update the expected files.

## Families with fixtures

These families have recorded fixtures and contract tests.

| Family | Fixtures |
| --- | --- |
| `node` | `node-list/default`, `node-show/default`, `node-add/created`, `node-add/tld-required`, `node-add/fingerprint-required` |

Add a family by writing its Gateway fixture test and its CLI contract test in the same change.
