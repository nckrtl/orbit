---
title: "ADR 0095: Relocate relocatable singleton roles as one Orbit action"
sidebarTitle: "0095 Relocate relocatable singleton roles as one Orbit action"
description: "Proposed. node:role:relocate moves relocatable singleton roles, starting with gateway, websocket, and metrics. Optional --from names leftover source state. Destination-already-has-role reconciles role-owned resources instead of no-op or overwrite."
---

# ADR 0095: Relocate relocatable singleton roles as one Orbit action

`orbit node:role:relocate` is the one Orbit action that moves a relocatable singleton role. The first relocatable roles are `gateway`, `websocket`, and `metrics`. Optional `--from` names leftover source state. When the destination already holds the role, relocate moves leftover role-owned resources instead of no-op or overwriting destination state.

## Status

Proposed.

This amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn)'s "only gateway is accepted" rule and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)'s add-then-remove move. It keeps the verb `relocate` from [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk). It does not change [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) or make `vpn` relocatable.

## Context

[ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn) added `POST /api/v1/nodes/{node}/roles/{role}/relocate` because remove-then-add is not a safe gateway cutover: the last gateway assignment owns implicit authority and `gateway.orbit`. The action and CLI still hardcoded `gateway`. `websocket` and `metrics` are also mutable singletons. Their node-scoped credentials, publication, and runtime live on the holder. Operators were told to add the role on the new Node and remove it from the old one. That two-step theater cannot work while the singleton assignment still exists, and a remove-first path drops the generated Reverb or Grafana identity.

The Gateway process is special: relocate must not rsync the serving checkout or stop leftover Caddy and PHP-FPM. Websocket and metrics can converge the destination baseline and retract the source host projection in the same request. Credentials are stored on the Node, so a row transfer without a settings move would generate a new identity on the destination and break clients.

Ops also hits a leftover state: the destination already holds the assignment after a botched add or remove, and the old Node still has checkout, Caddy, or credentials. Refusing "already assigned" leaves that residue. Silently succeeding leaves it too. Overwriting destination credentials corrupts a working identity.

## Decision

- Declare `relocatable` on each `RoleDefinition`. `gateway`, `websocket`, and `metrics` are relocatable. `vpn` and every non-singleton role are not. Relocate refuses a role that is not relocatable.
- Keep one generalized `RelocateNodeRoleAction`. Do not copy a per-role relocate action. Role-specific work is a hook after the shared claim lock, source resolution, and assignment transfer.
- Keep `orbit node:role:relocate <node> <role> [--from] [--force]`. The verb stays `relocate`. Authorization stays the current active Gateway Node. `--force` stays required.
- `--from` is optional. When omitted, the source is the current singleton holder. When the destination does not hold the role, `--from` must name that holder. When the destination already holds the role, `--from` names the leftover source Node.
- When the destination does not hold the role, relocate preflights the target, copies missing role-owned settings from the source, transfers the existing `node_roles` row inside the singleton claim lock, converges destination role-owned resources, and retracts source leftovers. It does not purge destination data.
- When the destination already holds the role and `--from` names a different Node, relocate keeps the destination assignment, copies missing role-owned settings from `--from`, converges the destination, and retracts leftovers on `--from`. When the destination already holds the role and `--from` is omitted, refuse "already assigned".
- Destination settings win. Relocate never overwrites a non-empty destination credential or setting with the source value.
- `gateway` still opens `orbit:gateway-https` on the target, grants access to the `vpn` and `metrics` nodes, republishes private DNS, and retracts that firewall on the source. It still does not stop Caddy, PHP-FPM, or copy the serving checkout. [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate) still owns the DNS target and the granted access set.
- `websocket` copies the Reverb identity onto the destination when the destination is missing it, converges the destination baseline, and retracts the source publication, runtime, and leftover settings. It does not purge the Reverb checkout (`purge-data` stays a remove flag).
- `metrics` copies Grafana passwords onto the destination when the destination is missing them, converges the destination baseline, and retracts the source publication, exporters, cAdvisor, runtime, and leftover settings. It does not purge Prometheus or Grafana volumes.
- MCP `node-role-relocate` and the PHP SDK expose the same role set and optional `from` node ID.

## Rejected alternatives

- Keep relocate gateway-only and tell operators to add then remove websocket or metrics: rejected because those roles are singletons. Add refuses while the old assignment exists, and remove-first drops the generated identity and private hostname.
- Copy `RelocateGatewayRoleAction` per role: rejected because source resolution, the claim lock, consent, and leftover reconcile are shared. Role-specific work belongs in hooks.
- Infer relocatable from `singleton && mutable`: rejected so `RoleRegistry` states the product set explicitly. Mutability already means generic add and remove; relocate is a narrower contract.
- Rsync Gateway or Metrics process state inside relocate: rejected. Gateway relocate still moves assignment, firewall, access, and DNS. Websocket and metrics converge a new runtime on the destination.
- Overwrite destination credentials from the source: rejected because a destination that already has a working identity must keep it.
- Treat destination-already-has-role as success with no work: rejected because leftover source state would remain.

## Consequences

- `orbit node:role:relocate services websocket --force` is the supported websocket cutover. Ops can verify beast→services after CLEAN.
- `orbit node:role:relocate <node> metrics --force` is the supported Metrics cutover.
- `orbit node:role:relocate <node> websocket --from beast --force` reconciles leftovers on beast when services already holds `websocket`.
- Remove-then-add remains a fallback with a window and a new identity. Relocate is the supported path.
- Later relocatable roles add a `RoleDefinition` flag and a hook, not a new command.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: amends [ADR 0090](/decisions/0090-relocate-the-gateway-role-independently-of-vpn) and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role); keeps the [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) verb
- Detail: [`node`](/cli/node), [Realtime events with Reverb](/solutions/realtime-reverb), [Metrics](/reference/metrics), [CLI command vocabulary](/reference/cli-command-vocabulary), [Relocate the gateway role](/solutions/relocate-gateway-role)
- Verify: Gateway RoleRegistry, RelocateNodeRoleAction, node-role API, MCP catalogue, CLI `node:role:relocate`, and PHP SDK transport tests
