---
title: "Compute drivers"
description: "Task VMs for web Projects, the UpCloud driver, and the Incus sandboxes of the Orbit lane."
covers:
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute,Infrastructure/Compute,Actions/Compute}/**
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskSandbox.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Domain/Tasks/SandboxHostOperation.php,Infrastructure/Tasks/IncusSandboxHost.php}
  - apps/gateway/config/{task_vms,compute}.php
  - apps/gateway/resources/{task-vms,compute}/**
  - apps/gateway/database/migrations/*{create_task_vms_table,create_jobs_table,create_failed_jobs_table,create_task_sandboxes_table,add_fleet_enrollment_to_task_sandboxes,add_pi_ready_at_to_task_sandboxes}.php
---

# Compute drivers

A task group of a Project with `task_compute: vm` runs in its own VM. There are two lanes:

- The **web lane** serves every Project except `orbit`. Each group gets one [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster.
- The **Orbit lane** serves the `orbit` Project. Each group gets an isolated pair of Incus VMs that never joins the live fleet. See [Local Incus control](#local-incus-control).

Both lanes are off by default. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records the design.

## Task VMs

Partly built. The [settings](#configure-task-vms), the `task_vms` table and its [states](#states), the cloud-init user-data, the placement rule, and the [Incus provider](#incus-provider) exist. Nothing uses them yet. The jobs, the commands, the hub filter, and the placement hook are not built yet. The Phase 1 slices of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) build them.

A task VM is a stock Ubuntu 26.04 cloud VM on an Incus host. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the same code as a shared group, pinned to that Node.

### From claim to workspace

A claim moves a group through these steps. Steps 2 to 5 are queued jobs.

1. The scheduler claims a `vm` group. `AllocateTaskVmAction` picks a host with free budget and records a `provisioning` row. The row holds the VM name and a WireGuard address in the reserved range. Then the jobs are queued.
2. `ProvisionTaskVm` launches the VM with cloud-init user-data. Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key, and installs `openssh-server`. It does nothing else. On beast this takes 30 to 50 seconds.
3. `EnrollTaskVm` waits until cloud-init reports `done` with no errors. It reads the guest's IPv4 address and its ed25519 host key fingerprint from the host through `incus`.
4. `EnrollTaskVm` then runs the normal [Node provisioning](/reference/node-provisioning#add-a-node) for the Node `tvm-<id>`, through the host as [jump host](/reference/node-provisioning#enroll-through-a-jump-host).
5. `PrepareTaskVmRuntime` creates the group's CLIProxyAPI key and starts Pi as `orbit`. See [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The row becomes `ready`.
6. On the next tick, Orbit creates the [task workspace](/reference/tasks#task-vm-workspace) on the VM's Node. It is a normal Instance `task-<group id>` with the private Route `task-<id>.<project>.<dev-tld>`.

Until the row is `ready`, the claim returns the group to `todo` with the reason `Task VM: <state or error>`, and tries again on the next tick.

The Node `tvm-<id>` has user `orbit`, role `app-dev`, the dev Cluster, and the reserved WireGuard address.

### Destroy a task VM

When the group ends, Orbit removes its workspace Instance, which also withdraws the Route. Then `DestroyTaskVm` runs:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM. A VM that is already gone counts as deleted.
3. It removes the Node offline, with force. This removes its WireGuard peer, roles, and Process rows.
4. It sets the row to `destroyed`.

Each `tasks:tick` queues `DestroyTaskVm` for every task VM that is not `destroyed` and whose group has ended. Phase 1 does not park task VMs. Phase 2 adds parking.

### States

The `TaskVmState` enum has five cases.

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `ready` | Pi runs, and Orbit can create the workspace |
| `failed` | A job failed. The row keeps `error_code` and `error_message`, and the group shows the error as its reason |
| `destroying` | Cleanup has started |
| `destroyed` | The VM, the Node, and the model key are gone. The row stays for audit |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

The four jobs run on the `task-vms` database queue. Each job is idempotent and unique for its task VM. The Gateway scheduler starts a worker every minute:

```text
queue:work task-vms --queue=task-vms --stop-when-empty --max-time=50 --timeout=1500
```

A job that stops halfway runs again after 1800 seconds. `EnrollTaskVm` checks cloud-init every 15 seconds and fails after 10 minutes.

### Incus provider

`IncusTaskVmProvider` runs `sudo -n incus --project <project> …` on the host Node over SSH, as the host's managed user. It launches the host's `image` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` pinned to the host's `network`. The NIC keeps port isolation from the project's `default` profile. The user-data goes on stdin. The provider never creates a network.

Create and delete are idempotent by name. An existing VM counts as created, and an absent VM counts as deleted. A launch or delete that reports an error but leaves the wanted result also counts. Callers check `task_vms.enabled` first.

### Prepare an Incus host

Run `task-vms:prepare-host {node}` once for each host. It sends `resources/task-vms/incus-host.sh` to the host over SSH and runs it with `sudo -n bash -s --`. The script is idempotent and prints `{"ok":true}`. It sets up these parts:

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6. Orbit reserves bridge names that start with `orbittask`.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

### Limit fleet traffic on the hub

Run `task-vms:prepare-hub` once. It runs `resources/task-vms/hub.sh` on the `vpn` Node with arguments that it takes from Gateway records and settings. The script installs the nft table `inet orbit_task_vms`, and a oneshot unit that loads it after `wg-quick@orbit`. The table filters only the reserved range in `task_vms.wireguard_range`.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, CLIProxyAPI on TCP 8317, Reverb, and DNS on the hub |
| To task VMs | The Gateway on TCP 22 and TCP 3774, and the dev Cluster router on TCP 80, 443, and 5173 |

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Placement invariant

A task VM workspace is a normal Instance on a normal Node, so generic Instance operations need no sandbox guard. One rule protects the VM: an Instance on a task VM Node must be its group's `task-<group id>` workspace in the group's Project. The rule runs whenever an Instance is saved, so it covers create, clone, transfer, and register. Orbit also refuses an access grant from a task VM Node. The fleet rollout skips task VM Nodes. Shared groups never get a workspace on one.

### Configure task VMs

These keys live in the Gateway's `config/task_vms.php`. Set them in the Gateway's environment.

| Key | Variable | Meaning |
| --- | --- | --- |
| `task_vms.enabled` | `ORBIT_TASK_VMS_ENABLED` | Allows new task VMs. Default `false` |
| `task_vms.dev_cluster_id` | `ORBIT_TASK_VMS_DEV_CLUSTER_ID` | The ID of the Cluster that task VM Nodes join |
| `task_vms.wireguard_range` | `ORBIT_TASK_VMS_WIREGUARD_RANGE` | The reserved WireGuard range. Default `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24` |
| `task_vms.model_proxy_origin` | `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | The CLIProxyAPI origin that Pi on the VM uses, such as `http://10.44.0.3:8317`. An `http` or `https` origin with no path, query, or credentials |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.incus.hosts` | `ORBIT_TASK_VMS_INCUS_HOSTS` | A JSON list of hosts, in placement order. Default `[]` |

Each host is a JSON object with these snake_case keys. Other keys are an error.

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `node_id` | Yes | | The ID of the host Node. List each host once |
| `cidr` | Yes | | The task bridge network, such as `10.251.77.0/24`: a private network from `/16` to `/28`, not a host address. The bridge gets the first usable address and VMs get the others |
| `max_vms` | Yes | | The most task VMs on the host, from 1 to 64 |
| `project` | No | `orbit-tasks` | The Incus project |
| `network` | No | `orbittask0` | The bridge name: `orbittask` and 1 to 6 lowercase letters or digits |
| `pool` | No | `default` | The Incus storage pool for the VM root disk |
| `image` | No | `ubuntu-26.04-vm` | The Incus image alias |
| `cpus` | No | `2` | The vCPUs of each VM, from 1 to 64 |
| `memory` | No | `4GiB` | The memory of each VM, in `MiB` or `GiB` |
| `disk` | No | `20GiB` | The root disk of each VM, in `MiB` or `GiB` |

For example, `ORBIT_TASK_VMS_INCUS_HOSTS='[{"node_id":7,"cidr":"10.251.77.0/24","max_vms":4}]'`.

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id`, `model_proxy_origin`, and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` is the only place that reads host and guest output. It checks the instance state and its one IPv4 address inside the bridge range, the cloud-init status, and the host key fingerprint. Invalid output fails with `task_vm.invalid_host_output`. Every task VM error code starts with `task_vm.`. After that check, Orbit trusts its own records.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.invalid_config` | 500 | A `task_vms` value is invalid. See [Configure task VMs](#configure-task-vms) |
| `task_vm.unknown_host` | 409 | The Node is not in `task_vms.incus.hosts` |
| `task_vm.invalid_gateway_key` | 500 | The Gateway's SSH public key is not one OpenSSH public key line |
| `task_vm.workspace_mismatch` | 409 | A group's workspace is not on its ready task VM |
| `task_vm.foreign_instance` | 409 | An Instance on a task VM Node is not its group's workspace |
| `task_vm.invalid_host_output` | 502 | Incus returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host failed |
| `task_vm.bootstrap_failed` | 502 | Cloud-init in the VM reports `error` |

### Limits

Task VMs have these known limits.

- Incus accepts DNS before the ACL, so a task VM can query port 53 on any host address. It can read instance names from the DNS of other bridges. This risk is accepted.
- The first `app-dev` convergence on a new VM installs PHP, Caddy, Docker, and the agent. A job times out after 1500 seconds.
- Doctor can report task VM Nodes while they exist.

### Why task VMs work this way

The VM edge is the security boundary. Inside it, one user runs the agents, Pi, and the check with sudo, so the check sees what CI sees. Enrolling the VM as a normal Node reuses enrollment, Instances, Routes, checks, and publication instead of a second path. Host output is validated once, and Gateway records are trusted after that, so five states and no marker timestamps describe the whole lifecycle.

Public egress is open on every port. Limiting it to HTTP(S) adds no protection beyond the VM edge and the hub filter, and it broke clock sync, package sources, and key installs. CI on the pushed commit is the gate.

These alternatives were rejected:

- WireGuard in cloud-init. It bypasses normal enrollment.
- An Incus proxy device for SSH. Its port does not match the Node firewall catalog, and it needs forward and DNAT rules on the host.
- The Incus REST API with a restricted certificate. It needs more host setup, and the Gateway already has root SSH to the host.
- A hub table for each VM. One static filter on a reserved range does the same work.
- Prebuilt images. A stock image with cloud-init works on every provider and needs no image build.
- A GitHub token inside the VM. The Gateway already fetches and pushes over SSH.

## UpCloud driver

The UpCloud driver creates, observes, parks, resumes, and destroys VMs at UpCloud through its HTTPS API. Task claims use it only through the [first-build project lane](#project-lane-being-removed), which is off and is being removed. UpCloud task VMs move to the [task VM](#task-vms) path in Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm).

`ProvisionTaskSandboxAction` reserves one VM with the pinned Ubuntu image and the `starter-small` size. The reservation records its UUID, image, plan, network, and the Gateway's public SSH key before the request. Cloud-init creates the `orbit` user with only the Gateway's key. It carries no provider, GitHub, Pi, or model credential.

The driver sends a create request at most once for each reservation. After a lost response it looks for one exact match by hostname, title, and ownership label, and never sends a second create. It installs a provider firewall before it reports a VM as running: public SSH only from the Gateway, WireGuard only from the hub, and outbound HTTP(S), DNS, and the hub. `destroy` deletes only the recorded server and disk, and confirms both are gone before it releases capacity.

### Gateway configuration

Set these values in the Gateway environment.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_UPCLOUD_ENABLED` | Permit new VM provisioning | `false` |
| `ORBIT_UPCLOUD_TOKEN_FILE` | Absolute path to the provider credential file, mode `0600`, owned by the Gateway user | Unset |
| `ORBIT_UPCLOUD_MAX_VMS` | Maximum outstanding reservations | `0` |
| `ORBIT_UPCLOUD_ZONE` | UpCloud zone for new reservations | `nl-ams1` |
| `ORBIT_UPCLOUD_GATEWAY_ADDRESS` | Public IPv4 address allowed to SSH into the VM | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_ADDRESS` | Public IPv4 address of the WireGuard hub | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_PORT` | WireGuard UDP port | `51820` |

The token goes only to `https://api.upcloud.com/1.3`. Changed settings apply to new reservations. Turning provisioning off does not stop observation or cleanup.

### Enroll an owned project VM

`EnrollUpCloudSandboxAction` enrolls a running reservation as an `app-dev` Node. It needs `ORBIT_UPCLOUD_ENROLLMENT_ENABLED`, `ORBIT_UPCLOUD_DEV_CLUSTER_ID`, `ORBIT_UPCLOUD_MODEL_ADDRESS`, and `ORBIT_UPCLOUD_MODEL_PORT`. It is off by default and starts no agent. The Node stays out of the [fleet rollout](/reference/gateway-recovery#rollout-set-and-order) as `sandbox`. Cleanup removes the Node before the VM.

### Why the UpCloud driver works this way

A started cloud server does not prove that a task can run, so provider state and task state stay separate. Ownership recorded before each request lets cleanup find the VM after a Gateway restart. Refusing a second create when a response is lost prevents duplicate billed VMs.

## Project lane (being removed)

The first build of the web lane is still in the code. The Phase 1 slices of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) delete it. Keep it off. These Gateway settings still exist, with their required values:

| Setting | Required value |
| --- | --- |
| `ORBIT_SANDBOX_PROJECT_CLAIMS_ENABLED` | `false` |
| `ORBIT_INCUS_ENROLLMENT_ENABLED` | `false` |
| `ORBIT_INCUS_PROJECT_WORKSPACES_ENABLED` | `false` |
| `ORBIT_INCUS_DEV_CLUSTER_ID`, `ORBIT_INCUS_MODEL_ADDRESS`, `ORBIT_INCUS_MODEL_PORT` | Unset |
| `ORBIT_SANDBOX_PI_ARTIFACT_PATH`, `ORBIT_SANDBOX_PI_ARTIFACT_SHA256` | Unset |

Generic Instance operations on a sandbox workspace still fail with HTTP 409 `instance.sandbox_managed`. Manage such a workspace through its task group. `bin/sandbox-project-image` still builds Project images for this lane; do not use it. The optional `project_bootstrap` object in `/etc/orbit/sandbox-network.json`, and the helper's `project_enabled` operation, serve only this lane. Leave `project_bootstrap` out.

## Local Incus control

This section and the sections after it describe the Orbit lane. The local driver uses `orbit-agent sandbox` on a managed Linux host over pinned
SSH. The command accepts only bounded typed requests. It embeds the host
controller, so a guest checkout cannot replace host control code. The host's
managed account must already have Incus access; the command grants no permissions.

### Resources

Each reservation records its host, isolated Incus project, VM image fingerprints,
storage pool, subnet, and blocked networks. The project must be marked
`user.orbit.compute.owner=orbit-task-sandbox` and use `features.networks=false`.
VMs and storage belong to that project. Dedicated bridges and ACLs use the host
network namespace because bridges scoped to a project require OVN.

Only UUID-derived resources with the reservation's ownership markers can be
changed. Existing image identities, devices, profiles, and network policy must
match. Public HTTP(S) and public DNS are permitted; fleet, private, host, metadata,
and other group addresses are excluded. Host forwarding policy must also permit
the dedicated bridge. Host firewall access requires the opt-in policy below.

### Image test baselines

Sandbox images need a test baseline from CI. Each successful project job on `main` publishes a `sandbox-tia-<index>-<commit>` artifact for 14 days. It contains the Pest graph and a manifest with the Project path, tested commit, CI run, graph checksum, and test configuration checksums.

The graph records the tested commit and a result for every test file it links, whether the job ran the affected tests or the full suite. Image preparation must select a successful CI run and validate that manifest before importing the graph. Pull request runs do not publish these image inputs. A baseline accelerates local feedback; CI on the published task commit remains the merge gate.

Download the artifact outside the sandbox from a successful `main` CI run. Pass its extracted directory to `bin/tia-cache import-ci --project <path> --artifact <directory> --commit <tested-sha>` inside the image checkout. The command validates checksums, configuration, portable graph paths, test results, and commit ancestry. It seeds only an absent private graph and preserves an existing one. It does not fetch credentials or publish the imported graph to a shared cache store.

When Orbit prepares a sandbox checkout, it records the Project's default branch as `origin/HEAD` without contacting GitHub. Pest uses that local reference to select its baseline. The source ownership record fixes the default branch for the checkout; a changed branch or reference is refused before another turn.

### Power and recovery

A lock on the host serializes provisioning and power operations. Starting and
running VMs consume the host VM budget. The operator and test gateway share one volume for the group checkout. Park
stops every VM before snapshotting the volume and guest disks. Resume restores
all snapshots and rechecks capacity before starting either guest. A create interrupted
before start can be retried under the same identity. A parked sandbox requires
resume. Cleanup removes only that sandbox's VMs, worktree volume, snapshots, bridge, and ACL.

The driver retains ownership after an uncertain result. A missing VM is not proof
of complete cleanup. Only successful destruction releases the reservation as
destroyed. These controls provide the local compute boundary; task workspace,
agent, and scheduler integration must be proven before enabling VM task execution.


### Configure local placement

`ORBIT_INCUS_ENABLED` defaults to `false`. `ORBIT_INCUS_HOSTS` is a JSON list of
hosts, in placement order. Each entry contains:

| Field | Meaning |
| --- | --- |
| `node_id` | Enrolled host Node ID |
| `project` | `orbit-task-sandboxes`, or `orbit-sandbox-proof-<suffix>` for an isolated proof |
| `pool` | Storage pool for guest disks and the worktree volume |
| `max_vms` | Running or reserved VM budget, from 1 to 64 |
| `warm_pairs` | Unassigned Orbit pairs to keep ready: 0, 1, or 2; defaults to 0 |
| `orbit_images` | Pinned VM fingerprints keyed by `operator`, `gateway`, and optional workload role |
| `blocked_networks` | Additional host and LAN IPv4 CIDRs to exclude from public egress |

The allocator accepts only Orbit groups already pinned to `vm`. It reserves an Orbit
pair before provisioning. Existing reservations retain their
provider, host, image, network, and identity; a parked reservation resumes there.
Stopped guests release compute capacity while retaining their subnet and storage.
Orbit groups stay local. These settings alone do not start sandbox task execution.
Web-lane hosts use [`task_vms.incus.hosts`](#configure-task-vms).

### Keep an Orbit pair ready

Set a host's `warm_pairs` to 1 or 2 after its pair images and source template pass acceptance. The target must fit inside `max_vms`. Warm pairs use the same VM budget as task claims; each pair reserves two VMs, a subnet, and its private Pi port. Local compute, Orbit claims, and the Tasks extension must be enabled before replenishment starts. A zero target preserves the default behavior.

A warm reservation has its own UUID and records the exact published images and source template before cloning. It has no task group, workspace Instance, GitHub token, Pi token, or model key. Its running power state confirms the physical pair only. Normal source, native readiness, and Pi admission still run when a group claims it.

A new Orbit claim uses a matching warm reservation before allocating another pair, even when the remaining VM budget is zero. Assignment preserves the sandbox UUID, images, subnet, devices, and source-template provenance. An incomplete warm reservation can finish under the claim's normal retry path. An assigned sandbox never returns to the pool, and deleting its task group does not turn it into a warm reservation.

The scheduler attempts resumes and new claims before replenishing the pool. It creates or retries at most one warm pair per claim pass and does not replenish while eligible Orbit work waits. Failed preparation keeps its recorded identity and capacity reservation for retry. The orphan sweep preserves explicit unassigned warm intent. Reconciliation destroys stale or excess unassigned pairs through their recorded driver; it never removes another group's resources. A host removed from configuration retains its outstanding reservations until its recorded configuration is restored for cleanup.

Disabling the pool stops replenishment and drains its unassigned reservations. Running groups keep their recorded placement and compute mode. First-agent timing must be measured after warm assignment; a running pair alone is not evidence that an agent can start.

### Commands inside a guest

The host accepts a separate `guest_command` envelope addressed to an owned VM
role. It runs the command as the guest's managed `orbit` user, with a clean
environment and passwordless sudo inside the VM. Command input travels on
protected stdin. The host never interprets a guest command as a host shell
program. Time and output limits apply on both sides of the VM boundary.

Guest commands share a per-sandbox lock. Park, resume, and destroy take its
exclusive lock, so they wait for active commands. Guest work does not hold the
host capacity lock and cannot stall operations for another group. This transport
is an internal building block; workspace execution remains gated until the
full task path is connected and proven.

### Workspace command routing

A sandbox workspace records its reservation in `task_sandbox_id`. Task checks,
receipts, signatures, and diffs use that reservation to reach the guest. They
verify the group, Project, workspace, provider, host, and running state before
sending a command. A VM group with missing sandbox ownership fails closed.
Shared workspaces retain their managed-host transport and worker account.

Guest checkout paths are scoped to a sandbox, so different VMs on one host can use the same path. Shared Instances retain their unique Node and checkout path. Each sandbox still owns at most one workspace.

Sandbox checks use the guest's runtime and home. They do not borrow the host's
Vite+ installation, dependency seed, or `orbit-worker` account. Workspace
provisioning remains gated while Git publication, agent credentials, and
image preparation are integrated.

### VMs use temporary GitHub App access

The Gateway keeps the App private key and mints installation tokens scoped to the Project repository. Fetch and publication run directly in the owned VM over authenticated HTTPS. Tokens travel as protected SSH input, never in command arguments, stored origins, or Git configuration. Every Gateway operation obtains fresh access, so later turns do not depend on an expired token. Fetch updates remote-tracking refs without moving HEAD or replacing local work. Publication pushes the exact approved commit to `task-<id>` without force.

This applies to Orbit sandboxes only. No GitHub token enters a [task VM](#task-vms).

### Pi sessions stay bound to their sandbox

A sandbox reserves a random Pi token before guest configuration. The Gateway encrypts it at rest and excludes it from model serialization. Retries reuse the same token. Sandbox Pi connections never use the Gateway-wide token or the host Node’s Pi settings.

A thread records the sandbox reservation as its runtime identity. Creation and later requests check the owning group, workspace, placement, and running state. An Orbit connection uses the configured Incus host’s private address and the sandbox’s reserved proxy port. A parked, destroyed, replaced, or unconfigured sandbox refuses requests instead of selecting another server. Proxy provisioning and image setup remain prerequisites for enabling VM claims.

Sandbox MCP files are installed through the guest transport. They name the disposable test Gateway at `https://gateway.orbit/mcp/search`. Shared topology acquisition is refused for sandbox workspaces. VM reviewer requests record workload requirements and use the owned compute driver and private Gateway readiness gate.

### Prepare source inside the guest

`SandboxWorkspaceSource` initializes a blank checkout with a sandbox ownership marker, fetches remote refs directly from GitHub with temporary App access, and creates the task branch from its published branch or the Project default. The guest initializes its checkout and fetches the selected refs. Retrying a prepared checkout preserves local commits and uncommitted files. A foreign directory, changed origin, or changed checkout branch fails without replacing its contents.

An Incus Orbit sandbox can fetch from GitHub before the cloned private Gateway is retargeted. Each template guest already resolves public names through `1.1.1.1` and `9.9.9.9`, as described in [Build a cold candidate](#build-a-cold-candidate).

Before it refreshes dependencies, pair preparation gives the private Gateway's dnsmasq backend fixed public upstreams at `1.1.1.1` and `9.9.9.9`, which the host boundary permits. Private records and peer DNS routes stay in place. Orbit checks the source owner and isolated Gateway address before it writes the upstream file. A foreign file or failed validation refuses readiness; failed activation removes only the new owned drop-in.

This prepares source only; the claim gate still requires the runtime, model proxy, and topology bootstrap.

### Durable firewall policy on an Incus host

Install the fixed `apps/agent/resources/incus-host-network.py` helper as root-owned `/usr/local/libexec/orbit-sandbox-network` with mode `0755`. Grant the trusted compute account passwordless sudo for that exact executable with no arguments. Never grant a caller-supplied Python script or interpreter. The helper accepts only a bounded JSON request with `operation` (`enabled`, `project_enabled`, `verify`, `ensure`, or `remove`), `project`, and `sandbox_id` on standard input.

The root-owned `/etc/orbit/sandbox-network.json` file opts in selected Incus projects. Its required fields are `version: 1`, `projects`, `pi_host`, `gateway_address`, `wireguard_interface`, and `blocked_networks`.

Use canonical IPv4 values for the host and Gateway WireGuard addresses. Set `wireguard_interface` to the host’s WireGuard interface name. The host address must belong only to that interface, and its link kind must be `wireguard`. Include the host's LAN networks in `blocked_networks`. Keep the file at mode `0644` under directories that only root can write. An absent installation preserves the existing behavior. An incomplete or unsafe installation refuses new provisioning. The helper verifies the installed boot unit, its enablement, and the loaded Incus dependency before granting access.

Only new bridges receive `user.orbit.compute.host_network=1`. Existing unmarked bridges retain their current firewall policy. The helper checks the root configuration, project ownership, exact bridge identity, bridge settings, and ACL ownership through local Incus before granting access. It derives the subnet from the bridge and excludes private, metadata, multicast, host, LAN, and other sandbox destinations from public access. The request cannot supply rules, addresses, paths, or commands.

The helper installs dedicated IPv4 filter chains and scoped jumps ahead of the host's existing INPUT, OUTPUT, and FORWARD rules. It permits public HTTP(S), UDP/TCP DNS only to `1.1.1.1` and `9.9.9.9`, replies to those connections, DHCP, the operator's own model relay, and the recorded Gateway's Pi connection through that WireGuard interface. Pi replies must leave through the same interface. A matching source address on another interface is refused. Own topology peers can communicate. Other traffic involving the bridge is dropped. Dedicated IPv6 chains drop all traffic involving the bridge. Incus NIC filtering and ACLs remain active.

Root-owned manifests persist before rules are applied. Each address family changes in one `iptables-restore --noflush` transaction. IPv6 protection precedes IPv4 access. A failed second transaction retains the manifest and the first family's rules for retry. Retries restore a completely missing owned policy, but refuse changed chains, jumps, configuration, or foreign files.

Boot restoration waits up to 60 seconds for the recorded host WireGuard address, then verifies its interface before restoring access. Resume checks the policy before starting guests. Parking retains it. Destruction stops and removes guests, removes only the recorded policy, audits its absence, and then deletes the bridge. Unrelated chains and rules are preserved.

Install `apps/agent/resources/orbit-sandbox-host-network.service` and the supplied Incus service dependency before opting in. The boot service waits for host networking, verifies that every connected host network is still excluded, and restores every recorded policy before Incus can start. A new public host network outside the saved exclusions refuses startup until the operator recovers the policy.

The host needs Python 3, systemd, `iptables`, `ip6tables`, their save/restore tools, and local Incus. Firewall reloads must restore the owned policies before starting or resuming sandbox work. Do not flush the helper's chains as part of another service's policy update. Rule or jump drift requires operator recovery; the helper does not overwrite it. Drain every marked sandbox before removing the configuration, helper, or boot dependency.

The privileged CI test runs real packet checks in a disposable Linux network namespace. This proves packet filtering, retries, drift refusal, and exact cleanup without changing the host namespace. Installing the durable policy on a live host and proving disposable Incus connectivity are separate rollout steps. Keep Orbit VM claims disabled until those steps pass.

### Pi proxy on an Incus host

An Orbit host can set `gateway_address` to the real Gateway’s WireGuard address in `compute.incus.hosts`. New Orbit reservations then record the host’s WireGuard address and a port from `23001` to `23254`. A stopped reservation retains its subnet and port. The operator’s Incus NAT proxy forwards that private port to guest port `3774`; its ACL permits only the recorded Gateway to enter through that port.

The proxy never binds a public or wildcard address. Changing or removing an existing proxy through reprovisioning is refused as device drift. This requires a Pi server in the image and host forwarding that permits the owned bridge; creating the proxy does not weaken other host firewall rules.

Guest preparation admits Pi traffic from the recorded live Gateway to the operator's reserved bridge address on TCP port `3774`. A service owned by root restores this rule and a source-specific return route at boot, before Pi starts. The return route applies only to replies from that bridge address to the live Gateway. Traffic from the operator's isolated WireGuard address keeps its existing routes, even when the live and private Gateway addresses overlap.

Preparation finds the guest interface by its reserved address; an Incus device name does not fix the VM's interface name. It refuses foreign routing-table entries, policy rules, service files, or marked firewall rules. Retries keep the same policy and Pi process. These guest rules do not change the host ACL or permit new outbound fleet access.

### Group model keys

`SandboxModelKeys` reserves a random key in encrypted, hidden sandbox storage before registering it with CLIProxyAPI. Registration retries reuse the key and its recorded endpoint. A changed management endpoint refuses recovery until the original endpoint is restored. Registration stays disabled unless `compute.model_proxy.enabled` is enabled.

Revocation uses a JSON `PATCH` that replaces only the group’s key with an empty entry. It does not send credentials in a URL or replace the complete key list. Before registration or revocation, Orbit ensures that a separate random authentication key is present. This key stays encrypted in Gateway settings and never enters a guest. It remains after the last sandbox is destroyed, because CLIProxyAPI permits unauthenticated requests when no usable keys remain.

Orbit verifies that the revoked key and anonymous requests receive `401` before clearing its stored group key. Empty entries contain no credential or group identity. An uncertain result retains the encrypted key for cleanup retries.

Allocation registers model credentials and reserves the Pi token before compute provisioning. A failed registration leaves the reservation for retry without creating a VM. Lifecycle operations for one reservation use a shared lock. Parking retains both credentials. Destruction records its intent, revokes the model key, then asks the driver to remove compute. Failed revocation prevents resource removal; failed removal retains the Pi token until cleanup succeeds. The raw drivers refuse destruction while a model key remains.

### Guest Pi runtime

`SandboxPiRuntime` configures the image’s `/usr/local/bin/orbit-pi-server` binary as the managed `orbit` user. It writes only the reservation’s Pi token and model key into private guest files. Its model provider is `orbit-sandbox`, with the fixed URL `http://127.0.0.1:8317/v1`. The host relay remains a prerequisite. Set `compute.pi.models` to the supported model descriptors before preparing a workspace. Sandbox sessions use this provider even when a task names another provider.

Runtime preparation checks the reservation and checkout, refuses foreign files or changed credentials, and confirms authenticated Pi health. Repeating preparation keeps a healthy service running, so existing sessions remain available. The image must contain the Pi binary and the managed user with sudo; it must not contain `orbit-worker` or subscription credentials.

### Model relay on an Incus host

An Incus host can set `model_proxy_origin` to a fixed HTTP(S) origin on loopback or `10.44.0.0/16`. It must match the endpoint where the Gateway registers model keys. Each reservation keeps that origin. The agent creates an owned Caddy user service bound to that group’s bridge address on port `8317`. Only the operator address can enter, and only model API paths pass through. Management paths and other HTTP methods return `403`. The relay forwards the group key unchanged and has no shared credential.

This needs the host’s Caddy binary and an existing service manager for the compute account. It does not enable a user service manager or change host firewall rules. The Incus ACL permits only the operator to reach its own bridge on this port. Parking retains the relay and key; resume checks the recorded origin; destruction stops the service and removes its owned files before removing the network. Drift refuses mutation.

Guest preparation uses systemd socket forwarding from `127.0.0.1:8317` to the owned bridge. It verifies model-key authentication through that path before reporting readiness. A disposable connectivity proof remains a prerequisite for enabling this host option.

### Review retention policy

`TaskSandboxLifecycle::review` records the start of each review wait before changing compute. Incus has a five-minute grace period when there is no capacity waiter. A capacity waiter ends that grace immediately. Repeated calls keep the original deadline. A reservation with `preview` enabled stays running. Changing it to a preview after parking resumes it through the same credential checks.

Incus retains its stopped snapshot until resume or destruction. Failures retain the deadline and ownership for retry. Preview retention does not prevent explicit merge cleanup.

Review timing, confirmed parking time, and VM power are stored separately. A confirmed activation clears review timing for the next cycle. A failed activation retains it. Resume intent is recorded before the driver runs and stays until running power is confirmed, so an uncertain resume retries restoration instead of provisioning.

The scheduler reconciles review retention after publication and on later ticks. It restores Incus compute before fetching or starting resumed work. Eligible VM review resumes are attempted before new todo claims. A compute failure remains visible in `capacity_wait_reason`; it cannot start an agent through a shared workspace.


## Task workspace cleanup

Merge and cancellation remove task workspaces through their sandbox reservations. Under the group admission lock, Orbit checks the group, Project, Instance, reservation, and compute host. It refuses foreign group references and unexpected live Routes, Processes, Schedules, or database connections. Guest checkout paths never reach host source inspection or deletion.

For an Orbit Incus pair, Orbit revokes the model key, destroys owned compute, and confirms destruction before deleting the workspace row and clearing its task references. A failed operation retains ownership for retry. The reservation remains as audit history. A foreign workspace, Node, role, or live resource reference refuses cleanup.

The sweep retries reservations with no Instance when their group has ended, has been deleted, or has already recorded destruction intent. It does not adopt unrecorded host resources or start cleanup of an active group. Failed retries use the workspace sweep's time budget and backoff.

## Declared workload nodes

An Orbit subtask can declare `topology: ["app-dev", "app-prod", "app-prod-2"]`. The list names distinct workload nodes; omit it or use an empty list for the default operator and test Gateway. Subtask creation, group creation, and stored task definitions preserve this declaration. Only a subtask that has not started can change it.

Before dispatch, Orbit reserves the additional VM capacity, starts the pinned workload images on the group's network, and enrolls them with its private test Gateway. Each requested node must pass its native readiness check before the agent starts. A missing image, full budget, or failed enrollment leaves a visible wait. Workload nodes never join the live fleet. Resume repairs incomplete native enrollment before verifying the recorded expanded inventory. Cleanup includes every added node.

A reviewer’s `topology_requested` fallback records `app-dev` and `app-prod` as requirements on the current subtask. It uses the owned sandbox and its private Gateway. Orbit waits for capacity and fresh doctor readiness before resuming the reviewer. Repeated requests keep existing workload nodes. Web-lane groups refuse this Orbit-only request and continue in their workspace.

Native enrollment gives each workload a fresh SSH host key and records its ownership outside the shared checkout. Retries keep that key and the native Node identity. The private Gateway pins the key, provisions the requested role, and grants operator access through Orbit. Each guest must match the recorded source, subnet, and Node inventory before doctor can admit a turn.

Adding a workload node requires host images from the sandbox’s recorded template and the same pinned pair images. A host configuration change cannot replace a running group’s template. An incomplete expansion retries its recorded images; it does not adopt new configured images. Workload commands require the complete source descriptor to match the Project repository and default branch.

## Saved pair identity

The saved Orbit pair needs its network identity updated after cloning. The guest helper accepts either the existing four-Node topology or exactly a Gateway and a roleless operator. For the pair, it refuses additional Nodes or operator roles before changing addresses. It preserves private addresses, keys, DNS policy, and other settings while replacing the stored SSH addresses and Gateway endpoints.

## Sandbox power

Task group responses report `sandbox_power` separately from task status. It is `running`, `stopped`, or `destroyed` only when the owned reservation confirms that state. Shared groups, missing ownership, and transitions return `null`. Destroyed reservations remain visible after workspace cleanup. The CLI shows this value as **Sandbox power** for VM groups.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. Shared groups store the intent without changing shared-host power. Phase 1 does not park task VMs, so preview does not change them.

Use `orbit tasks:create --preview` or `orbit tasks:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent. A successful update stores intent. The next scheduler reconciliation changes compute, and `sandbox_power` reports the confirmed observation. Capacity and restore failures remain visible.

## Saved source templates

Set `orbit_source_template` on an Incus host configuration to bind its Orbit images to a saved source template. Allocation requires the template repository and default branch to match the Project and pins the descriptor on the reservation. The descriptor contains a template UUID, repository URL, default branch, and source commit. Each image and the dedicated source volume's `ready` snapshot carry that same identity. The source volume must have no attachments. A template is published under a new UUID; it is never updated in place.

The host validates the complete template before creating resources. It copies the snapshot into the group's worktree volume with group ownership in the create request. A retry accepts only that group's volume with the same template identity. It does not overwrite group work or adopt an unrelated populated volume. Requests without a template still create an empty volume for the existing source-initialization path.

This copy protocol is an internal building block. Image sanitation, source adoption, pair retargeting, and runtime readiness must all pass before a scheduler claim can use a saved pair. VM claims remain gated until the full lane is proven.

### Verify cold-build inputs

`bin/sandbox-template-inputs --check` validates an offline input set before candidate construction. It accepts a JSON object on standard input with exactly `root`, `packages`, `tools`, `source`, and `composer`. `root` is an absolute directory. `packages` is a nonempty list; the other three inputs are single objects. Each object contains exactly `file` and `sha256`. File names are relative to `root`; parent traversal, symlinks, duplicate paths, and nonregular files are refused. Package names must end in `.deb` and must encode an epoch separator as `%3a`, rather than a literal colon, because APT can silently ignore local paths containing a colon.

The command verifies every SHA-256 digest and checks both gzip tar archives without extracting them. Source entries must remain inside the source root. Tool entries are limited to the Node, Vite+, and pnpm trees under `opt/orbit-image` and the Bun, Pi server, and Orbit Agent binaries under `usr/local/bin`. Links cannot escape those boundaries. Hard links must refer to an earlier regular file. Duplicate entries, link ancestors, devices, FIFOs, privileged mode bits, and oversized archives are refused. Success returns file hashes and archive counts; errors return a fixed code without input paths or file contents.

Input verification does not install packages, create a candidate, certify upstream provenance, or publish an image. The builder must obtain packages and tool checksums from trusted sources, import the pinned commit's green CI baselines, and verify the same inputs again in the guest before use. Keep package acquisition outside the guests. Install the closed package set with an empty APT index and no external source; keep removals and downgrades disabled. Complete native enrollment and the publisher's guest audit before marking a template ready.

Cold tool inputs include the pinned pnpm package under `opt/orbit-image/pnpm`. The builder seeds one Vite+ home, including its Node and package-manager caches, and publishes the same managed entry points used by native app-role convergence. Bun lives under `/opt/orbit/bun`. Image audit verifies these entry points as the managed user from its home before publication. A blank workload image must support native role convergence without replacing its tool layout.

### Build a cold candidate

`bin/sandbox-template-build --plan` verifies inputs and refuses existing candidate resources. `--prepare` allocates a new isolated pair through the production Incus helper, installs the pinned offline inputs, creates the managed `orbit` account, and verifies the shared source and CI baseline hashes. `--converge` installs public DNS upstreams in each owned guest, bootstraps the private Gateway, and enrolls its roleless operator through native Orbit commands. Public DNS uses `1.1.1.1` and `9.9.9.9` with the systemd resolver default route. More specific fleet DNS routes remain authoritative. Guest DNS never depends on access to the Incus host. A foreign resolver file or failed resolver restart refuses readiness.

Each mode accepts the same JSON object with the required fields `project`, `pool`, `sandbox_id`, `budget`, `subnet`, `blocked_networks`, `base_image`, `inputs`, and `source_manifest`. `workload_roles` is an optional ordered list from `app-dev`, `app-prod`, and `app-prod-2`. Omission builds only the pair. The budget must cover all requested guests.

`project` must be a dedicated, owned `orbit-sandbox-proof-<name>` project. `base_image` is an imported x86_64 VM fingerprint. `inputs` uses the offline verifier's schema. `source_manifest` contains exactly `source_template`, `ci_run`, `projects`, and `sha256`; the final hash must identify the source archive.

The source manifest pins Composer lock hashes for the root and all five PHP projects, CI graph hashes for those five projects, and Bun lock hashes for the annotation package, web app, and Pi server. It records the green CI run used to prepare those graphs. Acquire and verify upstream image, package, tool, and CI artifacts before construction; a supplied hash or run ID alone is not proof of origin. Tool inputs include Node 24.21.0, Vite+ 0.3.0, pnpm 10.33.0, Bun 1.4.2, and compatible pinned Agent and Pi binaries. The fresh base must have no `orbit` or `orbit-worker` account and no UID or GID 1002.

Preparation refuses reused resources and a populated source volume. It retains a partial candidate after failure for inspection and explicit owned cleanup. Convergence accepts only that candidate's completed preparation receipt and matching inputs. Commands serialize with publication for the pair and template.

Before native convergence, the builder changes the standard Ubuntu archive and security source URLs from HTTP to HTTPS in every prepared guest. It preserves custom repositories, suites, components, comments, and file permissions. It refuses symlinked or writable source paths before changing any file. Retrying this step preserves sources already using HTTPS. Native convergence needs permitted public egress for package-source verification; the builder never adds host firewall rules. Complete any required host-policy approval separately.

Convergence confirms the pinned source commit, the isolated operator profile, and active Gateway and roleless operator inventory. Publication repeats that native readiness check. No builder mode promotes an alias, enables claims, or changes a live Gateway.

Before each agent dispatch, admission compares the private Gateway version with the owned branch commit. It refreshes Gateway dependencies, migrations, and services only when the version differs, then refreshes native pair prerequisites and requires fresh doctor readiness. A running baseline is polled before this preparation can modify the checkout.

The builder can prepare blank workload guests alongside the pair. All guests share the pinned source and verified offline inputs, including Docker prerequisites for workload roles. Convergence enrolls only the operator. Publication audits each workload for prerequisites and absence of enrollment state, then publishes every requested role with the same immutable source descriptor. Pass the same `workload_roles` to the publisher. Adding roles requires a new template; published templates cannot be extended in place.

Task runtime preparation uses the sandbox’s recorded image roles as its exact private Node inventory. For an expanded resume, it refreshes the private Gateway branch runtime and retries owned workload enrollment before strict retargeting. It retargets every recorded peer, requires an active workload role on each declared Node, and refuses foreign or extra Nodes. The operator stays roleless. Template publication keeps the stricter default-pair check.

### Prepare a copied source volume

The internal `guest-template-source.py` helper prepares a disposable source copy before publication. Run it as the managed user, with a JSON request on standard input containing `checkout` and `source_template`. The descriptor has exactly `id`, `repository`, `base`, and `commit`. The copy must already contain `.git/orbit-template-candidate.json` with that descriptor. The host builder must verify its own candidate volume and exclusive attachment before writing this marker.

Preparation refuses task-owned checkouts, changed source, mismatched identity, unsafe Git configuration, and external Git metadata. It checks the pinned commit before mutation, normalizes local and remote branches to the default branch, and writes the template marker without replacing it. Repeating the same request verifies the published state. Dependency caches remain intact. Errors return a fixed message without command output or credentials.

This helper prepares source only. It does not certify ignored files, dependency provenance, CI baselines, guest credentials, or image sanitation. The complete image publisher must verify those prerequisites before publishing the source snapshot and private pair images. Do not use the helper on a live workspace or a promoted template.

### Publish a disposable candidate

`bin/sandbox-template-publish --plan` validates a candidate without changing it. `--apply` prepares its source, stops every candidate guest, and publishes a private VM image for each recorded role and a dedicated source volume with a `ready` snapshot. Both modes accept one JSON object on standard input and return one JSON object. The required fields are `project`, `pool`, `sandbox_id`, and `source_template`. The optional `workload_roles` list must match the builder request. The template descriptor is the same as the source helper's descriptor.

The builder must mark every candidate VM and its source volume with `user.orbit.template.candidate=<template UUID>`. The names and compute ownership must match `sandbox_id`. Only the recorded candidate guests may attach the source volume. The candidate must be running, have only its root disk, source disk, and group network, and contain no task-source or Pi/model runtime state. The command refuses existing template volumes or matching image identities. It never changes a promoted alias or accepts an unmarked pair.

The publisher runs independent guest audits together, bounded by the requested inventory of at most five guests. Each guest command has a 60-minute limit for scanning mounted source and offline inputs. It waits for every audit before changing source, stopping guests, or creating publication outputs.

The command checks guest prerequisites and known credential locations before source changes. It refuses GitHub tokens in guest files or process environments, subscription credentials, and the shared worker account. This audit complements a clean image build; it cannot establish provenance for arbitrary candidate files. The cold builder must still supply verified packages, tools, dependencies, and CI baselines.

Publication returns pinned image fingerprints and the source descriptor for host configuration. It leaves the candidate stopped. On failure it removes only output resources carrying this operation's exact template identity, then audits their absence. It retains the candidate for diagnosis. A host crash can leave owned outputs; inspect them before retrying. Existing output identities are refused rather than overwritten. This command does not enable claims or change host firewall policy.

### Adopt a template checkout

A source template includes `.git/orbit-sandbox-template.json` with the same descriptor as its reservation. Before first use, the guest checks that marker, the pinned commit, the default branch, and a clean tracked and untracked tree. Ignored dependency caches can remain. The template uses a real local Git directory, contains only the default local and remote branches, and exposes only its canonical origin URL. Git includes, custom filters, alternate object stores, and replacement history are refused.

After validation, the guest records group ownership without replacing an existing marker. It fetches the task branch directly from GitHub and selects that branch, or the current default branch for new work. The template commit is the seed; the imported branch supplies the task's starting commit. Repeated preparation checks the recorded template identity and preserves local commits, dirty files, and dependencies. A populated checkout without the matching template marker remains refused.

### Prepare the isolated pair

Pair preparation checks group source ownership before changing the test Gateway. It repairs the saved Gateway inventory and the operator's WireGuard endpoint on the group's subnet. The Gateway ships copies of the same repair helpers tested by the E2E harness; its quality check verifies that they match.

The test Gateway validates Composer manifests and lock files. It refreshes dependency autoloaders and installs dependencies when lock files changed or dependencies are missing. It sets the private Gateway version to the verified branch commit, clears branch runtime caches, and runs migrations against its own SQLite database. It repairs Caddy’s checkout access and restarts PHP-FPM. After peer endpoints and workload enrollment are ready, it refreshes the pair’s native prerequisites before checking doctor health.

Prerequisite preparation validates the exact recorded private Node inventory, the Gateway at `10.44.0.1`, and the roleless operator at `10.44.0.3` before mutation. It converges both Nodes through the native Node Agent runtime, rebuilds the Gateway’s Caddyfile, and publishes private DNS from current branch intent. Agent convergence verifies the pinned public release checksum and configures the service and its private per-Node secret. Any failed converge refuses runtime readiness and leaves the reservation available for retry. Preparation also enables the native VPN DNS backend for boot, so it remains available after park and resume.

Pair preparation provides an owned `orbit` launcher in the operator’s `~/.local/bin`. It runs the CLI from the group’s branch checkout, so task commands use that branch’s behavior. A foreign launcher or linked launcher directory refuses preparation.

The operator then uses its isolated Gateway profile to list the active Gateway and roleless operator. Preparation reports success only when both guests confirm the same branch commit and the Gateway API reports that version. Admission also requires fresh node, role, and firewall doctor health for every recorded Node. Cold-image convergence applies the same native Agent convergence and pair doctor checks before marking a candidate ready. It never routes these commands through a shared host or a project-lane Node.

### Admit an Orbit sandbox claim

`ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED` defaults to false. Keep it off until the complete Orbit lane and host connectivity policy are proven. With the switch enabled, an Orbit claim needs local pair images, a pinned source template, the private Pi endpoint, the model relay, and Pi model configuration. Other Projects use [task VMs](#task-vms).

Provisioning reserves and attaches an owned workspace before preparing source, the isolated pair, and Pi in that order. It holds the group's execution lock during preparation. A failed step retains the reservation and workspace for retry; it never adopts an unrelated workspace or falls back to shared compute. Only successful preparation returns the workspace to the scheduler, which runs the Project's baseline setup and check before starting an implementer.

