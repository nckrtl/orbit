---
title: "ADR 0202: The fleet follows the Gateway through orbit self-update"
sidebarTitle: "0202 Fleet follows the Gateway"
description: "In progress. After each verified Gateway release, the Gateway visits each managed Linux Node in turn over SSH, installs the Orbit CLI if it is missing, runs orbit self-update there, re-applies its rendered configuration, and verifies. It skips unreachable Nodes, halts on the first failure, and catches up every 5 minutes. The agent stays observe-only."
---

# ADR 0202: The fleet follows the Gateway through orbit self-update

After every verified Gateway release, the fleet follows. The Gateway visits each managed Linux Node in turn over the SSH path it already uses. On each Node it installs the Orbit CLI if it is missing, runs `orbit self-update` there, re-applies the configuration it renders, and verifies the result. It goes one Node at a time, skips unreachable Nodes, and halts on the first failure. A catch-up every 5 minutes converges Nodes that were missed. The Orbit Agent stays observe-only. `orbit self-update` is the one node-local updater, so a separate decision can let an agent signal trigger it instead of SSH.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One command, `orbit self-update`, updates Orbit on any machine, whether the Gateway triggers it on a Node or an operator runs it on a Mac. It also serves [Deterministic first](/mission#principles): rollout order, verification, and halting are code. And it serves [Security fits the real threat model](/mission#principles): it adds no new way into a Node. The Gateway already changes Nodes over SSH, and the agent still runs nothing on anyone's behalf.

## Context

[ADR 0201](/decisions/0201-release-the-gateway-automatically-from-green-main) releases the Gateway automatically. Nothing updates the rest of the fleet. Measured on origin/main `dc629e7fb` (7 Oct 2026):

- **No fleet update path.** There is no `orbit update`, `update:all`, or self-update command. All Node changes run over SSH from the Gateway ([SSH connections](/reference/node-provisioning#ssh-connections)). `node:add` re-converges everything but refuses a Node that owns Instances ([Converge an existing Node](/reference/node-provisioning#converge-an-existing-node)). Role converge is the only broad re-apply.
- **Gateway-rendered artifacts go stale after a Gateway release.**
  - Caddyfiles are rebuilt only on site changes or `orbit:caddy-build`. Doctor reports `role.caddy_build_drift`.
  - Process units and FPM pools change only when their resource changes.
  - The private-DNS listener, the ProxyCli collector, and the annotator change only on their next publication.
- **The agent is updated by hand.** Its version and per-arch sha256 are pinned in `NodeAgentFootprint` (0.3.0). The documented upgrade is: tag a release, bump the pin, deploy the Gateway, then converge each Node by hand ([Install and upgrade](/reference/node-agent#install-and-upgrade)). Doctor reports `node.agent_binary_mismatch`.
- **The CLI is not distributed.** CI builds it as workflow artifacts that expire and need a GitHub login. The mac-arm build depends on the `mini` runner. Orbit does not install the CLI on Nodes or track versions ([CLI binaries: Limits](/reference/cli-binaries#limits)). The CLI sends no version, and the Gateway checks none.
- **The agent only observes, by decision** ([The agent only observes](/reference/node-agent#the-agent-only-observes)). It "listens on no port, runs nothing on anyone's behalf, and SSH stays the only way to change a Node". It runs on Linux only. Operator Macs have no agent, and the Gateway cannot SSH into them.

## Decision

The Gateway rolls each verified release out to the fleet through `orbit self-update`, as described below. The operator resolves a halted rollout.

### Desired fleet state

When a Gateway release passes verify and smoke, its release record gains the **desired fleet state**:

- the Orbit commit;
- the CLI release version, with a sha256 per platform;
- the agent pin, with a sha256 per arch;
- the expected footprint digest per Node.

`gateway:status` and a release-manifest endpoint serve it to any authenticated machine.

### Published CLI releases

CI publishes the CLI binaries of each green main commit as a GitHub release with `SHA256SUMS`, as it already does for `agent-v*`. Releases do not expire and need no login to download.

### `orbit self-update`

One node-local command, run as root on Nodes and as the user on operator machines:

1. Read the desired fleet state from the Gateway with the machine's normal Gateway identity.
2. On a managed Linux Node, replace the agent binary when it differs from the pin, using the same verified candidate-and-move steps as today's agent converge. Then restart `orbit-agent.service`, check that it stays up, and restore the previous binary when it does not.
3. Replace the CLI binary with the published release, last. Build the download URL on the machine, verify the sha256 from the manifest against the release's own `SHA256SUMS`, install the release beside the old one, and switch the `orbit` link atomically. Refuse a downgrade unless `--allow-downgrade` is passed.
4. Print a JSON result: versions before and after, and each step's outcome.

The CLI goes last because a standalone binary reads its own PHAR by path while it runs: once the file that runs the command is replaced, the process cannot load more code. Versioned files behind a link keep every running `orbit` on its own file; only the first update of a plain binary replaces the running file, and the command then exits right after its result. [Orbit self-update](/reference/self-update#replace-the-cli-last) has the details.

### Fleet rollout

After each verified Gateway release, the oneshot `orbit-fleet-converge.service` rolls out the desired state. It runs in its own unit, never inside PHP-FPM or the scheduler. It visits every active managed Linux Node **one at a time** in a fixed, lowest-risk-first order that the operator can override:

1. `app-dev` and `agent` Nodes
2. `s3` and `metrics` Nodes
3. `database`, `websocket`, and `vpn` Nodes
4. `app-prod` Nodes

For each Node, under the existing per-Node converge lock:

1. **Install the CLI if it is missing.** Download the published release for the Node's arch, verify its sha256, install it at the canonical path, and configure its Gateway connection with the Node's identity. Provisioning and role converge use the same step.
2. **Run `sudo orbit self-update --json`** over SSH.
3. **Re-apply the Gateway-rendered footprint:** `caddy-build`, plus the private-DNS listener, ProxyCli collector, annotator, and Gateway-owned units when their digest changed. This is a no-op when nothing changed, and it never changes Instance state. `orbit node:converge <node>` runs the same step manually.
4. **Verify:** Doctor reports the Node's families clean, and the agent reports the pinned version in presence.

Each Node's outcome is recorded on the release:

| Outcome | Action |
| --- | --- |
| `converged` or `unchanged` | Continue with the next Node |
| `unreachable` (SSH cannot connect) | Skip the Node, record it, and continue. The catch-up converges it when it returns |
| `failed` (reachable, but any step or the verify failed) | **Halt.** Leave the remaining Nodes untouched, mark the rollout `halted` with the Node and evidence, and alert |

- The alert is the same as in ADR 0201: a failed Activity entry, a filed problem, and the webhook to Anna.
- `orbit fleet:rollout status|resume [--skip=<node>]` shows and resumes a rollout.
- A halted rollout blocks new rollouts. Once it is resolved, the next rollout targets the newest desired state.
- A fleet failure never rolls back the Gateway. The failed Node and the Nodes not yet visited keep their previous versions.

### Catch-up

Every 5 minutes, unless a rollout is halted, a timer converges every Node whose reported versions or footprint digest differ from the desired state. It goes one Node at a time, with the same rules. That covers Nodes that were unreachable, new Nodes, and drift. Doctor gains `node.release_lag`.

### Operator machines

The CLI sends `X-Orbit-Client-Version`. When the Gateway's desired CLI version is newer, the CLI prints a throttled notice to run `orbit self-update`, and the operator runs it. `apps/desktop` already loads the web app from the Gateway and needs nothing.

### The agent stays observe-only

This decision does not change [The agent only observes](/reference/node-agent#the-agent-only-observes). Letting an agent signal trigger `orbit self-update` is a separate decision that needs its own record, with signed manifests and a narrow privileged trigger.

## Rejected alternatives

- **Agents pull and apply updates now.** A compromised Gateway, Reverb secret, or release key would become a root code-execution path to every Node. That reverses a documented boundary and needs offline signing and a privileged updater first. It also still would not cover Macs, which have no agent. `self-update` keeps that path open.
- **The Gateway performs each update step itself over SSH.** It works, but keeps the update logic in the Gateway. Running one node-local command makes the move to an agent trigger a change of trigger only.
- **Converge Nodes in parallel.** It is faster, but a bad release would break many Nodes at once. Sequential with a halt limits the damage to one Node.
- **Halt on unreachable Nodes.** One offline Node would block the whole fleet. Unreachable Nodes have changed nothing, and the catch-up converges them when they return.
- **Re-run `node:add` or role converge.** `node:add` refuses Nodes that own Instances, and role converge does far more than the Orbit footprint.
- **Keep CLI binaries as workflow artifacts.** They expire and need a login, so Nodes cannot fetch them reliably.

## Consequences

- After each Gateway release, every reachable managed Node runs the matching CLI, agent, and Gateway-rendered configuration, and the release record shows which Node is where.
- Agent upgrades become a pin bump plus a Gateway release, with no per-Node manual converge.
- The CLI becomes part of every managed Node.
- A rollout takes time proportional to the fleet size, and one failure stops it until an operator acts.
- Operator Macs update only when someone runs `orbit self-update`.
- Parked, each needing its own decision:
  - self-update pulling and applying the rendered footprint locally, so the Gateway stops pushing configuration over SSH;
  - auto-update on operator Macs;
  - a macOS agent;
  - an agent-triggered self-update.
- Third-party pins (Caddy, cAdvisor, fpm-exporter, Prometheus, Grafana, Plausible) and Reverb from `nckrtl/orbit-reverb` keep their own update paths.

## Affects

- Components: apps/cli, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: [ADR 0201](/decisions/0201-release-the-gateway-automatically-from-green-main) (the rollout starts after its verify and smoke)
- Detail: these pages receive the decision.
  - [Node agent](/reference/node-agent#install-and-upgrade): the upgrade becomes a pin bump plus a Gateway release.
  - [Node provisioning](/reference/node-provisioning#add-a-node): the CLI is installed on managed Nodes.
  - [CLI binaries](/reference/cli-binaries): `.github/workflows/orbit-cli-binary.yml` publishes releases, and `orbit self-update` installs them.
  - [Update and recover a Gateway](/reference/gateway-recovery): a new "Fleet rollout" section.
- Verify: tests and a disposable topology prove these outcomes.
  - `orbit self-update`: a checksum mismatch changes nothing, a downgrade is refused, an interrupted replace leaves a working binary, and the agent is replaced only when it differs from the pin.
  - The CLI install step installs a missing CLI, leaves a present one alone, and installs nothing on a checksum mismatch.
  - On a disposable topology with 3 Nodes:
    - a clean sequential rollout;
    - a failure on the second Node halts, leaves the third untouched, alerts, and `resume` finishes;
    - an offline Node is skipped and converged by the catch-up within 5 minutes after it returns.
  - The footprint re-apply makes no writes when digests match and changes no Instance.
