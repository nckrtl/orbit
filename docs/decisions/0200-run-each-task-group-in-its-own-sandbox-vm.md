# ADR 0200: Run each task group in its own sandbox VM

Each task group runs in a disposable VM, or for Orbit itself a small isolated set of VMs, that a compute driver provides. The implementers, the reviewer, and the task check all run inside it as one user, in one environment. The security boundary is the edge of the VM, not a Unix user on a shared host.

## Status

In progress.

Principle: [Security fits the real threat model](/mission#principles). The boundary moves to a place an agent cannot cross, and the defenses that live inside it go away. This also serves [Deterministic first](/mission#principles): the environment, the topology, and the lifecycle are decided by code and declarations, not by agent judgment.

## Context

Today a task group shares a node with other groups ([Shared Instance](/reference/tasks#shared-instance)). Agents run as `orbit-worker` without sudo ([One user for every task agent](/reference/pi-server#one-user-for-every-task-agent)). The handoff check runs as the managed user. Measurements from 7 Oct 2026 (groups with IDs of at least 600, 219 check reports on beast):

- **The two environments disagree.** `composer check` run by agents as `orbit-worker` failed 53 of 65 times (82%). Orbit's handoff check passed 130 of 154 times. About 77 Gateway tests need `sudo setfacl` or worker ACLs. Check directories owned by the managed user with mode `0700` return `EACCES` to agents.
- **Blocked turns.** 91 of 233 blocked or assistance requests name the user or permission split. Another 45 name Incus or topology acquisition. Blocked time up to a resolution adds up to 210 h.
- **The boundary does not hold.** `orbit-worker` is in `incus-admin`, which is root-equivalent. Threads on the T3 driver run as the managed user, who has passwordless sudo.
- **Capacity.** Groups that wait for assistance or a merge keep their node slot. Todo groups pinned to a leftover `reserved` Instance wait silently on a full node.
- **The trend.** The median group took 1.5 h for ids below 200 and 5.4 h for ids from 1200.

An UpCloud experiment on 6 Oct 2026 proved the building blocks for the web lane. A VM was created, enrolled with `node:add` as an `app-dev` node in dev Cluster #4, served a DLF Instance on a private Route through the beast router, and ran a Pi coding probe through CLIProxyAPI.

A review of the first web-lane build on 9 Oct 2026 (main at `bd36565c3`) found that no implementer, check, reviewer, and publish cycle had run on either lane. Two choices caused most of the work: offline Project images, and egress limited to HTTP(S) and DNS behind a private bridge reached through host proxy, DNAT, and SNAT rules. The build had about 12.7k production lines, 26 Python programs, 93 Instance guard calls, and a 9-state model with 10 marker timestamps. A spike on beast the same day showed the alternative. A stock Ubuntu 26.04 cloud VM was ready in 31 to 48 seconds. One host forward rule, one bridge ACL, and NIC port isolation formed its whole network boundary.

## Decision

Orbit assigns each task group its own VM through the lane of its Project. This amendment replaces the web-lane design of the first build. The Orbit lane is unchanged and gets its own design pass in Phase 4.

### Two lanes

| | Orbit lane (the `orbit` Project) | Web lane (every other Project) |
| --- | --- | --- |
| Machines | An **operator** VM and a **test gateway** VM by default. `app-dev` and `app-prod` join when a subtask declares them | One task VM, which is a normal `app-dev` Node |
| Network | Its own Incus network for each group. It never joins the live fleet | Joins the live fleet over WireGuard as an `app-dev` Node of the dev Cluster, with an address in the reserved range `10.44.0.128/25`, the upper half of the fleet VPN subnet |
| Control path | The real Gateway reaches the operator through the host's Orbit agent: an Incus proxy device for the Pi port, and `incus exec` for the check and turn receipts | Normal Node SSH. Before enrollment through the compute host as a jump host, and after it over WireGuard |
| Code | One worktree volume for each group, attached to the operator, the test gateway, and `app-dev` | A checkout inside the VM, served as a normal Instance `task-<group id>` |
| Routes | Only inside the test topology. Exposing the topology outside is out of scope | A private dev-cluster Route, `task-<id>.<project>.<dev-tld>` |

### Web lane

1. **Image.** Every provider uses the stock Ubuntu 26.04 cloud image and cloud-init. Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key, and installs `openssh-server`. The web lane has no Project images, templates, or warm pools.
2. **Enrollment.** The VM joins through the normal `node:add` path as an `app-dev` Node of the dev Cluster. Before enrollment, the Gateway reaches it with SSH ProxyJump through the compute host, and reads its host key from that trusted host. After enrollment, the Gateway uses WireGuard.
3. **Host network.** Each host has one task bridge under the reserved `orbittask` prefix, with its ACL attached when the bridge is created. The ACL drops private, link-local, CGNAT, and multicast egress and allows all other egress. Ingress allows SSH from the host only. NIC port isolation is on. One persistent host rule, `ufw route allow in on orbittask+`, lets the bridge pass the host's forward policy. This replaces the root-owned helper, the per-bridge policies, the proxy, DNAT, and SNAT rules, and the HTTP(S)-only egress rule.
4. **Fleet limits.** A static nft filter on the WireGuard hub covers the reserved range `10.44.0.128/25`. It lets task VMs reach the Gateway API, Reverb, CLIProxyAPI, and DNS on the hub. It lets the Gateway reach them over SSH and Pi, and the dev Cluster router reach their previews. The range lies inside the fleet VPN subnet `10.44.0.0/24`, above the addresses that existing Nodes use. The address allocator keeps other Nodes out of the range. A task VM Node has no access grants. This replaces the hub table for each sandbox.
5. **GitHub.** No GitHub token enters the VM. The Gateway fetches and pushes over SSH, as for shared groups. This reverses "temporary GitHub App tokens enter the owned sandbox" for the web lane.
6. **Pi.** The Gateway installs a pinned Pi artifact. Pi runs as a normal `pi-server` Process under `orbit`, with a token for each VM and a CLIProxyAPI key for each group.
7. **State.** The `task_vms` table holds one row for each VM. `TaskVmState` has five cases: `provisioning`, `ready`, `destroying`, `destroyed`, and `failed`. There are no marker timestamps. `IncusTaskVmProvider` validates host and guest output once. The Gateway trusts its own rows.
8. **Jobs.** `ProvisionTaskVm`, `EnrollTaskVm`, `PrepareTaskVmRuntime`, and `DestroyTaskVm` are queued jobs on the `task-vms` database queue. The Gateway scheduler starts the worker every minute.
9. **Lifecycle.** The VM is destroyed when the group ends, in this order: Instance removal, the `destroying` state, model key revocation, VM deletion, offline Node removal, and the `destroyed` state. Park and resume move to Phase 2, using the spike measurements: park in 2.5 seconds, resume in 14 seconds.
10. **No guard.** Generic Instance operations need no sandbox guard. One placement invariant protects task VM Nodes. Task VM Nodes are left out of the fleet rollout and of shared-group placement.
11. **Public egress.** Task VMs reach public addresses on every port. The VM edge and the hub filter are the boundary, and CI on the pushed commit is the gate.
12. **Accepted risk.** A VM can query port 53 on any host address, because Incus accepts DNS before the ACL. It can read instance names from the DNS of other bridges on that host. Phase 1 accepts this. Phase 2 decides on a host rule that drops it.

The first provider is Incus, on beast and shark. UpCloud uses the same cloud-init and enrollment path in Phase 3. Until then the UpCloud driver, its cloud-init, its bootstrap, and its enrollment action stay in the code without a claim path. ADR 0204 on a nightly UpCloud base image (pull requests #1072 and #1074) stays on hold for Phase 3.

### Orbit lane

The operator stays a roleless node, registered with the test gateway. It becomes a VM instead of a system container. The test topology keeps the fleet's WireGuard addresses, so the operator must never also join the live fleet.

A **compute driver** provides Orbit sandboxes. Its interface is `provision(image, size, network)`, `park`, `resume`, `destroy`, and `capacity`. A `vm` group counts against the driver's VM budget, not `TaskCeilings::PerNode`.

The trusted Incus host installs a fixed root-owned network helper, selected projects, and a boot dependency before enabling new Orbit sandboxes. The helper derives ownership and network identity from local Incus and accepts no caller-supplied rules or host commands. It persists one policy per bridge and checks it before guests start or resume. Dedicated filter chains enforce the complete boundary before permitting traffic through the host firewall. See [Durable firewall policy](/reference/compute-drivers#durable-firewall-policy-on-an-incus-host).

**Declared topology.** A subtask declares the nodes it needs, for example `topology: ["app-dev"]`. Orbit adds them before that subtask starts. A workload node always joins the group's test gateway. `topology_requested` stays as a fallback for discovery.

**Images.** The Orbit lane clones a saved operator and gateway pair, with the operator already registered and its CLI profile set. A warm pool keeps one or two pairs ready. `app-dev` and `app-prod` join from prebuilt images. Every image starts with the TIA baseline of `main`, imported from CI.

**Secrets.** Temporary GitHub App tokens enter the Orbit sandbox. The App private key stays on the Gateway. Tokens are scoped to the Project repository. The operator reaches CLIProxyAPI through a host proxy device, because `10.44.0.3` belongs to the test topology there. An Incus network ACL on the host limits outbound traffic to public HTTP(S) and DNS, CLIProxyAPI, and the group's own network.

**Lifecycle.** VM power is `running`, `stopped`, or `destroyed`, separate from task status.

- When the PR opens, the driver parks the sandbox: a snapshot, then a stop. It keeps the sandbox running for a 5-minute grace period only when no group waits for capacity. A group with `preview: true` stays running until the merge.
- Resume happens on changes requested, red CI, or a real conflict, and restores the snapshot taken when the PR opened. Resume groups are claimed before `todo` groups.
- When the PR merges, Orbit destroys the sandbox and its snapshots. Orbit marks every sandbox as a task sandbox, sweeps sandboxes left behind, and doctor does not report them as drift.

### Both lanes

There is no `orbit-worker` in a sandbox. Agents, the Pi server, and the task check run as the managed user with passwordless sudo, so the privileged tests run. Orbit's handoff check stays as fast feedback and still compares tree hashes. **CI on the pushed commit is the authoritative gate.** `bin/pr-head-check` already requires it before merge.

- **Model calls** go only through CLIProxyAPI. Each group gets its own key, created at claim and revoked at the end. No subscription sign-in is stored in any sandbox.
- **The Pi token is per VM.** The Gateway-wide `ORBIT_PI_TOKEN` is not used for sandboxes.
- **Blocked destinations.** No sandbox reaches `10.44.0.0/16` outside its allowed endpoints, the LAN, the host, other groups' networks, or `169.254.169.254`.
- **Task status and VM power are separate.** After the last approval Orbit opens the PR, and the group moves to the status `waiting_for_review`.
- **Base updates** come from the GitHub update-branch API, not an agent fixup.

Each Project has the setting `task_compute: shared | vm`. `shared` keeps today's flow and stays the default until each lane is proven. A group never switches mode while it runs. A `vm` group that cannot get a sandbox waits with a visible reason and never falls back to `shared`. The web lane is built first, on Incus. UpCloud follows in Phase 3 and the Orbit lane in Phase 4.

This decision changes [Shared Instance](/reference/tasks#shared-instance) and [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) for `vm` groups. Once no Project uses `shared`, the `orbit-worker` account and its `incus-admin` membership are retired.

## Rejected alternatives

- **Keep `orbit-worker` and grant it narrow sudo.** This removes the permission-related test failures, but keeps two environments on a shared host. It does not fix `incus-admin` root equivalence or T3 agents running as the managed user.
- **Run agents as the managed user on shared hosts.** Every agent would get passwordless sudo, Docker, and the managed user's credentials. One prompt injection can reach the fleet.
- **The operator joins the live fleet as well as the test topology.** Both networks use `10.44.0.0/16`. Mixing live and disposable traffic is already refused in [The operator guest](/reference/incus-topologies#the-operator-guest).
- **Operator only by default, adding the test gateway on demand.** Most Orbit features need a real machine. A default gateway makes every added node a plain join and gives the operator a working CLI target from the first turn.
- **Keep Orbit sandboxes running until merge.** In the measured PRs, 1 of 22 got `CHANGES_REQUESTED`. Parked sandboxes free capacity, and restoring a snapshot starts from a clean state.
- **Isolated sandboxes for every Project.** Project features need real routes, deployment, and a preview a human can open. Joining the fleet with no grants and hub-enforced limits keeps that and stays contained.
- **Copy subscription sign-ins into each sandbox.** It exposes the subscriptions, and rotating OAuth refresh tokens break when many copies refresh.
- **Fall back to `shared` silently when no sandbox is available.** It would mix environments again, which is the problem this decision removes.
- **WireGuard configured in cloud-init.** It bypasses normal enrollment, so the Node record, peer, and roles would not come from one path.
- **An Incus proxy device for SSH to a web-lane VM.** Its host port does not match the Node firewall catalog, and it needs forward and DNAT rules on the host.
- **The Incus REST API with a restricted certificate.** It needs more host setup, and the Gateway already has root SSH to the host.
- **A hub table for each VM.** One static filter on a reserved range enforces the same limits without per-VM rules on the live hub.
- **Egress limited to HTTP(S) and DNS.** The first build spent most of its acceptance work on clock sync, package sources, and key installs that this limit broke. It adds no protection beyond the VM edge.
- **Prebuilt Project images in Phase 1.** A stock image with cloud-init works on every provider and needs no offline build. Prebuilt images stay an option if enrollment proves too slow.

## Consequences

- Agents get the same verdict as the gate. The largest source of blocked turns and failed local checks goes away.
- The privileged tests run inside the sandbox. The ACL, `safe.directory`, and check-directory ownership work is removed for `vm` groups.
- A web-lane VM uses the same enrollment, Instance, Route, check, and publication code as a shared group. The new code is one table, one enum, one provider, four jobs, a cloud-init renderer, two setup scripts, and SSH jump support.
- Phase 1 deletes the code of the first web-lane build that the new lane leaves unused. The UpCloud driver stays until Phase 3. The Orbit lane and the old Incus controller stay until Phase 5.
- The first `app-dev` convergence on a fresh VM installs PHP, Caddy, Docker, and the agent. Its time on Incus is not measured yet. Prebuilt images are the fallback.
- A single worker started by the scheduler runs the jobs one at a time. A release that stops the scheduler interrupts a running job, which runs again.
- Task VM Nodes can appear in doctor while they exist.
- A root agent can tamper with the in-sandbox check result. CI on the pushed commit stays the authoritative gate.
- Nested virtualization is required wherever the Orbit lane adds VMs.

## Affects

- Components: apps/gateway, apps/e2e, apps/cli, apps/docs
- ADRs: none in progress on main. ADR 0204 (pull request #1072) stays on hold until Phase 3.
- Detail: [Compute drivers: Task VMs](/reference/compute-drivers#task-vms). [Node provisioning: Enroll through a jump host](/reference/node-provisioning#enroll-through-a-jump-host). [Tasks: Task VM workspace](/reference/tasks#task-vm-workspace). [Pi server: Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). [Incus topology registry](/reference/incus-topologies): operator VM and declared nodes. [Projects](/reference/projects): `task_compute`.
- Verify: The disposable-environment proofs check these outcomes.
  - One DLF group on beast runs claim, VM, enrollment, Route, implementer, check, reviewer, pull request, green CI, and merge. After merge, its Instance, Node, VM, and model key are gone, and `node:list` and `incus list` are clean.
  - A task VM reaches public addresses, and cannot reach private, link-local, CGNAT, or multicast addresses, another task VM, or the fleet outside the hub filter.
  - A local check run by an agent and Orbit's handoff check give the same verdict on the same tree.
  - A one-subtask Orbit group runs end to end in an operator VM.
  - An Orbit group runs to PR, then parks, resumes, merges, and nothing is left behind.
  - No App private key or provider credential enters a sandbox. No GitHub token enters a web-lane VM. GitHub tokens never appear in argv, logs, stored origins, or Git configuration.
