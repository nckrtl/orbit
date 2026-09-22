---
title: "Incus topology registry"
description: "How the harness registers, leases, retains, and releases disposable Incus topologies for discovery and proof."
---

# Incus topology registry

This page is for contributors, agents, and operators who use disposable Incus topologies from the `apps/e2e` harness. It answers which topology an issue or on-demand scenario starts from, which state it owns, and which `bin/e2e-topology` or `bin/e2e-scenarios` command controls it. The plan a proof runs is on [Proof plans](/reference/proof-plans). The persistent snapshot supplies ordinary topology clones and snapshot-lane scenarios and is described on [Topology snapshot](/reference/topology-snapshot).

## Discovery and proof

Orbit's [feature review](/reference/implementation-loop#orbit-review-on-incus) uses an authorized Incus environment to reproduce the submitted behavior. The commands below describe existing discovery and retained proof resources. [ADR 0005](/decisions/0005-rolling-incus-development-topology) governs the rolling snapshot. Retained proof mechanics remain available for explicit inspection and resource recovery.

## Registered profile and issue extension

Orbit registers the three-Node profile `gateway_app-dev_app-prod`. A discovery or proof attempt uses that profile unless its issue proof plan declares `"extension": "app-prod"`. The declaration adds the physical Node key `app-prod-2` to that attempt and accepts no other extension value. A proof plan can instead declare `"snapshot_replacement": true`; that declaration keeps the registered three-Node profile and selects cold construction for proof only. [ADR 0040](/decisions/0040-extend-issue-proof-with-one-app-prod-node) governs the extension, and [ADR 0037](/decisions/0037-promote-fresh-three-node-topology-snapshots) governs the replacement.

| Field | Value |
| --- | --- |
| Ordered roles | `gateway`, `app-dev`, `app-prod` |
| Required assignments | `gateway`: `gateway`, `vpn`, `websocket`, `router`; `app-dev`: `app-dev`, `metrics`, `database`; `app-prod`: `app-prod`, `ingress` |
| Checkout roles | `gateway` and `app-dev` at `/home/orbit/orbit`; `app-prod` has no checkout |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`; the hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>` |
| Instances | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<role>`, with 8 characters of the attempt ID |
| Addresses | Incus `.10`, `.11`, `.12`; WireGuard `10.44.0.1`, `.2`, `.3`, fixed on every clone; acquisition aligns the Gateway's stored network identity and each peer's saved and running endpoint with the cloned network |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}` and appears in the worktree branch name |

The three registered Nodes share the active `e2e-development` Cluster, which keeps its existing name and has no Cluster TLD. Gateway is its Router; app-prod is its Ingress. The explicit private sample Route keeps `e2e-dev.orbit`. Development traffic crosses from Gateway Router to app-dev. Public production Routes, when added, enter app-prod and pass through Gateway Router to the workload. The `database` role ensures Docker only; database containers and additional sample applications are separate resources. [ADR 0114](/decisions/0114-expand-the-three-node-incus-cluster) explains the layout.

Convergence expands the existing one-Node sample Cluster through Orbit commands. It refuses foreign members, another Cluster membership, an unexpected Router, or another Ingress. Repeated convergence reuses the same Cluster and roles. Router assignment retries at most five times on a lost Gateway response or a busy Router operation, because a Gateway Caddy upgrade can interrupt its own API connection. Other command failures stop convergence; retry reads the recorded membership. The optional app-prod-2 remains a workload-only extension outside this default Cluster until its scenario attaches it.

Changing this recipe does not promote a snapshot. Ordinary acquisition still clones the saved generation and does not provision the new roles. A generation with the old assignments cannot pass the new profile's readiness checks. Prepare and inspect a disposable topology before explicitly updating the shared snapshot.

An extended attempt keeps the three cloned Nodes and constructs one Node from the configured `orbit-base-ubuntu-26.04-runtime` image.

| Physical Node key | Source | Incus address | WireGuard address | Expected roles |
| --- | --- | --- | --- | --- |
| `app-prod-2` | Generic base image | `.13` | `10.44.0.4` | `app-prod` |

The attempt record stores the normalized construction declaration, the complete physical Node inventory, its snapshot generation or generic-base inputs, and every image alias and fingerprint used for cold construction. Discovery and proof construct separate `app-prod-2` VMs and never adopt one from another attempt. A replacement proof constructs all three registered Nodes from the generic base and never adopts records, source, or runtime from the promoted generation.

Convergence gives `app-prod-2` active app-prod services and a usable PHP runtime, with PHP-FPM and Caddy active. The `e2e-dev` Instance stays on `app-dev`, and `e2e-prod` stays on `app-prod`. The extra Node has no Instance. The extension creates no legacy Instance or Workspace and no Route target or other graph edge that creates multi-target routing.

## Prepared database resources

The disposable topology being prepared for the next snapshot runs three Node-owned Docker Processes on app-dev. Standard sample convergence creates or validates these resources through Orbit; ordinary acquisition obtains them only after that prepared generation is saved. The database role alone does not create them.

| Process and connection | Image | Database | Private address | Data volume |
| --- | --- | --- | --- | --- |
| `e2e-mysql` | `mysql:8.4` | `orbit_e2e` | `10.44.0.2:3306` | `e2e-mysql-data` |
| `e2e-postgres` | `postgres:18-alpine` | `orbit_e2e` | `10.44.0.2:5432` | `e2e-postgres-data` |
| `e2e-valkey` | `valkey/valkey:8-alpine` | Logical database `1`, registered with driver `redis` | `10.44.0.2:6379` | `e2e-valkey-data` |

Each Process uses `unless-stopped`, a persistent Docker volume, and a listener bound to the Node's WireGuard address. Same-Node attachments use that explicit Docker bind address; wildcard listeners use loopback. SQL connections use a separate application user. Gateway prerequisites include the MySQL and PostgreSQL PDO drivers so database queries can use these connections. Credentials remain in the guest's protected configuration and the Gateway registry; they do not belong in Git. Convergence preserves credentials and persistent volumes on repeat runs, refuses conflicting resource identities, and verifies authenticated queries.

The development Instance uses MySQL and Valkey; production uses PostgreSQL and Valkey. An Instance-owned queue worker and a Schedule exercise managed background work. The worker follows development hibernation; readiness accepts a sleeping worker only when its desired state is running and Doctor confirms healthy Process state. Small monorepo and Laravel package Projects each have a development Instance without a web Route.

The sample repository is a Laravel Project with Instances `e2e-dev` and `e2e-prod` on their respective workload Nodes. Sample convergence uses `project:list` and `project:create` with the explicit `laravel-app` type. CLI collections use `projects` and `instances`; native sample state uses `shape: instances`. The harness reads previous `app_instances` envelopes and state during snapshot upgrades, but writes the current names. Snapshots with Workspace samples use the distinct `workspaces` state marker. Database table names remain unchanged.

Convergence discovers commands by name, reuses existing production Instances even when old saved metadata is incomplete, and refreshes placement metadata from the live Instance. Production readiness must run for native samples. Gateway preparation updates the runtime checkout path in its preserved environment. Private DNS reloads atomically published catalogs, including replacements within the same second. Doctor reads protected Caddy projections through the managed sudo channel and treats WebSocket access as part of the shared WireGuard member rule. Development hydration preserves the registered source identity. Route verification expects one Route for web-serving Projects and none for non-web Project types.

## Topology states

Each topology is one attempt with a purpose, a lease, and a record under `<worktree>/.e2e/`, which dies with the worktree.

| Purpose | Created by | Files | Ends with |
| --- | --- | --- | --- |
| `discovery` | `acquire` | `attempt.json`, `topology.json` | `release`, `promote`, or `bin/worktree-remove` |
| `proof` | `prove` | `proof-attempt.json`, `proof-topology.json`, `proof.json`, captured evidence, and review records | Exact release after replacement, abandonment, or successful closeout refresh |
| `candidate-convergence` | `candidate` | `candidate-attempt.json`, `candidate-topology.json`, `candidate-convergence.json` | `release --candidate` or `promote` |

A lease names the issue, attempt ID, purpose, operation ID, acquisition time, topology extension, and construction declaration. The extension is `null` or `app-prod`, the replacement flag is boolean, and both are stored before the harness creates a network or VM. A proof result is `proved` or `diagnosis`; a candidate result is `converged` or `diagnosis`. A `diagnosis` topology stays alive for inspection and can never become proved.

A successful proof becomes reviewable only after the harness captures its complete evidence. Its proof topology then stays alive through review and closeout. The proof result, captured evidence, review records, `proof-inputs/`, `equivalence/`, and the `log` file survive release. [ADR 0056](/decisions/0056-retain-proof-topologies-for-interactive-review) governs this retained-proof review lifecycle.

`status` reports each active purpose and the proof's capture and review-evaluation state. An issue holds at most one attempt per purpose: `acquire` refuses a second discovery, `prove` refuses while a proof attempt exists, and `candidate` refuses while a candidate-convergence attempt exists.

## Capacity budget and leases

Capacity comes from `incus list`, never from a ledger: the harness counts the VMs that carry `user.orbit.e2e.owner=orbit-e2e` and the `10.232.<slot>.0/24` subnets in use.

| Setting | Value |
| --- | --- |
| VM size | 1 vCPU, 2 GiB memory, 16 GiB root disk (`e2e.incus.cpu`, `e2e.incus.memory`, `e2e.incus.root_size`) |
| VM budget | `e2e.incus.max_vms`, default 24, minimum nine; `ORBIT_E2E_INCUS_MAX_VMS` overrides it for one run |
| Network slots | Slot 1 belongs to the topology snapshot; disposable topologies take slots 2 through 200 |
| Incus scope | `e2e.incus.remote`, `e2e.incus.project`, and `e2e.incus.storage_pool`, set from `ORBIT_E2E_INCUS_REMOTE`, `ORBIT_E2E_INCUS_PROJECT`, and `ORBIT_E2E_INCUS_STORAGE_POOL`; defaults `local`, `default`, and `orbit-e2e` |

Every `incus` call carries `--project` from `e2e.incus.project` and lists resources on `e2e.incus.remote`, so the capacity count, the topologies, and the orphan sweep stay inside that project. The harness reserves three VMs for a standard attempt and four for an extended attempt before it creates a network or VM. It refuses `acquire`, `prove`, and `candidate` when the requested count would exceed the budget, naming the count and the limit. At the default, up to seven standard disposable topologies fit beside the topology snapshot; each extended attempt consumes one additional VM. A failed construction releases its reservation through exact resource cleanup.

There is no reaper: a topology lives until the operator releases it. Every command except `status` and `shell` holds the lock `topology-<ISSUE>` under `<primary>/.e2e/locks/`. Topology creation holds the host lock `topology-create` from network creation until the complete VM inventory exists.

## Commands

`acquire` takes the worktree as a positional argument. Every other command finds the issue among registered Git worktrees by branch or directory name, requires exactly one match, or takes `--worktree=PATH`. Internal worktrees use the configurable external base; ordinary Git worktrees are also supported. Every command accepts `--json`, and a failure prints `{"state":"failed","error":"..."}` with a nonzero exit.

| Command | What it does |
| --- | --- |
| `acquire ISSUE WORKTREE` | Creates discovery from the saved generation, applies pending Gateway migrations from mounted source, verifies readiness, and refuses duplicate discovery or a missing vendor tree |
| `shell ISSUE NODE [--proof --review-action=ID --required]` | Opens a login shell as `orbit` on one physical Node key of discovery, a retained diagnosis, or a captured successful proof; successful-proof use starts a separate interactive review action |
| `exec ISSUE NODE --argv=JSON [--proof --review-action=ID --required]` | Runs one argument vector as `orbit` on one physical Node key; `--argv-file=PATH` replaces `--argv`; successful-proof use records its result as a required or exploratory review action |
| `sync ISSUE` | Proves the mount, applies pending Gateway migrations from mounted source, and verifies readiness |
| `verify ISSUE` | Verifies discovery readiness and records the report |
| `prove ISSUE [--plan=PATH]` | Proves the clean worktree HEAD on a fresh proof topology; a declared snapshot replacement starts from the generic base, and the plan defaults to `.loop/proof/ISSUE.json` |
| `capture ISSUE [--plan=PATH]` | Captures and archives complete successful proof evidence without releasing the topology, then permits interactive review |
| `review ISSUE [--complete=ACTION --result=passed\|failed --finding=TEXT]` | Completes an interactive action when supplied, then evaluates required and exploratory review records |
| `equivalence ISSUE [--plan=PATH]` | Compares the clean HEAD with the retained proof using the plan that defaults to `.loop/proof/ISSUE.json`, then writes an immutable report; see [Equivalence outcomes](/reference/proof-plans#equivalence-outcomes) |
| `candidate ISSUE` | Converges and verifies the accepted head on a candidate-convergence topology after an `equivalent` report that requires it |
| `closeout ISSUE --candidate=SHA --artifact=SHA --merge=SHA --main-sha=SHA` | Verifies the accepted merge, refreshes the snapshot or installs its declared clean replacement, records closeout, and releases the exact retained proof topology only after the snapshot step succeeds |
| `status ISSUE` | Reports the state files, capture identity, retained topology, and review evaluation without touching Incus |
| `release ISSUE [--proof\|--candidate] [--replace\|--abandon] [--recover-extension=none\|app-prod --expected-attempt=ID]` | Releases the selected topology and verifies absence. A successful proof requires explicit replacement or abandonment; ordinary closeout owns post-refresh release. Recovery options identify one exact legacy lease. |

`bin/worktree-remove ISSUE` releases the proof topology only after its closeout guard permits cleanup, then releases discovery and removes the worktree. [ADR 0049](/decisions/0049-keep-delivery-artifacts-off-the-merge-head) describes the artifact refs used by retained proof. Captured proof evidence and review records remain in the primary archive after worktree removal.

### Guest commands

`exec` prints `{"state":"executed","exit_code":N,"stdout":"...","stderr":"..."}` with `--json` and the guest stdout without it, and exits `0` only when the guest command does. `--argv='["orbit","doctor","--json"]'` is an inline JSON array of strings; `--argv-file=PATH` names a file holding `{"argv":[...],"stdin":null}` when the vector needs stdin. The harness refuses both at once. Commands select physical Node keys, so `app-prod` selects the cloned Node and `app-prod-2` selects the constructed Node of an extended attempt. A shared role name never selects multiple Nodes.

The vector runs through `runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite PROGRAM ARGS`. No shell profile loads: `argv[0]` must resolve on the guest `PATH` or be absolute, and it cannot start with `-` or carry `=`. The harness links the checkout's `apps/cli/orbit` to `/usr/local/bin/orbit` on every checkout Node, so `orbit` resolves by name. Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`. `shell` opens the same environment with `bash -l`, in `/home/orbit/orbit` on a checkout Node and in `/home/orbit` on either app-prod Node; `exec` always runs in `/home/orbit`.

`exec --proof` and `shell --proof` accept a `diagnosis` proof under its existing debugging path. They accept a proved topology only after complete capture and bind each successful-proof action to its issue, candidate, and attempt in the separate review record. The harness records whether an action is required or exploratory and its result. An interactive shell action stays incomplete until `review` records its result and finding. Required failures and incomplete required records prevent approval; exploratory failures remain distinct.

## Discovery mount

`acquire` attaches the worktree to `gateway` and `app-dev` as the Incus disk device `orbit-source`, a virtiofs (virtual I/O filesystem) share mounted read-write at `/home/orbit/orbit`. Every host edit is live in both guests. Acquisition and `sync` also install the current guest helper scripts on every physical Node before readiness checks, including three-node topologies cloned from an older snapshot. Guests never run Composer: host `bin/bootstrap` owns `vendor/`, and `acquire` refuses a worktree without the Gateway, CLI, and SDK autoloaders. The harness places the preserved Gateway `.env` into the worktree when it is absent there. The mount device is part of the attempt inventory, so exact release removes it.

Before reporting readiness, acquisition updates the three cloned Nodes' stored public SSH addresses and retargets stored VPN endpoints that name the snapshot Gateway. It keeps omitted endpoints omitted, preserves endpoint ports, and aligns each peer's saved and running endpoint with the Gateway's provisioning inputs. A later peer configuration therefore selects the acquired Gateway without a manual override. Acquisition uses the current harness preparation code, not a cached copy from the snapshot.

This preparation preserves WireGuard keys and private addresses, DNS settings, SSH ports, roles, workloads, and unrelated configuration. It does not reprovision roles or workloads. Missing, invalid, or conflicting clone identity fails acquisition before readiness; an endpoint that names an unrelated host is a conflict, not permission to replace custom configuration. Acquisition rolls back only that attempt's resources.

### Gateway schema readiness

Acquisition and `sync` run `php artisan migrate --force --no-interaction` from the mounted Gateway checkout before they publish readiness for that source. The harness runs the command as `orbit` with `HOME=/home/orbit`, `ORBIT_HOME=/home/orbit/.orbit`, `ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway`, and `DB_DATABASE=/home/orbit/.orbit/gateway.sqlite`. This step applies only the migrations that mounted source declares. It does not run Gateway bootstrap, install dependencies, configure an application, provision roles or sample workloads, or change the promoted snapshot. Repeating `sync` leaves migrations that Laravel has already recorded unchanged.

A migration error or timeout makes `acquire` or `sync` exit nonzero. Failed acquisition runs exact-attempt cleanup. An ownership refusal keeps the lease and exact recovery target for `release`; do not bypass that refusal. Failed `sync` keeps the same discovery resources and its last successful `topology.json` source binding. Guest source markers describe the current mount input and are not readiness receipts. `status` continues to show the last successful binding, and standalone `verify` refuses when the live worktree differs from that binding.

Inspect the failed command output, `bin/e2e-topology status ISSUE --worktree=WORKTREE --json`, the retained lease, and the guest migration ledger before retrying. A migration can complete some changes before a later migration fails, and the harness does not roll the Gateway database back. After successful acquisition cleanup, correct the mounted source or its environment and rerun `bin/e2e-topology acquire ISSUE WORKTREE --json`. If acquisition cleanup retains a lease, resolve its ownership refusal before another acquisition. For failed `sync`, correct the mounted source or its environment and run `bin/e2e-topology sync ISSUE --worktree=WORKTREE --json`. Confirm a zero exit, inspect `php artisan migrate:status --no-interaction` on the Gateway, and run `bin/e2e-topology verify ISSUE --worktree=WORKTREE --json` before using the new source binding.

Gateway schema readiness means the mounted Gateway has no pending declared migrations and the topology readiness probes pass. It does not prove application health or feature acceptance. A missing application dependency, application key, or application database remains operator-owned setup and does not expand this schema step.

## Release and network ownership

`release` reads the target extension from the lease and uses a matching complete topology record when one exists. A complete record must match the lease's issue, purpose, attempt, and extension. A legacy lease without an extension remains compatible when it has a complete matching topology record. A legacy lease without that record is ambiguous, so ordinary release refuses before Incus access and does not infer a target from a current proof plan.

An operator recovers an ambiguous lease with both `--recover-extension=none|app-prod` and `--expected-attempt=ID`. Discovery is the default; `--proof` or `--candidate` selects that purpose. The harness requires the full 32-character attempt ID and matching issue, purpose, attempt, and existing target evidence. Before it accepts `none`, it also requires the exact attempt-derived `app-prod-2` VM to be absent. Matching recovery atomically adds only the extension to the lease, and the same input can retry after partial cleanup. Conflicting input cannot replace the stored target.

After target selection, `release` checks each VM against the attempt's ownership metadata, force-stops the running ones, deletes them, and verifies they are gone. It then checks the network's ownership immediately before it deletes the network, drops the lease and record, and unpins a proved commit's Git ref only when no retained evidence refers to it.

A successful proved attempt also requires a lifecycle guard: explicit replacement, explicit abandonment, or release by `closeout` after successful snapshot refresh from the verified merge. A failed refresh keeps every proof Node and the complete attempt lease for retry. A retry continues from the same exact target after partial cleanup. An ownership conflict preserves every unrelated resource and keeps the attempt record for diagnosis. The output lists `released`, `already_absent`, and `networks_reaped`.

The retained proof topology can exercise lease files, identity validation, capture and review records, and refusal before transport from inside a guest. Actual Incus deletion is a host boundary: an issue that changes release selection uses a separately reviewed host rehearsal bound to the same candidate and leaves the captured proof and review evidence unchanged.

Every Incus network named `oe-*` or `orbit-e2e-*` belongs to the harness and never outlives its topology. Every release ends with an orphan sweep that deletes each harness network in the configured Incus project with an empty `used_by`, except `oe-topo-snap` and `oe-standby`. The sweep holds the `topology-create` lock, so a network created moments before its first VM is never swept.

## Declared cold snapshot replacement

### Construction and evidence

A proof plan with `"snapshot_replacement": true` grants replacement authority before proof construction. The proof attempt remains issue-owned and uses normal setup, acceptance, manifest, capture, review, and exact-release records. It constructs only the registered Gateway, app-dev, and app-prod Nodes from the recorded generic base and exact candidate. Convergence must produce native Instance samples and Project-owned Routes, and verification refuses legacy sample state or any inventory other than those three Nodes.

The promoted generation stays stopped and unchanged during construction, proof, capture, and review. After the verified merge, closeout constructs a clean replacement from merged main and the recorded inputs. It verifies that clean topology before it starts the installation transaction, so reviewer changes to the retained proof topology cannot enter the shared snapshot.

### Installation and cleanup

Installation records the replacement, current generation, exact resources, and each swap step. It prepares the complete stopped candidate generation before it replaces any promoted resource. Failure before the swap removes only recorded candidate resources. Failure during the swap either restores the prior usable generation or leaves an explicit recovery record and no success result. Successful installation leaves one stopped three-Node promoted generation, and later ordinary discovery and proof acquire from it.

Successful closeout removes only the recorded clean replacement resources and the replaced snapshot resources after it verifies the installed generation. Explicit abandonment removes only the recorded issue replacement. Both paths retain the original captured proof and interactive review records.

### Other cold paths

This declared cold replacement uses the issue-proof lifecycle, not the disposable cold-scenario lifecycle, ordinary refresh, or disaster recovery. Cold scenarios remain disposable and have no issue-proof or promotion authority. Refresh converges the current promoted generation in place from main. `rebuild` and `recover-legacy` restore availability when snapshot state is absent or inconsistent; they cannot reclassify their result as issue proof.

## On-demand scenarios

`bin/e2e-scenarios`, governed by [ADR 0019](/decisions/0019-run-disposable-incus-scenario-lanes), runs committed cold-lane or snapshot-lane scenarios for one exact commit. It supports these invocations:

| Command | Result |
| --- | --- |
| `bin/e2e-scenarios cold [CANDIDATE_SHA]` | Runs every cold scenario for the current clean checkout. The optional full lowercase SHA must equal `HEAD`. |
| `bin/e2e-scenarios cold [CANDIDATE_SHA] --scenario=ID` | Runs only the named scenario. Repeat `--scenario` to select more scenarios in the given order. |
| `bin/e2e-scenarios snapshot [CANDIDATE_SHA]` | Runs every snapshot scenario for the current clean checkout. The optional full lowercase SHA must equal `HEAD`. |
| `bin/e2e-scenarios snapshot [CANDIDATE_SHA] --scenario=ID` | Runs only the named snapshot scenario. Repeat `--scenario` to select more scenarios in the given order. |
| `bin/e2e-scenarios run [CANDIDATE_SHA] --workers=COUNT` | Runs every cold and snapshot scenario with at most the positive worker count active at once. |
| `bin/e2e-scenarios run [CANDIDATE_SHA] --workers=COUNT --scenario=ID` | Runs selected cold and snapshot scenarios together. Repeat `--scenario` to preserve the requested aggregate order. |
| `bin/e2e-scenarios cleanup RUN_ID SCENARIO_ID ATTEMPT_ID` | Retries exact cleanup from the retained attempt record and verifies that its inventory is absent. |

Before it creates run state or changes Incus, the command resolves the commit, the complete catalog, every selected scenario, and the worker count. It rejects an unknown or repeated scenario ID, a scenario from another lane on a lane-specific command, an invalid recipe, a missing action deadline, an invalid declared input, and a missing, malformed, or non-positive worker count on `run`. With no filter, a lane-specific command selects every committed scenario in that lane, while `run` selects the complete catalog in its stable order.

The combined runner starts no more than the requested number of scenario workers. Each worker receives a separate attempt, operation, network, VM inventory, state path, guest filesystem, application data, and Pest process. Creation holds the host creation lock while it admits the recipe's actual VM count, selects one free network slot, and creates the recorded resources. Cold, snapshot, and variable-size recipes count against the same live Incus VM budget as every other harness topology. The lock makes admission atomic, while guest preparation, convergence, exercise, verification, reporting, and cleanup can overlap after creation.

A product failure, infrastructure error, or refused cleanup in one worker does not cancel another runnable flow. The runner fills free worker slots until every selected flow has a result, then writes one aggregate in selection order and returns its final exit code. On interruption, it stops launching work, asks every active worker to stop and clean its recorded resources, retries exact recovery where a worker cannot write a result, and records every unstarted flow explicitly as an `infrastructure-error`. It writes the complete non-passing aggregate before it returns. Recovery revalidates exact ownership and does not change an unrelated topology.

The faithful cold flow starts from the unchanged `orbit-base-ubuntu-26.04-runtime` image alias, synchronizes the exact candidate, converges the declared product roles, and verifies the complete inventory. It performs no pre-construction PCOV instrumentation and runs no PCOV collection. Normal product provisioning may install the packaged PCOV extension as part of the app-dev runtime.

A snapshot flow records the current promoted generation and verifies its three coordinated snapshots before construction. It creates a fresh attempt-scoped network, clones the three registered Nodes from that exact generation, synchronizes the exact candidate, converges the topology, and verifies readiness before its exercise action. A missing, stale, or changed generation produces an `infrastructure-error`, skips the exercise, and still runs exact cleanup. Every repeat clones a new attempt and cannot observe application or filesystem changes from an earlier run.

A snapshot scenario can declare additional Nodes from the configured `orbit-base-ubuntu-26.04-runtime` image. The attempt records each physical Node, the reserved capacity and network slot, the base image alias and fingerprint, and the promoted source generation. Construction refuses a pre-existing network or VM instead of adopting it. Cleanup removes only the attempt's exact recorded resources and leaves foreign resources unchanged.

The command writes each result and the complete aggregate under `<primary>/.e2e/scenarios/runs/<run-id>/`. Each result records the candidate, run, scenario, attempt, lane, normalized recipe and definition fingerprints, declared-input fingerprints, phase timings, action outcomes, verification, diagnostics, cleanup, remaining exact resources, and recovery command. A definition or declared-input change produces a different fingerprint. The aggregate contains one result for every selected scenario and is written after every runnable flow finishes.

The JSON aggregate and the standard Pest report use these outcomes:

| Outcome | Meaning |
| --- | --- |
| `passed` | Every required action and verification passed, and exact cleanup completed. |
| `failed` | A scenario action or product assertion failed. |
| `blocked` | The result schema reserves this status for an unavailable required run-scoped checkpoint. The current catalog has no checkpoint-dependent flow and does not produce this status. |
| `infrastructure-error` | Construction, reporting, verification infrastructure, or cleanup could not produce a valid scenario result. |

The process exits nonzero when any selected scenario is not `passed`, but only after it writes the complete aggregate. Cleanup failure keeps the original outcome, reports `infrastructure-error`, and retains the remaining exact inventory and recovery command. Recovery revalidates the recorded owner, run, scenario, attempt, and operation before deleting anything. It never selects a resource by prefix, age, glob, or an unresolved value.

This command is explicitly invoked by an operator. It is not part of `bin/test`, discovery acquisition, feature proof, review, merge, topology-snapshot promotion, or continuous integration. It writes no issue-proof or promotion receipt. A cold scenario does not read the persistent topology snapshot. A snapshot scenario reads and clones one verified promoted generation, but it never changes the generation, its VMs, its manifest, or another attempt. Nightly or pull-request triggers and affected-flow selection are separate work.

The cold acceptance recipe separates physical Node identity from product role assignment:

| Node key | Initial purpose | Address | Checkout | Expected roles |
| --- | --- | --- | --- | --- |
| `gateway` | Gateway | `.10` | yes | `gateway`, `vpn` |
| `operator` | Operator | `.11` | yes | `app-dev`, `metrics` |
| `app-prod` | Workload | `.12` | no | `app-prod` |
| `extra` | Extension | `.13` | no | none |

VM names, MAC addresses, and fixed IPv4 addresses derive from the attempt and physical Node key. Product operations resolve roles through the recipe, so `app-dev` targets the `operator` VM while `extra` remains present and roleless. The canonical feature recipe still uses the physical keys `gateway`, `app-dev`, and `app-prod`, preserving every persistent topology-snapshot identity.

Persistent topology-snapshot construction and this disposable flow call the same typed cold constructor. The persistent caller keeps its fixed slot, permission checks, manifest, corrupt-state, recovery, and promotion rules. The disposable caller receives an attempt-scoped network and VM inventory, reserves capacity for the recipe's actual four VMs, and writes no promoted manifest or topology-snapshot state.

Construction failure triggers exact cleanup. Cleanup first validates the owner and operation metadata of every present recipe resource, then stops and deletes VMs in reverse recipe order, deletes the network, and verifies absence. A resource owned by another operation refuses the entire deletion instead of being adopted or removed.
