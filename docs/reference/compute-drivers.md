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
  - apps/gateway/database/migrations/*create_task_sandboxes_table.php
---

# Compute drivers

The UpCloud compute driver provides the VM lifecycle for a task sandbox. It is an internal Gateway contract, disabled by default. The task scheduler still places groups on existing shared Nodes. This driver does not yet enroll a Node, install Pi, prepare an Instance, or start an agent. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) defines those later integrations.

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

Each call makes a bounded set of provider requests. No call waits in a sleep loop for boot or shutdown. Call `observe` again to see the next provider state. `running` means the provider reports the server started and the firewall matches the recorded bootstrap or sealed policy; SSH, cloud-init, Pi, and Instance readiness are separate checks.

`park` stops the VM without deleting its disk. `resume` starts that same VM. A stopped UpCloud Starter VM remains billable. The future task lifecycle must destroy it after the agreed one-hour review wait; this driver does not set that timer.

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
