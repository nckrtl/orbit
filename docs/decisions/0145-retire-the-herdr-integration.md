---
title: "ADR 0145: Retire the Herdr integration"
sidebarTitle: "0145 Retire the Herdr integration"
description: "Proposed. Orbit removes Herdr sessions, observers, observation grants, the JWKS endpoint, the herdr Doctor family, and the herdr CLI extension. A migration drops the Herdr tables. Amends ADR 0072, ADR 0091, and ADR 0141."
---

# ADR 0145: Retire the Herdr integration

Orbit no longer manages or observes Herdr sessions. The Gateway, CLI, PHP SDK, MCP tools, web API types, and documentation lose every Herdr feature, and a migration drops the Herdr tables. Features that Herdr shared with the rest of Orbit stay.

## Status

Proposed.

## Context

Orbit ran a named headless Herdr server on a Node as a Node Process, or adopted one that ran under an external service. It published a private receive-only observer for each session through Caddy, private DNS, and an Orbit certificate, and it signed short-lived observation grants for Commander. The Gateway served the signing keys at `/.well-known/jwks.json`. Doctor had a `herdr` family, and `node:remove` and `node:rename` refused a Node that still owned a Herdr session. The CLI showed the `herdr:*` commands only when the local `herdr` extension was enabled.

The maintainer no longer uses Herdr. Every Herdr session on the live Gateway was destroyed through Orbit, the observer Caddy sites are gone from their Node, and the Herdr services on that Node are disabled. The code still costs maintenance. It adds a site source to the Node Caddy build in [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway), a Doctor family, two Node lifecycle guards, and a public endpoint. The live database still holds disposable observation nonces and the grant signing key.

## Decision

Orbit removes the Herdr integration completely.

- The Gateway removes the `herdr/sessions` routes, the observation grant route, the `/.well-known/jwks.json` route, the Herdr actions, models, inspector, observer publishers, the observer service script, and the grant signer.
- Doctor drops the `herdr` family and its codes. A request for `--family=herdr` fails validation like any unknown family.
- `node:remove` and `node:rename` drop the `node.has_herdr_sessions` guard. Offline removal forgets only Node-owned Processes.
- Private DNS publishes no Herdr observer host records. The Node Caddy build has no Herdr observer site source.
- A migration drops `herdr_sessions`, `herdr_observation_nonces`, and `jwks_keys`. It uses `dropIfExists`, so it succeeds when rows remain and when a table is already gone. It has no `down()`, like the other table drops. It changes nothing on a Node, and it keeps every Process row, including one that ran a Herdr server.
- The CLI removes the `herdr:*` commands and the `herdr` extension slug. The PHP SDK removes the Herdr requests, responses, and the `herdr` Doctor family. The MCP manifest and the web API types are regenerated without the Herdr operations.
- Shared features stay: Node Processes from [ADR 0069](/decisions/0069-allow-node-process-targets), the Homebrew Tool manager from [ADR 0043](/decisions/0043-manage-homebrew-core-formulae), the local extension mechanism that `proxycli` uses, the shared Caddy lock, the realtime websocket role, and the SSH executors. The Gateway site still sends `/.well-known/*` to Laravel as [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin) decides, and Laravel now answers those paths with 404.

This decision amends three earlier ones:

- [ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine): Node removal no longer refuses, retracts, or forgets Herdr sessions.
- [ADR 0091](/decisions/0091-rename-a-node-without-changing-wireguard-identity): a rename no longer refuses a Node because of Herdr sessions.
- [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway): Herdr observers leave the site source list, the shared listener rule, the certificate and non-Caddy steps, and the Caddy installation rule.

## Rejected alternatives

- Keep the code and hide it behind the disabled CLI extension: rejected because the Gateway routes, the public signing key endpoint, the Doctor family, and the Caddy site source stay live for every caller, and each change to Processes, Caddy, or Node lifecycle must keep them working.
- Keep the tables and drop only the code: rejected because no code would read them, and `jwks_keys` keeps private signing key material that nothing needs.
- Destroy remaining Herdr sessions and their Processes in the migration: rejected because a migration must not change a Node, and the live Gateway has no sessions left. A Process row that remains stays an ordinary Node Process that `process:destroy` removes.
- Deprecate the commands for one release before removal: rejected because Orbit has one operator and no external Herdr client.

## Consequences

- The Gateway API, MCP tools, CLI, and SDK have fewer operations. The SDK models 162 operations instead of 169.
- A Node that still ran a Herdr observer unit, certificate, or Caddy site keeps those files until an operator removes them. The next Node Caddy build drops the site.
- An operator machine whose `extensions.json` still lists `herdr` fails with `extension.config_invalid`, because the CLI refuses unknown extensions. The operator removes `herdr` from that file or deletes the file, then enables `proxycli` again when needed.
- A `herdr` Tool installed through Homebrew stays an ordinary Tool. `tool:remove` removes it.
- The migration cannot be reversed. Herdr session rows, nonces, and the signing key are gone.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: amends [ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine), [ADR 0091](/decisions/0091-rename-a-node-without-changing-wireguard-identity), and [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway)
- Detail: [`doctor`](/cli/doctor), [`extension`](/cli/extension), [Node provisioning](/reference/node-provisioning), [Caddy configuration](/reference/caddy-configuration)
- Verify: `apps/gateway` Pest tests for the table-drop migration, the Doctor family list, Node removal and rename, the HTTP route surface, and the Node Caddy build; `apps/cli` command-surface and extension tests; `packages/php-sdk` guidance tests; `bin/mcp-tools --check`; an Incus run that migrates, reports a clean `orbit doctor`, and dry-runs `orbit:caddy-build` on every Node
