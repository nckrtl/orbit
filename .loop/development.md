# ORB-273 development record

Flow: discovery
Incus: not required
Candidate: `b9c389edbd4d13d22100c8aa0015378f5eaed26c`

## Change

`AppInstanceDeploymentLayoutRequest` now forces Saloon JSON flags
`JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT`, matching `EnvironmentRequest` and
`DeploymentStreamRequest`. Omitted `--sqlite-source-path` still omits the field
in `body()->all()` and encodes `{}` on the wire.

## Acceptance checks

1. Omitted sqlite path encodes `{}`.
   - `packages/php-sdk` `AppInstanceDeploymentLayoutRequestTest`:
     `encodes omitted sqlite path as an empty JSON object`
     passed, 6 tests / 26 assertions for the file.
   - `apps/cli` `PrepareDeploymentCommandTest`:
     `sends one typed HTTP request and omits an unspecified SQLite source path`
     now asserts `(string) body() === '{}'` and the PSR body is `{}`.
     File passed, 7 tests / 29 assertions.

2. Omission stays distinct from every supplied string.
   - SDK `preserves omission separately from every supplied string`
     still asserts empty `body()->all()` and now also `{}` encoding.

3. Gateway rejects a JSON array before conversion.
   - `apps/gateway` `AppInstanceDeploymentLayoutTest`:
     `rejects unknown malformed and wrongly typed input before conversion`
     includes dataset `array => '[]'`.
     File passed, 11 tests / 66 assertions.

## Project checks

- `packages/php-sdk` `composer check`: passed (guidance, rector, pint, phpstan)
- `apps/cli` `composer check`: passed
- `apps/gateway` `composer check`: passed

## Builder gate

Passed for exact candidate `b9c389edbd4d13d22100c8aa0015378f5eaed26c`.

Receipt: `.git/orbit-checks/b9c389edbd4d13d22100c8aa0015378f5eaed26c/review-33nszqxc/result.json`

Builder `test:affected` reported no further affected tests after the focused
acceptance runs above. TIA graphs in this worktree are local and not a
published main baseline.

## Documentation audit

Scope: ORB-273 pages `docs/reference/deployments.md` and
`docs/domains/applications.md`.

Fixed: none

Reported: none

`docs/reference/deployments.md` already states optional `--sqlite-source-path`
and `POST /api/v1/instances/{instance}/deployment-layout`. It does not document
JSON body encoding.

## Limits

Discovery development only; isolated acceptance proof not run.
No live Gateway fleet call was made; transport is covered by Saloon fakes and
the Gateway API feature test for `[]`.
