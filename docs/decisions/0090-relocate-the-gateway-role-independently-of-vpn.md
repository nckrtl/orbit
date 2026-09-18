---
title: "ADR 0090: Relocate the gateway role independently of vpn"
sidebarTitle: "0090 Relocate the gateway role independently of vpn"
description: "Proposed. The gateway role is a singleton that operators can move to another Node. vpn stays immutable. A dedicated relocate command keeps one active gateway assignment for the whole move."
---

# ADR 0090: Relocate the gateway role independently of vpn

The `gateway` role becomes singleton and mutable so DevOps can place the Laravel/FPM control plane on a different Node than `vpn`. Bare remove-then-add is not the supported product path: removing the last gateway assignment drops implicit Gateway authority and omits `gateway.orbit` from private DNS. `orbit node:role:relocate <node> gateway` transfers the one assignment in a single request. `vpn` stays immutable.

## Status

Proposed.

This amends the lifecycle policy in `App\Domain\Nodes\RoleRegistry` for `gateway` only. It does not change [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) or the VPN baseline.

## Context

Bootstrap assigns `gateway` and `vpn` to the same Node. `RoleRegistry` marked both `mutable: false`, so `node:role:remove` and `node:role:add` refused them. After `websocket` became a mutable singleton, operators moved that role with add and remove because the Gateway process stays up.

Live intent is different. Operators want `vpn` and private DNS to stay on the current Node (`gateway` at `10.44.0.1`) and to run the Laravel/FPM control plane on a new VPS. `websocket` relocate cannot be copied blindly:

- `GatewayRoleBaseline` only opened the `orbit:gateway-https` firewall and refused removal.
- `NativeGatewayWebConverger` writes Caddy, PHP-FPM, certificates, and checkout access on the machine that is already running the Gateway process. It is not a remote installer.
- Private DNS answers `gateway.orbit` from the Node that holds an active `gateway` role.
- `NodeAccessAuthorizer` grants fleet-wide implicit authority to the Node that holds that active role. `ServingNodeResolver` also requires exactly one active Gateway for several families.
- After a last-gateway remove, the next `node:role:add` is refused as `node_access.required`, and `gateway.orbit` disappears from the published catalog.

Mutability alone therefore opens a hole: the assignment can move, but the request that would add it again cannot run, and clients that use the hostname lose the control plane. A dedicated relocate keeps one active assignment for the whole operation.

The serving checkout, SQLite database, Orbit CA, and Gateway SSH keys live on the machine that runs the Gateway PHP process. Relocate does not rsync those secrets. The operator copies them, or accepts that `gateway.orbit` will point at a Node that does not yet serve `/up` until that work is done. The old Caddy and PHP-FPM stay running so a CLI profile that still uses the old WireGuard address keeps working.

## Decision

- Mark `RoleName::Gateway` `mutable: true` and keep it a singleton that may be assigned during provisioning. Keep `RoleName::Vpn` `mutable: false`.
- Add `POST /api/v1/nodes/{node}/roles/{role}/relocate` (`node:role:relocate`). Only `gateway` is accepted. The request requires `--force` consent. Authorization uses the current active Gateway Node (`ServingNode::Gateway`).
- Relocate preflights the target (active Linux Node, no role conflicts, not the current holder), opens `orbit:gateway-https` on the target, transfers the existing `node_roles` row to that Node inside the singleton claim lock, republishes private DNS, and retracts the gateway HTTPS firewall on the source. It does not stop Caddy, PHP-FPM, the serving checkout, SQLite, the Orbit CA, or VPN on the source.
- `GatewayRoleBaseline.remove` and `removeUnreachable` stop refusing. Reachable remove retracts the role-owned firewall and republishes DNS. Unreachable remove republishes DNS only. Converge opens the firewall and republishes DNS.
- Record `gateway.serving_node_id` during bootstrap. `NodeAccessAuthorizer` treats that Node as implicit control-plane authority even when the `gateway` role is briefly unassigned, so a documented remove-then-add fallback can still call the API. Relocate does not change the serving-host setting; the process still runs on the source until the operator moves it.
- Generic `node:role:add` and `node:role:remove` for `gateway` follow the same mutable singleton rules as `websocket`. Relocate is the supported path. Remove-then-add has a window with no `gateway.orbit` record; clients that still use the stored WireGuard address reach the leftover serving stack.
- MCP role enums include `websocket` next to the other roles so Ops can assign that role without the PHP CLI.

## Rejected alternatives

- Make `gateway` mutable and tell operators to remove then add, like `websocket`: rejected because the Gateway process is the API. Removing the last assignment drops implicit authority and private DNS for `gateway.orbit`.
- Make `vpn` mutable so both roles can move: rejected. Ops wants private DNS and the WireGuard server to stay on the original Node. The VPN baseline still refuses removal.
- Rsync the serving checkout, SQLite, CA, and SSH keys inside relocate: rejected as a secret-moving installer the current Gateway web converger does not own. Relocate moves the assignment, firewall, and DNS. The operator copies process state, then converges web on the new host.
- Allow two active `gateway` assignments during a cutover: rejected. Singleton semantics still matter: one control-plane authority, one `gateway.orbit` address.

## Consequences

- DevOps that wants the old Node named `vpn` and the new VPS named `gateway` must rename first. `orbit node:rename <old-node> vpn` runs while the new VPS is still unregistered. Then `orbit node:add gateway` and `orbit node:role:relocate gateway gateway --force`. [ADR 0091](/decisions/0091-rename-a-node-without-changing-wireguard-identity) owns the rename.
- `orbit node:role:relocate <new-node> gateway --force` is the supported live cutover command after the operator has provisioned the target Node.
- A brief DNS window exists only on the documented two-step fallback, not on relocate.
- Leftover Caddy and PHP-FPM on the source are operator cleanup after `/up` answers on the new Node.
- CLI profiles that store `https://10.44.0.1` must be pointed at the new WireGuard address after the serving stack moves.
- The MCP catalogue must be regenerated when role enums change.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs, apps/e2e
- ADRs: amends the `gateway` lifecycle in `RoleRegistry`; leaves [ADR 0061](/decisions/0061-use-vpn-dns-by-default-on-managed-peers) and the VPN baseline unchanged
- Detail: [`node`](/cli/node), [Relocate the gateway role](/solutions/relocate-gateway-role), [Private DNS](/reference/private-dns), [CLI command vocabulary](/reference/cli-command-vocabulary), [ADR 0091](/decisions/0091-rename-a-node-without-changing-wireguard-identity)
- Verify: Gateway RoleRegistry, GatewayRoleBaseline, RelocateGatewayRoleAction, NodeAccessAuthorizer, node-role API, MCP catalogue, CLI `node:role:relocate`, CLI `node:rename`, and PHP SDK transport tests
