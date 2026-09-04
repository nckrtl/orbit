# Prove the verify-only Doctor on an Incus topology

## Problem

A proof of the verify-only Doctor runs on the `gateway_app-dev_app-prod` proof topology. It needs a healthy baseline on all three Nodes, exactly one declared drift, exactly one declared unverifiable condition, and evidence that Doctor writes nothing. Each fixture must produce one finding and leave every other inspection untouched.

## Cause

Doctor reports one finding per inspector that fails, so a fixture must break exactly one inspector. The Instance and Workspace inspectors run `sudo bash`, and the role inspector is the only one that runs `sudo ufw`. A file inventory of the Gateway home also sees SQLite's `-wal` and `-shm` sidecars appear and disappear. SQLite creates and removes them for any connection, including a read-only one.

## Solution

Four fixture patterns give a Doctor proof its baseline, its drift, its unverifiable condition, and its evidence that Doctor writes nothing. The self-checking actions live beside the plan under `.loop/proof/` as proof fixtures and run from the candidate checkout on the Nodes that have one. [ADR 0022](../decisions/0022-track-the-issue-workspace-and-delete-it-before-merge.md) governs that issue workspace. An action on `app-prod`, which has no checkout, is a short `sudo bash -c` argv string.

### Baseline

The `converge` phase of `prove` completes before the first setup action runs. A setup action therefore records the Doctor report on every Node as the healthy baseline, without a projection step or a Caddy step of its own.

### Drift

Change `pm.max_children` inside the `[orbit-instance-1]` pool block of `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf` on `app-dev`. Doctor reports exactly one `instance.php_fpm_projection_mismatch`. A second `sed` restores the value, and the next report is clean.

### Unverifiable condition

Add a sudoers drop-in on `app-prod` that keeps `NOPASSWD:ALL` for the Orbit user and denies `/usr/sbin/ufw`. The role inspector is the only Doctor inspector that runs `sudo ufw`, so Doctor reports exactly one `role.inspection_failed`. Removing the file restores the baseline.

### Mutation scan

Inventory the Orbit home and record table row counts and service states before and after the Doctor requests. Only `activity_log` grows, by one audit row per request. Exclude the SQLite sidecars from the inventory.

## Limits

These fixtures depend on three properties of the harness and the Nodes.

- The baseline depends on the convergence sequence on [Topology snapshot](../reference/topology-snapshot.md#refresh), which `prove` runs before setup.
- Denying one sudo command works because sudoers applies the last matching entry, so the drop-in must sort after Orbit's grant in `/etc/sudoers.d`. Denying `bash` instead also breaks the Instance and Workspace inspectors.
- Setup actions run before every acceptance action, so the baseline report is recorded before any fixture is applied.

## Verification

`bin/e2e-topology prove <ISSUE>` records `proved` with every setup and acceptance action at exit `0`.
