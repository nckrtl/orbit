# Prove the verify-only Doctor on an Incus topology

## Problem

NCK-58 had to prove Doctor (ADR 0004) on the `gateway_app-dev_app-prod`
topology: a healthy baseline on all three nodes, one declared drift, one
declared unverifiable condition, no Doctor write path, exit codes, HTTP 200
reports, and redaction. The first `orbit doctor --json` on a fresh discovery
topology was not healthy on any node.

## Cause

Three separate causes, found by comparing each issue with the live files:

- `node.ssh_unreachable` on the gateway: `orbit:bootstrap` registered the
  gateway as a node with `user=orbit` and its WireGuard address, but never
  authorized its own managed key on that account or pinned its own host key.
  Doctor uses the fixed SSH boundary for every node, so the gateway could not
  observe itself. Fixed in `BootstrapGatewayAction` through the
  `GatewaySelfAccessConverger` seam.
- `role.firewall_projection_mismatch` on the gateway: the shared
  `NodeFirewallRuleCatalog` declared `orbit:gateway-https` with the WireGuard
  address as destination, while the writer (`NativeGatewayVpnConverger`) has
  always applied `to any` on interface `orbit` (v4 and v6). The catalog now
  matches the writer.
- `*.php_fpm_projection_mismatch` on both app nodes and
  `instance.caddy_projection_mismatch` on app-prod: harness state, not
  product state. The rolling standby refresh restores old snapshots and skips
  provisioning, so pool files written by an older renderer survive a renderer
  change. The app-prod internal-TLS fixture also replaces the product-managed
  `/etc/caddy/Caddyfile` symlink with a wrapper, which breaks Doctor's fragment
  lookup and makes the product's own Caddy publish fail validation.

## Solution

- Product defects got focused RED-to-GREEN fixes in `apps/gateway` with tests.
- Harness state is corrected by declared proof setup in
  `apps/e2e/resources/proof/NCK-58/plan.json`: restore the product symlink and
  place the e2e `local_certs` global block as
  `fragments/00-orbit-e2e-global.caddy` inside the managed version (the product
  publisher copies unmanaged fragments forward), then re-project every instance
  with `orbit instance:php <id> <current version>`.
- Drift fixture: change `pm.max_children` inside the `[orbit-instance-1]` pool
  block on app-dev. Doctor reports exactly one
  `instance.php_fpm_projection_mismatch`. A second `sed` restores it.
- Unverifiable fixture: a sudoers drop-in on app-prod that keeps `NOPASSWD:ALL`
  but denies `/usr/sbin/ufw`. Only the role inspector uses `sudo ufw`, so
  Doctor reports exactly one `role.inspection_failed`. Removing the file
  restores it.
- Mutation scan: inventory of the Orbit home, row counts per Gateway table,
  and service states before and after Doctor requests. Only `activity_log`
  grows, by one request-audit row per request. Exclude the SQLite `-wal` and
  `-shm` sidecars from the inventory: SQLite creates and removes them for any
  connection, including a read-only one, so they appear and disappear between
  two snapshots without any Doctor write.
- Self-checking actions live in `apps/e2e/resources/proof/NCK-58/doctor-proof.sh`.
  It runs from the candidate checkout on the checkout roles, so the closed
  guest-script inventory in `WorktreeSynchronizer` stays unchanged. Actions
  on app-prod, which has no checkout, are short `sudo bash -c` argv strings.

## Limits

- The re-projection setup is a no-op once the promoted standby is rebuilt
  cold. Until the harness re-projects on refresh, keep it.
- Denying one sudo command works because the sudoers drop-in sorts last and
  the last match wins. Denying `bash` instead would also break the instance
  and workspace inspectors.
- Setup actions run before every acceptance action, so the healthy baseline
  report is recorded in the setup section of the proof record.

## Verification

`bin/e2e-topology prove NCK-58 <worktree> --candidate-sha=<sha> --proof-plan-file=apps/e2e/resources/proof/NCK-58/plan.json --json`
records `proved` with every setup and acceptance action at exit 0.
