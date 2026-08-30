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
- open a shell, synchronize, verify, and execute against that attempt;
- prove the worktree's HEAD commit on a fresh proof attempt;
- release the attempt's instances, network, and devices; and
- verify that release completed.

Cleanup removes only the attempt's recorded inventory. There is no reaper: a
topology lives until `release`, and `bin/worktree-remove` releases it.

## Where state lives

The harness keeps no state outside the repository checkouts:

- `<worktree>/.e2e/` (gitignored) holds that issue's attempt: `attempt.json`
  (the lease: attempt id, purpose, operation), `topology.json` (the attempt
  record), `proof.json` (the last proof result), and `log` (one line per
  harness command). It dies with the worktree.
- `<primary checkout>/.e2e/` (gitignored) holds `standby/promoted.json`, the
  recorded generations under `standby/generations/`, a `standby/corrupt.json`
  marker while recovery is required, and the host locks under `locks/`.
- Capacity is read from `incus list`: the harness-owned VMs that exist and the
  `10.232.<slot>.0/24` subnets in use.

Migration note: before NCK-91 the harness kept journals, evidence, receipts,
leases, and the capacity ledger under `~/.local/state/orbit/e2e`; that
directory is no longer read or written. Copy `standby/promoted.json` from it
into `<primary>/.e2e/standby/` once when upgrading a host.

## Network ownership

Every Incus network in the `default` project whose name starts with `oe-`
(current harness) or `orbit-e2e-` (legacy harness) belongs to the harness. No
such network may outlive the topology that used it: every
`bin/e2e-topology release` ends with an orphan sweep that deletes each harness
network with an empty `used_by`, except `oe-standby`. The sweep holds the
host creation lock, so an acquisition between network creation and its first
VM is never swept. The sweep never touches a network outside those prefixes,
a network with users, or another Incus project. Each deleted name is reported
as `networks_reaped` in the release output.

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
| State | `<worktree>/.e2e/attempt.json`, `topology.json`, `proof.json`, `log` |
| Lifetime | Until `bin/e2e-topology release ISSUE` or `bin/worktree-remove` |

Issue IDs match `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`.

## Command surface

Every command takes the issue only; the attempt is whatever
`<worktree>/.e2e/` names. The worktree is found at
`<primary>/.worktrees/<issue-lowercase>-*` (exactly one) or given with
`--worktree=PATH`. Every command accepts `--json`. `acquire` and `prove`
refuse a stale promoted standby.

| Command | Purpose |
| --- | --- |
| `bin/e2e-topology acquire ISSUE WORKTREE` | Create a discovery attempt on the mounted worktree (about 21 to 23 s) |
| `bin/e2e-topology shell ISSUE ROLE` | Interactive login shell as `orbit` on one role, in `/home/orbit/orbit` on the checkout roles, with the `exec` environment |
| `bin/e2e-topology exec ISSUE ROLE --argv=JSON` | Run one argv vector as the orbit user on one role; `--argv-file=PATH` takes a file holding `{"argv":[...],"stdin":null}` instead (see [Guest commands](#guest-commands)) |
| `bin/e2e-topology sync ISSUE` | Re-verify the mounted source identity of the discovery attempt |
| `bin/e2e-topology verify ISSUE` | Verify the live attempt |
| `bin/e2e-topology prove ISSUE --plan=PATH` | Prove the worktree HEAD (clean tree) on a fresh proof attempt; the plan defaults to `proofs/ISSUE.json` (about 33 s) |
| `bin/e2e-topology status ISSUE` | Report the live attempt from `<worktree>/.e2e/` without touching infrastructure |
| `bin/e2e-topology release ISSUE` | Release the live attempt, verify absence, and sweep orphaned harness networks (`networks_reaped`) |
| `bin/e2e-standby status` | Show the promoted standby generation |
| `bin/e2e-standby fingerprint --main-sha=SHA` | Compute the prepared-state fingerprint |
| `bin/e2e-standby refresh --main-sha=SHA` | Refresh and promote the standby when the fingerprint changed |
| `bin/e2e-standby restore` | Restore the promoted generation and leave it stopped |
| `bin/e2e-live SHA` | Run the live acceptance suite against the exact candidate from the validation clone (see [Live acceptance suites](#live-acceptance-suites)) |

`bin/worktree-remove ISSUE slug` releases the issue's live topology first when
`<worktree>/.e2e/attempt.json` exists, then removes the worktree.

### Guest commands

`exec` runs one exact argument vector on one role of a discovery attempt and
prints `{"state":"executed","exit_code":N,"stdout":...,"stderr":...}`
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
every checkout role: discovery does it after the mount and proof right after
hydration, so `orbit` resolves by name for the orbit user on a mounted and a
bundled checkout alike:

```bash
bin/e2e-topology exec NCK-82 app-dev \
  --argv='["orbit","doctor","--json"]' --json
```

Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`.
`shell ISSUE ROLE` opens the same environment interactively (`runuser -u
orbit -- env -C /home/orbit/orbit ... bash -l` through `incus exec`).
`exec`, `sync`, `verify`, and `release` on an issue with no attempt fail with
`ISSUE has no active attempt.`; `exec` and `sync` refuse a proved attempt.

### Proof fixtures

Files under `proofs/<issue>/` at the repository root (for example
`proofs/NCK-82/`) are proof-only fixtures beside the plan `proofs/<issue>.json`. `prove` reads
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
(`name<TAB>mode<TAB>sha256` per file), which must match the host digest; a
mismatch is a `diagnosis`. The guest script
inventory in `WorktreeSynchronizer::REQUIRED_GUEST_SCRIPTS` stays closed;
fixtures are the per-issue layer beside it. An issue without a fixture
directory stages an empty inventory.

### Proof output

`prove --json` prints a compact result: `status` (`proved` or `diagnosis`),
`issue`, `attempt_id`, `candidate_sha`, `actions` (one `{"id","node","exit_code"}`
per action that ran), and `recorded_at`. A `diagnosis` adds `error` (the
failed phase and message) and, when a plan action failed, `failed_action`:
`{"id","node","exit_code","stdout_tail","stderr_tail"}` (each tail keeps the
final 4096 bytes). The same object is written to `<worktree>/.e2e/proof.json`.
The proved topology stays alive until `release`.

The proof plan file has this shape:

```json
{
  "setup": [{"id": "text", "node": "gateway", "argv": [], "timeout_seconds": 60}],
  "acceptance": [{"id": "text", "node": "app-dev", "argv": [], "timeout_seconds": 60}]
}
```

## Discovery mount

Discovery mounts the feature worktree read-write at `/home/orbit/orbit` on
`gateway` and `app-dev` with an Incus virtiofs disk device. Every host edit is
live in both guests without a transfer step. Guests never run composer in
discovery; host `bin/bootstrap` owns vendor. The gateway `.env` is placed into
the worktree if absent. The mount device is part of the attempt inventory, and
exact release removes it.

Proof never mounts host state. It synchronizes the worktree's HEAD commit
from Git (the tree must be clean), verifies clean guest checkout identity,
converges, runs the declared setup and acceptance checks, and records the
result. A failure before the VMs hold the candidate rolls the attempt back;
any later failure records a `diagnosis` and keeps the topology alive for
investigation. A proved attempt rejects `sync` and `exec`.

## Prepared-state limits

Proof plans call fixtures through the staged guest path described under
[Proof fixtures](#proof-fixtures), for example
`/var/lib/orbit-e2e/proof/doctor-proof.sh` from `proofs/NCK-58/`; a fixture
that needs the checkout still runs
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

`composer test:live-incus` in `apps/e2e` runs the lifecycle suite under
`tests/Live` against real Incus resources. It skips unless
`ORBIT_LIVE_INCUS=1`; the test lists its own `ORBIT_LIVE_*` inputs. Contracts
the inputs do not spell out:

- The suite reads the attempt state under `<ORBIT_LIVE_FEATURE_WORKTREE>/.e2e/`.
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
- `bin/e2e-topology prove --json` returns the compact result that is also
  written to `<worktree>/.e2e/proof.json`.

### `bin/e2e-live`

`bin/e2e-live <candidate-sha>` makes that recipe executable. It
is the required check for a harness-touching diff (`apps/e2e/app/**`,
`apps/e2e/resources/guest/**`, `apps/e2e/tests/Live/**`, `bin/e2e-*`). The
wrapper:

- owns the validation clone at `ORBIT_E2E_VALIDATE_ROOT` (default
  `$HOME/orbit-validate`), cloning it from the calling repository when
  absent, and refuses a dirty clone;
- fetches the candidate from the calling repository and runs
  `git checkout -B main <sha>` there, because the acquirer fingerprints the
  `main` ref and the refresher keys off `HEAD`;
- resets the linked worktree `.worktrees/acc-1` (branch `acc-1-live`) to the
  candidate, and runs `bin/bootstrap` in the clone and the worktree;
- exports every `ORBIT_LIVE_*` input, with `proofs/ACC-1.json` as the
  harness plan (its first acceptance action is `orbit node:list --json`);
- refuses to run while another worktree of the clone holds a live topology,
  and releases a stale `ACC-1` attempt itself;
- holds `<clone>/.e2e/locks/live.lock` so only one run drives the clone;
- refreshes the standby to the candidate when the prepared-state fingerprint
  changed, then runs the lifecycle suite; and
- prints one summary line (`lifecycle suite: passed, <n> assertions,
  <seconds>s — <command>`) for the pull request body.

The lifecycle suite takes about 3 minutes on a warm clone.
