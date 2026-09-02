# Incus topology registry

Incus provides disposable development and proof topologies for issues marked
`Proof: incus`. The lifecycle is governed by
[ADR 0005](../decisions/0005-rolling-incus-development-topology.md) (prepared
topology snapshot, refresh, exact cleanup) and
[ADR 0006](../decisions/0006-topology-led-feature-development.md) (separate
discovery, fresh proof, immutable proved attempts). Automated-only work stays
independent of Incus.

A profile is registered only when the repository provides and verifies all of
these exact-ID operations:

- create a disposable discovery attempt for one Linear issue;
- open a shell, synchronize, verify, and execute against that attempt;
- prove the worktree's HEAD commit on a separate fresh proof attempt while
  discovery remains available;
- inspect or release a retained failed proof independently;
- release the selected attempt's instances, network, and devices; and
- verify that release completed.

Cleanup removes only the attempt's recorded inventory. There is no reaper: a
topology lives until `release`, and `bin/worktree-remove` releases it.

## Where state lives

The harness keeps no state outside the repository checkouts:

- `<worktree>/.e2e/` (gitignored) holds that issue's discovery in
  `attempt.json` and `topology.json`, its separate proof in
  `proof-attempt.json` and `proof-topology.json`, `proof.json` (the last proof
  result), and `log` (one line per harness command). They die with the
  worktree.
- `<primary checkout>/.e2e/` (gitignored) holds the state of the one persistent
  topology snapshot (see [Topology snapshot](#topology-snapshot)):
  `topology-snapshot/promoted.json`,
  the recorded generations under `topology-snapshot/generations/`, a
  `topology-snapshot/corrupt.json` marker while recovery is required, and the
  host locks under `locks/`.
- Capacity is read from `incus list`: the harness-owned VMs that exist and the
  `10.232.<slot>.0/24` subnets in use.

Migration note: before NCK-91 the harness kept journals, evidence, receipts,
leases, and the capacity ledger under `~/.local/state/orbit/e2e`; that
directory is no longer read or written. If that directory still contains the
retired `standby/promoted.json`, copy it into `<primary>/.e2e/standby/` once.
Then use `bin/e2e-topology-snapshot recover-legacy` to migrate it.

## Network ownership

Every Incus network in the `default` project whose name starts with `oe-`
(current harness) or `orbit-e2e-` (legacy harness) belongs to the harness. No
such network may outlive the topology that used it: every
`bin/e2e-topology release` ends with an orphan sweep that deletes each harness
network with an empty `used_by`, except a current topology snapshot network
(`oe-topo-snap`) or its retired pre-rename identity (`oe-standby`). The
sweep holds the host creation lock, so an acquisition between network creation
and its first VM is never swept. The sweep never touches a network outside
those prefixes, a network with users, or another Incus project. Each deleted
name is reported as `networks_reaped` in the release output.

## Supported platform

Orbit supports Ubuntu 26.04 nodes only. The single registered profile is
`gateway_app-dev_app-prod`. All three nodes use Ubuntu 26.04 and the promoted
topology snapshot generation.

## Registered profiles

### `gateway_app-dev_app-prod`

Registered on 2026-08-29 under ADR 0005. ADR 0006 added the attempt-scoped,
separate discovery and proof lifecycle.

| Field | Value |
| --- | --- |
| Profile ID | `gateway_app-dev_app-prod` |
| Ordered roles | `gateway`, `app-dev`, `app-prod` |
| Required assignments | `gateway`: `gateway`, `vpn`; `app-dev`: `app-dev`, `metrics`; `app-prod`: `app-prod` |
| Checkout roles | `gateway`, `app-dev` (guest path `/home/orbit/orbit`) |
| Prepared image | Base image `orbit-base-ubuntu-26.04-runtime`; coordinated snapshots `main-<generation-id>` on `orbit-e2e-topology-snapshot-{gateway,app-dev,app-prod}` |
| Addresses | Incus `.10/.11/.12` on `oe-<issue-hash>`; WireGuard `10.44.0.1/.2/.3` |
| Attempt purpose | At most one `discovery` and one separate `proof` per issue |
| Proof status | `proved` or `diagnosis` |
| State | Discovery: `attempt.json`, `topology.json`; proof: `proof-attempt.json`, `proof-topology.json`; result: `proof.json`; commands: `log` |
| Lifetime | Discovery lasts through development and proof; a failed proof lasts through inspection; promotion or `bin/worktree-remove` releases both |

Issue IDs match `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`.

## Host budget

Each VM is 1 vCPU and 2 GiB (`e2e.incus.cpu`, `e2e.incus.memory`). The harness
refuses to acquire past `e2e.incus.max_vms`, which defaults to 24. The minimum
is nine VMs: three for the persistent snapshot, three for discovery, and three
for proof. At the default, three snapshot VMs and seven disposable topologies
fit. Raise the limit for one run with `ORBIT_E2E_INCUS_MAX_VMS`. Network slots
are not the constraint: the topology snapshot holds slot 1, leaving slots 2
through 200 for disposable topologies.

## Command surface

Every command takes the issue only. Development commands target discovery by
default; `--proof` explicitly selects the retained proof where supported. The
worktree is found at
`<primary>/.worktrees/<issue-lowercase>-*` (exactly one) or given with
`--worktree=PATH`. Every command accepts `--json`. `acquire` and `prove`
refuse a stale promoted topology snapshot.

| Command | Purpose |
| --- | --- |
| `bin/e2e-topology acquire ISSUE WORKTREE` | Create a discovery attempt on the mounted worktree (about 21 to 23 s) |
| `bin/e2e-topology shell ISSUE ROLE [--proof]` | Interactive login shell as `orbit` on discovery, or explicitly on a retained failed proof, with the `exec` environment |
| `bin/e2e-topology exec ISSUE ROLE --argv=JSON [--proof]` | Run one argv vector as the orbit user on discovery, or explicitly on a retained failed proof; `--argv-file=PATH` takes a file holding `{"argv":[...],"stdin":null}` instead (see [Guest commands](#guest-commands)) |
| `bin/e2e-topology sync ISSUE` | Re-verify the mounted source identity of the discovery attempt |
| `bin/e2e-topology verify ISSUE` | Verify discovery |
| `bin/e2e-topology prove ISSUE --plan=PATH` | Prove the worktree HEAD (clean tree) on a fresh proof attempt while discovery remains active; the plan defaults to `proofs/ISSUE.json` (about 33 s) |
| `bin/e2e-topology status ISSUE` | Report discovery and proof together from `<worktree>/.e2e/` without touching infrastructure |
| `bin/e2e-topology release ISSUE [--proof]` | Release discovery by default, or explicitly the proof, verify absence, and sweep orphaned harness networks (`networks_reaped`) |
| `bin/e2e-topology-snapshot status` | Show the promoted topology snapshot generation |
| `bin/e2e-topology-snapshot fingerprint --main-sha=SHA` | Compute the prepared-state fingerprint |
| `bin/e2e-topology-snapshot promote ISSUE` | Make the issue's proved topology the topology snapshot generation, then release proof and discovery (see [Topology snapshot](#topology-snapshot)) |
| `bin/e2e-topology-snapshot refresh --main-sha=SHA` | Maintenance: refresh the topology snapshot in place when no promotable proof exists |
| `bin/e2e-topology-snapshot restore` | Restore the promoted generation and leave it stopped |
| `bin/e2e-topology-snapshot rebuild --main-sha=SHA` | Recover stale state only when every exact configured topology snapshot VM, `-next` VM, and network is absent (see [Stale manifests and rebuild](#stale-manifests-and-rebuild)) |
| `bin/e2e-topology-snapshot recover-legacy --main-sha=SHA` | Prove ownership of present schema-4/5 topology snapshot resources, retain recovery evidence, and cold-build a verified replacement |

`bin/worktree-remove ISSUE slug` releases a retained proof first and discovery
second, then removes the worktree.

### Guest commands

`exec` runs one exact argument vector on one role of discovery by default and
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
`exec`, `sync`, `verify`, and `release` on an issue with no selected attempt
fail closed. `exec --proof` and `shell --proof` accept only a retained
`diagnosis` proof and still run as the `orbit` user. They refuse an immutable
proved topology. `sync` and `verify` continue to target discovery.

### Proof fixtures

Files under `proofs/<issue>/` at the repository root (for example
`proofs/NCK-82/`) are proof-only fixtures beside the plan
`proofs/<issue>.json`. `prove` reads them from the exact candidate commit, never
from the host working tree, and installs them root-owned (`0755` for
executables, `0644` otherwise) at `/var/lib/orbit-e2e/proof/<name>` on every
role of the proof attempt, including `app-prod`, which has no checkout. A proof
plan references a fixture by that guest path on any node:

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
`issue`, `attempt_id`, `candidate_sha`, `plan_sha256` (the normalized complete
plan fingerprint), `actions` (one `{"id","node","exit_code"}` per action that
ran), and `recorded_at`. A plan that declared the topology it
ends with adds `ends_with` and `skipped_probes`. A `diagnosis` adds `error` (the
failed phase and message) and, when a plan action failed, `failed_action`:
`{"id","node","exit_code","stdout_tail","stderr_tail"}` (each tail keeps the
final 4096 bytes). The same object is written to `<worktree>/.e2e/proof.json`.
The proved topology stays immutable through review and merge. Promotion makes
it the new topology snapshot generation, then releases both proof and retained
discovery.

The proof plan file has this shape:

```json
{
  "setup": [{"id": "text", "node": "gateway", "argv": [], "timeout_seconds": 60}],
  "acceptance": [{"id": "text", "node": "app-dev", "argv": [], "timeout_seconds": 60}]
}
```

Every setup and acceptance action must exit `0`. Every nonzero exit makes the
proof a diagnosis and stops later actions. This includes timeout exits `124`
and `137`.

Every proof action runs through a guest deadline. The harness sends `TERM` at
`timeout_seconds`, gives the fixture five seconds to run its cleanup traps,
then sends `SIGKILL` if it still runs. The Incus transport has seven seconds of
headroom beyond the declared deadline. This keeps `TERM` catchable and keeps a
hung cleanup bounded.

An optional top-level `"mutates": true` declares that the plan changes the
topology. Every plan that writes reusable node state must declare it. `promote`
refuses a proved mutating topology.

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
  removed can never become the topology snapshot.

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
investigation. Discovery remains active and stays the default development
target. Use `shell --proof` or `exec --proof` for explicit unprivileged
debugging of the failed proof, then `release --proof` before the next proof.
A proved attempt rejects all proof-targeted shell and command execution.

## Prepared-state limits

Proof plans call fixtures through the staged guest path described under
[Proof fixtures](#proof-fixtures), for example
`/var/lib/orbit-e2e/proof/doctor-proof.sh` from `proofs/NCK-58/`; a fixture
that needs the checkout still runs
only on `gateway` and `app-dev`. `app-prod` actions can call a staged fixture
or a short `sudo bash -c` argument vector.

Known prepared-state limits (first observed on 2026-08-30, NCK-58):

- A topology snapshot refresh restores the promoted snapshots and skips
  provisioning, so every convergence ends with the `reproject.product-state`
  step (NCK-83):
  `converge-sample-app.sh reproject` on `app-dev` first runs
  `node:role:add --converge` for every app role. The legacy `instances` branch
  then runs `instance:php` for every Instance, with development Instances last
  because the app-dev runtime converger publishes the Gateway DNS records for
  every active site. The typed `app_instances` branch validates the persisted
  source-ready `e2e-dev` item for the separate `laravel-typed` App instead. The
  App uses `https://github.com/laravel/laravel.git`, an explicit `public` root,
  and the repository's remote default branch. A legacy `laravel` App may keep
  nullable `main_branch` and `root` values during this transition. Typed
  convergence does not change that App or its legacy Instance and Workspace
  records. This branch is source-only. It never runs `instance:php`, creates a
  Workspace, or prepares an app-prod site. It hydrates and verifies only the
  typed item's recorded `checkout_path`.
  The prepared-state allowlist tracks the App, legacy Instance, typed item,
  Node, and legacy Workspace command closure used by these two deterministic
  branches. A change in either branch therefore invalidates the promoted
  generation. Before legacy re-projection,
  `converge-sample-app.sh internal-tls` on `app-prod` places the e2e
  `local_certs` global block as
  `fragments/00-orbit-e2e-global.caddy` inside the managed Caddy version
  behind the product-owned `/etc/caddy/Caddyfile` symlink (NCK-84); the
  product publisher copies unmanaged fragments forward, so Doctor reports no
  Caddy drift and the harness never replaces the managed symlink.

## Topology snapshot

A topology snapshot is a coordinated set of node snapshots in one promoted
generation. Its owning checkout keeps the source VMs stopped and records the
generation in `<primary>/.e2e/topology-snapshot/promoted.json`. Every `acquire`
and `prove` clones the promoted snapshot `main-<generation>`.

### Topology snapshot identity

Orbit has one persistent topology snapshot:

| Owner | Network | Instances | Network slot |
| --- | --- | --- | --- |
| Primary checkout | `oe-topo-snap` | `orbit-e2e-topology-snapshot-<role>` | 1 (`10.232.1.0/24`) |

Linux limits bridge interface names to 15 characters. The bridge names use
`topo-snap` for that reason. VM, command, state, configuration, and public
documentation names use the full `topology-snapshot` term.

Discovery and proof are separate disposable copies of this snapshot. They can
remain active together. The successful proof stays unchanged through review
and merge. Promotion then replaces the persistent snapshot with that retained
proof and releases both disposable topologies.

`HostCapacity` reserves slot 1, gives disposable topologies slots 2 through
200, and counts every VM against `ORBIT_E2E_INCUS_MAX_VMS`. The orphan network
sweep and legacy retirement protect the current snapshot and its bounded
pre-rename identity.

After a merge, `bin/e2e-topology-snapshot promote ISSUE` makes the reviewer's
proved topology the new generation instead of rebuilding it:

1. It refuses, without touching Incus, when the issue has no `proved` proof,
   when the recorded normalized plan fingerprint differs from the current
   plan, when any declared action is missing or has a nonzero exit, when the
   plan (`--plan`, default `proofs/ISSUE.json`) carries `"mutates": true`, when
   `main` in the primary checkout does not hold the proved candidate (same
   commit, or same tree), or when the candidate changes the cold base.
2. Under the topology snapshot refresh, generation, and issue locks it stops
   the three proved VMs and copies each one (`incus copy --instance-only`) to
   `<current-instance>-next` in the topology snapshot. It uses
   the fixed network, address, and MAC, removes the attempt metadata, and
   snapshots the copies as `main-<generation>`. The old topology snapshot
   instances are untouched until here. A failure deletes the copies and
   leaves the proved topology stopped.
3. It deletes each old topology snapshot instance and renames its copy into
   place. It then writes the manifest (`main_sha` = the proved candidate, the
   fingerprint of that commit with the Laravel pin the proof converged with,
   and the old generation as `previous_generation_id`) and forgets the
   manifests of the replaced instances' snapshots.
4. It releases the proved topology and retained discovery, then prints the
   generation and all released resources.

The replaced instances take every earlier snapshot with them: after a
promotion only the promoted generation exists on the host. A manifest that
names an earlier snapshot is stale, and `bin/e2e-topology-snapshot rebuild`
recovers it.

`bin/e2e-topology-snapshot refresh --main-sha=SHA` is a maintenance command when
no proved topology exists. It restores the promoted snapshots, converges
(including product re-projection), and re-snapshots in about two minutes. It
restores the previous snapshot when verification fails. Merge closeout does not
substitute refresh for a missing or invalid retained proof.

Guests are reachable from the Gateway only over WireGuard after role
provisioning; the harness repairs cloned WireGuard endpoints through root
`incus exec` (`retarget-vpn.sh`) and never depends on public SSH. After that
repair, `orbit:node-retarget` updates the public record over WireGuard; see
[node retarget](node-retarget.md).

`bin/e2e-topology-snapshot refresh --allow-cold` permits only initial
construction when no promoted generation or topology snapshot resources
exist. It never replaces a promoted generation. An operating-system,
base-image, cold-epoch, or corrupt topology snapshot change requires a
separate reviewed disaster-recovery procedure before the harness mutates
Incus resources.

### Stale manifests and rebuild

A manifest that names snapshots or VMs the host does not hold is stale, not
corrupt. The topology snapshot was rebuilt or replaced. `status` reports
`state: stale` with a `recovery` command, and `refresh` refuses before it
mutates anything. Both name
`bin/e2e-topology-snapshot rebuild --main-sha=<sha>`. Neither writes
`topology-snapshot/corrupt.json`, and no manual `incus delete` is needed.

`bin/e2e-topology-snapshot rebuild --main-sha=SHA` is the ordinary
absent-resource recovery. It inventories all exact configured base VM names,
their `-next` names, and the configured network while it holds the topology
snapshot refresh lock. It refuses before any Incus or manifest mutation
when one of those resources exists. The refusal lists every present exact name
and directs the operator to `recover-legacy`. This rule does not depend on
whether the promoted manifest is missing, malformed, or readable. When all
exact resources are absent, rebuild forgets stale manifests and cold-builds the
topology snapshot at `SHA`. `SHA` must be the clean commit checked out on
`main`.

### Legacy topology snapshot disaster recovery

Use this supported command when ordinary rebuild reports present resources:

```bash
bin/e2e-topology-snapshot recover-legacy --main-sha=<full-main-sha>
```

Recovery accepts only a readable, unbound schema-4 or schema-5 promoted
manifest. It takes one complete inventory from the configured Incus remote,
project, and storage pool. It authorizes only the configured topology snapshot
identity and these exact resources:

- each configured base VM or its exact `-next` promotion copy;
- Orbit owner and valid operation metadata;
- complete issue and attempt metadata when a `-next` copy still carries its
  promotion attempt identity, and no feature issue metadata on a base VM;
- the configured network, deterministic role MAC, expected storage pool, and
  no unexpected host disk;
- each promoted snapshot on its base VM;
- the exact bridge subnet, NAT, DHCP, IPv6, and dnsmasq configuration; and
- network users that are exact inventoried VMs in the configured project.

Missing, duplicated, changing, incomplete, unavailable, or foreign evidence
fails closed. Recovery does not adopt a resource from its name alone. It does
not use a prefix, glob, age, or operator-supplied Incus name as deletion
authority.

The same command migrates the retired pre-rename identity. If only
`.e2e/standby/` state or the old `*-standby-*` Incus resources exist, the
resolver validates and removes that exact identity, then cold-builds the
current topology snapshot. It refuses when current and retired identities
coexist. It also refuses an incomplete or invalid retired
`.e2e/standby/recovery.json` journal because the original code must complete an
in-progress transaction first. A completed journal remains as migration
evidence and does not block the migration.

Before mutation, recovery writes the canonical inventory and its SHA-256 digest
to `<primary>/.e2e/topology-snapshot/recovery.json`. It retains the promoted and
recorded manifests there. The journal records `authorized`, then pending and
verified boundaries for instances, network, manifests, and construction. Final
evidence records the new generation, requested SHA, stopped exact VMs,
configured network, and absence of every `-next` VM. A later recovery archives
completed evidence as
`.e2e/topology-snapshot/recoveries/<operation-id>.json` before it starts a new
authorized record.

One topology snapshot refresh lock remains held from inventory and authorization
through teardown, cold construction, promotion, final verification, and the
final journal write. Its physical lock key stays compatible with pre-rename
processes, so an old and a new harness process cannot mutate the same resources
at the same time. A retry with the same SHA resumes
only from retained inventory whose digest and observed exact state still agree.
It accepts already verified deletions. If construction was interrupted, it
records
`construction_cleanup_pending`, removes only resources stamped with that exact
construction operation, verifies absence, and records
`construction_cleanup_verified` before it constructs again. Any other change
fails closed.

Every nonzero JSON result includes `error`, `recovery_evidence`,
`recovery_phase`, and `next_action`. Use these actions:

| Diagnostic | Next action |
| --- | --- |
| The refresh lock is busy | Retry the same `recover-legacy --main-sha=SHA` command after the current topology snapshot operation ends. |
| The retained record is incomplete | Preserve all remaining resources and rerun the same SHA so recovery can resume its verified boundaries. |
| Inventory or ownership evidence is missing, foreign, ambiguous, unavailable, or changed | Preserve the resources, correct the reported evidence problem, and retry the same recovery command. |
| The promoted manifest is missing, malformed, bound, or not schema 4/5 | Preserve the named resources and restore the matching readable legacy manifest before retrying. |
| Recovery is complete | Run `bin/e2e-topology-snapshot status`. |

Do not run `incus delete`, remove a manifest, or edit retained recovery evidence
by hand. Those actions discard the authority or retry evidence that makes this
workflow safe.

## Harness changes

Product feature issues do not change `apps/e2e` or `bin/e2e-*`. A harness
change needs its own issue. Before implementation, the repository owner and the
implementer must agree on the requested behavior and the issue-specific proof.

Use the normal discovery and separate proof topology when they can prove the
change. If the change affects acquire, release, proof, promotion, or recovery,
the issue contract must name the additional focused lifecycle checks. The
successful proof must still be immutable and promotable before closeout. Orbit
does not create a validation clone, a second persistent snapshot, or a generic
nested lifecycle run.
