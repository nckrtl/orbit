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

This page is for the contributor, agent, or reviewer who runs Orbit on disposable Incus machines. The `apps/e2e` harness leases one topology per issue, and `bin/e2e-topology` controls it. `bin/e2e-scenarios` runs regression scenarios on demand. The guest convergence fixtures establish the sample application's current state and are fingerprinted in the prepared topology data. Every topology starts from the shared [topology snapshot](/reference/topology-snapshot). The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill covers the working habits.

## Topologies on the reviewer's request

An Orbit task group has no topology by default. Workspace provisioning never acquires one. The reviewer decides whether discovery needs a topology and ends a turn with `topology_requested` through the [turn receipt](/reference/tasks#request-a-topology). An implementer asks the reviewer through the existing consult instead. Agents run as `orbit-worker` without sudo; they do not acquire topologies themselves.

Orbit consumes the receipt, acquires `TASK-<group>` as the managed user, and resumes the same requesting reviewer thread in its original review, consult, or relay context with the acquisition result or failure. A consult stays open and its implementer stays paused during acquisition; a resource request neither answers it nor escalates it to the operator. The resumed reviewer answers normally under the original context's outcome and cause rules.

Acquisition changes host firewall rules. Each group holds at most one topology, shared across its subtasks and review turns. A repeated request uses that group's topology, not another one. Acquisition failure or absence of a topology never prevents approval: these topologies support discovery, not mandatory proofs. Orbit releases the topology when it removes the group's workspace.

## Registered profile

An issue topology has three VMs with the profile `gateway_app-dev_app-prod` and one small Incus system container, `operator`. Every topology includes the operator. The [extension](#the-app-prod-2-extension) adds a fourth workload VM, not another operator.

| Field | Value |
| --- | --- |
| Workload VMs | `gateway`, `app-dev`, `app-prod`, in this order |
| Operator | `operator`, a small roleless system container on the same topology network |
| Roles | `gateway`: `gateway`, `vpn`, `websocket`, `router`; `app-dev`: `app-dev`, `metrics`, `database`; `app-prod`: `app-prod`, `ingress` |
| Checkouts | `gateway`, `app-dev`, and `operator` mount the worktree live at `/home/orbit/orbit`. `app-prod` has no checkout. |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`. The hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>`. |
| Instances | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<node>`, with the first 8 characters of the attempt ID |
| Workload addresses | Incus `.10`, `.11`, and `.12`. WireGuard `10.44.0.1`, `.2`, and `.3`. The operator has its own non-conflicting addresses and WireGuard peer. |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`. `acquire` and `sync` require it in the worktree's branch name, such as `TASK-58` on branch `task-58`. |

The three workload Nodes share the active `e2e-development` Cluster. It has no Cluster TLD. Gateway is its Router, and app-prod is its Ingress. Development traffic goes from the Gateway Router to app-dev. Public production Routes enter app-prod and pass through the Gateway Router to the workload.

A change to this recipe does not change the saved snapshot. Acquisition clones the saved generation and does not provision new roles. `acquire` verifies readiness against the assignments that the generation records, so it passes on a generation with other assignments. A full `sync` and `verify` check the current recipe, and they fail on that generation. Run a snapshot `refresh` to adopt a new recipe.

### The operator guest

The operator is a roleless Node registered with the topology's Gateway, with a Gateway access grant and its own WireGuard peer in that topology. It has no workload role and hosts no sample Instance. It trusts the topology's Orbit CA and mounts the worktree live, so host edits reach `apps/web` without a copy or rebuild. Its CLI profile and web proxy configuration refer only to this topology. Missing configuration is an error, not permission to use the real Gateway or another user's credentials.

The [topology snapshot](/reference/topology-snapshot#operator-container) includes the operator container and its prepared configuration. Acquisition aligns its network identity and WireGuard endpoint with the cloned Gateway. The operator is separate from beast's network namespace: beast already belongs to the real fleet, and the topology reuses the fleet's WireGuard address range. Joining that tunnel on beast would mix live and disposable traffic.

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

A lease names the issue, the attempt ID, the purpose, the operation ID, and the acquisition time. There is no reaper. A topology lives until the operator or Orbit releases it.

Task agents and reviewers use only the topology allocated to their task. They never acquire or release a topology, receive no sudo, and do not touch the host firewall. Acquisition and teardown belong to Orbit or the operator. Use `exec`, `spawn`, `logs`, and `kill` from the task workspace to work on the allocated topology. Clean up processes you spawn, but leave the topology allocated for teardown.

### The app-prod-2 extension

A topology can carry one extra production Node, `app-prod-2`. It is the recipe `gateway_app-dev_app-prod_app-prod`.

| Physical Node key | Source | Incus address | WireGuard address | Roles |
| --- | --- | --- | --- | --- |
| `app-prod-2` | The `orbit-base-ubuntu-26.04-runtime` image | `.13` | `10.44.0.4` | `app-prod` |

The three standard VMs and the operator container still come from the snapshot. The harness builds `app-prod-2` from the base image inside the same attempt and network, and it records the image fingerprint. It refuses when the image changes between the check and the build. Convergence provisions `app-prod-2` as an `app-prod` Node. It stays outside the `e2e-development` Cluster and gets no Instance. An extended topology reserves four VMs.

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

Every `incus` call carries the configured project. The harness reserves the recipe's VMs, three or four, before it creates a network or a VM. It refuses `acquire` when the budget cannot hold them, and it names the count and the limit. At the default VM budget, seven standard topologies fit beside the snapshot. Each also needs one operator system container; that container is not a fourth VM.

Memory limits apply when the harness creates or clones a VM. The registered three-Node profile has a 4.5 GiB configured budget. `ORBIT_E2E_INCUS_MEMORY=2GiB` overrides every Node's limit for a run. The cold recipe's workload development Node and `extra` Node keep the 2 GiB default. The roleless operator is a separate small system container, not part of these VM memory totals. These limits do not change the CPU allocation or reduce the application's CPU work. See the [ZFS efficiency measurements](/solutions/incus-zfs-efficiency) for the evidence and workload limits.

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
| `web ISSUE` | Runs the web development server in the acquired topology's operator, publishes it on beast's loopback, and streams output until stopped; see [Web session](#web-session) |
| `release ISSUE` | Stops its web session, removes its loopback publication, removes the topology, and verifies that it is gone |

`NODE` is a guest key: `gateway`, `app-dev`, `app-prod`, `operator`, or `app-prod-2` on an extended topology. `--argv-file=PATH` can replace `--argv` on `exec` and `spawn`. The file holds `{"argv":[...],"stdin":null}`, and only `exec` accepts stdin. The harness refuses both options together.

### Web session

Run `bin/e2e-topology web TASK-58` in the task workspace only after its topology has been acquired. `web` requires that topology and its configured operator; it never acquires one implicitly. It runs `vp dev` in `/home/orbit/orbit/apps/web` in the operator, with guest TCP port `5173` and strict-port behavior. An occupied guest port fails startup; there is no guest-port fallback.

The command publishes the dev server only on beast's IPv4 loopback, `127.0.0.1`, using an available OS-assigned ephemeral host TCP port. After readiness, it prints the actual host port and URL, such as `http://127.0.0.1:P` with the numeric port in place of `P`. There is no fixed host port, DNS name, or Caddy Route. [Web app: Run against a topology](/reference/web-app#run-against-a-topology) gives the Mac SSH forwarding steps.

`web` stays in the foreground and streams dev-server output. Keep it running while using the page. Ctrl-C, command termination, startup failure, and topology release stop that session's dev-server process and remove its loopback publication. Stopping `web` does not release the topology. Only one web session may run per topology. A second invocation fails clearly without disturbing the first.

The session pins the Gateway URL and trusted CA to the selected topology. Inherited endpoint overrides must not redirect Gateway, realtime, or metrics traffic to the live fleet. If the topology, operator, URL, CA, Gateway grant, or WireGuard configuration is absent, startup fails clearly instead of falling back to the caller's live profile or another user's credentials.

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

`acquire` attaches the worktree to `gateway`, `app-dev`, and `operator` as the Incus disk device `orbit-source`. It is a read-write virtiofs share at `/home/orbit/orbit`. A host edit is live in all three checkout guests at once. Run `sync` only after a migration or a change to a guest helper script.

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
| Find the primary | Reads `$XDG_STATE_HOME/orbit/e2e-primary-checkouts/{origin key}` when set, then `$HOME/.local/state/orbit/e2e-primary-checkouts/{origin key}`, then `/var/lib/orbit/e2e-primary-checkouts/{origin key}`. The primary owner is the invoking user or the owner of the invoking checkout. Without a live registration, the command runs in the clone. |
| Update the bridge | Checks out the clone's HEAD in `<worktree root>/<clone directory>-e2e`, on branch `<clone branch>-e2e`. The worktree root is the primary's `orbit.worktreeRoot`, default `/fast/worktrees/orbit`. |
| Mirror the work | Copies the clone's modified and untracked files, removes its deleted tracked files, and mirrors each `vendor/` directory without preserving source owners or groups |
| Run | Runs the bridge's `bin/e2e-topology`, with each clone path replaced by the bridge path and `--worktree` set to the bridge |

The origin key is the SHA-256 of the origin URL's lowercase host and its path, so every clone of one repository finds the same primary. The cleanup helper uses the same lookup order and owner rule.

Missing or dangling links and explicitly rejected registrations are successful no-ops. Once an eligible primary has a promoted marker, operational failures while probing its Git directories, origin, or worktree-root setting are cleanup failures, not an absent registration. An unset worktree-root key still uses the default. The helper returns nonzero before changing task or bridge resources, so teardown retains the task checkout for retry. [Primary registration](/reference/instance-setup#primary-registration) describes the shared registry and the worker's access grants.

State directories and operation locks allow access only to their owner and ACL-named users. The harness keeps existing ACL masks, including the managed user's grant to `orbit-worker`, when it opens the state root, ensures a parent directory, or acquires a lock. It removes access for the owning group and other users without recalculating those masks. An already-private owner-owned directory or lock needs no permission change when the worker uses it.

The mirror leaves the bridge's other ignored files, such as `.e2e/`, `.env`, and Gateway storage, because the harness and the guests write them. So a file that a guest writes into the mount appears in the bridge, not in the clone. The evidence log is in the bridge too. Every command mirrors the clone first, so any command, such as `status`, pushes an edit. Set `ORBIT_E2E_BRIDGE=0` to run in the clone itself. `bin/e2e-topology-snapshot` never bridges, because snapshot operations belong to the primary checkout.

In a task workspace on branch `task-58`, use the allocated topology with commands such as `bin/e2e-topology exec TASK-58 gateway --argv='["orbit","node:list","--json"]'`. The topology snapshot [registers](/reference/topology-snapshot#commands) the primary checkout. When the Gateway ends a task, the Orbit Project's configured teardown step runs the installed copy of `bin/e2e-task-cleanup` to remove the task's bridge. [Tasks](/reference/tasks#complete-and-cleanup) describes that cleanup. Orbit releases the group's topology, including any web session and loopback publication, before removing the workspace. Bridge removal itself does not release a topology.

Vendor mirroring keeps content, symbolic links, file timestamps, and deletion of stale entries. With rsync, it disables owner, group, and permission preservation after archive mode and omits directory timestamps. Existing destination permissions and ACL grants stay in place; new files use source permissions subject to the destination's defaults and the caller's umask, including executable permissions. This lets `orbit-worker` update a managed-user-owned bridge without trying to change its ownership, chmod owner-owned entries, or set timestamps on directories the worker does not own.

A managed-user run that creates a directory with explicit mode `0700` can mask inherited named-user ACL grants and cancel worker access despite default ACLs. Keeping an existing ACL mask does not repair that inaccessible directory owned by another user. The operator must restore its effective grants and keep state owning-group/other access closed. The task lifecycle must preserve effective worker access on state and bridge directories it creates.

The helper checks the checkout identity and origin, the promoted snapshot marker, and the repository identity. It reads the same registration paths as the bridge, including the shared directory, and it accepts a primary owned by the checkout owner. A primary registered by the managed user is found after teardown runs as `orbit-worker`. The worker also needs to traverse and write the primary's `.git` and the bridge worktree root. [Primary registration](/reference/instance-setup#primary-registration) records the copy and that grant.

It targets `task-{id}-e2e` under that primary's worktree root. It removes a registered worktree only when both its path and branch match. A bridge checked out on another branch stays. It deletes the task bridge branch only when no worktree has it checked out, and deletes only `refs/orbit/e2e-bridge/task-{id}`. It never removes another task's bridge or releases a topology.

An absent bridge, or a checkout with no registration, is success. A registration that exists is not that case. A cleanup command failure exits nonzero and makes teardown retain the task checkout and Instance for retry. Removal of a matching bridge, its unused branch, and its staging ref is idempotent. The helper does not alter the checkout that runs it or that checkout's worktrees.

Orbit provisions the group's workspace without acquiring a topology. Only the [reviewer's request](#topologies-on-the-reviewers-request) acquires `TASK-<group>` as the managed user. Orbit releases it before removing the workspace. Acquiring changes host firewall rules, which the task worker cannot do; agents and reviewers only use the topology. Acquisition failure resumes the requesting reviewer with the failure and never prevents approval. A failed release keeps the workspace and topology state so a later removal retries it. An absent topology needs no release, and a workspace without `bin/e2e-topology` has no topology.

## Release

`release` checks every recorded guest against the attempt's ownership metadata, including each workload VM, any extension VM, and the operator system container. It stops the owned web session's dev-server process and removes its loopback publication. It force-stops any running recorded guests, deletes them, and verifies that every recorded guest is absent, including the operator. It checks the network's ownership just before deleting it.

Only after web-session cleanup succeeds, every recorded guest is absent, and the network is removed does release drop the lease and topology record. It never drops topology state while a recorded guest remains. The output lists `released`, `already_absent`, and `networks_reaped`.

An ownership mismatch or cleanup failure stops release and keeps every unrelated resource. The lease and attempt record stay for diagnosis and retry. A retry continues from the same exact target and handles already-absent owned guests without selecting new resources.

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
| `cold-four-node` | cold | Cold acceptance, four VMs plus operator container (five guests/Nodes) | Orbit builds and verifies the whole topology from the base image, and cleanup removes it |
| `cold-construction-cleanup` | cold | Cold acceptance, four VMs plus operator container (five guests/Nodes) | After an injected source failure during construction, cleanup removes exactly the recorded resources |
| `snapshot-lifecycle` | snapshot | Registered, three VMs plus operator container (four guests/Nodes) | A clone of the snapshot syncs, converges, passes readiness, runs a bounded app-dev action, and verifies |
| `snapshot-isolation` | snapshot | Registered, three VMs plus operator container (four guests/Nodes) | A fresh clone does not contain a marker that an earlier attempt wrote |
| `snapshot-extension` | snapshot | Four VMs including `app-prod-2`, plus operator container (five guests/Nodes) | The [extension](#the-app-prod-2-extension) Node has its recorded identity, capacity, and image fingerprint |

The checkout must be clean. An optional SHA must be the full lowercase `HEAD`. Before it changes Incus, the command validates the catalog, every selected scenario, and the worker count. It refuses an unknown or repeated ID, a scenario from the other lane, an invalid recipe, a missing action deadline, and an invalid declared input.

Each worker gets its own attempt, network, workload VMs, operator container, state path, and Pest process. One Pest test is one independent flow, and a flow stops at its first failed step. Admission holds the `topology-create` lock while it counts the recipe's VMs against the shared budget and picks a network slot. After that, `run` workers go on in parallel. A failure in one worker does not cancel another.

The cold flow starts from the unchanged base image and installs no PCOV before construction. Fresh development Nodes get the sample TLD `beast`. Each fresh production Node gets its own name as its TLD through the provision command. The unique production TLD lets the sample Instance clone create its preview route. A snapshot flow checks the promoted generation first. A missing, stale, or changed generation gives `infrastructure-error` and skips the exercise. A snapshot flow never changes the generation, its VMs or operator container, or its manifest.

A snapshot flow mounts no worktree. It clones the three workload VMs and the operator container, and builds `app-prod-2` for the extension. Before dependency installation, it repairs the cloned Gateway addresses and WireGuard endpoints, so DNS can use the new Gateway. It synchronizes the exact candidate commit from Git into the checkout Nodes and checks the guest commit. It converges the whole topology, checks the commit again, and runs the readiness probes. Then it runs the exercise and a final verification.

Scenario resources carry the issue `SCN-1` and the extra metadata `user.orbit.e2e.run`, `user.orbit.e2e.scenario`, and `user.orbit.e2e.recipe`. VM names are `orbit-e2e-scn-<run>-<scenario>-<attempt>-<node>`, with 8 characters of the run ID, 6 hex characters of the SHA-256 of the scenario ID, and 8 characters of the attempt ID. The network is `oe-` plus 12 hex characters of the SHA-256 of `<run>:<scenario>:<attempt>`. These VMs count against the same budget as issue topologies.

The cold acceptance recipe has four workload VMs and one operator system container: five guests. The snapshot recipe has three workload VMs and one operator container: four guests/Nodes. The extension adds one workload VM. VM counts exclude the operator container; guest and Node counts include it.

| Node key | Address | Checkout | Roles |
| --- | --- | --- | --- |
| `gateway` | `.10` | yes | `gateway`, `vpn` |
| Workload development Node | `.11` | yes | `app-dev`, `metrics` |
| `app-prod` | `.12` | no | `app-prod` |
| `extra` | `.13` | no | none |
| `operator` system container | Own non-conflicting address | yes | none; Gateway access grant and WireGuard peer |

The roleless operator is distinct from the cold recipe's development workload Node. It has the same topology-pinned configuration and web-session contract as the operator in a snapshot topology.

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

### Three workload VMs and an operator container

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
