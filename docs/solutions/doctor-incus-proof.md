# Prove the verify-only Doctor on an Incus topology

## Problem

A Doctor proof on the `gateway_app-dev_app-prod` topology needs a healthy baseline on all three Nodes. It then needs exactly one declared drift, exactly one declared unverifiable condition, and evidence that Doctor writes nothing. It also records exit codes, HTTP reports, and redaction. A fresh discovery topology does not start healthy, and each fixture must produce one finding without touching anything else.

## Cause

A refreshed topology snapshot restores files an older renderer wrote and skips provisioning. Pool files and Caddy projections therefore drift from the current renderers until the harness re-projects every role and instance. The [topology snapshot page](../reference/topology-snapshot.md) states that rule. A mutation scan that inventories the Gateway home also sees SQLite's `-wal` and `-shm` sidecars appear and disappear. SQLite creates and removes them for any connection, including a read-only one.

## Solution

Three fixture patterns give a Doctor proof its baseline, its drift, and its unverifiable condition. One scan proves that Doctor writes nothing.

- **Baseline:** the plan's setup runs the harness `reproject` action. The current renderers then project every role and instance before the healthy baseline report is recorded.
- **Caddy:** the plan needs no Caddy setup action. The harness places the e2e `local_certs` block as an unmanaged fragment, and the product publisher copies unmanaged fragments forward.
- **Drift:** change `pm.max_children` inside the `[orbit-instance-1]` pool block on `app-dev`. Doctor reports exactly one `instance.php_fpm_projection_mismatch`. A second `sed` restores the value.
- **Unverifiable condition:** add a sudoers drop-in on `app-prod` that keeps `NOPASSWD:ALL` and denies `/usr/sbin/ufw`. Only the role inspector uses `sudo ufw`, so Doctor reports exactly one `role.inspection_failed`. Removing the file restores the baseline.
- **Mutation scan:** inventory the Orbit home and record table row counts and service states before and after the Doctor requests. Only `activity_log` grows, by one audit row per request. Exclude the SQLite sidecars from the inventory.

The self-checking actions live in `proofs/NCK-58/doctor-proof.sh`, the fixture directory of the plan `proofs/NCK-58.json`. They run from the candidate checkout on the Nodes that have one, so the closed inventory of guest scripts in the harness stays unchanged. Actions on `app-prod`, which has no checkout, are short `sudo bash -c` argv strings.

## Limits

These fixtures depend on three properties of the harness and the Nodes.

- The re-projection setup is a no-op on a topology snapshot that was rebuilt cold; keep it in the plan because a refreshed snapshot needs it.
- Denying one sudo command works because the sudoers drop-in sorts last and the last match wins. Denying `bash` instead would also break the instance and workspace inspectors.
- Setup actions run before every acceptance action, so the healthy baseline report is recorded in the setup section of the proof record.

## Verification

`bin/e2e-topology prove NCK-58 --plan=proofs/NCK-58.json --json` records `proved` with every setup and acceptance action at exit 0.
