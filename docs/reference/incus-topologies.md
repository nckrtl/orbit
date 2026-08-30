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

## Network ownership

Every Incus network in the `default` project whose name starts with `oe-`
(current harness) or `orbit-e2e-` (legacy harness) belongs to the harness. No
such network may outlive the topology that used it: every
`bin/e2e-topology release` and `bin/e2e-topology reap` ends with an orphan
sweep that deletes each harness network with an empty `used_by`, except
`oe-standby` and the network of an active lease (an acquisition owns its
network before the first VM attaches). The sweep never touches a network
outside those prefixes, a network with users, or another Incus project. Each
deleted name is recorded as `networks_reaped` in the command output, in the
release receipt under `evidence/releases/ISSUE/ATTEMPT.json`, and in the
operation journal (`network.sweep`). A repeated `release` sweeps again and
reports the new deletions only, appending them to the receipt. The
project-manager post-merge cleanup names `networks_reaped: n` from its release
of the proof topology in its handoff.

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
| `bin/e2e-topology exec ISSUE ATTEMPT ROLE --argv-file=PATH` | Run one argv vector as the orbit user on one role; the file holds `{"argv":[...],"stdin":null}`. `--argv=JSON` takes the vector inline instead (see [Guest commands](#guest-commands)) |
| `bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH` | One-shot proof of the exact candidate on a fresh proof attempt (about 33 s) |
| `bin/e2e-topology diagnose ISSUE ATTEMPT` | Move a proved attempt to diagnosis; one-way |
| `bin/e2e-topology status ISSUE [ATTEMPT]` | Report the active or exact attempt without touching infrastructure |
| `bin/e2e-topology release ISSUE ATTEMPT` | Release one exact attempt, verify absence, and sweep orphaned harness networks (`networks_reaped`) |
| `bin/e2e-topology reap --issue-state-file=PATH` | Release expired attempts of terminal issues from an issue-state snapshot, then sweep orphaned harness networks (`networks_reaped`) |
| `bin/e2e-standby status` | Show the promoted standby generation |
| `bin/e2e-standby fingerprint --main-sha=SHA` | Compute the prepared-state fingerprint |
| `bin/e2e-standby refresh --main-sha=SHA` | Refresh and promote the standby when the fingerprint changed |
| `bin/e2e-standby restore` | Restore the promoted generation and leave it stopped |
| `bin/e2e-live SHA [--rolling]` | Run the live acceptance suites against the exact candidate from the validation clone (see [Live acceptance suites](#live-acceptance-suites)) |

### Guest commands

`exec` runs one exact argument vector on one role of a discovery attempt and
prints `{"state":"executed","operation_id":...,"exit_code":N,"stdout":...,"stderr":...}`
with `--json`. The exit code of the process is the exit code of the guest
command (`0` maps to success). The vector comes from exactly one source:

- `--argv='["program","arg",...]'`: an inline JSON array of strings, no stdin;
- `--argv-file=PATH`: a file holding `{"argv":[...],"stdin":null}` when the
  command needs stdin or the vector is long.

Passing both is refused. The vector runs as the orbit user through
`runuser -u orbit -- env ... PROGRAM ARGS`, so `argv[0]` must be a program name
that resolves on the guest `PATH` (`/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin`)
or an absolute path. Shell profiles are not loaded, and the first argument
cannot start with `-` or carry `=`. The harness links the checkout's CLI
entrypoint (`/home/orbit/orbit/apps/cli/orbit`) to `/usr/local/bin/orbit` on
every checkout role: discovery does it in the `mount.source` phase and proof
right after hydration, so `orbit` resolves by name for the orbit user on a
mounted and a bundled checkout alike:

```bash
bin/e2e-topology exec NCK-82 ATTEMPT app-dev \
  --argv='["orbit","doctor","--json"]' --json
```

Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`.
`exec`, `sync`, `verify`, and `release` on an issue with no lease fail with
`ISSUE has no active attempt.`; a present but malformed lease is still
reported as invalid.

### Proof fixtures

Files under `apps/e2e/resources/proof/<issue>/` (for example
`apps/e2e/resources/proof/NCK-82/`) are proof-only fixtures. `prove` reads
them from the exact candidate commit, never from the host working tree, and
installs them root-owned (`0755` for executables, `0644` otherwise) at
`/var/lib/orbit-e2e/proof/<name>` on every role of the proof attempt, including
`app-prod`, which has no checkout. A proof plan references a fixture by that
guest path on any node:

```json
{"id": "fixture-app-prod", "node": "app-prod", "argv": ["/var/lib/orbit-e2e/proof/fixture-check.sh", "app-prod"], "timeout_seconds": 60}
```

File names are flat and match `[a-z0-9][a-z0-9._-]*`; a nested directory or
symlink is refused. Staging happens after the candidate identity check and
before convergence. Every role prints its installed inventory
(`name<TAB>mode<TAB>sha256` per file) and the proof record stores the result
under `proof_fixtures` with `files`, the host `digest`, and the digest each
role observed under `roles`; a mismatch is a `diagnosis`. The guest script
inventory in `WorktreeSynchronizer::REQUIRED_GUEST_SCRIPTS` stays closed;
fixtures are the per-issue layer beside it. An issue without a fixture
directory stages an empty inventory.

### Proof output

`prove --json` prints `state`, `operation_id`, `issue`, `attempt_id`, and
`proof`, the record without its `plan` key; the full plan is only in the
record file. When the state is `diagnosis`, the object ends with
`failed_action`: `{"id","node","exit_code","stdout_tail","stderr_tail"}` for the
last action that exited non-zero (each tail keeps the final 2048 bytes), or
`null` when the failure happened outside a plan action; `proof.verification`
then names the failed phase as `proof.<phase>`. Read the full record at
`evidence/proofs/ISSUE/ATTEMPT.json` when the tails are not enough.

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

## Prepared-state limits

Proof plans call fixtures through the staged guest path described under
[Proof fixtures](#proof-fixtures), for example
`/var/lib/orbit-e2e/proof/doctor-proof.sh` from
`apps/e2e/resources/proof/NCK-58/`; a fixture that needs the checkout still runs
only on `gateway` and `app-dev`. `app-prod` actions can call a staged fixture
or a short `sudo bash -c` argument vector.

Known prepared-state limits (first observed on 2026-08-30, NCK-58):

- A rolling refresh restores the promoted snapshots and skips provisioning, so
  every convergence ends with the `reproject.product-state` step (NCK-83):
  `converge-sample-app.sh reproject` on `app-dev` runs the product's own
  projection path (`node:role:add --converge` for every app role, then
  `instance:php` for every instance with development instances last, because
  the app-dev runtime converger publishes the Gateway DNS records for every
  active site). The prepared-state allowlist tracks the projection renderers
  and this command closure, so a renderer change invalidates the promoted
  generation. Before that step, `converge-sample-app.sh internal-tls` on
  `app-prod` places the e2e `local_certs` global block as
  `fragments/00-orbit-e2e-global.caddy` inside the managed Caddy version
  behind the product-owned `/etc/caddy/Caddyfile` symlink (NCK-84); the
  product publisher copies unmanaged fragments forward, so Doctor reports no
  Caddy drift and the harness never replaces the managed symlink.

## Standby

Refresh the standby with `bin/e2e-standby refresh --main-sha=SHA` after a
merge changes the prepared-state fingerprint. A rolling refresh restores the
promoted snapshots, converges (including product re-projection), and
re-snapshots in about two minutes.

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

## Live acceptance suites

`composer test:live-incus` in `apps/e2e` runs the lifecycle and rolling
suites under `tests/Live` against real Incus resources. They skip unless
`ORBIT_LIVE_INCUS=1`; each test lists its own `ORBIT_LIVE_*` inputs. Contracts
the inputs do not spell out:

- `XDG_STATE_HOME` must point at the state root the wrappers use (normally
  `$HOME/.local/state`), because the suites read evidence, journals, and
  lease files under `<XDG_STATE_HOME>/orbit/e2e`.
- `ORBIT_LIVE_MAIN_WORKTREE` is the repository the suite runs from, and
  `ORBIT_LIVE_FEATURE_WORKTREE` is a linked worktree of it that is checked
  out on a branch whose name starts with the lowercase issue key (for
  `ORBIT_LIVE_ISSUE=ACC-1`, `acc-1-...`); a detached `HEAD` fails with
  `The Git command failed.` and any other branch with
  `The worktree branch does not match the issue.`
- The lifecycle suite runs the proof plan's first acceptance action as its
  discovery command and requires that action to print JSON on stdout (for
  example `orbit node:list --json`). Use a small harness plan for the suite
  rather than a feature's proof plan.
- `bin/e2e-topology prove --json` returns the proof summary without `plan`;
  the full record with the plan is the persisted file at
  `<XDG_STATE_HOME>/orbit/e2e/evidence/proofs/<issue>/<attempt>.json`.

### `bin/e2e-live`

`bin/e2e-live <candidate-sha> [--rolling]` makes that recipe executable. It
is the required check for a harness-touching diff (`apps/e2e/app/**`,
`apps/e2e/resources/guest/**`, `apps/e2e/tests/Live/**`, `bin/e2e-*`). The
wrapper:

- owns the validation clone at `ORBIT_E2E_VALIDATE_ROOT` (default
  `$HOME/orbit-validate`), cloning it from the calling repository when
  absent, and refuses a dirty clone;
- fetches the candidate from the calling repository and runs
  `git checkout -B main <sha>` there, because the acquirer fingerprints the
  `main` ref and the refresher keys off `HEAD`;
- resets the linked worktrees `.worktrees/acc-1` (branch `acc-1-live`) and,
  with `--rolling`, `.worktrees/acc-2` (branch `acc-2-live`) to the
  candidate, and runs `bin/bootstrap` in the clone and each worktree;
- exports `XDG_STATE_HOME` (default `$HOME/.local/state`) and every
  `ORBIT_LIVE_*` input, with `apps/e2e/resources/proof/ACC-1/plan.json` as
  the harness plan (its first acceptance action is `orbit node:list --json`);
- refuses to run while a feature topology other than `ACC-1` or `ACC-2` is
  active, and releases a stale `ACC-1` or `ACC-2` attempt itself;
- refreshes the standby to the candidate when the prepared-state fingerprint
  changed, then runs the lifecycle suite;
- with `--rolling`, commits two marker changes to
  `apps/e2e/resources/guest/prepare-node.sh` on the throwaway branch
  `acc-1-rolling` (`ORBIT_LIVE_ROLLING_SHA`, `ORBIT_LIVE_FAILURE_SHA`),
  writes the failing migration file from
  `bin/e2e-standby fingerprint --main-sha=<failure-sha>`, runs the rolling
  suite, and refreshes the standby back to the candidate afterwards, also
  when the suite fails; and
- prints one summary line per suite (`<suite> suite: passed, <n> assertions,
  <seconds>s — <command>`) for the handoff `checks` and the pull request body.

The lifecycle suite alone takes about 3 minutes on a warm clone; `--rolling`
adds about 6 minutes for the two extra standby refreshes.
