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
  product state. The rolling topology snapshot refresh restores old snapshots
  and skips provisioning, so pool files written by an older renderer survive a
  renderer change. The app-prod internal-TLS fixture also replaced the
  product-managed `/etc/caddy/Caddyfile` symlink with a wrapper, which broke
  Doctor's fragment lookup and made the product's own Caddy publish fail
  validation (fixed in the fixture by NCK-84).

## Solution

- Product defects got focused RED-to-GREEN fixes in `apps/gateway` with tests.
- Harness state is corrected in convergence: `converge-sample-app.sh
  internal-tls` places the e2e `local_certs` global block as
  `fragments/00-orbit-e2e-global.caddy` inside the managed version (the product
  publisher copies unmanaged fragments forward), and `reproject` re-projects
  every role and instance. The NCK-58 proof plan needs no Caddy setup action.
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
- Self-checking actions lived in `proofs/NCK-58/doctor-proof.sh` (the fixture
  directory of the proof plan `proofs/NCK-58.json`).
  It runs from the candidate checkout on the checkout roles, so the closed
  guest-script inventory in `WorktreeSynchronizer` stays unchanged. Actions
  on app-prod, which has no checkout, are short `sudo bash -c` argv strings.

## Limits

- The re-projection setup is a no-op once the promoted topology snapshot is
  rebuilt cold. Until the harness re-projects on refresh, keep it.
- Denying one sudo command works because the sudoers drop-in sorts last and
  the last match wins. Denying `bash` instead would also break the instance
  and workspace inspectors.
- Setup actions run before every acceptance action, so the healthy baseline
  report is recorded in the setup section of the proof record.

## Verification

`bin/e2e-topology prove NCK-58 --plan=proofs/NCK-58.json --json`
records `proved` with every setup and acceptance action at exit 0.
