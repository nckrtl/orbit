---
title: "Prove the verify-only Doctor on an Incus topology"
description: "A repeatable way to prove that Doctor reports drift without repairing it, on disposable machines."
covers:
  - apps/gateway/app/Infrastructure/Doctor/**
  - apps/e2e/app/Console/Commands/Topology/{Acquire,Exec,Status,Release}Command.php
  - apps/e2e/app/E2E/EvidenceLog.php
---

# Prove the verify-only Doctor on an Incus topology

## Problem

A proof of the verify-only Doctor runs on a leased `gateway_app-dev_app-prod` topology. It needs a healthy baseline on all three Nodes, exactly one declared drift, exactly one declared unverifiable condition, and evidence that Doctor writes nothing. Each fixture must produce one finding and leave every other inspection untouched.

Informational tool discoveries may appear in a healthy baseline. They do not change health or exit status. Drift and unverifiable findings still fail the report. Incus proves Linux behavior. [macOS enrollment](/reference/node-provisioning#macos-nodes), native platform and home-volume observation, and tool operations need a separate task-owned fixture on a real Mac.

## Cause

Doctor reports one finding per inspector that fails, so a fixture must break exactly one inspector. The Node inspector also reads the free space and free inodes for root and the managed user's home filesystem, including on the Gateway. Low disk space can therefore spoil a baseline even when the intended fixture affects another family.

The production Instance inspector runs `sudo bash`. The role inspector runs `sudo ufw`. The firewall inspector also runs it when the selected Node has a persisted or synthetic firewall target. The public Route edge inspector, in the `instance` family, runs `sudo ufw status numbered` on a Node that serves a public Route edge. Doctor checks current projections only. The proof does not need to fixture local DNS snippet compatibility checks.

The public Route edge inspector checks the certificate actually served by the ingress against Mozilla roots, the Route hostname, and a 30-day expiry window. A certificate finding is distinct from the proof's declared drift and must be absent in the healthy baseline. A file inventory of the Gateway home also sees changes that are not Doctor writes. SQLite creates and removes its `-wal` and `-shm` sidecars for any connection, including a read-only one. The Caddy build check creates a lock file under the Orbit home's `locks/caddy-build/` directory, and the file stays.

## Solution

Lease a topology, apply four fixture patterns with `bin/e2e-topology exec`, and record each step with `--record=LABEL`. The [Incus topology registry](/reference/incus-topologies#commands) describes every command. The four patterns give the proof its baseline, its drift, its unverifiable condition, and its evidence that Doctor writes nothing.

### Lease the topology

Run `bin/e2e-topology acquire ISSUE .` from the worktree, where the branch name contains the issue. Run `bin/e2e-topology status ISSUE` to see the lease. In a task workspace clone, every command runs through the bridge worktree, and the evidence log is in the bridge.

Every `exec` below runs one argument vector as `orbit` on one Node. Run the Orbit CLI on `gateway`. An action on `app-prod`, which has no checkout, uses `sudo` or a short `sh -c` string. `exec` allows 60 seconds, so pass `--timeout=SECONDS` when a Doctor request needs more.

Each `--record` appends the command, its UTC start and end times, its exit code, and its redacted output to `<worktree>/.e2e/evidence.log`. `orbit doctor` exits `0` when the report is healthy and `1` when it has a finding. The log records both.

Find the numeric ID of `app-prod` and the production user of one production Instance on it, such as the sample Instance `e2e-prod`.

```bash
bin/e2e-topology exec ISSUE gateway --argv='["orbit","node:list","--json"]' --record="node list"
bin/e2e-topology exec ISSUE gateway --argv='["orbit","instance:list","--json"]' --record="instance list"
```

`instance:list --json` gives the `production_user` of each Instance. The steps below use `<node-id>` and `<user>` for these values.

### Baseline

A leased topology is a clone of the promoted snapshot generation, which the last snapshot refresh converged and verified. Record one Doctor request for all registered Nodes as the healthy baseline.

```bash
bin/e2e-topology exec ISSUE gateway --argv='["orbit","doctor","--json"]' --record="doctor baseline"
```

The entry shows exit `0` and no findings. When it shows `node.disk_low`, check the affected Node with `df --output=source,avail,size,iavail,itotal -k -- / "$HOME"` as its managed user. Doctor does not free space; remove only fixtures owned by this lease, or use a fresh topology if the low-space files are not yours. Do not use a role converge to try to clear disk usage. For projection drift, converge the affected role with `orbit node:role:add NODE ROLE --converge`, and record the baseline again before you apply a fixture.

An `active` systemd status alone does not prove that a Process is healthy. Doctor reports `process.crash_loop` for a Process desired running when it observes auto-restart or a growing restart count during its bounded inspection. If the baseline has this finding, record its active state, sub-state, and restart counts before changing a task-owned fixture. A stable count left by earlier restarts is not drift. The [Process runtime state](/reference/processes-and-schedules#process-runtime-state) reference owns these rules. Restore the fixture to healthy operation and record a clean baseline before the mutation scans; Doctor does not restart it for you.

### Mutation scan, before

Record the Orbit home inventory, the table row counts, and the service states after the baseline and before the first fixture. The inventory excludes the SQLite sidecars and the Caddy build lock files.

```bash
bin/e2e-topology exec ISSUE gateway --argv='["sh","-c","find /home/orbit/.orbit -type f ! -name \"*-wal\" ! -name \"*-shm\" ! -path \"*/locks/caddy-build/*\" -printf \"%s %T@ %p\\n\" | sort -k3"]' --record="home inventory before"
bin/e2e-topology exec ISSUE gateway --argv='["php","/home/orbit/orbit/apps/gateway/artisan","db:show","--counts"]' --record="row counts before"
bin/e2e-topology exec ISSUE app-prod --argv='["systemctl","list-units","--type=service","--all","--no-pager","--plain","--no-legend"]' --record="services app-prod before"
```

Record the same service list on `gateway` and `app-dev`, each with its own label.

For a repository-probe proof, use only a disposable development checkout owned by the lease. Save its Git configuration before planting a hook or custom filesystem monitor that writes a marker. Install the fixture before the mutation scan, then run Doctor's `project` and `instance` families and verify that the marker is absent. Restore the saved configuration and remove the planted files before releasing the topology.

Doctor's repository probes pass `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false`. They must ignore those planted callbacks without changing the stored configuration. A read-only Git verb alone is not evidence that a checkout-selected program cannot write files.

### Drift

Doctor checks the PHP-FPM projection only for production Instances. It compares each generated file byte for byte with the expected content. So edit the pool file `/etc/orbit/php-fpm/<user>/generated/pool.conf` of the production Instance on `app-prod`. Change the line `listen.mode = 0660` to `listen.mode = 0666`.

```bash
bin/e2e-topology exec ISSUE app-prod --argv='["sudo","grep","-n","^listen.mode","/etc/orbit/php-fpm/<user>/generated/pool.conf"]' --record="pool listen mode before"
bin/e2e-topology exec ISSUE app-prod --argv='["sudo","sed","-i","s/^listen.mode = 0660$/listen.mode = 0666/","/etc/orbit/php-fpm/<user>/generated/pool.conf"]' --record="apply pool drift"
bin/e2e-topology exec ISSUE gateway --argv='["orbit","doctor","--node=<node-id>","--json"]' --record="doctor app-prod drift"
```

Doctor reports exactly one `instance.php_fpm_projection_mismatch`. Restore `0660` with a second `sed`, and record the next report, which is clean.

```bash
bin/e2e-topology exec ISSUE app-prod --argv='["sudo","sed","-i","s/^listen.mode = 0666$/listen.mode = 0660/","/etc/orbit/php-fpm/<user>/generated/pool.conf"]' --record="restore pool drift"
bin/e2e-topology exec ISSUE gateway --argv='["orbit","doctor","--node=<node-id>","--json"]' --record="doctor app-prod drift restored"
```

Do not edit `pm.max_children`. That line is in `local.conf`, and Doctor checks only the structure of `local.conf`, not its values. The running master reads the pool file only at its next reload, so the edit changes no running worker.

### Unverifiable condition

Add a sudoers drop-in on `app-prod` that keeps `NOPASSWD: ALL` for the `orbit` user and denies `/usr/sbin/ufw`. The command checks the file with `visudo` before it installs it, because a broken drop-in stops every `sudo` call.

```bash
bin/e2e-topology exec ISSUE app-prod --argv='["sudo","sh","-c","echo \"orbit ALL=(ALL) NOPASSWD: ALL, !/usr/sbin/ufw\" > /tmp/zz-doctor-deny-ufw && visudo -cf /tmp/zz-doctor-deny-ufw && install -m 0440 -o root -g root /tmp/zz-doctor-deny-ufw /etc/sudoers.d/zz-doctor-deny-ufw"]' --record="deny ufw on app-prod"
bin/e2e-topology exec ISSUE gateway --argv='["orbit","doctor","--node=<node-id>","--family=role","--json"]' --record="doctor app-prod unverifiable"
```

The role request reports exactly one `role.inspection_failed`. A full-family request isolates the same failure only when the selected Node has no persisted firewall rules, no synthetic Metrics firewall targets, and no public Route edge. Remove the file and record the restored report.

```bash
bin/e2e-topology exec ISSUE app-prod --argv='["sudo","rm","/etc/sudoers.d/zz-doctor-deny-ufw"]' --record="allow ufw on app-prod"
bin/e2e-topology exec ISSUE gateway --argv='["orbit","doctor","--node=<node-id>","--family=role","--json"]' --record="doctor app-prod unverifiable restored"
```

### Mutation scan, after

Record the inventory, the row counts, and the three service lists again, with labels that end in `after`. Compare each pair of entries in the evidence log. Only `activity_log` grows, by one audit row per Doctor request between the two scans.

### Release

Copy the evidence log entries that you need, then run `bin/e2e-topology release ISSUE`.

## Limits

These fixtures depend on these properties of the harness and the Nodes.

- `acquire` does not converge the three standard Nodes. The baseline depends on the convergence that the last [snapshot refresh](/reference/topology-snapshot#refresh) ran.
- The Gateway runs Doctor from the mounted worktree. The Nodes keep the state that the promoted generation wrote. A branch that changes an expected projection shows drift in the baseline until you converge the role.
- A converge rewrites the files Orbit owns on the Node, including the pool file. Converge before the first scan, never during a fixture.
- Denying one sudo command works because sudoers applies the last matching entry. So the drop-in name must sort after `/etc/sudoers.d/orbit`, which holds Orbit's grant.
- The drop-in name must not contain a dot, because sudo skips such a file.
- Denying `bash` breaks more than one inspector. The production Instance, private Route, public Route edge, custom proxy Route, Schedule, and Caddy build inspectors all run `sudo bash`.
- Every `exec` holds the issue lock, so the steps run in order, and the evidence log keeps that order.

## Verification

`<worktree>/.e2e/evidence.log` holds every labelled entry in order. `doctor baseline` and both restored reports show exit `0` and no findings. `doctor app-prod drift` shows exit `1` and exactly one `instance.php_fpm_projection_mismatch`. `doctor app-prod unverifiable` shows exit `1` and exactly one `role.inspection_failed`. The before and after scan entries differ only in the `activity_log` row count.
