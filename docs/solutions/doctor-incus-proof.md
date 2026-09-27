---
title: "Prove the verify-only Doctor on an Incus topology"
description: "A repeatable way to prove that Doctor reports drift without repairing it, on disposable machines."
covers:
  - apps/gateway/app/Infrastructure/Doctor/**
  - apps/e2e/app/E2E/TopologyProofRunner.php
---

# Prove the verify-only Doctor on an Incus topology

## Problem

A proof of the verify-only Doctor runs on the `gateway_app-dev_app-prod` proof topology. It needs a healthy baseline on all three Nodes, exactly one declared drift, exactly one declared unverifiable condition, and evidence that Doctor writes nothing. Each fixture must produce one finding and leave every other inspection untouched.

## Cause

Doctor reports one finding per inspector that fails, so a fixture must break exactly one inspector. The production Instance inspector runs `sudo bash`. The role inspector runs `sudo ufw`. The firewall inspector also runs it when the selected Node has a persisted or synthetic firewall target. The public Route edge inspector, in the `instance` family, runs `sudo ufw status numbered` on a Node that serves a public Route edge.

A file inventory of the Gateway home also sees changes that are not Doctor writes. SQLite creates and removes its `-wal` and `-shm` sidecars for any connection, including a read-only one. The Caddy build check creates a lock file under the Orbit home's `locks/caddy-build/` directory, and the file stays.

## Solution

Four fixture patterns give a Doctor proof its baseline, its drift, its unverifiable condition, and its evidence that Doctor writes nothing. The self-checking actions live beside the plan under `.loop/proof/` as proof fixtures. They run from the candidate checkout on the Nodes that have one. An action on `app-prod`, which has no checkout, is a short `sudo bash -c` argv string.

### Baseline

The `converge` phase of `prove` completes before the first setup action runs. A setup action therefore records the Doctor report on every Node as the healthy baseline, without a projection step or a Caddy step of its own.

### Drift

Doctor checks the PHP-FPM projection only for production Instances. It compares each generated file byte for byte with the expected content. So pick one production Instance on `app-prod`, and edit its pool file `/etc/orbit/php-fpm/<user>/generated/pool.conf`. Change the line `listen.mode = 0660` to `listen.mode = 0666`. Doctor reports exactly one `instance.php_fpm_projection_mismatch`. A second `sed` restores `0660`, and the next report is clean.

Do not edit `pm.max_children`. That line is in `local.conf`, and Doctor checks only the structure of `local.conf`, not its values. The running master reads the pool file only at its next reload, so the edit changes no running worker.

### Unverifiable condition

Add a sudoers drop-in on `app-prod` that keeps `NOPASSWD:ALL` for the Orbit user and denies `/usr/sbin/ufw`. Run `orbit doctor --node=<node-id> --family=role` to produce exactly one `role.inspection_failed`. A full-family request isolates the same failure only when the selected Node has no persisted firewall rules, no synthetic Metrics firewall targets, and no public Route edge. Removing the file restores the baseline.

### Mutation scan

Inventory the Orbit home and record table row counts and service states before and after the Doctor requests. Only `activity_log` grows, by one audit row per request. Exclude the SQLite sidecars and the Caddy build lock files from the inventory.

## Limits

These fixtures depend on these properties of the harness and the Nodes.

- The baseline depends on the convergence sequence on [Topology snapshot](/reference/topology-snapshot#refresh), which `prove` runs before setup.
- Denying one sudo command works because sudoers applies the last matching entry, so the drop-in must sort after Orbit's grant in `/etc/sudoers.d`.
- Denying `bash` breaks more than one inspector. The production Instance, private Route, public Route edge, custom proxy Route, Schedule, and Caddy build inspectors all run `sudo bash`.
- Setup actions run before every acceptance action, so the baseline report is recorded before any fixture is applied.

## Verification

`bin/e2e-topology prove <ISSUE>` records `proved` with every setup and acceptance action at exit `0`.
