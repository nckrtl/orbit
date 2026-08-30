# Incus topology registry

Incus provides the disposable development topology for issues marked
`Proof: incus`. The lifecycle is governed by
[ADR 0005](../decisions/0005-rolling-incus-development-topology.md) (prepared
standby, refresh, exact cleanup) and
[ADR 0006](../decisions/0006-topology-led-feature-development.md) (discovery,
fresh proof, immutable proved attempts). Automated-only work stays independent
of Incus.

A profile is registered only when the repository provides and verifies all of
these exact-ID operations:

- create a disposable discovery attempt for one Linear issue;
- synchronize, verify, and execute against one exact attempt;
- prove one exact candidate commit on a fresh proof attempt;
- record proof and release evidence against the attempt;
- release the attempt's instances, network, devices, and manifest; and
- verify that release completed.

Cleanup is idempotent and removes only the attempt's recorded inventory. The
TTL reaper is only a fallback for abandoned discovery or diagnosis attempts.

## Supported platform

Orbit supports Ubuntu 26.04 nodes only. The single registered profile is
`gateway_app-dev_app-prod`. All three nodes use Ubuntu 26.04 and the promoted
standby generation.

## Registered profiles

### `gateway_app-dev_app-prod`

Registered on 2026-08-29 after live acceptance (ADR 0005). The attempt-scoped
discovery and proof lifecycle passed live acceptance on 2026-08-30 (ADR 0006).

| Field | Value |
| --- | --- |
| Profile ID | `gateway_app-dev_app-prod` |
| Ordered roles | `gateway` (roles `gateway`, `vpn`), `app-dev`, `app-prod` |
| Checkout roles | `gateway`, `app-dev` (guest path `/home/orbit/orbit`) |
| Prepared image | Base image `orbit-base-ubuntu-26.04-runtime`; promoted standby snapshots `main-<generation-id>` on `orbit-e2e-standby-{gateway,app-dev,app-prod}` |
| Addresses | Incus `.10/.11/.12` on `oe-<issue-hash>`; WireGuard `10.44.0.1/.2/.3` |
| Attempt purpose | `discovery` or `proof`; one active attempt per issue |
| Proof status | `proved` or `diagnosis` |
| Manifest | `$XDG_STATE_HOME/orbit/e2e/topologies/ISSUE/ATTEMPT.json`; active pointer `topologies/ISSUE/active.json` |
| Evidence | `evidence/proofs/ISSUE/ATTEMPT.json`, `evidence/releases/ISSUE/ATTEMPT.json`, `standby/failures/<evidence>.json` |
| Maximum lifetime | 7 days per lease; a proved attempt is never reaped while its pull request is open |

Issue IDs match `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`.

## Command surface

Every command accepts `--json`. `acquire` and `prove` refuse a stale promoted standby.

| Command | Purpose |
| --- | --- |
| `bin/e2e-topology acquire ISSUE WORKTREE` | Create a discovery attempt on the mounted worktree (about 21 to 23 s) |
| `bin/e2e-topology sync ISSUE ATTEMPT WORKTREE` | Re-verify the mounted source identity of one discovery attempt |
| `bin/e2e-topology verify ISSUE ATTEMPT` | Verify one exact attempt |
| `bin/e2e-topology exec ISSUE ATTEMPT ROLE --argv-file=PATH` | Run one argv vector as the orbit user on one role; the file holds `{"argv":[...],"stdin":null}` |
| `bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH` | One-shot proof of the exact candidate on a fresh proof attempt (about 33 s) |
| `bin/e2e-topology diagnose ISSUE ATTEMPT` | Move a proved attempt to diagnosis; one-way |
| `bin/e2e-topology status ISSUE [ATTEMPT]` | Report the active or exact attempt without touching infrastructure |
| `bin/e2e-topology release ISSUE ATTEMPT` | Release one exact attempt and verify absence |
| `bin/e2e-topology reap --issue-state-file=PATH` | Release expired attempts of terminal issues from an issue-state snapshot |
| `bin/e2e-standby status` | Show the promoted standby generation |
| `bin/e2e-standby fingerprint --main-sha=SHA` | Compute the prepared-state fingerprint |
| `bin/e2e-standby refresh --main-sha=SHA` | Refresh and promote the standby when the fingerprint changed |
| `bin/e2e-standby restore` | Restore the promoted generation and leave it stopped |

The proof plan file has this shape:

```json
{
  "setup": [{"id": "text", "node": "gateway", "argv": [], "timeout_seconds": 60}],
  "acceptance": [{"id": "text", "node": "app-dev", "argv": [], "timeout_seconds": 60}],
  "post_deployment_actions": [
    {"target": "text", "operation": "text", "reason": "text", "recovery": "text", "verification": "text"}
  ]
}
```

## Discovery mount

Discovery mounts the feature worktree read-write at `/home/orbit/orbit` on
`gateway` and `app-dev` with an Incus virtiofs disk device. Every host edit is
live in both guests without a transfer step. Guests never run composer in
discovery; host `bin/bootstrap` owns vendor. The gateway `.env` is placed into
the worktree if absent. The mount device is part of the attempt inventory, and
exact release removes it.

Proof never mounts host state. It synchronizes the exact candidate commit from
Git, verifies clean guest checkout identity, converges, runs the declared
setup and acceptance checks, and records the result. The harness can retry one
transport failure before checkout identity is verified; any later failure
moves the attempt to `diagnosis`. A proved attempt rejects sync, exec, and
state changes.

## Proof fixtures

Proof plans may call a fixture script from the candidate checkout, for example
`/home/orbit/orbit/apps/e2e/resources/proof/doctor-proof.sh`, on the checkout
roles `gateway` and `app-dev`. The guest-script inventory under
`apps/e2e/resources/guest` is closed: `WorktreeSynchronizer` requires the exact
list, so a proof-only script must not be added there. `app-prod` has no
checkout; declare its actions as short `sudo bash -c` argument vectors.

Known prepared-state limits (first observed on 2026-08-30, NCK-58):

- A rolling refresh restores the promoted snapshots and skips provisioning, so
  projections rendered by older Gateway code (for example PHP-FPM pools) stay
  stale after a renderer change. Doctor reports that as drift. Re-project
  through product commands in proof setup, or rebuild the standby cold.
- `converge-sample-app.sh` replaces the product-managed `/etc/caddy/Caddyfile`
  symlink on `app-prod` with an e2e wrapper for internal TLS. The product's
  Caddy publisher then fails validation on the next publish, and Doctor's
  fragment lookup misses. Restore the managed symlink and keep the
  `local_certs` block as `fragments/00-orbit-e2e-global.caddy` inside the
  managed version instead; the publisher copies unmanaged fragments forward.

## Standby

Refresh the standby with `bin/e2e-standby refresh --main-sha=SHA` after a
merge changes the prepared-state fingerprint. A rolling refresh restores the
promoted snapshots, converges, and re-snapshots in about one minute.

Guests are reachable from the Gateway only over WireGuard after role
provisioning; the harness repairs cloned WireGuard endpoints through root
`incus exec` (`retarget-vpn.sh`) and never depends on public SSH. After that
repair, `orbit:node-retarget` updates the public record over WireGuard; see
[node retarget](node-retarget.md).

`bin/e2e-standby refresh --allow-cold` permits only initial construction when
no promoted generation or standby resources exist. It never replaces a
promoted generation. An operating-system, base-image, cold-epoch, or corrupt
standby change requires a separate reviewed disaster-recovery procedure before
the harness mutates Incus resources.
