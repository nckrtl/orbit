---
title: "ADR 0195: Request topologies for review with an operator container"
sidebarTitle: "0195 On-request topologies"
description: "In progress. Reviewers request a group's disposable topology through a turn receipt; every topology includes an operator container for isolated web UI work."
---

# ADR 0195: Request topologies for review with an operator container

Orbit provisions task workspaces without a topology. The reviewer requests one when discovery needs it. Every topology includes a roleless operator system container that runs the web development server against only that topology.

## Status

In progress.

Principle: this decision serves [lean](/mission#principles), [agents operate, humans steer](/mission#principles), and [one way, one name](/mission#principles). Groups allocate machines only when the reviewer needs discovery, and agents request that work through the existing turn command. No principle exception is needed.

## Context

Most groups never need an Incus topology. Acquiring one during workspace provisioning wastes resources and makes ordinary task startup depend on discovery infrastructure. Task agents run as `orbit-worker` without sudo, while acquisition changes host firewall rules and belongs to the managed user.

Web UI development against the live fleet can change real Processes, Schedules, databases, and firewall rules. Beast is a WireGuard peer of that fleet. Topologies reuse the fleet's private address range, so beast cannot join a topology's tunnel in its own network namespace without mixing live and disposable traffic.

## Decision

The reviewer owns the request, Orbit owns acquisition and release, and the topology's operator container owns the web development session.

### Reviewer-owned acquisition

Workspace provisioning never acquires a topology automatically. Only a reviewer turn, including a consult or relay, may end with `topology_requested` through `$(git rev-parse --git-path orbit)/turn`. An implementer that needs a topology asks the reviewer through the existing consult. The turn command refuses this outcome from an implementer and tells it to use that consult. There is no separate agent acquisition CLI or API command.

Orbit consumes the existing turn receipt, acquires the group's one `TASK-<group>` topology as the managed user, and resumes the requesting reviewer with the acquisition result or failure. A repeated request reuses an existing group topology. The request does not approve or reject a subtask. Acquisition failure or absence of a topology never prevents approval: topologies support discovery, not required proofs.

Orbit resumes the same reviewer thread in its original review, consult, or relay context. A consult stays open and the implementer stays paused while acquisition runs. The request does not answer or escalate the consult, even on failure. A pending relay or direction resolution also stays pending. The resource receipt requires a summary but refuses question, cause, and pull request flags, and leaves question records and assistance flags unchanged. The resumed reviewer answers normally under its original context's outcome and cause rules. The group's subtasks share the topology until Orbit removes the group's workspace, when Orbit releases it.

### Operator in every topology

Every topology includes a small Incus system container named `operator`, in addition to its workload VMs. The operator is a roleless Node registered with the topology's Gateway, has a Gateway access grant and its own WireGuard peer in that topology, and trusts the topology's Orbit CA. It hosts no workload Instance. The task worktree is mounted live at `/home/orbit/orbit`, including `apps/web`.

Release checks ownership, stops, deletes, and verifies absence for every recorded guest, including the operator container and any extension VM. It also stops the owned web process and removes its loopback publication. Orbit retains the lease and topology record on failure and never drops topology state while a recorded guest remains. Network deletion and successful session cleanup precede dropping that state.

The coordinated topology snapshot includes the operator container and its prepared tooling and configuration. Acquisition aligns its network identity and WireGuard endpoint with the cloned Gateway. After deployment, the operator must rebuild the shared topology snapshot on beast to include this guest, using the snapshot's ownership-checked rebuild or recovery path. This shared operation is not a task fixture change.

### Foreground web session

`bin/e2e-topology web ISSUE` requires an already acquired topology and its configured operator guest. It never implicitly acquires one. It runs `vp dev` in the operator's `apps/web`, on guest TCP port `5173` with strict-port behavior and no automatic guest-port fallback.

The session publishes only on beast's IPv4 loopback, `127.0.0.1`, at an available OS-assigned ephemeral host TCP port. After readiness it prints the actual host port and URL. There is no promised fixed host port, DNS route, or Caddy Route; `topo-<issue>.orbit.test` is not part of this contract.

The command stays in the foreground and streams dev-server output. Ctrl-C, command termination, startup failure, and topology release stop the session's dev-server process and remove its loopback publication. Stopping web does not release the topology. Only one web session may run per topology; a second invocation fails clearly without disturbing the first.

The Gateway URL and trusted CA are pinned to the selected topology. Inherited endpoint overrides cannot redirect Gateway, realtime, or metrics traffic to the live fleet. Missing topology or required operator configuration fails clearly instead of falling back to the real Gateway, the caller's live profile, or another user's credentials.

For Mac access, keep web running and substitute its printed numeric host port for `P` in `ssh -N -o ExitOnForwardFailure=yes -L 127.0.0.1:5173:127.0.0.1:P beast`. Open `http://127.0.0.1:5173` on the Mac. If local port `5173` is occupied, use another free Mac-local port and open that port instead. Keep both commands running and stop each with Ctrl-C.

## Rejected alternatives

- Acquire during every workspace provision: most groups do not need discovery infrastructure.
- Let an implementer acquire through a CLI or API: acquisition changes host firewall rules, and the existing consult lets the reviewer own the decision without granting agents sudo.
- Require a topology for approval: discovery availability is not proof of correctness.
- Join the topology tunnel on beast: its live WireGuard identity and the reused address range would mix fleets.
- Run UI experiments against the live Gateway: destructive actions would affect live resources.
- Publish a DNS/Caddy route or a public listener: loopback and SSH forwarding provide access without a new fleet route.
- Choose a fixed host port or let the guest server choose another port: concurrent groups need independent host ports, while a guest conflict must fail visibly.
- Fall back to a live profile: missing disposable configuration must not turn a safe experiment into a live operation.

## Consequences

- Ordinary groups use no topology resources. The reviewer pays acquisition cost only when discovery needs it.
- Every topology and the shared snapshot gain one operator container and its lifecycle, identity, CA trust, access grant, and WireGuard configuration.
- The shared snapshot needs an operator-owned rebuild after deployment.
- A web session owns both its process and loopback publication. Reviewers can continue after an acquisition failure and may approve without a topology.
- The completion subtask absorbs this ADR into the owning reference pages and retires it according to the [ADR overview](/decisions/overview).

## Affects

- Components: apps/gateway, apps/e2e, apps/web, apps/docs
- ADRs: none.
- Detail: [Incus topologies](/reference/incus-topologies#topologies-on-the-reviewers-request), [Tasks](/reference/tasks#request-a-topology), [Topology snapshot](/reference/topology-snapshot#operator-container), and [Web app](/reference/web-app#run-against-a-topology).
- Verify: turn receipt role checks, task acquisition and reviewer-resume tests, topology construction and snapshot verification, web startup and cleanup tests, an isolated web session with Mac SSH forwarding, and `composer docs-lint`.
