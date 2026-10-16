---
title: "Incus topologies"
description: "How the harness composes a disposable Incus topology from declared Nodes and one recipe base image, runs it nested in a task VM or on a bare host, and releases it."
covers:
  - bin/{e2e-topology,e2e-topology-images,e2e-topology-host-setup}
  - apps/e2e/config/e2e.php
  - apps/e2e/app/Console/Commands/{Topology,TopologyImages}/**
  - apps/e2e/app/E2E/**
  - apps/e2e/resources/**
  - apps/gateway/app/Console/Commands/RenderNodeProgramsCommand.php
---

# Incus topologies

This page is for the contributor, agent, or reviewer who runs Orbit on disposable Incus machines. The `apps/e2e` harness composes one topology per issue from the Nodes the issue declares. Each Node starts from the same prepared recipe base image, and the harness then bootstraps a fresh test Gateway and enrolls the Nodes. `bin/e2e-topology` controls a topology, `bin/e2e-topology-images` imports the recipe base image, and `bin/e2e-topology-host-setup` prepares a machine, such as a task VM, to run topologies nested. The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill covers the working habits.

[Compute drivers](/reference/compute-drivers#clone-and-set-up-every-workspace) describes task workspace creation and its package and CI caches.

## Declared topologies in task VM groups

An Orbit task group on a [task VM](/reference/compute-drivers#task-vms) gets a topology only when its subtasks [declare](/reference/compute-drivers#declare-a-topology) one. Orbit prepares it nested in the group's VM as `TASK-<group>`, with `--nodes` set to the union of the declarations, after the group's baseline check and before the first turn Orbit [prepares](/reference/compute-drivers#prepare-the-topology) for the subtask. Agents use it and never acquire or release it. The reviewer records evidence on each declared node, and Orbit refuses the approval without it. See [Prove work on a topology](/reference/compute-drivers#prove-work-on-a-topology).

## Declared Nodes

Every topology has a `gateway` VM and an `operator` VM. `acquire --nodes=LIST` adds the workload Nodes the work needs. `LIST` is a comma-separated list of entries, in any order. An entry names one of `app-dev`, `app-prod`, and `app-prod-2`, and may add the role `ingress` after a `+`, for example `app-prod+ingress`. `app-prod-2` requires `app-prod`. Without the option, the list is `app-dev,app-prod`. An empty list, `--nodes=`, gives the Gateway and the operator only. A task VM group's subtask `topology` uses the same entries.

| Node key | Image alias | Incus address | WireGuard address | Roles | Checkout |
| --- | --- | --- | --- | --- | --- |
| `gateway` | `orbit-e2e-base` | `.10` | `10.44.0.1` | `gateway`, `vpn` | yes |
| `app-dev` | `orbit-e2e-base` | `.11` | `10.44.0.2` | `app-dev` | yes |
| `app-prod` | `orbit-e2e-base` | `.12` | `10.44.0.3` | `app-prod` | no |
| `app-prod-2` | `orbit-e2e-base` | `.13` | `10.44.0.4` | `app-prod` | no |
| `operator` | `orbit-e2e-base` | `.14` | `10.44.0.5` | none | yes |

`app-prod-2` is a second `app-prod` Node from the same base image. Each production Node gets its own name as its development TLD, and `app-dev` gets `beast`.

The Node catalog `apps/e2e/resources/topology-nodes.json` holds this table. For each Node it names the software profile, address position, checkout, memory, TLD, and the Node it requires. It also names the default list, the roles an entry may add, and the roles that put a Node in its own Cluster. Topology selection, composition, and readiness derive from it.

The WireGuard address is the address position minus 9. The network's DHCP range ends at the highest position the topology uses. A new Node kind needs one catalog entry and one case of the harness `RoleImage` enum. A Gateway test reads the same file, so the Gateway accepts the same `topology` entries as the harness.

The harness assigns the roles in the table and the roles each entry adds, and nothing else. It creates no Router, no Cluster except the one an `ingress` entry needs, no other role, and no sample Project, Instance, or Route. Add other things the work needs with the Orbit CLI on the topology, for example `orbit project:create`, and record those steps as evidence. So a topology holds only what the task declared and created.

| Field | Value |
| --- | --- |
| Network | `oe-<hash>` on `10.232.<slot>.0/24`. The hash is 12 hex characters of the SHA-256 of `<issue>:<attempt>`. |
| Guests | `orbit-e2e-<issue-lowercase>-<attempt-prefix>-<node>`, with the first 8 characters of the attempt ID |
| Checkouts | `gateway`, `app-dev`, and `operator` mount the worktree live at `/home/orbit/orbit`. Production Nodes have no checkout. |
| Issue ID | Matches `[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}`. `acquire` and `sync` require it in the worktree's branch name, such as `TASK-58` on branch `task-58`. |

### The operator guest

The operator is a roleless Node registered with the topology's Gateway, with a Gateway access grant and its own WireGuard peer in that topology. It has no workload role and hosts no Instance. It trusts the topology's Orbit CA and mounts the worktree live, so host edits reach `apps/web` without a copy or rebuild. Its CLI profile and web proxy configuration refer only to this topology. Missing configuration is an error, not permission to use the real Gateway or another user's credentials.

The operator is separate from the host's network namespace. A host such as beast or a task VM may already belong to the real fleet, and the topology reuses the fleet's WireGuard address range. Joining that tunnel on the host would mix live and disposable traffic.

## One recipe base image

Every topology Node launches the same base fingerprint built on its task VM host, including the operator VM. The task VM receives one archive and a manifest; `bin/e2e-topology-images ensure --recipe-images=PATH` imports and validates its recipe identity, base profile, fingerprint, and VM type. Imports are repeatable and remove the archive after verification. The harness does not build images. Standalone hosts must import an approved base archive through the same manifest contract.

The base already provides pinned software, `orbit` with uid/gid 1000 and passwordless sudo, masked apt timers, and identity cleanup before publication. The shared base preserves DNS configuration for normal task VMs. Only nested topology guests receive persistent public DNS upstreams: as soon as their guest agents respond, the harness sets them before waiting for cloud-init. This avoids the two-minute network-online timeout on topology bridges without usable DNS, while normal task VMs keep their link DNS for internal names.

Convergence clears known hosts, verifies the account, and keeps apt timers masked. It converts the exact root-owned Bun launcher from the recipe into the symlink required by app-role enrollment. Other executables remain subject to the normal conflict checks.

Before enrollment, the harness checks pnpm as `orbit` and repairs a broken launcher from the recipe's integrity-pinned native archive, at the same version. This supports existing bases whose wrapper still names `pnpm.cjs`; it uses the recipe installer without building an image.

Cloud-init creates fresh SSH keys; the harness waits through its seed reboot before configuration. The shared base stays usable as a task VM image, so convergence does not disable cloud-init in the published image.

The Gateway runs its rendered `gateway vpn` enrollment programs except bootstrap during composition. This supplies VPN prerequisites and the Gateway's Caddy program, which a software-only recipe role does not replace. Composition installs no PHP: every Node, the Gateway included, runs the recipe's [PHP build](/reference/compute-drivers#php-build) from the base image. Workload and operator enrollment still use the normal product paths. All Nodes keep the source image's minimum disk size. Guest ownership repair stays on the guest filesystem and does not scan the mounted checkout. The operator needs 1 GiB of memory for the shared base; 512 MiB can kill cloud-init during boot.

| Command | What it does |
| --- | --- |
| `bin/e2e-topology-images ensure --nodes=LIST --recipe-images=PATH` | Imports one recipe base for every declared role; builds none |
| `bin/e2e-topology-images status` | Reports the imported base fingerprint and recipe identity |

## Composition

`acquire ISSUE WORKTREE [--nodes=LIST] [--replace]` composes the topology in this order. Each step names itself in a failure. `--replace` first releases a topology the issue holds; without it, `acquire` refuses an issue that holds one. When the worktree has no Gateway `.env`, `acquire` writes it from `.env.example`, as `bin/bootstrap` does.

| Step | Work |
| --- | --- |
| `reserve` | Under the host lock `topology-create`, counts the declared VMs against the budget, picks a network slot, and creates the network |
| `create` | Creates each guest from the recipe base image, with a fixed MAC and Incus address, and mounts the worktree on the checkout Nodes |
| `start` | Starts every guest and waits for its agent and its address. VMs also wait for cloud-init and a stable boot ID before composition. |
| `bootstrap.gateway` | Prepares the worktree's Gateway `.env`, runs the Gateway and VPN programs, then runs `orbit:bootstrap` at the Gateway's address |
| `enroll.<node>` | Enrolls each workload Node in catalog order with `orbit:node-provision`, through the guest script `converge-node.sh` |
| `enroll.operator` | Registers the roleless operator, grants it Gateway access, and points its CLI at the topology Gateway and CA |
| `roles.<node>` | For each workload Node whose entry adds a role, runs `orbit node:role:add <node> <role> --json` from the operator. `ingress` first puts the Node in its own Cluster. |
| `readiness` | Runs the readiness probes |

`bootstrap.gateway` writes the Gateway checkout path into the worktree's Gateway `.env` and applies the migrations. `orbit:bootstrap` then creates the Orbit CA, the Gateway SSH key, and the VPN at `10.44.0.1`.

For each workload Node, `enroll.<node>` authorizes the Gateway SSH key and reads the Node's host key. It passes the Node's roles, WireGuard address, and host key fingerprint to `orbit:node-provision`. Enrollment is serial, because it writes the Gateway's SQLite store.

An `ingress` entry needs Cluster membership, and a Cluster holds one ingress Node. So the operator creates the Cluster `e2e-<node>` for that Node and adds the Node to it before it adds the role.

The harness changes guests only through Orbit commands and its own guest scripts. It never edits the Gateway database. A failed acquisition removes every resource it created, drops the lease, and exits nonzero with the step and the redacted error. Node provisioning requests `--diagnostics`, which includes the last 40 lines of command output, limited to 4,000 characters and redacted before truncation. When cleanup itself fails, the lease stays so `release` can finish it.

### Readiness

Readiness runs these probes on the Nodes the topology has. A probe that fails is retried until the readiness window ends; then the topology fails with every failed probe and its last output.

| Probe | Node | Passes when |
| --- | --- | --- |
| `nodes.registered` | `gateway` | `orbit node:list` shows exactly the declared Nodes, each `active` with the roles from the table and its entry, and the operator roleless |
| `service.gateway` | `gateway` | Caddy and PHP-FPM are active |
| `service.vpn` | `gateway` | `wg-quick@orbit` is active |
| `wireguard.reachability` | `gateway` | Every other Node answers a ping on its WireGuard address |
| `caddy.<node>` | `app-dev`, `app-prod`, `app-prod-2` | Caddy is active and its Caddyfile, written by a [Node Caddy build](/reference/caddy-configuration#node-caddy-build), is valid |
| `operator.gateway` | `operator` | `orbit gateway:status` answers through the operator's own topology profile |
| `source.<node>` | checkout Nodes | The worktree is mounted and its guest source marker matches the host |

| Setting | Default | Variable |
| --- | --- | --- |
| Guest start window: agent and address | 900 seconds | `ORBIT_E2E_GUEST_READINESS_SECONDS` |
| Readiness window | 600 seconds | `ORBIT_E2E_READINESS_SECONDS` |
| One probe command | 120 seconds | none |

Nested guests boot about three times slower than on a bare host, so the windows are long. A slow start only waits; a guest that never answers still fails at the end of the window.

## Run topologies nested in a task VM

A topology also runs inside a VM, such as a task VM, with nested KVM. Run `sudo bin/e2e-topology-host-setup` once in that VM before the first recipe base import. The script is idempotent. Orbit [runs it](/reference/compute-drivers#prepare-the-topology) in a task VM group that declares a topology.

| Step | What happens |
| --- | --- |
| Packages | Installs `incus`, the QEMU system emulator for the machine's architecture, `btrfs-progs`, and `nftables` |
| Idmap | Adds `root:1000000:1000000000` to `/etc/subuid` and `/etc/subgid` when they have no root entry, and restarts Incus. This retains the host setup for other Incus containers. |
| Incus | Initializes Incus when it has no `orbit-e2e` storage pool |
| Access | Adds the invoking user to `incus-admin`. The group applies to new logins only, so start the harness in a new session, for example over SSH or with `sudo -u orbit -i`. |
| AppArmor | Adds `file mr /usr/bin/bash,` to `/etc/apparmor.d/local/wg-quick` and reloads the profile, for container workloads that use `wg-quick`. |
| Worktrees | Creates `/fast/worktrees/orbit`, the default worktree root, owned by the invoking user |
| Isolation | Installs the nftables table `orbit-e2e-isolation` and the boot unit `orbit-e2e-isolation.service` that loads it |
| UFW | When UFW is active, as on an enrolled task VM, allows DHCP and DNS over UDP in, and routed traffic in, on `incusbr0` and `oe+` |

A task VM's `/etc/subuid` holds only the range of its user `orbit`, which is why the Idmap step exists.

Incus gets a btrfs pool `orbit-e2e` on a sparse loop file (`--pool-size`, default 45GiB), the bridge `incusbr0` on `10.99.0.1/24`, and a default profile with a root disk and an `eth0` NIC. The harness replaces that NIC for each guest.

The isolation table keeps topology guests away from the machine's own WireGuard network, `orbit` on `10.44.0.0/24` by default (`--wireguard-interface`, `--wireguard-subnet`).

| Rule | Drops |
| --- | --- |
| Forward from `oe*` to the WireGuard interface | Topology guests reaching fleet Nodes through the machine's tunnel |
| Forward from `incusbr0` to the WireGuard interface | Other inner guests doing the same |
| Input from `oe*` to the WireGuard subnet | Topology guests reaching the machine's own WireGuard address |
| Input from `incusbr0` to the WireGuard subnet | Other inner guests doing the same |

A topology guest needs none of these paths: it talks to its own Gateway over its own WireGuard network. Its public egress and the WireGuard traffic between topology Nodes keep working.

## Capacity

The harness counts capacity from `incus list`, never from a ledger. It counts the VMs that carry `user.orbit.e2e.owner=orbit-e2e` and the `10.232.<slot>.0/24` subnets in use.

| Setting | Value |
| --- | --- |
| VM size | 1 vCPU (`e2e.incus.cpu`); root disks retain the recipe image size. Memory comes from the Node catalog, as below. |
| VM budget | `e2e.incus.max_vms`, default 24. `ORBIT_E2E_INCUS_MAX_VMS` sets it for one run. |
| Network slots | 1 to 200 |
| Incus scope | `e2e.incus.remote`, `e2e.incus.project`, and `e2e.incus.storage_pool`, from `ORBIT_E2E_INCUS_REMOTE`, `ORBIT_E2E_INCUS_PROJECT`, and `ORBIT_E2E_INCUS_STORAGE_POOL`. The defaults are `local`, `default`, and `orbit-e2e`. The remote must be `local`, because network creation and deletion also change host firewall rules. |

The catalog gives 1.5 GiB of memory to `gateway`, 2 GiB to `app-dev`, 1 GiB each to `app-prod` and `app-prod-2`, and 1 GiB to the operator VM. `ORBIT_E2E_INCUS_MEMORY=2GiB` (`e2e.incus.memory`) sets every guest for one run.

Every `incus` call carries the configured project. The harness reserves the declared VMs before it creates a network or a guest, and refuses `acquire` when the budget cannot hold them, naming the count and the limit. The operator VM counts against the budget.

### Locks

Every command except `status`, `shell`, and `web` holds the lock `topology-<ISSUE>` under `<primary>/.e2e/locks/`. Topology creation holds the host lock `topology-create` from network creation until every guest exists. `web` atomically reserves its operator proxy device rather than holding the issue lock throughout the session, so other discovery commands and release can run while the page is open.

## Commands

`acquire` takes the worktree as an argument. Every other command finds the issue among the registered Git worktrees by branch or directory name. It needs exactly one match, or `--worktree=PATH`. Every command accepts `--json`. A failure prints `{"state":"failed","error":"..."}` and exits nonzero.

| Command | What it does |
| --- | --- |
| `acquire ISSUE WORKTREE [--nodes=LIST] [--replace]` | [Composes](#composition) the topology and verifies readiness |
| `shell ISSUE NODE` | Opens a login shell as `orbit` on one Node |
| `exec ISSUE NODE --argv=JSON [--timeout=SECONDS] [--record=LABEL]` | Runs one argument vector as `orbit` on one Node and waits for it. The timeout defaults to 60 seconds, with a maximum of 3600. |
| `spawn ISSUE NODE NAME --argv=JSON` | Starts a long-lived process as the transient unit `orbit-e2e-NAME.service` and returns at once |
| `logs ISSUE NODE NAME [--since=TIME] [--lines=N] [--record=LABEL]` | Prints the unit's output with precise timestamps, also after the process ends |
| `kill ISSUE NODE NAME` | Stops the unit. Its output stays readable. |
| `sync ISSUE [--quick]` | Proves the mount, applies pending Gateway migrations, and verifies readiness. `--quick` skips verification. |
| `verify ISSUE` | Verifies readiness and records the report |
| `status ISSUE` | Reports the state files without touching Incus: `state` is `absent` or `acquired` |
| `web ISSUE` | Runs the web development server in the operator, publishes it on the host's loopback, and streams output until stopped; see [Web session](#web-session) |
| `release ISSUE` | Stops its web session, removes its loopback publication, removes the topology, and verifies that it is gone |

`NODE` is a Node key of the topology: `gateway`, `operator`, or a declared workload Node. `--argv-file=PATH` can replace `--argv` on `exec` and `spawn`. The file holds `{"argv":[...],"stdin":null}`, and only `exec` accepts stdin. The harness refuses both options together.

An issue holds one topology at a time. `acquire` creates it and `release` removes it. Its state lives under `<worktree>/.e2e/`, in `attempt.json` and `topology.json`, and it dies with the worktree. A lease names the issue, the attempt ID, the operation ID, the declared Nodes, and the acquisition time. There is no reaper. A topology lives until the operator or Orbit releases it.

`release` and `acquire --replace` also accept an older lease with `purpose: discovery`. Cleanup uses the lease's declared Nodes and exact issue and attempt ownership, without reading the older topology record. Other old formats and malformed leases are refused; release them with the harness that created them.

Task agents and reviewers use only the topology allocated to their task. They never acquire or release a topology, receive no sudo, and do not touch the host firewall. Use `exec`, `spawn`, `logs`, and `kill` from the task workspace to work on the allocated topology. Clean up processes you spawn, but leave the topology allocated for teardown.

### Web session

Run `bin/e2e-topology web TASK-58` in the task workspace only after its topology has been acquired. `web` requires that topology and its configured operator; it never acquires one implicitly. It installs the mounted web app's dependencies with `vp install` and runs `vp dev` as the transient `orbit-e2e-web.service` unit in `/home/orbit/orbit/apps/web` in the operator, with guest TCP port `5173` and strict-port behavior. An occupied guest port fails startup; there is no guest-port fallback.

The command publishes the dev server only on the host's IPv4 loopback, `127.0.0.1`, using an available OS-assigned ephemeral host TCP port. After readiness, it prints the actual host port and URL, such as `http://127.0.0.1:P` with the numeric port in place of `P`. There is no fixed host port, DNS name, or Caddy Route. [Web app: Run against a topology](/reference/web-app#run-against-a-topology) gives the Mac SSH forwarding steps.

`web` stays in the foreground and streams dev-server output. Keep it running while using the page. Ctrl-C, command termination, startup failure, and topology release stop that session's dev-server process and remove its loopback publication. Stopping `web` does not release the topology. Only one web session may run per topology. A second invocation fails clearly without disturbing the first.

The proxy device records a random ownership token before startup, and the unit's description carries the same token. Cleanup reads that durable reservation rather than trusting whether an external command returned successfully. It stops only the matching unit and keeps the reservation until the unit is confirmed stopped. A guest lock serializes startup and cleanup; cancellation records prevent a delayed start from reviving a cleaned session. Release can recover a session after its foreground owner crashes, and failed cleanup retains the marker for retry.

The public wrapper execs PHP with its own PID, so TERM or HUP sent to that PID reaches the foreground owner and waits for cleanup, just as terminal Ctrl-C does.

The session pins the Gateway URL and trusted CA to the selected topology. Inherited endpoint overrides must not redirect Gateway, realtime, or metrics traffic to the live fleet. If the topology, operator, URL, CA, Gateway grant, or WireGuard configuration is absent, startup fails clearly instead of falling back to the caller's live profile or another user's credentials.

Agents drive the page from `operator` at `http://127.0.0.1:5173`, using the Playwright dependency in the mounted `apps/web`. Install Chromium and WebKit with `vp exec playwright install --with-deps webkit chromium` there, then run browser code with `vp exec node --input-type=module -e '...'` through `exec`. The [topology skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md#check-the-web-ui-on-the-held-topology) describes checks against the topology, live reload, and isolated actions. In a task workspace clone, run a harness command such as `status` after each edit to mirror it into the mounted bridge. Demo-mode screenshots remain the layout check; they do not replace the live Gateway check when a topology is held.

### Guest commands

`exec` runs the vector through `runuser -u orbit -- env -C /home/orbit HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite PROGRAM ARGS`. No shell profile loads. `argv[0]` must resolve on the guest `PATH` or be an absolute path. It cannot start with `-` or contain `=`.

The harness links `apps/cli/orbit` to `/usr/local/bin/orbit` on each checkout Node, so `orbit` resolves by name. Wrap a pipeline in `["sh","-c","..."]` and root work in `["sudo","..."]`. `shell` opens the same environment with `bash -l`. It starts in `/home/orbit/orbit` on a checkout Node and in `/home/orbit` on a production Node.

With `--json`, `exec` prints `{"state":"executed","exit_code":N,"stdout":"...","stderr":"..."}`. Without it, `exec` prints the guest stdout. It exits `0` only when the guest command does.

Incus waits for every process that an `exec` session starts. A command that leaves a background process holds `exec` open until the timeout. Start a long-lived process with `spawn` instead. `spawn` runs `systemd-run --collect` with the same user, directory, and environment as `exec`. A name has 1 to 40 lowercase letters, digits, or hyphens. Kill a unit before you spawn another one with the same name. The harness log `<worktree>/.e2e/log` records every `exec`, `spawn`, and `kill`, with the redacted argv and the exit code.

### Evidence log

`exec --record=LABEL` and `logs --record=LABEL` append one entry for people to `<worktree>/.e2e/evidence.log` and one JSON record to `<worktree>/.e2e/evidence.jsonl`, under one lock. A label has 1 to 80 letters, digits, spaces, dots, dashes, or underscores, and is not blank. The harness checks the label before it touches Incus.

```text
=== 2026-09-24T06:40:00.123Z viewer after crash node=app-dev exit=0 duration=1234ms end=2026-09-24T06:40:01.357Z
$ orbit node:list --json
--- stdout
...
--- stderr
...
```

The header gives the start time, the label, the Node, the exit code, the duration, and the end time, in UTC with milliseconds. The `$` line is the argv as shell-quoted words. The harness redacts the argv and the output as it does in `<worktree>/.e2e/log`. The log is append-only. The harness creates it with mode `0600` in the `0700` `.e2e/` directory, and refuses a symbolic link in that directory path.

Each line of `evidence.jsonl` is one record, without the output. `argv` is the redacted argument list, not shell words:

```json
{"v":1,"start":"2026-09-24T06:40:00.123Z","end":"2026-09-24T06:40:01.357Z","duration_ms":1234,"label":"viewer after crash","node":"app-dev","exit":0,"argv":["orbit","node:list","--json"]}
```

The agent owns the machine and can write either file itself. The Gateway's evidence gate is a cooperative check, which the reviewer and the person who reads the approval audit.

Recording never changes the command's output or exit code. A failed append prints a warning on stderr. The log can hold output that the redactor does not recognize as a secret, so treat it like the rest of `.e2e/`. `bin/worktree-remove` deletes it with the worktree, so copy what you need first.

## Discovery mount

`acquire` attaches the worktree to the checkout Nodes as the Incus disk device `orbit-source`. It is a read-write virtiofs share at `/home/orbit/orbit`. A host edit is live in every checkout Node at once. Run `sync` only after a migration or a change to a guest helper script.

Guests never run Composer. Host `bin/bootstrap` owns `vendor/` and the Gateway `.env`. `acquire` refuses a worktree without the Gateway, CLI, and SDK autoloaders or without `apps/gateway/.env`. The harness sets only that file's `ORBIT_GATEWAY_CHECKOUT` to `/home/orbit/orbit/apps/gateway`. The Gateway's database, CA, and keys live in the guest under `/home/orbit/.orbit`, not in the worktree.

### Gateway schema

`acquire` and `sync` run `php artisan migrate --force --no-interaction` from the mounted Gateway as `orbit`. This applies only the migrations that the mounted source declares. A migration error makes `acquire` or `sync` exit nonzero. A failed `acquire` removes its own resources. A failed `sync` keeps the topology and its last good source binding in `topology.json`. Fix the source and run `sync` again. Then check `php artisan migrate:status` on the Gateway and run `verify`.

`sync --quick` proves the mount, installs the current guest helper scripts, and applies the migrations. It skips the readiness probes and prints a note that readiness was not verified. It leaves `topology.json` and the guest source marker at the last full `sync`. So `status` shows that binding, and `verify` refuses a mount that differs from it until the next full `sync`.

## State access

State directories and operation locks allow access only to their owner and ACL-named users. The harness keeps existing ACL masks when it opens the state root, ensures a parent directory, or acquires a lock. It removes access for the owning group and other users without recalculating those masks. An already-private owner-owned directory or lock needs no permission change.

## Release

### Order

`release` checks every recorded guest against the attempt's ownership metadata. It stops the owned web session's dev-server process and removes its loopback publication. It force-stops any running recorded guests, deletes them, and verifies that every recorded guest is absent. It checks the network's ownership just before deleting it.

Only after web-session cleanup succeeds, every recorded guest is absent, and the network is removed does release drop the lease and topology record. It never drops topology state while a recorded guest remains. The output lists `released`, `already_absent`, and `networks_reaped`.

### Failures

If an ownership mismatch or cleanup failure occurs, release stops and keeps every unrelated resource. The lease and attempt record stay for diagnosis and retry. A retry continues from the same exact target and handles already-absent owned guests without selecting new resources.

### Orphan networks

Every network named `oe-*` belongs to the harness and never outlives its topology. Every release ends with an orphan sweep. The sweep selects networks by that name prefix, not by ownership metadata. It deletes each one in the configured project whose `used_by` list is empty. It holds the `topology-create` lock, so it never deletes a network that a starting acquisition just created.

### After a merge

`bin/worktree-remove ISSUE` cleans up after a merge. It refuses a dirty worktree and a branch that `origin/main` does not contain, queues a TIA cache refresh, releases the discovery topology, removes the worktree, deletes the merged branch, and prunes the worktree list.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Composed from one recipe base

A topology starts only the Nodes the work declares, from one shared recipe base image, and the harness wires them together for that topology.

One shared whole-topology snapshot was rejected. It baked every Node, the Gateway database, the CA, and every key into a single generation. So it could not start a subset, and its extension Node took a cold build of about 20 minutes. Every change to prepared state needed a refresh, promotion, and recovery machinery. A snapshot for each combination of Nodes was rejected for the same reasons, multiplied.

The base image holds no identity, so a topology always has its own Gateway, CA, and keys. Composition takes longer than cloning a snapshot, and that is acceptable: start time matters little for the groups that need a topology, and a composed topology is simple and the same every time.

The base supplies pinned software. Convergence runs the checkout's Gateway and VPN programs, then enrollment sets each Node's identity and role services. Separate role images add no software today, so they would duplicate build and transfer work.

### Declared roles only

The harness assigns the base roles of each declared Node and the roles its entry adds, and nothing else. The old profile also added a Cluster, Router, Ingress, Metrics, a database role, WebSocket, and sample Laravel applications to every topology. Building and hydrating those samples was the slowest and most fragile part of preparation, and most tasks did not need them. A declared role is added with `orbit node:role:add`, the product path the fleet uses, so the topology is set up the way a real one is. Anything else a task needs, it adds with the CLI.

### Nested in the task VM

A group's topology runs inside the group's own task VM, so the VM edge stays the only boundary and an Orbit task VM is like any other task VM. The inner Incus uses a btrfs loop pool: a `dir` pool copies every disk in full, and ZFS on a ZFS volume caches twice. The isolation rules live in their own nftables table at a priority before the filter table, because the harness inserts its own forward rules at the top of the iptables `FORWARD` chain for each topology network.

### An isolated operator for web work

The operator is a small roleless VM launched from the same base as every other Node. Its own WireGuard identity, Gateway grant, CA, and pinned endpoint keep UI actions on the disposable fleet. Joining the topology tunnel on the host was rejected: the host may have a live identity, and topologies reuse address ranges. Running experiments against the real Gateway or falling back to a live profile was rejected because a missing disposable configuration must never turn a safe experiment into a live operation.

The web session owns both its systemd unit and its Incus proxy device. The proxy publishes only on host IPv4 loopback; SSH forwarding gives Mac access without a public listener, DNS entry, or Caddy Route. Those alternatives add shared fleet state and exposure. Concurrent groups need OS-assigned host ports rather than a fixed port. The guest port stays fixed and strict so a conflict fails visibly, rather than silently opening the wrong server. Stopping the session removes both resources; release retries cleanup without forgetting ownership on failure.

### Exact ownership

Every resource records its owner, issue, attempt, and operation when the harness creates it. Setup and cleanup change only recorded resources, after they check the live metadata again. They never select by prefix, glob, age, broad query, or unset variable. A resource without the expected metadata is outside the topology, even when its name looks right. So a mistake cannot delete another person's machine or a production resource. The one exception is the orphan network sweep. It selects by the harness name prefix, and deletes only networks that no guest uses.

### Topologies are never production

Topologies never reuse production resources or credentials. Evidence from a topology never authorizes a production deployment.

### A live mount

Discovery mounts the worktree, so an edit reaches the guests at once. Copying source into the guests after every edit is a rejected alternative, because it is slow and hides which source the guests run.

### Long-lived processes as systemd units

systemd already supervises, logs, and stops processes. Detaching inside `exec` with `nohup`, `setsid`, or a double fork is a rejected alternative, because it depends on shell skill and keeps no output. A separate process supervisor on the guests is also rejected.

### Chosen evidence entries

The harness records an `exec` only when you give it a label. Most commands are exploration, and a proof summary needs chosen, labelled entries. The log is plain text, not JSON, because people read and grep it, and JSON hides line breaks in output.

### A quick sync keeps the verified binding

`sync --quick` does not record the new source. Otherwise `status`, `verify`, and the guest source marker would describe a binding that no readiness check has seen. A full `sync` stays a complete readiness check.

### A bridge for task workspace clones

The harness expects every topology on a shared host to belong to a linked worktree of the primary checkout, which holds the host locks. The bridge gives a clone that shape. Teaching the harness to accept clones is a rejected alternative, because it changes about ten identity checks and the guest mount evidence. Mirroring with `rsync --delete` is also rejected, because it deletes the files that the harness and the guests keep in the mount. Finding the primary through the main cache store is rejected, because topologies would then depend on published test caches.

### Cleanup stays with the Project

The bridge layout belongs to this repository, so the Orbit Project's teardown runs the installed helper. Putting that removal in the Gateway was rejected, because the engine would then know one repository's worktrees. A failed cleanup exits nonzero and teardown keeps the checkout for retry. The helper does not release the topology.
