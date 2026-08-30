# Remote file writes on uutils coreutils

## Problem

Metrics convergence succeeded once and failed on every later run with
`metrics.configuration_publish_failed` and
`metrics.exporter_configuration_failed`. The remote command printed only
`install: No such file or directory`, and the destination directory existed.

## Cause

The fleet nodes ship uutils coreutils, not GNU coreutils. Its `install`
refuses an existing destination when the source is `/dev/stdin`:

```
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # first run
$ printf a | sudo install -m 0640 /dev/stdin /etc/orbit/metrics/marker   # second run
install: No such file or directory
```

Creating a new file works, and overwriting from a regular file works. Only the
overwrite-from-standard-input combination fails, so the failure appears one
convergence after the code that causes it.

## Solution

Never point a remote `install` at a live path. Write to
`<path>.orbit-candidate`, remove any stale candidate first, then `mv -fT` the
candidate onto the target. The move is atomic, which running containers and
systemd units need anyway. `MetricsSshExecutor::stageFile()` and
`MetricsExporterSshExecutor::publishConfiguration()` follow this shape, and
`RemoteProcessRuntimeManager` already did.

## Limits

The quirk is specific to `/dev/stdin` sources. Directory creation
(`install -d`), mode-only changes, and regular-file sources are unaffected.
Numeric container identities such as Grafana's `472` also need a separate
`chown`, because `install -o`/`-g` reject an identity with no `passwd` entry.

## Verification

`proofs/NCK-73.json` disables and re-enables the `app-prod` exporter and then
recovers a failed assignment. Each step reconverges over configuration that
already exists, which is exactly the case that failed before. Unit coverage
lives in `apps/gateway/tests/Unit/Infrastructure/Metrics/MetricsSshExecutorTest.php`
and `MetricsExporterSshExecutorLifecycleTest.php`.
