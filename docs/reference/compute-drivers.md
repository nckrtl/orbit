---
title: "Compute drivers"
description: "Provider-owned virtual machines, capacity, and recovery for task sandboxes."
covers:
  - apps/gateway/app/Domain/{Compute/**,Tasks/SandboxHostOperation.php}
  - apps/gateway/app/Actions/Compute/**
  - apps/gateway/app/Infrastructure/{Compute/**,Tasks/IncusSandboxHost.php}
  - apps/gateway/app/Models/TaskSandbox.php
  - apps/gateway/config/compute.php
  - apps/gateway/resources/compute/**
  - apps/gateway/database/migrations/*{create_task_sandboxes_table,add_fleet_enrollment_to_task_sandboxes,add_pi_ready_at_to_task_sandboxes}.php
---

# Compute drivers

The UpCloud compute driver provides the VM lifecycle for a task sandbox. It is an internal Gateway contract, disabled by default. Shared groups continue on existing Nodes. VM groups use their lane only when its claim switch is enabled. Enrollment uses the managed fleet path. Project claims use the UpCloud path behind a separate switch that defaults to disabled. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) defines the ownership and recovery policy.

## Provision a VM

`ProvisionTaskSandboxAction` reserves one sandbox for a managed task group. It uses the pinned image and smallest size, and takes the network from Gateway configuration. The first size is `starter-small`: one CPU, 1 GB memory, a 20 GB disk, and 1 GB swap. The image is the pinned Ubuntu Resolute template. The disk meets the task image minimum from the DLF experiment; a provider power state does not prove that a project fits or that bootstrap has finished.

The reservation records its UUID and immutable image, plan, network, and Gateway public SSH key before any provider mutation. A lock for the provider serializes claims against the configured VM budget. Reserved, uncertain, stopping, and deleting VMs all consume capacity. A task group reuses its current reservation. A destroyed reservation stays in history; a later claim gets a new identity.

The driver creates a VM with cloud-init. Cloud-init creates the managed `orbit` user, authorizes only the Gateway's public SSH key, installs base prerequisites, adds swap, and creates an empty `/home/orbit/orbit` checkout directory owned by `orbit` with mode `0700`. Source preparation verifies that directory before importing the Project. Cloud-init contains no provider token, GitHub token, Pi token, proxy key, or subscription sign-in. Project runtime and agent configuration belong to the later enrollment step.

## Credentials and network

The provider token stays on the Gateway. `ORBIT_UPCLOUD_TOKEN_FILE` names a regular file with mode `0600`. It contains the existing `upctl` configuration's `token: ucat_…` line. The file must belong to the Gateway process user. The HTTP client sends it only to `https://api.upcloud.com/1.3`, refuses redirects, and reports fixed error messages without provider response bodies or chained request exceptions.

The driver installs a provider firewall before reporting a VM as running. Incoming public traffic is limited to SSH from the configured Gateway and WireGuard from the configured hub. Outgoing public traffic permits HTTP(S), DNS, and the hub's WireGuard endpoint. Private and link-local destinations are blocked, with one bootstrap exception: HTTP to the metadata service at `169.254.169.254`. Cloud-init needs that endpoint to receive its initial configuration.

After verifying cloud-init completion, the enrollment caller must call `sealNetwork`. The driver records the sealed policy before replacing and verifying the firewall, and removes the metadata exception. A failed update retains that intent for retry. The later fleet enrollment must also install hub-side limits on decrypted WireGuard traffic before an agent starts; the provider firewall cannot inspect traffic inside that tunnel.

The reservation records an irreversible credential fingerprint before creation. If the credential changes, observation and deletion fail closed; responses from another account must never count as proof that the VM was deleted. Destroy outstanding sandboxes before rotating this initial driver's credential. Account identity and deliberate credential rotation belong to a later integration.

## Gateway configuration

Set these values in the Gateway environment before provisioning a sandbox.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_UPCLOUD_ENABLED` | Permit new VM provisioning | `false` |
| `ORBIT_UPCLOUD_TOKEN_FILE` | Absolute path to the protected provider credential file | Unset |
| `ORBIT_UPCLOUD_MAX_VMS` | Maximum outstanding sandbox reservations | `0` |
| `ORBIT_UPCLOUD_ZONE` | UpCloud zone for new reservations | `nl-ams1` |
| `ORBIT_UPCLOUD_GATEWAY_ADDRESS` | Public IPv4 address allowed to SSH into the VM | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_ADDRESS` | Public IPv4 address of the WireGuard hub | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_PORT` | WireGuard UDP port | `51820` |

Changing a setting applies to new reservations. Existing reservations keep their recorded network and image. Disabling new provisioning does not disable observation or cleanup.

## Observe, park, resume, and destroy

### Enroll an owned project VM

`EnrollUpCloudSandboxAction` is an internal, separately gated enrollment step. It does not enable scheduler claims or start an agent. Set `ORBIT_UPCLOUD_ENROLLMENT_ENABLED`, `ORBIT_UPCLOUD_DEV_CLUSTER_ID`, `ORBIT_UPCLOUD_MODEL_ADDRESS`, and `ORBIT_UPCLOUD_MODEL_PORT` to prepare project-lane enrollment. The selected development Cluster must have an active router. The Gateway and WireGuard hub must have active role assignments. The model address must be a WireGuard IPv4 address; it is not a subscription credential.

Before any remote enrollment mutation, the action reserves a Node and records both sides of its sandbox ownership in one database transaction. The reservation retains its cluster, hub, Gateway, router, model endpoint, and WireGuard address. Retries use that identity, refuse changed endpoints or foreign Node ownership, and never adopt an existing Node merely because its name matches. Generic Node provisioning refuses these owned Nodes.

The Gateway verifies the provider server and disk, then pins the newly assigned public address's first SSH host key. This initial key scan is trust on first use over the provider-owned address; it is not a provider-signed key attestation. Later key changes are refused. The action checks that cloud-init has finished successfully and that no `orbit-worker` account exists, then seals provider metadata access.

Before publishing the sandbox's WireGuard peer, the Gateway installs an owned nftables policy on the hub. Rules run before the hub's normal forwarding accept rules. Only DNS on the hub, the Gateway API, the recorded model endpoint, and control/preview connections from the Gateway and dev router are permitted. Other tunnel traffic is dropped, including fleet, LAN, metadata, and other task destinations. Public HTTP(S) and DNS still use the VM's provider network. The sandbox has no outgoing Node access grants.

The hub policy has a separate owned table and protected files per reservation. Repeating the same policy verifies it; changed intent, foreign files, or rule drift refuse mutation. A systemd dependency loads the policy before the WireGuard interface at boot. A failure retains the Node and reservation for recovery and prevents enrollment from being reported complete. The hub needs nftables, Python 3, systemd, and root network-namespace support for validation. Enrollment never installs packages on the shared hub.

The recorded helper source is immutable for that reservation; an upgrade that changes it requires draining the reservation or an explicit migration. The fixed preview ports are 80, 443, and 5173; other preview listeners remain blocked.

The native WireGuard peer listens on the UDP port recorded in the sandbox specification. This matches the provider firewall rule for hub replies; a random guest port would prevent the handshake.

The managed `orbit` user performs enrollment. This step provisions the native Node and its `app-dev` role, without Pi, model keys, GitHub access, or project source. The enrolled Node stays out of the [fleet rollout](/reference/gateway-recovery#rollout-set-and-order) as `sandbox`, because the Node is removed with its task group. Pi/runtime preparation, workspace creation, task claim admission, and cloud reconstruction remain later steps. Cleanup must remove the owned fleet peer before removing its hub policy or provider resources.

The packet regression fixture runs in disposable network namespaces on Linux: `sudo -n unshare --net python3 tests/Fixtures/Compute/sandbox_hub_network_test.py resources/compute/sandbox-hub-network.py --packets` from `apps/gateway`. It tests real nftables traffic, drift refusal, retries, and file ownership. It mocks systemd operations and does not prove boot ordering. Incus boot and deployed fleet acceptance remain rollout checks.

Each call makes a bounded set of provider requests. No call waits in a sleep loop for boot or shutdown. Call `observe` again to see the next provider state. `running` means the provider reports the server started and the firewall matches the recorded bootstrap or sealed policy; SSH, cloud-init, Pi, and Instance readiness are separate checks.

`park` stops the VM without deleting its disk. `resume` starts that same VM. A stopped UpCloud Starter VM remains billable. The sandbox lifecycle records the review start and requests destruction after one hour; the raw driver does not set that timer.

`destroy` records deletion intent, stops the VM, verifies its exact server identity and disk ownership, then deletes only the recorded server and disk. It confirms that both are absent before releasing capacity. If the server is already absent, the recorded disk can still be cleaned up, but only when its title matches the reservation and it is detached. A sandbox attached to an enrolled Node cannot be destroyed until the Node leaves the fleet. Unrelated VMs, disks, and snapshots are never swept or adopted.

## Recover a lost response

Creation is sent at most once per reservation. The driver records that it is about to send the request before sending it. If the response is lost, a later call searches for the reservation's unique hostname, title, and ownership label. One exact match can be recovered. Several matches, a mismatched identity, or no match after an ambiguous create fails closed. It never sends another create request merely because a timeout or an empty list occurred.

A crash between recording the attempt and sending it can therefore require operator recovery. Keep the reservation until the provider has been checked. An uncertain creation consumes capacity and cannot be forgotten by `destroy` while its existence is unresolved.

Stop, start, firewall replacement, and deletion are reconciled from current provider state. Provider errors retain the reservation and fixed error code for retry. Destroyed rows remain available for audit. A migration rollback refuses to drop the ownership table while any reservation remains outstanding. Deleting a task group leaves its sandbox record intact so cleanup cannot lose the provider identity.

## Why it works this way

Provider state and task state are separate. A cloud server being started is not proof that a task can run. Stable ownership recorded before mutation makes failures inspectable and allows cleanup after a Gateway restart. Refusing a second ambiguous create prevents duplicate billable VMs.

The compute boundary exposes typed image, size, and network intent rather than caller-supplied cloud-init or shell commands. The UpCloud driver uses the HTTPS API directly; the Gateway does not need `upctl` installed. Later providers can implement the same lifecycle without changing the task engine.


## Local Incus control

The local driver uses `orbit-agent sandbox` on a managed Linux host over pinned
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

### Local Project image ownership

A new local Project reservation records `project_slug` beside its one pinned `operator` image. The host accepts only a private x86_64 VM image with `user.orbit.project.owner=orbit-task-project-image`, `user.orbit.project.slug=<project-slug>`, `user.orbit.project.account=orbit`, and `user.orbit.project.bootstrap=unenrolled`. Image preparation must verify the managed account and absence of fleet or private-topology identity before assigning these properties. An image with Orbit template properties is refused even if it also has Project properties.

Provisioning verifies the Project marker on an existing guest and worktree volume before any mutation. A retry cannot change the Project or adopt an unmarked reservation. These image checks do not enroll the guest or authorize SSH, WireGuard, or hub access. Keep local Project claims disabled until bootstrap, network, fleet, Route, and cleanup acceptance has passed.

### Enroll an owned local Project VM

Local fleet enrollment has its own disabled-by-default `ORBIT_INCUS_ENROLLMENT_ENABLED` gate. Set `ORBIT_INCUS_DEV_CLUSTER_ID`, `ORBIT_INCUS_MODEL_ADDRESS`, and `ORBIT_INCUS_MODEL_PORT` for new reservations. The recorded host, private SSH endpoint, public hub endpoint, one Project image, and subnet must match the owned running guest. Initial admission reads the SSH public key through the host's read-only Incus identity operation before reserving a fleet Node.

The reservation pins both sides of Node ownership, the complete Incus placement, and the SSH key. Retries verify the same guest and key through a separate read-only fleet identity operation. This operation permits existing enrollment files but keeps all host placement and firewall checks. It cannot replace a missing Node or repin a changed key.

The hub confirms the sandbox's fleet limits before native provisioning publishes its peer. Its configured public UDP endpoint must match the recorded bootstrap endpoint, including on retries. After verifying the owned guest's SSH identity, the Gateway installs its public SSH key through the Incus control channel. A clean Project image contains no fleet keys. The bootstrap key file accepts only that key and refuses unsafe paths or foreign keys.

The same authenticated bootstrap aligns the guest's UTC clock with the Gateway once per guest boot. It repeats alignment after a stopped VM boots again, before runtime admission. A protected receipt prevents same-boot retries from changing the clock or a running check's process identity. Clock alignment uses the existing Incus control channel and adds no network grant.

Bootstrap uses the recorded private host address and reserved SSH port; enrolled traffic uses the VM's own WireGuard address. The Node joins only as `app-dev`, uses the managed `orbit` account, and has no grants to other Nodes. Local Project workspace admission has a separate disabled-by-default `ORBIT_INCUS_PROJECT_WORKSPACES_ENABLED` gate. Enable it only after local enrollment, runtime, and cleanup acceptance.

### Local Project SSH identity

The typed `project_identity` host operation reads the SSH public key from one running, owned Project VM. It verifies the private image provenance, guest and worktree Project markers, storage pool, subnet and devices first. The bridge must reject traffic by default. It refuses an Orbit topology guest, additional guests, foreign worktree attachments, or changed placement. The Gateway compares the response with the reserved Project, image, pool and subnet and validates the Ed25519 key before using it as a pinned SSH identity. Private key bytes never leave the guest.

### Local Project bootstrap reservation

An Incus host can record `project_bootstrap` with a public IPv4 `wireguard_address` and UDP `wireguard_port`. This requires the host's private `gateway_address`. Allocation records these endpoints with the host's WireGuard SSH address and a port from 24001 through 24254 in the Project reservation. Its subnet keeps that port reserved while parked. Existing reservations retain their endpoints when configuration changes.

The host validates the closed `project_bootstrap` descriptor against its own interface and the separately approved root policy before recording it on the bridge, guest, and worktree volume. A retry refuses changed or missing endpoint markers before mutation. The SSH proxy listens only on the recorded host WireGuard address and reserved port, and forwards to the owned guest at port 22. Host filtering accepts the recorded Gateway on the WireGuard interface only when the connection's original destination is that host address and port.

The host translates the source of only that bootstrap flow to its bridge address, so SSH replies keep their return path when the guest starts WireGuard. Enrolled SSH uses the guest's WireGuard address. Direct SSH to the guest and other proxy ports remain blocked.

The guest's temporary SSH recovery rule accepts its bridge address at guest port 22. The host proxy port is an external endpoint and is never opened inside the guest. Before an unfinished enrollment retries its SSH scan, the owned host restores this recovery rule. It replaces only the exact older Orbit rule for the recorded proxy port and refuses other rule drift. Native role convergence removes the recovery rule after fleet SSH is ready. A completed enrollment does not reopen it.

Native base-host convergence installs the existing WireGuard member rule through the bootstrap connection before checking SSH through the tunnel. This check does not depend on the temporary recovery rule allowing traffic to the WireGuard address.

The root policy permits UDP from this guest to the recorded public WireGuard hub endpoint and established replies. Project policy has one guest and grants no topology Pi ingress. Private-network and host exclusions remain in force. Fleet enrollment installs its own hub policy before publishing the peer. New live host and hub paths require separate approval.

This read-only check does not create a bootstrap endpoint, enroll a Node, or change host or hub networking. Those steps remain required before local Project claims can start.

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
| `project_images` | Pinned VM fingerprints keyed by Project slug |
| `blocked_networks` | Additional host and LAN IPv4 CIDRs to exclude from public egress |

The allocator accepts only groups already pinned to `vm`. It reserves an Orbit
pair or one Project VM before provisioning. Existing reservations retain their
provider, host, image, network, and identity; a parked reservation resumes there.
Stopped guests release compute capacity while retaining their subnet and storage.

Project work uses UpCloud after configured local capacity is exhausted. A host
that cannot be observed is an error, not evidence of available cloud placement.
Orbit groups stay local until cloud support for that lane is proven. The task
workspace entry point remains gated while workspace and agent integration is
completed; these settings alone do not start sandbox task execution.

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

Enrolled UpCloud VMs also have a Git credential helper. It requests a fresh repository token from the Gateway over WireGuard and verified HTTPS for each Git authentication. The Gateway derives the repository from the VM's current task ownership, requires binary access to its own Node and its private Pi token, and refuses stopped, destroyed, detached, or ended groups. The helper accepts only the Project repository on github.com.

Provisioning grants the enrolled VM access to its own Node only. It grants no access to the Gateway or another Node.

Cloud-init installs GitHub CLI. An owned `gh` wrapper runs it with the temporary token in its process environment, including access to Actions artifacts. The wrapper also supports `orbit-github gh …`. Neither helper stores the installation token. The Gateway CA and endpoint are provisioned through the pinned SSH channel. VMs require the App installation even when the Project uses `gh_cli` on shared machines. The App must be installed on the Project repository; no personal access token is needed. GitHub installation tokens expire after one hour. Cleanup blocks renewal; tokens already issued retain their GitHub expiry.

The helper accepts opaque bearer tokens, including signed token formats with dots and hyphens. It refuses missing tokens, whitespace, and header-control characters.

### Pi sessions stay bound to their sandbox

A sandbox reserves a random Pi token before guest configuration. The Gateway encrypts it at rest and excludes it from model serialization. Retries reuse the same token. Sandbox Pi connections never use the Gateway-wide token or the host Node’s Pi settings.

A thread records the sandbox reservation as its runtime identity. Creation and later requests check the owning group, workspace, placement, and running state. An Orbit connection uses the configured Incus host’s private address and the sandbox’s reserved proxy port. A Project connection uses its enrolled guest Node. A parked, destroyed, replaced, or unconfigured sandbox refuses requests instead of selecting another server. Proxy provisioning and image setup remain prerequisites for enabling VM claims.

Sandbox MCP files are installed through the guest transport. For Orbit they name the disposable test Gateway at `https://gateway.orbit/mcp/search`; Project sandboxes use the configured live Gateway. Shared topology acquisition is refused for sandbox workspaces. VM reviewer requests record workload requirements and use the owned compute driver and private Gateway readiness gate.

### Prepare source inside the guest

`SandboxWorkspaceSource` initializes a blank checkout with a sandbox ownership marker, fetches remote refs directly from GitHub with temporary App access, and creates the task branch from its published branch or the Project default. The guest initializes its checkout and fetches the selected refs. Retrying a prepared checkout preserves local commits and uncommitted files. A foreign directory, changed origin, or changed checkout branch fails without replacing its contents.

Before the first fetch in an Incus Orbit sandbox, Orbit configures guest DNS for GitHub domains through `1.1.1.1` and `9.9.9.9`. This bootstrap works before the cloned private Gateway is retargeted. Its persistent resolver drop-in routes only `github.com`, `githubusercontent.com`, and `githubassets.com`; private topology DNS keeps its existing policy. Preparation refuses foreign source ownership or a changed resolver drop-in.

Pair preparation installs the same GitHub resolver on the private Gateway before refreshing dependencies. It also gives that Gateway's dnsmasq backend fixed public upstreams at `1.1.1.1` and `9.9.9.9`, which the host boundary permits. Private records and peer DNS routes stay in place. Orbit checks the source owner and isolated Gateway address before it writes the upstream file. A foreign file or failed validation refuses readiness; failed activation removes only the new owned drop-in.

This prepares source only; the claim gate still requires the runtime, model proxy, and topology bootstrap.

### Keep guest paths off the host

Generic Instance operations refuse sandbox workspaces with `instance.sandbox_managed`. This includes source preparation, deployment, removal, setup, dependency commands, logs, environment operations, and host runtime projection. A missing reservation on a VM task also refuses the operation. Manage sandbox workspaces through their task group and the compute driver. Task checks, receipts, source preparation, and publication use the sandbox transports described above. Physical host doctor reports exclude sandbox workspaces; their guest paths do not describe host drift.

### Durable firewall policy on an Incus host

Install the fixed `apps/agent/resources/incus-host-network.py` helper as root-owned `/usr/local/libexec/orbit-sandbox-network` with mode `0755`. Grant the trusted compute account passwordless sudo for that exact executable with no arguments. Never grant a caller-supplied Python script or interpreter. The helper accepts only a bounded JSON request with `operation` (`enabled`, `project_enabled`, `verify`, `ensure`, or `remove`), `project`, and `sandbox_id` on standard input.

The `project_enabled` operation checks the separate Project opt-in. Root verifies an attached Project guest's image provenance, endpoint markers, exact proxy and NIC devices, and worktree ownership before admitting it. Provisioning keeps a new guest stopped until this check passes. Resume checks the restored reservation again before starting it. Public-key identity reads use `verify`, which refuses missing or changed rules and never restores them.

The root-owned `/etc/orbit/sandbox-network.json` file opts in selected Incus projects. Its required fields are `version: 1`, `projects`, `pi_host`, `gateway_address`, `wireguard_interface`, and `blocked_networks`. Optional `project_bootstrap` contains a separate `projects` opt-in list, a public IPv4 `wireguard_address`, and a UDP `wireguard_port`. Its projects must already belong to the main opt-in list. Project reservations must match these endpoints and use `pi_host` as their private SSH host address. Adding this opt-in preserves existing Orbit policy records; each Project record pins its own opt-in and endpoints.

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

Allocation registers model credentials and reserves the Pi token before compute provisioning. A failed registration leaves the reservation for retry without creating a VM. Lifecycle operations for one reservation use a shared lock. Parking retains both credentials. Destruction records its intent, revokes the model key, then asks the driver to remove compute. Failed revocation prevents resource removal; failed removal retains the Pi token until cleanup succeeds. The raw drivers refuse destruction while a model key remains. Enrolled Project nodes must leave the fleet before destruction.

### Guest Pi runtime

`SandboxPiRuntime` configures the image’s `/usr/local/bin/orbit-pi-server` binary as the managed `orbit` user. It writes only the reservation’s Pi token and model key into private guest files. Its model provider is `orbit-sandbox`, with the fixed URL `http://127.0.0.1:8317/v1`. The host relay remains a prerequisite. Set `compute.pi.models` to the supported model descriptors before preparing a workspace. Sandbox sessions use this provider even when a task names another provider.

Runtime preparation checks the reservation and checkout, refuses foreign files or changed credentials, and confirms authenticated Pi health. Repeating preparation keeps a healthy service running, so existing sessions remain available. The image must contain the Pi binary and the managed user with sudo; it must not contain `orbit-worker` or subscription credentials.

### Pi runtime on an enrolled UpCloud VM

The Gateway prepares Pi only on the reservation's active, owned `app-dev` Node. Configure `ORBIT_SANDBOX_PI_ARTIFACT_PATH` with a Pi executable for Linux x64 owned by the Gateway process user and `ORBIT_SANDBOX_PI_ARTIFACT_SHA256` with its digest. Build this artifact from `apps/pi-server` with its `build:linux` script. Orbit sends it over pinned SSH on protected stdin. The guest verifies its length, ELF architecture, and digest before installing a root-owned executable. A reservation receipt makes retries idempotent. Foreign binaries, changed artifacts, or unsafe files refuse preparation.

The VM forwards `127.0.0.1:8317` to the model address and port recorded during enrollment. The saved model-key registration origin must be that exact HTTP endpoint. HTTPS origins cannot use this TCP relay. The guest receives only its group model key and Pi token. Provider and model-management credentials stay on the Gateway. The Gateway separately issues temporary App tokens scoped to the Project repository.

Preparation reports readiness only after authenticated Pi and model requests succeed and invalid credentials receive `401`. Orbit records readiness on the reservation. Project Pi connections require that receipt and the active owned Node; they never adopt a shared `pi-server` Process. Failed preparation clears readiness and retains ownership for retry. Keep claims disabled until the complete provision-to-agent path passes live validation.

### Model relay on an Incus host

An Incus host can set `model_proxy_origin` to a fixed HTTP(S) origin on loopback or `10.44.0.0/16`. It must match the endpoint where the Gateway registers model keys. Each reservation keeps that origin. The agent creates an owned Caddy user service bound to that group’s bridge address on port `8317`. Only the operator address can enter, and only model API paths pass through. Management paths and other HTTP methods return `403`. The relay forwards the group key unchanged and has no shared credential.

This needs the host’s Caddy binary and an existing service manager for the compute account. It does not enable a user service manager or change host firewall rules. The Incus ACL permits only the operator to reach its own bridge on this port. Parking retains the relay and key; resume checks the recorded origin; destruction stops the service and removes its owned files before removing the network. Drift refuses mutation.

Guest preparation uses systemd socket forwarding from `127.0.0.1:8317` to the owned bridge. It verifies model-key authentication through that path before reporting readiness. A disposable connectivity proof remains a prerequisite for enabling this host option.

### Review retention policy

`TaskSandboxLifecycle::review` records the start of each review wait before changing compute. Incus has a five-minute grace period when there is no capacity waiter. A capacity waiter ends that grace immediately. UpCloud stays running for one hour so incoming review feedback can reach the existing VM. Repeated calls keep the original deadline. A reservation with `preview` enabled stays running. Changing it to a preview after parking resumes it through the same credential checks.

Incus retains its stopped snapshot until resume or destruction. UpCloud retention ends one hour after the review wait began. Capacity waits do not stop it early. Expiry uses the normal credential revocation and destruction path. Cleanup removes the owned enrolled Node and hub policy before that path can remove its VM. Failures retain the deadline and ownership for retry. Preview retention does not prevent explicit merge cleanup.

Review timing, confirmed parking time, and VM power are stored separately. A confirmed activation clears review timing for the next cycle. A failed activation retains it. Resume intent is recorded before the driver runs and stays until running power is confirmed, so an uncertain resume retries restoration instead of provisioning.

The scheduler reconciles review retention after publication and on later ticks. It restores Incus compute before fetching or starting resumed work. Eligible VM review resumes are attempted before new todo claims. A compute failure remains visible in `capacity_wait_reason`; it cannot start an agent through a shared workspace. Cloud branch reconstruction remains a prerequisite for unattended claims.


## Task workspace cleanup

Merge and cancellation remove task workspaces through their sandbox reservations. Under the group admission lock, Orbit checks the group, Project, Instance, reservation, and compute host. It refuses foreign group references and unexpected live Routes, Processes, Schedules, or database connections. Guest checkout paths never reach host source inspection or deletion.

For an Orbit Incus pair, Orbit revokes the model key, destroys owned compute, and confirms destruction before deleting the workspace row and clearing its task references. A failed operation retains ownership for retry. The reservation remains as audit history. Enrolled Project cleanup on either provider records destruction intent and revokes the model key before removing an exclusive workspace, its native app-dev role and Node, and the owned hub policy. The reservation retains provider IDs throughout. If provider deletion fails after the workspace is removed, cleanup retries through the reservation. A foreign workspace, Node, role, or live resource reference refuses cleanup.

The sweep retries reservations with no Instance when their group has ended, has been deleted, or has already recorded destruction intent. It does not adopt unrecorded host resources or start cleanup of an active group. Failed retries use the workspace sweep's time budget and backoff.

## Declared workload nodes

An Orbit subtask can declare `topology: ["app-dev", "app-prod", "app-prod-2"]`. The list names distinct workload nodes; omit it or use an empty list for the default operator and test Gateway. Subtask creation, group creation, and stored task definitions preserve this declaration. Only a subtask that has not started can change it.

Before dispatch, Orbit reserves the additional VM capacity, starts the pinned workload images on the group's network, and enrolls them with its private test Gateway. Each requested node must pass its native readiness check before the agent starts. A missing image, full budget, or failed enrollment leaves a visible wait. Workload nodes never join the live fleet. Resume repairs incomplete native enrollment before verifying the recorded expanded inventory. Cleanup includes every added node.

A reviewer’s `topology_requested` fallback records `app-dev` and `app-prod` as requirements on the current subtask. It uses the owned sandbox and its private Gateway. Orbit waits for capacity and fresh doctor readiness before resuming the reviewer. Repeated requests keep existing workload nodes. Project VM groups refuse this Orbit-only request and continue in their Project workspace.

Native enrollment gives each workload a fresh SSH host key and records its ownership outside the shared checkout. Retries keep that key and the native Node identity. The private Gateway pins the key, provisions the requested role, and grants operator access through Orbit. Each guest must match the recorded source, subnet, and Node inventory before doctor can admit a turn.

Adding a workload node requires host images from the sandbox’s recorded template and the same pinned pair images. A host configuration change cannot replace a running group’s template. An incomplete expansion retries its recorded images; it does not adopt new configured images. Workload commands require the complete source descriptor to match the Project repository and default branch.

## Saved pair identity

The saved Orbit pair needs its network identity updated after cloning. The guest helper accepts either the existing four-Node topology or exactly a Gateway and a roleless operator. For the pair, it refuses additional Nodes or operator roles before changing addresses. It preserves private addresses, keys, DNS policy, and other settings while replacing the stored SSH addresses and Gateway endpoints.

## Sandbox power

Task group responses report `sandbox_power` separately from task status. It is `running`, `stopped`, or `destroyed` only when the owned reservation confirms that state. Shared groups, missing ownership, and transitions return `null`. Destroyed reservations remain visible after workspace cleanup. The CLI shows this value as **Sandbox power** for VM groups.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. Shared groups store the intent without changing shared-host power.

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

The source archive can carry a Caddy repository snapshot in `.git/orbit-caddy-source`. Preparation verifies its signing key, signed metadata, package index, and package before copying it into the image's protected package cache. Native role convergence installs the Caddy package from that authenticated local repository. A missing snapshot keeps the public-source path; a changed or incomplete snapshot refuses preparation. See [package sources](/reference/node-provisioning#package-sources) for the trust checks.

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

`ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED` defaults to false. Keep it off until the complete Orbit lane and host connectivity policy are proven. With the switch enabled, an Orbit claim needs local pair images, a pinned source template, the private Pi endpoint, the model relay, and Pi model configuration. Other Projects keep a visible wait until their lane is enabled.

Provisioning reserves and attaches an owned workspace before preparing source, the isolated pair, and Pi in that order. It holds the group's execution lock during preparation. A failed step retains the reservation and workspace for retry; it never adopts an unrelated workspace or falls back to shared compute. Only successful preparation returns the workspace to the scheduler, which runs the Project's baseline setup and check before starting an implementer.

### Admit a Project claim

Project claims use local Incus capacity first when a host has that Project's development image. An existing reservation keeps its provider. An unavailable host or incomplete local configuration refuses admission; only measured lack of local capacity permits cloud placement. Local enrollment and workspace admission each require their own opt-in. Cloud recovery keeps its recorded provider and restores from the published branch.

An enrolled Project VM runs checks and workspace commands through its own pinned fleet SSH connection. Pi uses the same fleet model endpoint and per-sandbox credentials as the cloud lane. Temporary repository access renews only for the owned, running Node and its Project. Local park retains the Node and bootstrap reservation. Resume verifies the restored guest and SSH key, reinstalls its hub limits, and confirms Pi before another turn starts.

Web-serving Projects get one generated private Route, `task-<id>.<project>.<dev-tld>`, on their enrolled VM. Native development provisioning uses the Project root and prepares PHP, certificates, Caddy, and private DNS.

Laravel previews import their environment through the native Instance environment flow. An empty `APP_KEY` receives one key per workspace; retries synchronize the stored environment and retain its keys instead of importing it again. Only the owned, running Project guest can use this runtime path. Native preview source-access grants use the same ownership guard and target that guest through pinned fleet SSH. Generic source, transfer, and removal actions retain their sandbox guards. Non-web Projects keep a source-only workspace.

Fleet cleanup applies to both providers. After destruction intent and model revocation, it records one native Instance removal journal for the exclusive workspace. Its source inventory names the sandbox reservation; it does not claim to inspect or quarantine a host checkout. Source finalization records retention inside that VM until compute destruction. Guest-local certificates and services remain with it, so cleanup can withdraw publication while the VM is parked.

Native Route withdrawal handles active previews and resumes from recorded evidence after a partial failure. Runtime cleanup and row deletion finish before removal of the native `app-dev` role and peer, hub policy, and compute. A failed Route or peer removal retains reservation ownership for retry. Foreign journals, targets, public Routes, and changed workspace ownership refuse cleanup before mutation.

### Admit an UpCloud project claim

`ORBIT_SANDBOX_PROJECT_CLAIMS_ENABLED` defaults to false. Local placement also requires `ORBIT_INCUS_PROJECT_WORKSPACES_ENABLED`; cloud placement requires UpCloud enrollment. Enable UpCloud compute, enrollment, and the model proxy, and configure Pi models and the pinned artifact before enabling this switch. Orbit projects retain their local pair path.

The claim reserves and starts one VM, enrolls its owned Node, attaches one private task workspace, fetches source directly from GitHub, and prepares Pi. It prepares the private preview for web-serving Projects and then returns the workspace to the scheduler. The scheduler runs the project's setup steps, including the TIA baseline restore, and its baseline check before starting the implementer. Retries keep the reservation and preserve prepared source. Non-web Projects have no preview Route.

Review expiry, merge, and cancellation use the owned cleanup path. A failed cleanup retains destruction intent and provider IDs. Review feedback can use the original running VM during retention.

When review feedback resumes a group whose UpCloud VM was destroyed, Orbit first confirms an open pull request in the Project repository. It reserves a replacement VM only after the old reservation records confirmed destruction, revokes its model and Pi credentials, and releases its fleet Node. Provider server and disk IDs remain in that reservation as audit history; recovery does not require clearing them.

It restores `task-{group id}` at the confirmed pull request commit using temporary GitHub App access, prepares fresh Pi and model credentials, and reruns Project setup and baseline checks before starting the implementer. A missing branch or mismatched commit keeps the group waiting; recovery never starts from the default branch. Retries preserve the replacement reservation and local work.

A destroyed cloud VM must finish branch recovery before preview access resumes. Keep unattended claims disabled until the complete live UpCloud flow has passed acceptance.

### Publish a local Project development image

`bin/sandbox-project-image --plan`, `--prepare`, and `--publish` use one closed JSON request with `project`, `pool`, `sandbox_id`, `budget`, `project_slug`, `base_image`, and `source_template`. The project must be an owned proof project. The pinned base must be a private `app-dev` image published from the same source template. It must have no aliases. This path never converts an enrolled Node or changes an existing image.

Preparation creates one owned VM with a 20 GiB root disk and no network or source mount. It verifies the managed account, toolchain, PHP extensions, and absence of fleet, task, agent, or repository credentials. It removes the inherited Pi executable from that temporary clone. Project images contain no Pi executable or artifact receipt; runtime preparation installs the Gateway's pinned artifact for each sandbox. Orbit templates retain their Pi executable.

The checkout is empty. Publication repeats that audit, stops the VM, and publishes a new private image with Project provenance. Partial failures retain the candidate for inspection. `--destroy` removes only the matching temporary VM and leaves the published image intact. The Project source, environment, setup, and CI baseline are prepared on each task's owned workspace.

Preparation uses the host's running VM budget. Stopped guests keep their ownership and do not consume running capacity; an uncertain power state still does. Incus can retain base properties in the published image as well as the clone configuration. The publisher verifies and removes only the matching inherited Orbit template properties from its temporary candidate and its owned new image. The base image remains unchanged. The published image must carry Project provenance and no Orbit template properties. A failed publication retains its candidate and output for inspection.
