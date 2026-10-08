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

An UpCloud experiment on 6 Oct 2026 proved the building blocks for the project lane. A VM was created, enrolled with `node:add` as an `app-dev` node in dev Cluster #4, served a DLF Instance on a private Route through the beast router, and ran a Pi coding probe through CLIProxyAPI. Allocation per group and the lifecycle were not built.

## Decision

Orbit assigns each task group its own sandbox through the compute driver and lane defined below.

### Compute drivers

A **compute driver** provides sandboxes. Its interface is `provision(image, size, network)`, `park`, `resume`, `destroy`, and `capacity`. The first driver is local Incus, on beast, sabre, and shark. UpCloud follows as overspill. The scheduler places a group on local capacity first and on a cloud driver when local capacity is full. A `vm` group counts against the driver's VM budget, not `TaskCeilings::PerNode`.

### Local host firewall boundary

The trusted Incus host installs a fixed root-owned network helper, selected projects, and a boot dependency before enabling new local sandboxes. The helper derives ownership and network identity from local Incus and accepts no caller-supplied rules or host commands. It persists one policy per bridge and checks it before guests start or resume. Existing unmarked bridges keep their policy. Dedicated filter chains enforce the complete boundary before permitting traffic through the host firewall; an unconditional bridge accept bypasses the intended restrictions. Persistent recovery and exact cleanup are part of the compute lifecycle. See [Durable firewall policy](/reference/compute-drivers#durable-firewall-policy-on-an-incus-host).

### Two lanes

| | Orbit lane (the `orbit` Project) | Project lane (every other Project) |
| --- | --- | --- |
| Machines | An **operator** VM and a **test gateway** VM by default. `app-dev` and `app-prod` join when a subtask declares them | One VM |
| Network | Its own Incus network for each group. It never joins the live fleet | Joins the live fleet over WireGuard as an `app-dev` node of the dev Cluster |
| Control path | The real Gateway reaches the operator through the host's Orbit agent: an Incus proxy device for the Pi port, and `incus exec` for the check and turn receipts | The Gateway sends typed envelopes to the Orbit Agent, as on any node |
| Code | One worktree volume for each group, attached to the operator, the test gateway, and `app-dev` | A checkout inside the VM, served as a real Instance |
| Routes | Only inside the test topology. Exposing the topology outside is out of scope | A private dev-cluster Route, `task-<id>.<project>.<dev-tld>` |

The operator stays a roleless node, registered with the test gateway. It becomes a VM instead of a system container. The test topology keeps the fleet's WireGuard addresses, so the operator must never also join the live fleet.

**Local Project admission.** A local Project reservation owns exactly one guest from that Project's pinned development image. Its recorded Incus host, project, storage pool, subnet, image and bootstrap endpoint must still match before fleet enrollment. An Orbit topology image is never a Project development image. The guest has the managed account and no existing fleet or private-topology identity. The trusted host reads the SSH public key from that exact guest; private keys stay inside it.

Before WireGuard enrollment, the live Gateway uses a private host proxy to the guest's SSH port. Only the recorded Gateway can reach that proxy through the host's WireGuard interface. A distinct, separately approved host policy permits this bootstrap path and UDP to the recorded public WireGuard hub endpoint. The host policy for an isolated Orbit pair does not authorize a Project VM to join the live fleet. The hub installs the reservation's limits before publishing its peer. Retries retain the same guest, SSH identity and two-way Node ownership; they never adopt another Node or grant access to other Nodes.

After enrollment, the Gateway reaches the guest through its recorded fleet address. The owned development Instance and private task Route use the native runtime. Cleanup removes the Route, Instance, Node and hub peer before destroying local compute. Local park retains that identity, and resume verifies it before another agent starts. Existing UpCloud reservations retain their provider-specific identity and destroy/rebuild behavior.

**Declared topology.** A subtask declares the nodes it needs, for example `topology: ["app-dev"]`. Orbit adds them before that subtask starts. A workload node always joins the group's test gateway. `topology_requested` stays as a fallback for discovery.

**Images.**
- The Orbit lane clones a saved operator+gateway pair, with the operator already registered and its CLI profile set. A warm pool keeps one or two pairs ready. `app-dev` and `app-prod` join from prebuilt images.
- Each project-lane Project has a dev image with its runtime, the Orbit Agent, the Pi server, and the managed user.
- Every image starts with the TIA baseline of `main`, imported from CI.

### One user and one environment inside the sandbox

There is no `orbit-worker` in a sandbox. Agents, the Pi server, and the task check run as the managed user with passwordless sudo, so the privileged tests run. Orbit's handoff check stays as fast feedback and still compares tree hashes. **CI on the pushed commit is the authoritative gate.** `bin/pr-head-check` already requires it before merge.

### Secrets and network

- **Temporary GitHub App tokens enter the owned sandbox.** The App private key stays on the Gateway. Tokens are scoped to the Project repository and renewed for direct fetch and push. UpCloud guests authenticate renewal with their enrolled WireGuard identity and private Pi token. A root agent can read its repository token; this is an accepted boundary for a disposable Project VM. Publication still pushes only the approved commit.
- **Model calls** go only through CLIProxyAPI. Each group gets its own key, created at claim and revoked at the end. The Orbit-lane operator reaches it through a host proxy device, because `10.44.0.3` belongs to the test topology there. No subscription sign-in is stored in any sandbox.
- **The Pi token is per node.** The Gateway-wide `ORBIT_PI_TOKEN` is not used for sandboxes.
- **Limits on outbound and fleet traffic are enforced outside the sandbox.** Project lane: the WireGuard hub ACL. Orbit lane: an Incus network ACL on the host. Cloud: the provider firewall as well.
  - The public internet over HTTP(S) is allowed.
  - Blocked: `10.44.0.0/16`, the LAN, the host, other groups' networks, and `169.254.169.254`.
  - Exceptions: CLIProxyAPI. In the project lane, also the Gateway API and the dev-cluster router.
- **Incoming traffic to a project-lane node** comes only from the Gateway, the dev-cluster router, and nodes granted access. The node has no grants to other nodes. Orbit firewall rules express this, so doctor sees drift.

### Lifecycle

- **Task status and VM power are separate.** After the last approval Orbit opens the PR, and the group moves to the new status `waiting_for_review`. VM power is `running`, `stopped`, or `destroyed`.
- **Park and resume.**
  - When the PR opens, the compute driver parks the sandbox. It keeps the sandbox running for a 5-minute grace period only when no group waits for capacity.
  - Local Incus takes a snapshot and stops the VM.
  - UpCloud Starter plans bill while stopped, so the VM is kept for at most 1 hour and then destroyed.
  - A group with `preview: true` stays running until the merge.
- **Resume** happens on changes requested, red CI, or a real conflict. Incus restores the snapshot taken when the PR opened. Cloud drivers build a new VM from the image and the `task-<id>` branch. Resume groups are claimed before `todo` groups.
- **When the PR merges**, a project-lane node leaves the fleet. Orbit destroys the sandbox and its snapshots. Orbit marks every sandbox as a task sandbox, sweeps sandboxes left behind, and doctor does not report them as drift.
- **Base updates** come from the GitHub update-branch API, not an agent fixup. Only a real conflict or red CI resumes the group.

### Rollout

Each Project has the setting `task_compute: shared | vm`. `shared` keeps today's flow and stays the default until each lane is proven. A group never switches mode while it runs. A `vm` group that cannot get a sandbox waits with a visible reason and never falls back to `shared`. The Orbit lane is built first.

This decision changes [Shared Instance](/reference/tasks#shared-instance) and [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) for `vm` groups. Once no Project uses `shared`, the `orbit-worker` account and its `incus-admin` membership are retired.

## Rejected alternatives

- **Keep `orbit-worker` and grant it narrow sudo.** This removes the permission-related test failures, but keeps two environments on a shared host. It does not fix `incus-admin` root equivalence or T3 agents running as the managed user.
- **Run agents as the managed user on shared hosts.** Every agent would get passwordless sudo, Docker, and the managed user's credentials. One prompt injection can reach the fleet.
- **The operator joins the live fleet as well as the test topology.** Both networks use `10.44.0.0/16`. Mixing live and disposable traffic is already refused in [The operator guest](/reference/incus-topologies#the-operator-guest).
- **Operator only by default, adding the test gateway on demand.** Most Orbit features need a real machine. A default gateway makes every added node a plain join and gives the operator a working CLI target from the first turn.
- **Isolated sandboxes for every Project.** Project features need real routes, deployment, and a preview a human can open. Joining the fleet with no grants and hub-enforced limits keeps that and stays contained.
- **Keep sandboxes running until merge.** In the measured PRs, 1 of 22 got `CHANGES_REQUESTED`. Parked sandboxes free capacity, and restoring a snapshot starts from a clean state.
- **Copy subscription sign-ins into each sandbox.** It exposes the subscriptions, and rotating OAuth refresh tokens break when many copies refresh.
- **Fall back to `shared` silently when no sandbox is available.** It would mix environments again, which is the problem this decision removes.

## Consequences

- Agents get the same verdict as the gate. The largest source of blocked turns and failed local checks goes away.
- The privileged tests run inside the sandbox. The ACL, `safe.directory`, and check-directory ownership work is removed for `vm` groups.
- Overspill to cloud capacity uses the same lifecycle and images.
- Images, snapshots, warm pools, and VM budgets need to be built and kept current. That work is listed in the implementation plan.
- Publication runs directly in the owned sandbox with temporary repository access. The Gateway retains the approved-commit gate.
- A root agent can tamper with the in-sandbox check result. CI on the pushed commit stays the authoritative gate.
- Nested virtualization is required wherever the Orbit lane adds VMs. Cloud drivers serve the project lane first.

## Affects

- Components: apps/gateway, apps/e2e, apps/cli, apps/docs
- ADRs: none in progress
- Detail: [Tasks](/reference/tasks): Shared Instance becomes "Task sandbox", plus Lifecycle and Scheduler. [Pi server](/reference/pi-server): host setup and the user model. [Incus topology registry](/reference/incus-topologies): operator VM and declared nodes. A new page, `docs/reference/compute-drivers.md`. [Projects](/reference/projects): `task_compute`.
- Verify: The disposable-environment proofs check these outcomes.
  - A one-subtask Orbit group runs end to end in an operator VM.
  - A local check run by an agent and Orbit's handoff check give the same verdict on the same tree.
  - A group runs to PR, then parks, resumes, merges, and nothing is left behind.
  - A sandbox cannot reach `10.44.0.0/16` except at its allowed endpoints.
  - No App private key or provider credential enters a sandbox. GitHub tokens never appear in argv, logs, stored origins, or Git configuration.
