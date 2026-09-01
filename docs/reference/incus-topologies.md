# Incus topology registry

Incus provides disposable development and proof topologies for issues marked
`Proof: incus`. The lifecycle is governed by
[ADR 0005](../decisions/0005-rolling-incus-development-topology.md) (prepared
standby, refresh, exact cleanup) and
[ADR 0006](../decisions/0006-topology-led-feature-development.md) (separate
discovery, fresh proof, immutable proved attempts). Automated-only work stays
independent of Incus.

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
- `<primary checkout>/.e2e/` (gitignored) holds the state of the standby that
  checkout owns (see [Standby identity](#standby-identity)): `standby/promoted.json`, the
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
network with an empty `used_by`, except a standby network (`oe-standby`,
`oe-live-standby`). The sweep holds the
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
| Ordered roles | `gateway`, `app-dev`, `app-prod` |
| Required assignments | `gateway`: `gateway`, `vpn`; `app-dev`: `app-dev`, `metrics`; `app-prod`: `app-prod` |
| Checkout roles | `gateway`, `app-dev` (guest path `/home/orbit/orbit`) |
| Prepared image | Base image `orbit-base-ubuntu-26.04-runtime`; promoted standby snapshots `main-<generation-id>` on `orbit-e2e-standby-{gateway,app-dev,app-prod}` (the validation clone: `orbit-e2e-live-standby-*`) |
| Addresses | Incus `.10/.11/.12` on `oe-<issue-hash>`; WireGuard `10.44.0.1/.2/.3` |
| Attempt purpose | `discovery` or `proof`; one active attempt per issue |
| Proof status | `proved` or `diagnosis` |
| State | `<worktree>/.e2e/attempt.json`, `topology.json`, `proof.json`, `log` |
| Lifetime | Until `bin/e2e-topology release ISSUE` or `bin/worktree-remove` |

Issue IDs match `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`.

## Host budget

Each VM is 1 vCPU and 2 GiB (`e2e.incus.cpu`, `e2e.incus.memory`). The harness
refuses to acquire past `e2e.incus.max_vms`, which defaults to 24: the six
standby VMs of both standbys plus six feature topologies. Raise it for one run
with `ORBIT_E2E_INCUS_MAX_VMS`. Network slots are not the constraint — the
standbys hold slots 1 and 200, leaving 198 for feature topologies.

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
| `bin/e2e-standby promote ISSUE` | Make the issue's proved topology the standby generation, then release it (see [Standby](#standby)) |
| `bin/e2e-standby refresh --main-sha=SHA` | Fallback: refresh the standby in place when the fingerprint changed |
| `bin/e2e-standby restore` | Restore the promoted generation and leave it stopped |
| `bin/e2e-standby rebuild --main-sha=SHA` | Recovery: delete this checkout's standby VMs and network, forget its manifests, and cold-build the standby again (see [Stale manifests and rebuild](#stale-manifests-and-rebuild)) |
| `bin/e2e-live SHA` | Run the feature flow once against a standby built from the exact candidate in the validation clone (see [Live acceptance suites](#live-acceptance-suites)) |

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

A plan can declare `fixture_issues` when its acceptance actions must execute an
affected historical fixture. The harness stages each declared directory under
its issue namespace, such as
`/var/lib/orbit-e2e/proof/NCK-116/refuses-a-shifted-rule-number.sh`. It still
stages the plan owner's fixtures at `/var/lib/orbit-e2e/proof/`. The list is an
explicit allowlist of valid issue IDs. It cannot contain duplicates.

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
`issue`, `attempt_id`, `candidate_sha`, `actions` (one
`{"id","node","expected_exit_code","exit_code"}` per action that ran), and
`recorded_at`. A plan that declared the topology it
ends with adds `ends_with` and `skipped_probes`. A `diagnosis` adds `error` (the
failed phase and message) and, when a plan action failed, `failed_action`:
`{"id","node","exit_code","stdout_tail","stderr_tail"}` (each tail keeps the
final 4096 bytes). The same object is written to `<worktree>/.e2e/proof.json`.
The proved topology stays alive until `release`.

The proof plan file has this shape:

```json
{
  "setup": [{"id": "text", "node": "gateway", "argv": [], "timeout_seconds": 60}],
  "acceptance": [{"id": "text", "node": "app-dev", "argv": [], "timeout_seconds": 60, "expected_exit_code": 0}]
}
```

`expected_exit_code` defaults to `0`. A plan can declare only the exact timeout
exits `124` and `137`. The runner accepts an action only when its actual exit
equals the declared exit. All other nonzero exits remain a diagnosis.

Every proof action runs through a guest deadline. The harness sends `TERM` at
`timeout_seconds`, gives the fixture five seconds to run its cleanup traps,
then sends `SIGKILL` if it still runs. The Incus transport has seven seconds of
headroom beyond the declared deadline. This keeps `TERM` catchable and keeps a
hung cleanup bounded.

An optional top-level `"mutates": true` declares that the plan changes the
topology. Every plan that writes reusable node state must declare it. `promote`
refuses a proved mutating topology, including a topology whose expected timeout
left a killed process or temporary record for an inspector to examine.

### Declaring the topology a plan ends with

A plan whose behaviour is about removing a node declares the topology it ends
with:

```json
{"ends_with": {"nodes": ["gateway", "app-dev"]}}
```

Verification then runs in full against the declared set. Only the probes that
run *on* a declared-absent node are skipped; they are named in the proof
result as `skipped_probes`, beside the declaration itself. Nothing else is
relaxed:

- The two fleet probes still run, told which nodes to expect.
  `role.assignments` fails when a node the plan declared absent is still
  registered in any status, so a declaration cannot be used to look away from
  a node that is still there.
- Removing a node without declaring it fails exactly as before: the probes of
  that node still run and find nothing.
- `gateway` can never be left out; it holds the registry every other probe
  reads.
- A declaration implies `"mutates": true`, so a topology whose nodes the plan
  removed can never become the standby.

The declaration speaks about Orbit's node registry only. All three Incus
instances still exist and are still checked for network identity; `release`
remains the only thing that removes a VM.

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

- A standby refresh restores the promoted snapshots and skips provisioning, so
  every convergence ends with the `reproject.product-state` step (NCK-83):
  `converge-sample-app.sh reproject` on `app-dev` first runs
  `node:role:add --converge` for every app role. The legacy `instances` branch
  then runs `instance:php` for every Instance, with development Instances last
  because the app-dev runtime converger publishes the Gateway DNS records for
  every active site. The typed `app_instances` branch validates the persisted
  source-ready `e2e-dev` item instead. This branch is source-only. It
  never runs `instance:php`, creates a Workspace, or prepares an app-prod site.
  It hydrates and verifies only the typed item's recorded `checkout_path`.
  The prepared-state allowlist tracks the App, legacy Instance, typed item, Node,
  and legacy Workspace command closure used by these two deterministic
  branches. A change in either branch therefore invalidates the promoted
  generation. Before legacy re-projection,
  `converge-sample-app.sh internal-tls` on `app-prod` places the e2e
  `local_certs` global block as
  `fragments/00-orbit-e2e-global.caddy` inside the managed Caddy version
  behind the product-owned `/etc/caddy/Caddyfile` symlink (NCK-84); the
  product publisher copies unmanaged fragments forward, so Doctor reports no
  Caddy drift and the harness never replaces the managed symlink.

## Standby

A standby is one physical set of stopped VMs and one promoted generation in the
owning checkout's `<primary>/.e2e/standby/promoted.json`. Every `acquire` and
`prove` clones the promoted snapshot `main-<generation>`.

### Standby identity

Every checkout that acts as a primary owns its own standby. `ORBIT_E2E_STANDBY_NAMESPACE`
names which one, and the namespace derives every physical name:

| Namespace | Owner | Network | Instances | Network slot |
| --- | --- | --- | --- | --- |
| _(empty, default)_ | the repository's primary checkout | `oe-standby` | `orbit-e2e-standby-<role>` | 1 (`10.232.1.0/24`) |
| `live` | the validation clone `bin/e2e-live` drives | `oe-live-standby` | `orbit-e2e-live-standby-<role>` | 200 (`10.232.200.0/24`) |

Decided on 2026-08-30 for NCK-102, after a `bin/e2e-live` run promoted from the
validation clone into the shared standby: the clone deleted the snapshots the
primary's manifest named, and recovery needed `incus delete` of three instances
and the network by hand. The alternative — writing the promoted generation into
every checkout that tracks the same standby — was rejected: it makes one
checkout write another's state, and it keeps `bin/e2e-live` serialized behind
every other session's topology. Separate standbys make the clone's promotion
invisible to the primary, so `bin/e2e-live` only has to refuse an `ACC-*`
collision of its own.

The namespace is an allowlist, not free text. Each standby needs a distinct
`10.232.<slot>.0/24` subnet, so `HostCapacity` reserves the slot of every known
standby and hands feature topologies only the rest, and it counts the VMs of
both standbys against `ORBIT_E2E_INCUS_MAX_VMS`. Two standbys plus one feature
topology is nine VMs, so the limit may not go below nine. The orphan network
sweep and legacy retirement protect the network and instance names of every
standby, not just this checkout's.

After a merge, `bin/e2e-standby promote ISSUE` makes the reviewer's proved
topology the new generation instead of rebuilding it:

1. It refuses, without touching Incus, when the issue's attempt is not a
   `proved` proof, when the plan (`--plan`, default `proofs/ISSUE.json`)
   carries `"mutates": true`, when `main` in the primary checkout does not
   hold the proved candidate (same commit, or same tree), or when the
   candidate changes the cold base.
2. Under the standby refresh, generation, and issue locks it stops the three
   proved VMs and copies each one (`incus copy --instance-only`) to
   `<standby instance>-next` of the checkout's own standby, attached to that
   standby's network with its fixed address and MAC, with the attempt metadata
   removed, and snapshots
   the copies as `main-<generation>`. The old standby instances are untouched
   until here; a failure deletes the copies and leaves the proved topology
   stopped.
3. It deletes each old standby instance and renames its copy into place, then
   writes the manifest (`main_sha` = the proved candidate, the fingerprint of
   that commit with the Laravel pin the proof converged with, the old
   generation as `previous_generation_id`) and forgets the manifests of the
   replaced instances' snapshots.
4. It releases the proved topology (`bin/e2e-topology release`) and prints the
   generation and the released resources.

The replaced instances take every earlier snapshot with them: after a
promotion only the promoted generation exists on the host. A promotion touches
only the standby of its own namespace, so another checkout's standby is never
affected; a manifest of the same namespace that named an earlier snapshot is
stale and `bin/e2e-standby rebuild` recovers it.

`bin/e2e-standby refresh --main-sha=SHA` is the fallback when no proved
topology exists: it restores the promoted snapshots, converges (including
product re-projection), and re-snapshots in about two minutes, and restores
the previous snapshot when the refreshed standby fails verification.

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

### Stale manifests and rebuild

A manifest that names snapshots or VMs the host does not hold is stale, not
corrupt: the standby was rebuilt, or promoted from another checkout that owns
the same namespace. `status` reports `state: stale` with a `recovery` command
and `refresh` refuses before it mutates anything, both naming
`bin/e2e-standby rebuild --main-sha=<sha>`. Neither writes `standby/corrupt.json`,
and no manual `incus delete` is needed.

`bin/e2e-standby rebuild --main-sha=SHA` is that recovery. It deletes this
checkout's standby VMs, the `-next` copies a failed promotion left behind, and
the standby network itself; it forgets every generation manifest and the
corrupt marker; then it cold-builds the standby at `SHA` from the base image.
It refuses to delete a VM that is not harness-owned or that still carries an
issue's attempt metadata: release that topology first. `SHA` must be what the
checkout's `main` holds, as for `refresh`.

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

`bin/e2e-live <candidate-sha>` is the proof of a harness issue: one run of the
feature flow against a standby built from the candidate.

For ORB-94, focused automated fixtures are the complete evidence for the typed
`app_instances` path, including empty-to-active creation, idempotence,
fail-closed shape validation, source-only re-projection, hydration, and probe
selection. The ORB-94 `bin/e2e-live` run uses current-main product code and
therefore proves only unchanged legacy `instances` integration. It does not
prove typed live behavior. After ORB-94 is `Done` on `origin/main`, ORB-76 owns
the first exact-head live proof of typed empty-to-active `e2e-dev` creation and
cannot close without that result.

The wrapper:

- owns the validation clone at `ORBIT_E2E_VALIDATE_ROOT` (default
  `$HOME/orbit-validate`), cloning it from the calling repository when
  absent, and refuses a dirty clone; the clone is its own primary checkout,
  so its standby generation lives in `<clone>/.e2e/standby/promoted.json`
  (copy the primary's file there once);
- exports `ORBIT_E2E_STANDBY_NAMESPACE=live`, so the clone owns the `live`
  standby and the promote step never touches the primary's;
- refuses while another acceptance (`ACC-*`) topology is live on the Incus host
  (`ORBIT_E2E_INCUS_PROJECT`, `ORBIT_E2E_INCUS_REMOTE`) and holds
  `<clone>/.e2e/locks/live.lock` so only one run drives the clone; a feature
  topology of another issue shares nothing with the run and is no conflict;
- fetches the candidate from the calling repository and runs
  `git checkout -B main <sha>` there, resets the linked worktree
  `.worktrees/acc-1` (branch `acc-1-live`) to the candidate, drops the
  `apps/gateway/.env` a guest wrote into that mounted worktree (it names guest
  paths, and `bin/bootstrap` would try to create them on the host), and runs
  `bin/bootstrap` in both;
- releases a stale `ACC-1` attempt, then refreshes the clone's standby to the
  candidate (`unchanged` when the fingerprint did not move), or runs
  `bin/e2e-standby rebuild` when the clone holds no usable generation, which is
  how the clone's own standby is built the first time;
- exports every `ORBIT_LIVE_*` input, with `proofs/ACC-1.json` as the
  harness plan, and runs the lifecycle suite: acquire, sync, exec, release,
  prove, release, prove again, promote the proved topology into the clone's
  standby, acquire from the promoted generation, exec, release; and
- prints one summary line for the pull request body:
  `lifecycle: passed, <assertions> assertions, <seconds> s`.

The clone's standby is its own: after a run the primary's `bin/e2e-standby
status` and `bin/e2e-topology acquire` keep working with no manual file copy.
The run takes about four minutes on a warm clone, plus the one-time cold build
of the clone's standby.
