---
title: "Incus topology registry"
description: "How the harness leases, prepares, and releases disposable Incus topologies, and how it runs on-demand scenarios."
covers:
  - bin/{e2e-topology,e2e-clone-bridge,e2e-task-cleanup,e2e-scenarios}
  - apps/e2e/config/e2e.php
  - apps/e2e/app/Console/Commands/{Topology,Scenario}/**
  - apps/e2e/app/E2E/{TopologyAcquirer,TopologyReleaser,IssueTopologyConstructor,AcquisitionRollback,DiscoveryGuestPreparer,WorktreeSynchronizer,WorktreeLocator,HostCapacity,OrphanNetworkSweep,IncusNetworkLifecycle,EvidenceLog}.php
  - apps/e2e/app/E2E/{Scenario,SnapshotScenario,ColdTopology}*.php
  - apps/e2e/app/E2E/Value/{Topology,Guest,Evidence,Scenario}*.php
  - apps/e2e/resources/guest/converge-sample-{app,fixtures}.sh
---

# Incus topology registry

This page is for the contributor, agent, or reviewer who runs Orbit on disposable Incus machines. The `apps/e2e` harness leases one topology per issue, and `bin/e2e-topology` controls it. `bin/e2e-scenarios` runs regression scenarios on demand. The guest convergence fixtures establish the sample application's current state and are fingerprinted in the prepared topology data. Every topology starts from the shared [topology snapshot](/reference/topology-snapshot). The [proving-on-incus](https://github.com/nckrtl/orbit/blob/main/.agents/skills/proving-on-incus/SKILL.md) skill covers the working habits.

## Registered profile

An issue topology uses the three-Node profile `gateway_app-dev_app-prod`. The [extension](#the-app-prod-2-extension) adds a fourth Node.

| Field | Value |
| --- | --- |
| Physical Nodes | `gateway`, `app-dev`, `app-prod`, in this order |
| Roles | `gateway`: `gateway`, `vpn`, `websocket`, `router`; `app-dev`: `app-dev`, `metrics`, `database`; `app-prod`: `app-prod`, `ingress` |
| Checkouts | `gateway` and `app-dev` mount the worktree at `/home/orbit/orbit`. `app-prod` has no checkout. |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`. The hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>`. |
| Instances | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<node>`, with the first 8 characters of the attempt ID |
| Addresses | Incus `.10`, `.11`, and `.12`. WireGuard `10.44.0.1`, `.2`, and `.3`. |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`. `acquire` and `sync` require it in the worktree's branch name, such as `TASK-58` on branch `task-58`. |

The three Nodes share the active `e2e-development` Cluster. It has no Cluster TLD. Gateway is its Router, and app-prod is its Ingress. Development traffic goes from the Gateway Router to app-dev. Public production Routes enter app-prod and pass through the Gateway Router to the workload.

A change to this recipe does not change the saved snapshot. Acquisition clones the saved generation and does not provision new roles. `acquire` verifies readiness against the assignments that the generation records, so it passes on a generation with other assignments. A full `sync` and `verify` check the current recipe, and they fail on that generation. Run a snapshot `refresh` to adopt a new recipe.

## Sample resources

Convergence builds the sample workloads through the Orbit CLI, never by editing the Gateway database.

| Resource | Content |
| --- | --- |
| Laravel Project | Instances `e2e-dev` on app-dev and `e2e-prod` on app-prod. `e2e-dev` has one explicit private Route, `e2e-dev.orbit`. |
| Database Processes | `e2e-mysql` (`mysql:8.4`), `e2e-postgres` (`postgres:18-alpine`), and `e2e-valkey` (`valkey/valkey:8-alpine`) on app-dev. Each is a Node-owned Docker Process with a persistent volume, bound to `10.44.0.2`. |
| Connections | `e2e-dev` uses MySQL and Valkey. `e2e-prod` uses PostgreSQL and Valkey. Valkey uses logical database `1` with driver `redis`. |
| Background work | The Process `e2e-queue` and the Schedule `e2e-scheduler` on `e2e-dev` |
| Non-web Projects | `e2e-monorepo` (`monorepo`) and `e2e-package` (`laravel-package`), each with one development Instance and no Route |

Credentials stay in the guest's protected configuration and in the Gateway. Repeated convergence keeps credentials, volumes, environment keys, and database contents. It refuses a resource whose identity conflicts with the expected one.

Guest convergence reads only the current CLI names: `projects`, `instances`, `project_id`, and `target.instance_id`. It creates the `e2e-dev.orbit` Route with `route:create <instance> e2e-dev.orbit --publication=private`. The first argument is the Instance id. Sample `APP_URL` values store `https://{{instance.domain}}`. [Project and Instance](/reference/projects#project-and-instance) defines those names, and [Guest script inputs](/reference/topology-snapshot#guest-script-inputs) lists the commands.

CI checks those calls and the JSON fields the scripts read against the current CLI signatures and the OpenAPI schemas. A failed script reports the script, the VM, the exit code, and the redacted stderr tail. [Failed guest scripts](/reference/topology-snapshot#failed-guest-scripts) states that error.

## Discovery topology

An issue holds one discovery topology at a time. `acquire` creates it and `release` removes it. Its state lives under `<worktree>/.e2e/`, in `attempt.json` and `topology.json`, and it dies with the worktree.

A lease names the issue, the attempt ID, the purpose, the operation ID, and the acquisition time. There is no reaper. A topology lives until someone releases it.

### The app-prod-2 extension

A topology can carry one extra production Node, `app-prod-2`. It is the recipe `gateway_app-dev_app-prod_app-prod`.

| Physical Node key | Source | Incus address | WireGuard address | Roles |
| --- | --- | --- | --- | --- |
| `app-prod-2` | The `orbit-base-ubuntu-26.04-runtime` image | `.13` | `10.44.0.4` | `app-prod` |

The three standard Nodes still come from the snapshot. The harness builds `app-prod-2` from the base image inside the same attempt and network, and it records the image fingerprint. It refuses when the image changes between the check and the build. Convergence provisions `app-prod-2` as an `app-prod` Node. It stays outside the `e2e-development` Cluster and gets no Instance. An extended topology reserves four VMs.

The committed scenario `snapshot-extension` uses the extension. A discovery topology gets it only when `<worktree>/.loop/proof/<ISSUE>.json` declares `"extension": "app-prod"` (see [what acquire reads](#what-acquire-reads)).

### What acquire reads

`acquire` checks that the worktree belongs to this repository and to the current user, and that its branch names the issue. It refuses a worktree without the Gateway, CLI, and SDK `vendor/` autoloaders.

It clones the promoted generation, even when that generation is behind `main`. It refuses when no generation is promoted, when the generation's identity does not match its manifest, or when a snapshot resource is missing. It also refuses when the worktree's `apps/e2e/resources/prepared-state.json` changes the cold epoch or the base image alias of the generation. The snapshot must then be [built again](/reference/topology-snapshot#rebuild-and-recover) from the new base.

`acquire` also reads two optional files in the worktree. A malformed file makes it fail.

| File | Effect |
| --- | --- |
| `.loop/flow.json` | `{"schema":1,"flow":"proof"}` makes `acquire` refuse a generation that does not match current `main`. Without the file, the flow is `discovery`. |
| `.loop/proof/<ISSUE>.json` | `"extension": "app-prod"` adds `app-prod-2`. `acquire` and a full `sync` then converge the whole topology before readiness. |

### Capacity

The harness counts capacity from `incus list`, never from a ledger. It counts the VMs that carry `user.orbit.e2e.owner=orbit-e2e` and the `10.232.<slot>.0/24` subnets in use.

| Setting | Value |
| --- | --- |
| VM size | 1 vCPU and 16 GiB root disk (`e2e.incus.cpu`, `e2e.incus.root_size`). Memory defaults to 1.5 GiB for `gateway`, 1 GiB for `app-prod` and `app-prod-2`, and 2 GiB for other Nodes (`e2e.incus.memory`). |
| VM budget | `e2e.incus.max_vms`, default 24, minimum 9. `ORBIT_E2E_INCUS_MAX_VMS` sets it for one run. |
| Network slots | Slot 1 belongs to the topology snapshot. Disposable topologies take slots 2 to 200. |
| Incus scope | `e2e.incus.remote`, `e2e.incus.project`, and `e2e.incus.storage_pool`, from `ORBIT_E2E_INCUS_REMOTE`, `ORBIT_E2E_INCUS_PROJECT`, and `ORBIT_E2E_INCUS_STORAGE_POOL`. The defaults are `local`, `default`, and `orbit-e2e`. The remote must be `local`, because network creation and deletion also change host firewall rules. |

Every `incus` call carries the configured project. The harness reserves the recipe's VMs, three or four, before it creates a network or a VM. It refuses `acquire` when the budget cannot hold them, and it names the count and the limit. At the default budget, seven topologies fit beside the snapshot.

Memory limits apply when the harness creates or clones a VM. The registered three-Node profile has a 4.5 GiB configured budget. `ORBIT_E2E_INCUS_MEMORY=2GiB` overrides every Node's limit for a run. The cold recipe's `operator` and `extra` Nodes keep the 2 GiB default. These limits do not change the CPU allocation or reduce the application's CPU work. See the [ZFS efficiency measurements](/solutions/incus-zfs-efficiency) for the evidence and workload limits.

### Locks

Every command except `status` and `shell` holds the lock `topology-<ISSUE>` under `<primary>/.e2e/locks/`. Topology creation holds the host lock `topology-create` from network creation until every VM exists.

## Commands

`acquire` takes the worktree as an argument. Every other command finds the issue among the registered Git worktrees by branch or directory name. It needs exactly one match, or `--worktree=PATH`. Every command accepts `--json`. A failure prints `{"state":"failed","error":"..."}` and exits nonzero.

| Command | What it does |
| --- | --- |
| `acquire ISSUE WORKTREE` | Creates discovery from the saved generation, applies pending Gateway migrations, and verifies readiness |
| `shell ISSUE NODE` | Opens a login shell as `orbit` on one Node |
| `exec ISSUE NODE --argv=JSON [--timeout=SECONDS] [--record=LABEL]` | Runs one argument vector as `orbit` on one Node and waits for it. The timeout defaults to 60 seconds, with a maximum of 3600. |
| `spawn ISSUE NODE NAME --argv=JSON` | Starts a long-lived process as the transient unit `orbit-e2e-NAME.service` and returns at once |
| `logs ISSUE NODE NAME [--since=TIME] [--lines=N] [--record=LABEL]` | Prints the unit's output with precise timestamps, also after the process ends |
| `kill ISSUE NODE NAME` | Stops the unit. Its output stays readable. |
| `sync ISSUE [--quick]` | Proves the mount, applies pending Gateway migrations, and verifies readiness. `--quick` skips verification. |
| `verify ISSUE` | Verifies readiness and records the report |
| `status ISSUE` | Reports the state files without touching Incus |
| `release ISSUE` | Removes the topology and verifies that it is gone |

`NODE` is a physical Node key: `gateway`, `app-dev`, `app-prod`, or `app-prod-2` on an extended topology. `--argv-file=PATH` can replace `--argv` on `exec` and `spawn`. The file holds `{"argv":[...],"stdin":null}`, and only `exec` accepts stdin. The harness refuses both options together.

### Guest commands

`exec` runs the vector through `runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite PROGRAM ARGS`. No shell profile loads. `argv[0]` must resolve on the guest `PATH` or be an absolute path. It cannot start with `-` or contain `=`.

The harness links `apps/cli/orbit` to `/usr/local/bin/orbit` on each checkout Node, so `orbit` resolves by name. Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`. `shell` opens the same environment with `bash -l`. It starts in `/home/orbit/orbit` on a checkout Node and in `/home/orbit` on app-prod.

With `--json`, `exec` prints `{"state":"executed","exit_code":N,"stdout":"...","stderr":"..."}`. Without it, `exec` prints the guest stdout. It exits `0` only when the guest command does.

Incus waits for every process that an `exec` session starts. A command that leaves a background process holds `exec` open until the timeout. Start a long-lived process with `spawn` instead. `spawn` runs `systemd-run --collect` with the same user, directory, and environment as `exec`. A name has 1 to 40 lowercase letters, digits, or hyphens. Kill a unit before you spawn another one with the same name. `spawn` works on discovery only. The harness log `<worktree>/.e2e/log` records every `exec`, `spawn`, and `kill`, with the redacted argv and the exit code.

### Evidence log

`exec --record=LABEL` and `logs --record=LABEL` append one entry to `<worktree>/.e2e/evidence.log`. A label has 1 to 80 letters, digits, spaces, dots, dashes, or underscores, and is not blank. The harness checks the label before it touches Incus.

```text
=== 2026-09-24T06:40:00.123Z viewer after crash node=app-dev exit=0 duration=1234ms end=2026-09-24T06:40:01.357Z
$ orbit node:list --json
--- stdout
...
--- stderr
...
```

The header gives the start time, the label, the Node, the exit code, the duration, and the end time, in UTC with milliseconds. The `$` line is the argv as shell-quoted words. The harness redacts the argv and the output as it does in `<worktree>/.e2e/log`. The log is append-only. The harness creates it with mode `0600` in the `0700` `.e2e/` directory, and refuses a symbolic link in that directory path.

Recording never changes the command's output or exit code. A failed append prints a warning on stderr. The log can hold output that the redactor does not recognize as a secret, so treat it like the rest of `.e2e/`. `bin/worktree-remove` deletes it with the worktree, so copy what you need first.

## Discovery mount

`acquire` attaches the worktree to `gateway` and `app-dev` as the Incus disk device `orbit-source`. It is a read-write virtiofs share at `/home/orbit/orbit`. A host edit is live in both guests at once. Run `sync` only after a migration or a change to a guest helper script.

Guests never run Composer. Host `bin/bootstrap` owns `vendor/`, and `acquire` refuses a worktree without the Gateway, CLI, and SDK autoloaders. `acquire` places the preserved Gateway `.env` in the worktree when it is absent. When the worktree has a `.env`, the harness sets only its `ORBIT_GATEWAY_CHECKOUT` to `/home/orbit/orbit/apps/gateway`.

Before it reports readiness, `acquire` aligns each cloned Node with the new network. It updates the stored public SSH addresses and points the stored VPN endpoints at the acquired Gateway. It keeps WireGuard keys, private addresses, DNS settings, SSH ports, roles, and workloads. A missing or conflicting identity fails acquisition before readiness.

### Gateway schema

`acquire` and `sync` run `php artisan migrate --force --no-interaction` from the mounted Gateway as `orbit`. This applies only the migrations that the mounted source declares. It does not run Gateway bootstrap, install dependencies, or provision roles. The harness does not roll back a migration that fails halfway.

A migration error makes `acquire` or `sync` exit nonzero. A failed `acquire` removes its own resources. A failed `sync` keeps the topology and its last good source binding in `topology.json`. Fix the source and run `sync` again. Then check `php artisan migrate:status` on the Gateway and run `verify`.

`sync --quick` proves the mount, installs the current guest helper scripts, and applies the migrations. It skips the readiness probes and prints a note that readiness was not verified. It leaves `topology.json` and the guest source marker at the last full `sync`. So `status` shows that binding, and `verify` refuses a mount that differs from it until the next full `sync`.

## Task workspace clones

A task workspace is an independent clone, not a linked worktree. It holds neither the topology snapshot nor its locks. So `bin/e2e-topology` in such a clone runs the command through a bridge worktree of the primary checkout.

| Step | What happens |
| --- | --- |
| Find the primary | Reads the invoking user's registry, then `/var/lib/orbit/e2e-primary-checkouts/{origin key}`. The primary owner is the invoking user or the owner of the invoking checkout. Without a live registration, the command runs in the clone. |
| Update the bridge | Checks out the clone's HEAD in `<worktree root>/<clone directory>-e2e`, on branch `<clone branch>-e2e`. The worktree root is the primary's `orbit.worktreeRoot`, default `/fast/worktrees/orbit`. |
| Mirror the work | Copies the clone's modified and untracked files, removes its deleted tracked files, and mirrors each `vendor/` directory |
| Run | Runs the bridge's `bin/e2e-topology`, with each clone path replaced by the bridge path and `--worktree` set to the bridge |

The origin key is the SHA-256 of the origin URL's lowercase host and its path, so every clone of one repository finds the same primary.

The mirror leaves the bridge's other ignored files, such as `.e2e/`, `.env`, and Gateway storage, because the harness and the guests write them. So a file that a guest writes into the mount appears in the bridge, not in the clone. The evidence log is in the bridge too. Every command mirrors the clone first, so any command, such as `status`, pushes an edit. Set `ORBIT_E2E_BRIDGE=0` to run in the clone itself. `bin/e2e-topology-snapshot` never bridges, because snapshot operations belong to the primary checkout.

In a task workspace on branch `task-58`, run `bin/e2e-topology acquire TASK-58 .`, then the other commands with `TASK-58`. The topology snapshot [registers](/reference/topology-snapshot#commands) the primary checkout. When the Gateway ends a task, the Orbit Project's configured teardown step runs the installed copy of `bin/e2e-task-cleanup` to remove the task's bridge. [Tasks](/reference/tasks#complete-and-cleanup) describes that cleanup. Release the topology before the task ends, because bridge removal does not release it.

The helper checks the checkout identity and origin, the promoted snapshot marker, and the repository identity. It reads the same registration paths as the bridge, including the shared directory, and it accepts a primary owned by the checkout owner. A primary registered by the managed user is found after teardown runs as `orbit-worker`. The worker also needs to traverse and write the primary's `.git` and the bridge worktree root. [Primary registration](/reference/instance-setup#primary-registration) records the copy and that grant.

It targets `task-{id}-e2e` under that primary's worktree root. It removes a registered worktree only when both its path and branch match. A bridge checked out on another branch stays. It deletes the task bridge branch only when no worktree has it checked out, and deletes only `refs/orbit/e2e-bridge/task-{id}`. It never removes another task's bridge or releases a topology.

An absent bridge, or a checkout with no registration, is success. A registration that exists is not that case. A cleanup command failure exits nonzero and makes teardown retain the task checkout and Instance for retry. Removal of a matching bridge, its unused branch, and its staging ref is idempotent. The helper does not alter the checkout that runs it or that checkout's worktrees.

## Release

`release` checks each VM against the attempt's ownership metadata. It force-stops the running VMs, deletes them, and verifies that they are gone. It checks the network's ownership just before it deletes the network. Then it drops the lease and the record. The output lists `released`, `already_absent`, and `networks_reaped`.

An ownership mismatch stops the release and keeps every unrelated resource. The attempt record stays for diagnosis. A retry continues from the same exact target.

Every network named `oe-*` or `orbit-e2e-*` belongs to the harness and never outlives its topology. Every release ends with an orphan sweep. The sweep selects networks by those name prefixes, not by ownership metadata. It deletes each one in the configured project whose `used_by` list is empty, except the snapshot networks `oe-topo-snap` and `oe-standby`. It holds the `topology-create` lock, so it never deletes a network that a starting acquisition just created.

`bin/worktree-remove ISSUE` cleans up after a merge. It works in this order:

1. It refuses a dirty worktree and a branch that `origin/main` does not contain.
2. It refuses while `.e2e/` holds a successful proof.
3. It queues a TIA cache refresh.
4. It releases a failed proof topology, then the discovery topology, and removes the worktree.
5. It deletes the merged branch and prunes the worktree list.

## On-demand scenarios

`bin/e2e-scenarios` runs committed regression scenarios for one exact commit. Each scenario starts in one lane. The cold lane builds a fresh topology from the `orbit-base-ubuntu-26.04-runtime` image. The snapshot lane clones the current promoted generation.

| Command | Result |
| --- | --- |
| `cold [CANDIDATE_SHA] [--scenario=ID ...]` | Runs the cold scenarios, or only the named ones in the given order |
| `snapshot [CANDIDATE_SHA] [--scenario=ID ...]` | Runs the snapshot scenarios, or only the named ones |
| `run [CANDIDATE_SHA] --workers=COUNT [--scenario=ID ...]` | Runs cold and snapshot scenarios with at most `COUNT` workers at once. `cold` and `snapshot` run their scenarios one after another. |
| `cleanup RUN_ID SCENARIO_ID ATTEMPT_ID` | Retries exact cleanup from the retained attempt record |

The catalog holds five committed scenarios.

| ID | Lane | Recipe | What it proves |
| --- | --- | --- | --- |
| `cold-four-node` | cold | Cold acceptance, four Nodes | Orbit builds and verifies the whole topology from the base image, and cleanup removes it |
| `cold-construction-cleanup` | cold | Cold acceptance, four Nodes | After an injected source failure during construction, cleanup removes exactly the recorded resources |
| `snapshot-lifecycle` | snapshot | Registered, three Nodes | A clone of the snapshot syncs, converges, passes readiness, runs a bounded app-dev action, and verifies |
| `snapshot-isolation` | snapshot | Registered, three Nodes | A fresh clone does not contain a marker that an earlier attempt wrote |
| `snapshot-extension` | snapshot | Registered plus `app-prod-2` | The [extension](#the-app-prod-2-extension) Node has its recorded identity, capacity, and image fingerprint |

The checkout must be clean. An optional SHA must be the full lowercase `HEAD`. Before it changes Incus, the command validates the catalog, every selected scenario, and the worker count. It refuses an unknown or repeated ID, a scenario from the other lane, an invalid recipe, a missing action deadline, and an invalid declared input.

Each worker gets its own attempt, network, VMs, state path, and Pest process. One Pest test is one independent flow, and a flow stops at its first failed step. Admission holds the `topology-create` lock while it counts the recipe's VMs against the shared budget and picks a network slot. After that, `run` workers go on in parallel. A failure in one worker does not cancel another.

The cold flow starts from the unchanged base image and installs no PCOV before construction. A snapshot flow checks the promoted generation first. A missing, stale, or changed generation gives `infrastructure-error` and skips the exercise. A snapshot flow never changes the generation, its VMs, or its manifest.

A snapshot flow mounts no worktree. It clones the three Nodes, and builds `app-prod-2` for the extension. Before dependency installation, it repairs the cloned Gateway addresses and WireGuard endpoints, so DNS can use the new Gateway. It synchronizes the exact candidate commit from Git into the checkout Nodes and checks the guest commit. It converges the whole topology, checks the commit again, and runs the readiness probes. Then it runs the exercise and a final verification.

Scenario resources carry the issue `SCN-1` and the extra metadata `user.orbit.e2e.run`, `user.orbit.e2e.scenario`, and `user.orbit.e2e.recipe`. VM names are `orbit-e2e-scn-<run>-<scenario>-<attempt>-<node>`, with 8 characters of the run ID, 6 hex characters of the SHA-256 of the scenario ID, and 8 characters of the attempt ID. The network is `oe-` plus 12 hex characters of the SHA-256 of `<run>:<scenario>:<attempt>`. These VMs count against the same budget as issue topologies.

The cold acceptance recipe separates the physical Node from its roles.

| Node key | Address | Checkout | Roles |
| --- | --- | --- | --- |
| `gateway` | `.10` | yes | `gateway`, `vpn` |
| `operator` | `.11` | yes | `app-dev`, `metrics` |
| `app-prod` | `.12` | no | `app-prod` |
| `extra` | `.13` | no | none |

The harness writes each result and the aggregate under `<primary>/.e2e/scenarios/runs/<run-id>/`. A result records the commit, the run, scenario, and attempt IDs, the lane, the recipe and definition fingerprints, the phase timings, the action outcomes, the verification, the cleanup, and the recovery command.

| Outcome | Meaning |
| --- | --- |
| `passed` | Every action and the verification passed, and cleanup completed |
| `failed` | An action or a product assertion failed |
| `blocked` | A required run-scoped checkpoint was unavailable. No current scenario uses a checkpoint. |
| `infrastructure-error` | Construction, reporting, verification, or cleanup could not give a valid result |

The command exits nonzero when any scenario did not pass, but only after it writes the whole aggregate. On interruption, it starts no new work, asks each worker to clean up, and records each unstarted flow as `infrastructure-error`. A cleanup failure keeps the original outcome, reports `infrastructure-error`, and lists the remaining resources and the recovery command.

An operator runs this command explicitly. It is not part of `bin/test`, CI, review, or merge, and it never changes the topology snapshot.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Disposable topologies from one snapshot

Each issue gets fresh VMs on an isolated network, cloned from one prepared snapshot. Isolated networks let several topologies reuse the same addresses without conflict. Cloning takes about a minute, while a cold build takes much longer. Work on shared long-lived machines is a rejected alternative, because one change can leave state that breaks the next.

### Exact ownership

Every resource records its owner, issue, attempt, and operation when the harness creates it. Setup and cleanup change only recorded resources, after they check the live metadata again. They never select by prefix, glob, age, broad query, or unset variable. A resource without the expected metadata is outside the topology, even when its name looks right. So a mistake cannot delete another person's machine or a production resource. The one exception is the orphan network sweep. It selects by the harness name prefixes, and deletes only networks that no VM uses.

### Topologies are never production

Topologies never reuse production resources or credentials. Evidence from a topology never authorizes a production deployment.

### A live mount

Discovery mounts the worktree, so an edit reaches the guests at once. Copying source into the guests after every edit is a rejected alternative, because it is slow and hides which source the guests run.

### Three Nodes

The profile gives the Gateway, app-dev, and app-prod roles the room they need, with Router, database, WebSocket, and Ingress on those same three VMs. A fourth VM in every topology is a rejected alternative, because it raises the cost of every session for tests that need only one development host. Router on app-prod would work, but it keeps production routing on one VM and tests fewer network hops. The `gateway` role conflicts with `app-dev`, so those two roles need separate VMs.

A test that needs two production Nodes uses the `app-prod-2` extension instead. The extension is a separate recipe, so the snapshot and every other topology keep three VMs. Putting `app-prod` on the Gateway or app-dev Node is not possible, because those roles conflict.

### Long-lived processes as systemd units

systemd already supervises, logs, and stops processes. Detaching inside `exec` with `nohup`, `setsid`, or a double fork is a rejected alternative, because it depends on shell skill and keeps no output. A separate process supervisor on the guests is also rejected.

### Chosen evidence entries

The harness records an `exec` only when you give it a label. Most commands are exploration, and a proof summary needs chosen, labelled entries. The log is plain text, not JSON, because people read and grep it, and JSON hides line breaks in output.

### A quick sync keeps the verified binding

`sync --quick` does not record the new source. Otherwise `status`, `verify`, and the guest source marker would describe a binding that no readiness check has seen. A full `sync` stays a complete readiness check.

### A bridge for task workspace clones

The harness expects every topology to belong to a linked worktree of the primary checkout. The bridge gives a clone that shape. Teaching the harness to accept clones is a rejected alternative, because it changes about ten identity checks and the guest mount evidence. Mirroring with `rsync --delete` is also rejected, because it deletes the files that the harness and the guests keep in the mount. Finding the primary through the main cache store is rejected, because topologies would then depend on published test caches.

### Cleanup stays with the Project

The bridge layout belongs to this repository, so the Orbit Project's teardown runs the installed helper. Putting that removal in the Gateway was rejected, because the engine would then know one repository's worktrees. A failed cleanup exits nonzero and teardown keeps the checkout for retry. The helper does not release the topology.

### Scenarios stay outside delivery

Scenarios are regression evidence for one commit, and they run on demand. They never gate review or merge. A cold scenario proves that Orbit builds from the unchanged base image, so it installs nothing before construction.

Topology fixtures identify Instances by their current API fields and use the `instance` morph alias for Instance-owned runtime records.
