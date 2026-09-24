---
title: "ADR 0091: Rename a Node without changing WireGuard identity"
sidebarTitle: "0091 Rename a Node without changing WireGuard identity"
description: "Proposed. A Node name is a unique registry identifier. Operators can change it with node:rename. WireGuard keys, addresses, and role rows stay on the same Node."
---

# ADR 0091: Rename a Node without changing WireGuard identity

`orbit node:rename <node> <name>` changes the unique registry name on an existing Node. The name is not WireGuard peer material. Keys, addresses, SSH identity, and role assignments stay put. The command refuses when the Node still owns Herdr sessions, because observer hostnames embed the Node name.

## Status

Proposed. Amended by [ADR 0145](/decisions/0145-retire-the-herdr-integration): Orbit has no Herdr sessions, so a rename no longer refuses with `node.has_herdr_sessions`.

This extends the Node registry contract used by [ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine) and the family-specific action list in [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk). It does not change [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) or [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn).

## Context

DevOps needs the current Node named `gateway` (id 1, `10.44.0.1`) to become `vpn` so a new VPS can register as `gateway` and receive the Laravel/FPM role. `nodes.name` is unique. `node:add` looks up an existing record by that name. There is no name-update path today, so registering the new VPS as `gateway` while the old Node still holds the name collides.

The name is a registry identifier used for CLI resolution, `node:add` and retarget lookup, the provisioning lock key `orbit:node-provision:{name}`, and Herdr observer hostnames of the form `{session}.herdr.{name}.{tld}`. WireGuard comments may include the name, but peer identity is the public key and address. Private DNS platform names such as `gateway.orbit` follow the active `gateway` role, not the Node name.

A rename that rewrote Herdr observer hostnames would republish Caddy sites and private DNS. That is a remote publication, not a registry write. The first supported path refuses while any Herdr session exists so operators destroy those sessions, rename, then recreate them under the new hostname.

## Decision

- Add `PATCH /api/v1/nodes/{node}/name` (`node:rename`). Authorization uses the target Node (`ServingNode::Target`).
- Accept the same name grammar as `node:add`: ASCII `alpha_dash`, maximum 63 characters, unique among Nodes. A request that repeats the current name succeeds and writes nothing.
- Hold the provisioning lock for the current name, then for the new name, so a concurrent `node:add` or retarget cannot claim the destination name and a concurrent lifecycle operation cannot use the old name mid-rename.
- Refuse with `node.has_herdr_sessions` when the Node still owns a Herdr session. Do not rewrite stored `observer_hostname` values.
- Leave WireGuard keys, addresses, SSH identity, role rows, and private DNS catalog records unchanged. Stale WireGuard comments update on the next peer converge.
- Treat `rename` as a family-specific `node` action. Do not use `update` for this command: `update` means a partial field patch, and the only field this command writes is the registry name.

## Rejected alternatives

- Tell operators to remove the old Node and add it again under a new name: rejected. Removal deletes the registry row and WireGuard peer. DevOps needs the same Node to keep `vpn` and private DNS.
- Register the new VPS under a temporary name, then swap: rejected. Two unique names still cannot both be `gateway`. Rename-first while the new VPS is unregistered is the supported order.
- Republish Herdr observers during rename: rejected for this first version. Publication is a remote Caddy and DNS change. Destroy, rename, then recreate keeps the hostname contract explicit.
- Use `node:update` as the verb: rejected. The command changes one identity field and belongs with the other family-specific Node actions.

## Consequences

- Operators rename the current `gateway` Node to `vpn` before they register a new Node named `gateway`.
- After rename, CLI and MCP callers that used the old name must use the new name or the numeric id.
- A Node with Herdr sessions cannot rename until those sessions are gone.
- The MCP catalogue must be regenerated when the route is added.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs, apps/e2e
- ADRs: extends [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) and [ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine); used with [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn)
- Detail: [`node`](/cli/node), [Relocate the gateway role](/solutions/relocate-gateway-role), [CLI command vocabulary](/reference/cli-command-vocabulary)
- Verify: Gateway RenameNodeAction, node-rename API, CLI `node:rename`, PHP SDK transport, and MCP catalogue tests
