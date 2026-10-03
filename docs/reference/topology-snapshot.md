---
title: "Topology snapshot"
description: "How the harness builds, refreshes, and recovers the shared topology snapshot with three VMs and an operator container."
covers:
  - bin/e2e-topology-snapshot
  - apps/e2e/resources/prepared-state.json
  - apps/e2e/resources/guest/**
  - apps/e2e/app/Console/Commands/TopologySnapshot/**
  - apps/e2e/app/E2E/TopologySnapshot*.php
  - apps/e2e/app/E2E/{TopologyConverger,TopologyVerifier,PreparedStateFingerprint,PromotedTopologySnapshotResolver,StaleTopologySnapshotManifest,LegacyTopologySnapshotRecovery}.php
  - apps/e2e/app/E2E/Value/TopologySnapshot*.php
---

# Topology snapshot

This page is for the operator who maintains Orbit's one topology snapshot. Every issue topology and every snapshot-lane scenario clones it. `bin/e2e-topology-snapshot` in the `apps/e2e` harness manages it. The disposable topologies are on the [Incus topology registry](/reference/incus-topologies).

## Identity

A topology snapshot generation is a coordinated set of four Incus snapshots: one on each of the three workload VMs and one on the operator system container. The primary checkout owns it and keeps every guest stopped. It records the generation under `<primary>/.e2e/topology-snapshot/`: `promoted.json`, `generations/<id>.json`, `corrupt.json` after a failed rollback, and the recovery journal. The Nodes carry the [registered profile](/reference/incus-topologies#registered-profile).

| Resource | Name |
| --- | --- |
| Network | `oe-topo-snap`, slot 1, `10.232.1.0/24` |
| VMs | `orbit-e2e-topology-snapshot-gateway`, `-app-dev`, and `-app-prod` |
| System container | `orbit-e2e-topology-snapshot-operator` |
| Base image | `orbit-base-ubuntu-26.04-runtime` |
| Generation ID | The first 12 characters of the main SHA, a hyphen, and the first 12 characters of the prepared fingerprint |
| Snapshot | `main-<generation-id>` on every guest |

### Operator container

Every topology includes the small `operator` system container from the snapshot. It is a roleless Node registered with the topology's Gateway, with a Gateway access grant, its own WireGuard peer, and trust in the topology's Orbit CA. It has the tooling to run `vp dev` in `apps/web`. It hosts no workload Instance and receives no workload role.

Discovery acquisition mounts the task worktree live in the operator at `/home/orbit/orbit`, as it does in gateway and app-dev. It aligns the operator's network identity and WireGuard endpoint with the acquired Gateway, and pins its Gateway URL and trusted CA to that topology. Missing operator configuration is a readiness failure, not permission to use the real fleet. [Web session](/reference/incus-topologies#web-session) defines the dev-server and publication lifecycle.

After deployment of this change, the operator must rebuild the shared topology snapshot on beast to include this container. The new contract requires a generation containing all three VMs and the operator container. Use the ownership-checked [rebuild and recovery](#rebuild-and-recover) commands for the snapshot's actual state; do not manually delete shared resources. The task's disposable environment does not authorize this shared rebuild.

## Prepared fingerprint

`apps/e2e/resources/prepared-state.json` lists the repository files that shape the prepared state. The prepared fingerprint is the SHA-256 of those files' hashes, the cold epoch, the base image alias, the declared epochs, and the topology profile. The sample Laravel release pin is part of it too. When the structural inputs change, `refresh` pins the newest stable `laravel/laravel` tag at `13.0.0` or later for the sample app. Otherwise it keeps the pinned release. The main SHA is the source identity, not a fingerprint input.

Guest convergence scripts are listed as structural inputs. Changing one therefore invalidates the prepared snapshot. A merge that changes none of the structural inputs leaves the snapshot alone.

## Commands

Every command accepts `--json`. `--main-sha=SHA` must be the full SHA of the clean commit that the primary checkout has checked out.

| Command | What it does |
| --- | --- |
| `status` | Prints the promoted generation, `missing`, or `stale` with a `recovery` command. It fails when a guest is not stopped. |
| `fingerprint [--main-sha=SHA]` | Computes the prepared fingerprint of that commit. The default is `HEAD`. |
| `refresh --main-sha=SHA [--allow-cold]` | Refreshes the generation in place when the fingerprint changed. `--allow-cold` permits only the first build. |
| `restore` | Restores the promoted snapshots, leaves every guest stopped, and clears `corrupt.json` |
| `rebuild --main-sha=SHA` | Forgets stale manifests and builds from the base image, when every snapshot resource is absent |
| `recover-legacy --main-sha=SHA` | Proves ownership of the present snapshot resources, deletes them, and builds again |
| `register [--force]` | Registers the primary checkout for its origin, so task workspace clones can [bridge](/reference/incus-topologies#task-workspace-clones) to it. `--force` replaces a live registration. |

`bin/e2e-topology` and `bin/e2e-topology-snapshot` also register the primary checkout when they run in it, or in one of its linked worktrees, while it holds a promoted generation. The first live primary keeps the registration. Another primary replaces it only when the registered checkout is gone or holds no promoted generation.

## Refresh

`refresh` keeps the snapshot current with `main`. Run it from the primary checkout at the requested SHA, with a clean tree. The result is `unchanged`, `promoted`, or `failed`.

When the fingerprint of that commit equals the promoted one, `refresh` checks that the snapshots exist and that every guest is stopped. Then it reports `unchanged` and starts nothing. Otherwise it restores the promoted snapshots, starts the guests, and synchronizes `main`. It converges and verifies, stops the guests, takes the `main-<generation-id>` snapshots, and promotes the new generation. It keeps the preceding generation and every generation that a live topology still uses, and deletes older ones.

A failed refresh keeps the old generation promoted. It stops and restores every guest and keeps the failure evidence. A failed rollback writes `corrupt.json`, and `restore` or `rebuild` must run next. A change to the cold epoch or the base image alias fails with a request for a cold rebuild.

`--allow-cold` permits a first build only when no promoted generation, `corrupt.json`, snapshot network, or snapshot guest exists. It never replaces a promoted generation.

### Convergence

Convergence runs every Orbit step that a fresh topology needs, in this order. The steps marked "Router" run only when the Gateway Node has the `router` role, as in the registered profile.

| Step | Work |
| --- | --- |
| `validate.prerequisites` | Checks the network identity of every Node |
| `align.identity` | Aligns each Node's machine identity with the new network |
| `prerequisites.gateway`, `bootstrap.gateway` | Installs the Gateway prerequisites and bootstraps the Gateway |
| `authorize.gateway-ssh`, `retarget.vpn` | Authorizes Gateway SSH on the workload Nodes and points their VPN at the Gateway |
| `provision.app-dev`, `provision.app-prod` | Adds the workload Nodes and their roles through the Gateway |
| `authorize.app-dev-operator`, `configure.app-dev-cli` | Grants app-dev access to the Gateway and configures its CLI |
| Operator preparation | Registers the operator as a roleless Node, grants Gateway access, configures its WireGuard peer and topology CA trust, and prepares its CLI and web tooling |
| `create.sample-resources` | Creates or reuses the sample Project, Instances, and Route |
| `converge.metrics`, `reproject.product-state`, `refresh.metrics-publication` | Converges Metrics and runs `node:role:add --converge` for every app role, so every projection matches the checkout |
| `await.instance-api-readiness` | Waits until `instance:list --json` answers on app-dev |
| `converge.shared-cluster`, `refresh.private-dns` | Router: joins the three Nodes to `e2e-development`, sets the Gateway Router, and refreshes private DNS |
| `hydrate.sample-apps` | Prepares the sample checkouts, environment files, databases, and production release |
| `converge.sample-fixtures` | Router: creates or checks the [sample resources](/reference/incus-topologies#sample-resources), then records the sample placement again, because a deployment can move the current release |
| `normalize.permissions` | Normalizes file permissions on every Node |
| `compact.storage` | Clears cached APT archives and trims each guest root. On Nodes without `app-dev`, `database`, or `metrics`, it also removes Docker images that no container references. |

Native production HTTPS checks use managed private DNS to reach the Route destination. They verify the Orbit CA certificate and follow the recipe's routing without assuming the Gateway is the Router.

Storage cleanup runs after sample preparation and before readiness and snapshotting. It flushes root filesystem deletions before trimming. It preserves container data, container-referenced images, and the warm images on development, database, and Metrics Nodes. Later roles that need a removed image download it again. Unsupported block discard is skipped; other cleanup failures stop convergence. ZFS cannot reclaim blocks while an older retained snapshot still references them. The existing generation retention rules continue to apply. See [Clean the prepared guests](/solutions/incus-zfs-efficiency#clean-the-prepared-guests).

#### Guest script inputs

Guest convergence reads Projects and Instances through the Orbit CLI. [Project and Instance](/reference/projects#project-and-instance) names those records. The scripts do not read a second name for either record.

| Command | Project and Instance links |
| --- | --- |
| `project:list --json` | `projects`. Each Project has `id`. |
| `instance:list --json` | `instances`. Each Instance has `id` and `project_id`. |
| `route:list --json` and `route:create --json` | `project_id` on an app Route. `target` is `id`, `instance_id`, and `position`, so the scripts read `target.instance_id`. |

The table lists the Project and Instance links only. The scripts also read other fields on the same records, including `slug`, `name`, `node_id`, and `status`.

`create.sample-resources` creates the explicit sample Route with `route:create <instance> e2e-dev.orbit --publication=private`. The first argument is the Instance `id`. The app Route form does not take a Project id, `--target`, `--node`, or `--cluster`. [Create and change targets](/reference/routes#create-and-change-targets) defines that form. The custom proxy form is separate and can take `--node`. The sample Route stays private, as [Sample resources](/reference/incus-topologies#sample-resources) describes.

The harness writes no Caddy file on any Node. Every Caddyfile comes from a [Node Caddy build](/reference/caddy-configuration#node-caddy-build), so Doctor reports no `role.caddy_build_drift`. The sample production site answers over TLS with the Orbit CA leaf that the Gateway publishes. Hydration and verification trust the Orbit root CA.

Guest preparation points `/etc/resolv.conf` at the systemd-resolved stub. It also writes the public upstream servers `1.1.1.1` and `8.8.8.8` into a systemd-resolved drop-in, because runtime resolver settings do not survive a snapshot reboot. Orbit's private DNS routes still apply to private names.

#### Failed guest scripts

A guest convergence script that exits nonzero stops that step. The harness error names the script, the VM, and the exit code. It also includes the tail of that script's stderr.

The harness redacts stderr the same way it redacts output in the [evidence log](/reference/incus-topologies#evidence-log). The tail is the end of that redacted text. It keeps at most the last 20 lines and 2000 characters. When those lines are longer, it keeps the last 2000 characters. A shorter stream is included whole. When stderr is empty, the error still names the script, the VM, and the exit code. Bytes that are not valid UTF-8 are replaced before the tail is cut, so a bad byte does not drop the tail.

A probe that retries reports the last attempt. That error names the same script, VM, exit code, and stderr tail.

A failed sample App state inspection stops verification before the probes. Its error names `converge-sample-app.sh`, the VM, and the exit code, then the same redacted stderr tail. When the inspection returns no result, the error names the script and the VM.

#### Guest script drift

CI checks Orbit CLI calls in the `apps/e2e/resources/guest` shell scripts against the current command signatures. It sees a call written as `"$orbit"`, as Python `orbit()`, as PHP `command([...])` passed to that binary, or as a direct path whose last two segments are `cli/orbit`. The command must exist. Each option must exist on that command. Positional arguments must fit the signature: at least as many as it requires, and no more than it accepts. When Python builds an argument list whose length is not visible in the script, CI checks the arguments it can count.

CI also checks JSON fields those calls read. A PHP snippet may read a field only when the OpenAPI schema defines it on a record that snippet decodes, including a field of a nested object. A Python value that comes from `orbit()`, including a list element and a helper result such as `unique()`, may read a declared field of that value, including a nested object.

A list response may use its CLI collection name and the envelope fields `data`, `meta`, and `request_id`. An object that allows additional properties may use any field. A call or a field that does not match fails CI. [API reference generation](/reference/api-reference) writes that schema.

### Readiness

Verification runs a fixed set of probes on the Nodes. It also checks that the operator container exists, remains roleless, has its Gateway access grant and WireGuard peer, trusts the topology CA, and can reach the selected Gateway through its own topology configuration.

The Caddy probes read only the live Caddyfile and fail when no Node Caddy build wrote it. The production probe requires exactly one copy of each production site. The `metrics.orbit` probe requires the client guard that the build writes after the `bind` line. The guard allows the stored fleet VPN subnet, or `10.44.0.0/24` when none is stored. Each active Instance of a `laravel-app` Project must have exactly one Route, and every other active Instance none.

## Rebuild and recover

### Stale manifests

A manifest that names snapshots or guests that the host does not hold is stale, not corrupt. `status` reports `state: stale` with the recovery command. `refresh` and `restore` refuse before they change anything.

### Rebuild

`rebuild` handles a snapshot whose resources are all gone. It refuses while any snapshot guest, `-next` copy, or the network exists, and it names each present resource. When all are absent, it deletes every manifest and `corrupt.json` and runs a cold build at the SHA.

### Recover

`recover-legacy` handles a snapshot whose resources are still present. It accepts only a readable promoted manifest. It authorizes the snapshot guests, their `-next` copies, and the snapshot network. Each guest must carry the owner and operation metadata, the snapshot network and MAC, no extra disk, and the promoted snapshot. The network may have only those guests as users. Any other evidence fails closed, and a name, prefix, glob, or age never authorizes deletion.

Recovery writes the journal `topology-snapshot/recovery.json` before it changes anything. The journal holds the inventory, its SHA-256 digest, the requested SHA, and the phase history, from `authorized` to `construction_verified` or `failed`. Recovery deletes the guests, the network, and the manifests, and verifies each step in the journal. Then it runs a cold build and verifies the new generation. A retry with the same SHA resumes from the journal when the digest still matches the host. A new recovery archives a finished journal to `topology-snapshot/recoveries/<operation-id>.json`.

Every recovery result includes `error`, `recovery_evidence`, `recovery_phase`, and `next_action`. Never run `incus delete`, remove a manifest, or edit the journal by hand. Recovery depends on that evidence to resume safely.

## Locks

Two host locks under `<primary>/.e2e/locks/` serialize every snapshot change. Each lock file records the owning process, the operation ID, and the time.

| Lock file | Held by |
| --- | --- |
| `standby-refresh.lock` | `refresh`, `restore`, `rebuild`, and `recover-legacy`. Each waits up to 3600 seconds. |
| `standby-generation.lock` | Exclusive while snapshots or the promoted manifest change. `acquire` holds it shared while it copies the snapshots. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The snapshot is a cache

The snapshot only makes acquisition fast. Git stays the authority for source, and each topology mounts its own worktree. A snapshot never replaces the merged repository or the worktree.

### Refresh only when prepared state changes

A merge that leaves the fingerprint unchanged does not start the guests. A refresh rolls the promoted generation forward instead of building from the base image. A cold build after each merge is a rejected alternative, because it is slow and gains nothing.

### Promotion only after every gate

A partial generation is never promoted. A failed refresh keeps the old generation. A generation behind `main` still serves acquisition. A missing snapshot resource or a changed cold base blocks only new acquisitions. A failed refresh never rolls back merged source.

### Recovery by exact inventory

Recovery deletes only resources whose identity and metadata it has proved and journaled. A name alone never authorizes deletion, so recovery cannot remove a resource that it does not own.

### Guest scripts read Project and Instance JSON

Guest scripts read `project_id` and `target.instance_id`, and they create the sample Route with `route:create <instance> <domain>`. Keeping another JSON name for those fields was rejected. The CLI returns one name for each field, and a refresh stops when a script requires a field the response does not have.

### A failed script shows a short redacted tail

The harness error includes the script's stderr, so an operator can see why the step stopped without opening the VM. The tail stops at 20 lines and 2000 characters, so a long log does not replace the error. Keeping the whole stderr was rejected for that reason. The tail uses the same redaction as other harness evidence, so a secret on stderr is not copied into the command result. Sample App state inspection uses that same tail, because a bare inspection failure hides the script error that stopped verification.

### CI compares guest scripts with the CLI and the API

A guest script that calls a removed `orbit` command, passes arguments the signature does not accept, or reads a JSON field the response does not define, fails during convergence, after the VMs are already up. CI rejects that script first. The check reads the scripts and compares each visible call and field with the current signatures and schemas. A separate list of allowed commands was rejected, because that list drifts from the scripts.
