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

The driver creates a VM with cloud-init. Cloud-init creates the managed `orbit` user, authorizes only the Gateway's public SSH key, installs base prerequisites, and adds swap. It contains no provider token, GitHub token, Pi token, proxy key, or subscription sign-in. Project runtime and agent configuration belong to the later enrollment step.

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

The managed `orbit` user performs enrollment. This step provisions the native Node and its `app-dev` role, without Pi, model keys, GitHub access, or project source. Pi/runtime preparation, workspace creation, task claim admission, and cloud reconstruction remain later steps. Cleanup must remove the owned fleet peer before removing its hub policy or provider resources.

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
the dedicated bridge. This driver does not change the host firewall.

### Image test baselines

Sandbox images need a test baseline from CI. Each successful project job on `main` publishes a `sandbox-tia-<index>-<commit>` artifact for 14 days. It contains the Pest graph and a manifest with the Project path, tested commit, CI run, graph checksum, and test configuration checksums. Image preparation must select a successful CI run and validate that manifest before importing the graph. Pull request runs do not publish these image inputs. A baseline accelerates local feedback; CI on the published task commit remains the merge gate.

Download the artifact outside the sandbox from a successful `main` CI run. Pass its extracted directory to `bin/tia-cache import-ci --project <path> --artifact <directory> --commit <tested-sha>` inside the image checkout. The command validates checksums, configuration, portable graph paths, test results, and commit ancestry. It seeds only an absent private graph and preserves an existing one. It does not fetch credentials or publish the imported graph to a shared cache store.

When Orbit prepares a checkout from bundles, it records the Project's default branch as `origin/HEAD` without contacting GitHub. Pest uses that local reference to select its baseline. The source ownership record fixes the default branch for the checkout; a changed branch or reference is refused before another turn.

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
provisioning remains gated while bundle publication, agent credentials, and
image preparation are integrated.

### Git objects cross the boundary as bundles

Sandbox publication exports the approved commit as a bundle. The Gateway receives
bounded chunks in a private temporary directory, checks the transfer digest,
and verifies the bundle in a fresh trusted bare repository before pushing that
exact commit to `task-<id>`. It never force-pushes. Extra advertised refs,
missing prerequisites, corrupt objects, and a different commit are refused.

Fetching reverses that path. The Gateway fetches a named branch and exports a
bundle. The guest imports it into the matching remote-tracking ref without
changing its current branch or working tree. Repository tokens stay in the
Gateway's protected process input and temporary credential file. No token or
credential helper enters the sandbox. Transfer files are removed after the
operation; destroying the sandbox also removes interrupted guest transfers.

### Pi sessions stay bound to their sandbox

A sandbox reserves a random Pi token before guest configuration. The Gateway encrypts it at rest and excludes it from model serialization. Retries reuse the same token. Sandbox Pi connections never use the Gateway-wide token or the host Node’s Pi settings.

A thread records the sandbox reservation as its runtime identity. Creation and later requests check the owning group, workspace, placement, and running state. An Orbit connection uses the configured Incus host’s private address and the sandbox’s reserved proxy port. A Project connection uses its enrolled guest Node. A parked, destroyed, replaced, or unconfigured sandbox refuses requests instead of selecting another server. Proxy provisioning and image setup remain prerequisites for enabling VM claims.

Sandbox MCP files are installed through the guest transport. For Orbit they name the disposable test Gateway at `https://gateway.orbit/mcp/search`; Project sandboxes use the configured live Gateway. Shared topology acquisition is refused for sandbox workspaces. The compute driver must prepare and enroll requested workload nodes before the VM topology path can be enabled.

### Prepare source inside the guest

`SandboxWorkspaceSource` initializes a blank checkout with a sandbox ownership marker, imports remote refs through the trusted bundle broker, and creates the task branch from its published branch or the Project default. No clone runs in the guest. Retrying a prepared checkout preserves local commits and uncommitted files. A foreign directory, changed origin, or changed checkout branch fails without replacing its contents. This prepares source only; the claim gate still requires the runtime, model proxy, and topology bootstrap.

### Keep guest paths off the host

Generic Instance operations refuse sandbox workspaces with `instance.sandbox_managed`. This includes source preparation, deployment, removal, setup, dependency commands, logs, environment operations, and host runtime projection. A missing reservation on a VM task also refuses the operation. Manage sandbox workspaces through their task group and the compute driver. Task checks, receipts, source preparation, and publication use the sandbox transports described above. Physical host doctor reports exclude sandbox workspaces; their guest paths do not describe host drift.

### Pi proxy on an Incus host

An Orbit host can set `gateway_address` to the real Gateway’s WireGuard address in `compute.incus.hosts`. New Orbit reservations then record the host’s WireGuard address and a port from `23001` to `23254`. A stopped reservation retains its subnet and port. The operator’s Incus NAT proxy forwards that private port to guest port `3774`; its ACL permits only the recorded Gateway to enter through that port.

The proxy never binds a public or wildcard address. Changing or removing an existing proxy through reprovisioning is refused as device drift. This requires a Pi server in the image and host forwarding that permits the owned bridge; creating the proxy does not weaken other host firewall rules.

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

The VM forwards `127.0.0.1:8317` to the model address and port recorded during enrollment. The saved model-key registration origin must be that exact HTTP endpoint. HTTPS origins cannot use this TCP relay. The guest receives only its group model key and Pi token. It never receives provider, GitHub, or model-management credentials.

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

Merge and cancellation remove task workspaces through their sandbox reservations. Under the group admission lock, Orbit checks the group, Project, Instance, reservation, and compute host. It refuses foreign group references and unexpected live Routes, Processes, Schedules, or database connections. Guest checkout paths never reach the shared-host Instance remover.

For Incus, Orbit revokes the model key, destroys owned compute, and confirms destruction before deleting the workspace row and clearing its task references. A failed operation retains ownership for retry. The reservation remains as audit history. UpCloud cleanup records destruction intent and revokes the model key before removing an exclusive workspace, its native app-dev role and Node, and the owned hub policy. The reservation retains provider IDs throughout. If provider deletion fails after the workspace is removed, cleanup retries through the reservation. A foreign workspace, Node, role, or live resource reference refuses cleanup.

The sweep retries reservations with no Instance when their group has ended, has been deleted, or has already recorded destruction intent. It does not adopt unrecorded host resources or start cleanup of an active group. Failed retries use the workspace sweep's time budget and backoff.

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

### Prepare a copied source volume

The internal `guest-template-source.py` helper prepares a disposable source copy before publication. Run it as the managed user, with a JSON request on standard input containing `checkout` and `source_template`. The descriptor has exactly `id`, `repository`, `base`, and `commit`. The copy must already contain `.git/orbit-template-candidate.json` with that descriptor. The host builder must verify its own candidate volume and exclusive attachment before writing this marker.

Preparation refuses task-owned checkouts, changed source, mismatched identity, unsafe Git configuration, and external Git metadata. It checks the pinned commit before mutation, normalizes local and remote branches to the default branch, and writes the template marker without replacing it. Repeating the same request verifies the published state. Dependency caches remain intact. Errors return a fixed message without command output or credentials.

This helper prepares source only. It does not certify ignored files, dependency provenance, CI baselines, guest credentials, or image sanitation. The complete image publisher must verify those prerequisites before publishing the source snapshot and private pair images. Do not use the helper on a live workspace or a promoted template.

### Adopt a template checkout

A source template includes `.git/orbit-sandbox-template.json` with the same descriptor as its reservation. Before first use, the guest checks that marker, the pinned commit, the default branch, and a clean tracked and untracked tree. Ignored dependency caches can remain. The template uses a real local Git directory, contains only the default local and remote branches, and exposes only its canonical origin URL. Git includes, custom filters, alternate object stores, and replacement history are refused.

After validation, the guest records group ownership without replacing an existing marker. It imports the task branch through the trusted bundle path and selects that branch, or the current default branch for new work. The template commit is the seed; the imported branch supplies the task's starting commit. Repeated preparation checks the recorded template identity and preserves local commits, dirty files, and dependencies. A populated checkout without the matching template marker remains refused.

### Prepare the isolated pair

Pair preparation checks group source ownership before changing the test Gateway. It repairs the saved Gateway inventory and the operator's WireGuard endpoint on the group's subnet. The Gateway ships copies of the same repair helpers tested by the E2E harness; its quality check verifies that they match.

The test Gateway validates Composer manifests and lock files. It refreshes dependency autoloaders and installs dependencies when lock files changed or dependencies are missing. It clears branch runtime caches, runs migrations against its own SQLite database, repairs Caddy’s checkout access, and restarts PHP-FPM. The operator then uses its isolated Gateway profile to list the active Gateway and roleless operator. Preparation reports success only when both guests confirm the same branch commit. It never routes these commands through a shared host or a project-lane Node.

### Admit an Orbit sandbox claim

`ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED` defaults to false. Keep it off until the complete Orbit lane and host connectivity policy are proven. With the switch enabled, an Orbit claim needs local pair images, a pinned source template, the private Pi endpoint, the model relay, and Pi model configuration. Other Projects keep a visible wait until their lane is enabled.

Provisioning reserves and attaches an owned workspace before preparing source, the isolated pair, and Pi in that order. It holds the group's execution lock during preparation. A failed step retains the reservation and workspace for retry; it never adopts an unrelated workspace or falls back to shared compute. Only successful preparation returns the workspace to the scheduler, which runs the Project's baseline setup and check before starting an implementer.

### Admit an UpCloud project claim

`ORBIT_SANDBOX_PROJECT_CLAIMS_ENABLED` defaults to false. Its first lane uses UpCloud directly; it refuses project Incus reservations rather than changing their placement. Enable UpCloud compute, enrollment, and the model proxy, and configure Pi models and the pinned artifact before enabling this switch. Orbit projects retain their local pair path.

The claim reserves and starts one VM, enrolls its owned Node, attaches one private task workspace, imports source through the Git bundle broker, and prepares Pi. It then returns the source-resolved workspace to the scheduler. The scheduler runs the project's setup steps, including the TIA baseline restore, and its baseline check before starting the implementer. Retries keep the reservation and preserve prepared source. The task workspace has no preview Route.

Review expiry, merge, and cancellation use the owned cleanup path. A failed cleanup retains destruction intent and provider IDs. Review feedback can use the original running VM during retention. Reconstruction after destruction remains unavailable and reports `compute.rebuild_required`; do not enable unattended project claims until that recovery path is implemented and validated.
