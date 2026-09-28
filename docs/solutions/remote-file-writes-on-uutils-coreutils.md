---
title: "Remote file writes on uutils coreutils"
description: "Why writing a remote file from standard input with install fails on uutils coreutils, and the write sequence that works."
covers:
  - apps/gateway/app/Infrastructure/Metrics/{MetricsSshExecutor,MetricsExporterSshExecutor}.php
  - apps/gateway/app/Infrastructure/Nodes/NodeAgentSshExecutor.php
  - apps/gateway/app/Infrastructure/Processes/RemoteProcessRuntimeManager.php
---

# Remote file writes on uutils coreutils

## Problem

A convergence that writes a file on a Node succeeds once and fails on every later run. The remote command prints only `install: No such file or directory`, although the directory exists. In Metrics, the codes are `metrics.configuration_publish_failed` and `metrics.exporter_configuration_failed`.

## Cause

Ubuntu 26.04 ships uutils coreutils, not GNU coreutils. Its `install` refuses an existing destination when the source is `/dev/stdin`:

```text
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # first run
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # second run
install: No such file or directory
```

Creating a new file works, and overwriting from a regular file works. Only an overwrite from standard input fails, so the failure shows one convergence after the code that causes it.

## Solution

Never point a remote `install` from standard input at a live path. Remove any stale `<path>.orbit-candidate`, write the candidate, and then `mv -fT` it onto the target. The move is atomic, which running containers and systemd units need anyway. Metrics SSH publishers, `MetricsExporterSshExecutor::publishConfiguration()`, and `NodeAgentSshExecutor::publishFile()` use this sequence. `RemoteProcessRuntimeManager` writes each unit to `/etc/orbit/systemd-candidates`, verifies the candidate with `systemd-analyze`, and then moves it into place. Metrics exporter SSH lifecycle operations now delegate file publication to the shared publisher.

## Limits

The problem affects only `/dev/stdin` sources. `install -d`, mode changes, and regular-file sources work. A numeric container identity, such as Grafana's `472`, also needs a separate `chown`, because `install -o` and `-g` refuse an identity without a `passwd` entry.

## Verification

Converge the same role twice over existing configuration, which is the case that fails. Unit tests cover the sequence in `apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsSshExecutorTest.php` and `MetricsExporterSshExecutorLifecycleTest.php`; `apps/gateway/tests/Feature/Infrastructure/Processes/RemoteProcessRuntimeManagerTest.php` checks candidate verification and atomic publication of systemd units.
