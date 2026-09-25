---
title: "ADR 0128: Run a visibility-only agent on managed Nodes"
sidebarTitle: "0128 Run a visibility-only agent on managed Nodes"
description: "Proposed. A small Rust program, orbit-agent, runs on every managed Linux Node and reports presence and Process state. It never runs commands and never listens on a port. SSH stays the only way the Gateway changes a Node."
---

# ADR 0128: Run a visibility-only agent on managed Nodes

Every managed Linux Node runs `orbit-agent`, a small Rust program that watches the Node and reports what it sees. The agent reports only. It runs no command the Gateway or anyone else sends, and it opens no listening port. SSH stays the only way the Gateway changes a Node. The Gateway installs, upgrades, and removes the agent over SSH, the same way it manages the Metrics exporters.

## Status

Proposed.

Amended by [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) and [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime).

This lifts the deferral of an Orbit agent in [ADR 0127](/decisions/0127-share-one-ssh-connection-per-node) for visibility only. The Gateway rule against agents that run commands stays in place.

## Context

The web app learns Node reachability and Process state by polling. A Node shows online or offline from Prometheus `up`, scraped every 10 seconds and polled by the browser every 10 seconds. A Process's `runtime_status` comes from node_exporter and cAdvisor through Prometheus, with a further 10-second Gateway cache. A change that Orbit did not make, such as a crash, an OOM kill, or a manual `docker stop`, shows only after the next scrape and poll.

A program on the Node can see those changes as they happen. systemd publishes unit state changes on D-Bus, and Docker streams container events from its socket. Only a process on the Node can read either without polling.

ADR 0127 deferred an Orbit agent because it adds an application to build, ship, and update on every Node, and because SSH access would still be needed for recovery. The Gateway guidance also forbids agents. Both concerns target an agent that replaces SSH as the way the Gateway acts on a Node. orbit-old's agent did exactly that: it ran commands that the Gateway pushed to it over an HTTP listener.

Memory matters because the agent runs on every Node. On beast, orbit-old's Rust agent used 5.2 MB resident memory with a 5.6 MB binary. The Bun-based `pi-server` uses 94 MB to 116 MB. Rust also has mature native libraries for systemd D-Bus (`zbus`) and the Docker socket (`bollard`).

## Decision

- Orbit ships `orbit-agent`, a Rust program in `apps/agent`. It is a separate project with its own Cargo manifest and checks.
- The agent is visibility-only. It observes the Node and publishes what it observes. It must not accept commands, run programs on anyone's behalf, or change the Node. It must not listen on any port or socket. Every connection it makes is outbound.
- The first slice observes two things: that the agent is running and connected, and the runtime state of Orbit Processes. It reads Process state from systemd for `orbit-process-*.service` units and from Docker for `orbit-process-*` containers. [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) defines how it publishes both.
- The Gateway installs the agent on every Node that `ManagedNodeEligibility` allows, the same boundary as the Metrics exporters in [ADR 0057](/decisions/0057-limit-metrics-exporters-to-managed-nodes). An operator client never runs the agent.
- The Gateway pins one agent version with one SHA-256 checksum for each supported architecture, `x86_64` and `aarch64`. It installs over SSH with the pattern cAdvisor uses: download a candidate, verify the checksum, and move it into place. It runs the agent under a hardened systemd unit, `orbit-agent.service`, with `Restart=always`.
- `node:add` installs or upgrades the agent and fails at step `agent` when it cannot. A role converge also converges the agent on that role's Node, but an agent failure there does not fail the role.
- Online `node:remove` stops and deletes the agent, as it retires the Metrics exporters. A failure in that step does not stop the removal. Offline removal leaves the agent on the machine and lists it under `retained_on_node`.
- Doctor reports a missing, inactive, or outdated agent on an eligible Node in the `node` family.

## Rejected alternatives

- Keep polling Prometheus only: rejected because every change Orbit did not make stays up to 20 seconds late, and a faster scrape costs every Node and the Metrics Node without making the web app live.
- An agent that also runs commands: rejected because it creates a second way to change a Node. It needs its own authorization, listener, and recovery story, and SSH would still be required when the agent breaks.
- Write the agent in PHP, Bun, or Go: rejected because the agent runs on every Node. PHP and Bun cost roughly 20 times the memory of the Rust agent measured on beast. Go is close in size, but Orbit already carries Rust in `apps/desktop` and plans a Tauri Mac app that embeds the agent crate.
- Revive orbit-old's agent: rejected because its core is a command server. Only its build setup is worth reusing.
- Install the agent through the `apt` tool manager: rejected because Orbit publishes no apt repository, and a pinned binary with a checksum matches how the Gateway already installs cAdvisor.

## Consequences

- The web app can show Node presence and Process state within seconds, and falls back to Prometheus when a Node has no agent.
- Every managed Node carries one more binary and systemd unit. Upgrading the agent needs a Gateway change to the pin and then `node:add` or a role converge on each Node.
- The agent does not replace SSH, the task workspace probes, or the hibernation probes. Moving those probes to the agent and a Tauri Mac app that embeds the agent crate need their own decisions.
- Because the agent never listens and never acts, a compromised Gateway gains no new way into a Node, and a compromised Node gains no new way into the fleet beyond reporting false state about itself.
- The Gateway guidance changes from "no agents" to "no agents that run commands".

## Affects

- Components: apps/gateway, apps/docs, apps/e2e
- ADRs: lifts the agent deferral in [ADR 0127](/decisions/0127-share-one-ssh-connection-per-node) for visibility only; reuses the eligibility boundary of [ADR 0057](/decisions/0057-limit-metrics-exporters-to-managed-nodes); [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels) defines the transport and [ADR 0130](/decisions/0130-publish-agent-binaries-as-github-releases) the release
- Detail: [Node agent](/reference/node-agent), [Node provisioning](/reference/node-provisioning), [`doctor`](/cli/doctor)
- Verify: agent unit tests in `apps/agent`; Gateway install, upgrade, removal, and Doctor tests; the agent Incus proof and the beast check
