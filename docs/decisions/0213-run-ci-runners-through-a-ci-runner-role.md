---
title: "ADR 0213: Run CI runners through a ci-runner role"
sidebarTitle: "0213 CI runner role"
description: "In progress. A ci-runner role prepares a Node to host self-hosted runners for GitHub Actions in unprivileged Incus containers. Each runner is a Gateway record that Orbit registers through the GitHub App, converges, and removes."
---

# ADR 0213: Run CI runners through a ci-runner role

A new `ci-runner` role prepares a Node to host self-hosted runners for GitHub Actions. Every runner runs in an unprivileged Incus container on that Node. Each runner is a CI runner record that the Gateway registers with one repository through its GitHub App, converges on the Node, and deregisters on removal.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). Runners become commands that an agent can run and a human can read, instead of hand-made services and a shell script. It also serves [Security fits the real threat model](/mission#principles): a job runs as an unprivileged user in a container whose egress cannot reach the Gateway, WireGuard, or the LAN, so a job never acts as the Node. No principle exception is proposed.

## Context

Orbit's own CI already runs on Orbit Nodes, but Orbit does not manage those runners. GitHub lists five runners for `nckrtl/orbit`:

| Runner | Node | Labels | State |
| --- | --- | --- | --- |
| `sabre-1`, `sabre-2` | Sabre | `self-hosted`, `Linux`, `X64`, `sabre`, `orbit-shard` | Online |
| `ci-beast-1`, `ci-beast-2` | Beast | `self-hosted`, `Linux`, `X64`, `orbit-shard` | Online |
| `ci-shark-1` | Shark | `self-hosted`, `Linux`, `X64`, `orbit-shard` | Offline, its container stopped |

- Sabre has no role. Its runners run on the host as `github-runner`, which has passwordless `sudo`. A `github-runner-egress` systemd unit loads an nftables rule, made by hand, that limits their egress. A job that runs `sudo` bypasses that rule and can act as Sabre toward the Gateway. The implementation loop describes [the setup](/reference/implementation-loop#self-hosted-gateway-runner).
- Beast and Shark are `app-dev` Nodes. Their runners run in unprivileged Incus containers in the project `orbit-ci`, with a `runner` user that has no `sudo`. [`tools/ci-runners/setup.sh`](https://github.com/nckrtl/orbit/blob/main/tools/ci-runners/setup.sh) builds them by hand. Both Nodes list `orbit-ci/*` in `task_vms.operator_owned`, so the task VM stray report ignores those containers. The implementation loop describes [those runners](/reference/implementation-loop#self-hosted-shard-runners).

Doctor sees none of this state, registration tokens pass through an operator's shell, and removal is a manual recipe.

Every CI step that calls `sudo` runs only on GitHub-hosted runners. The `privileged` test group runs in its own job on a GitHub-hosted runner, and shards already leave it out. Only Sabre's unsharded Gateway job can still select a privileged test.

GitHub's repository registration-token endpoint needs the App permission `Administration: write`.

## Decision

The Gateway owns the role, every CI runner record, and every registration. The parts below form one contract.

### The role

`orbit node:role:add <node> ci-runner` assigns the role. It is Linux-only, like every role, is not a singleton, is not relocatable, and cannot be assigned through `node:add --role`. It shares a Node with every role except `gateway` and `vpn`, whose Nodes hold the fleet's trust anchors. Cluster membership does not matter to it.

The role installs Incus when it is missing and creates the Incus project `orbit-ci`, the bridge `orbitci0`, the ACL `orbit-ci-egress`, the restricted default profile, and the `ufw route allow in on orbitci0` rule, as `setup.sh host` does today. The ACL rejects every private, CGNAT, loopback, link-local, multicast, and reserved IPv4 range, and all IPv6. The project's resources belong to the role, so the task VM stray report and Doctor treat `orbit-ci` on a `ci-runner` Node as owned, without an `operator_owned` entry.

Convergence uses the role baseline, as every role does today. When [ADR 0211](https://github.com/nckrtl/orbit/pull/1251) is built, its migration moves this baseline to remote programs with the rest.

### CI runner records

A CI runner is a Gateway record with a Node, a unique name, a repository, labels, and a status. The repository is `<owner>/<repository>`. Labels always include `self-hosted`, `Linux`, and the architecture label, plus any labels the operator adds.

| Command | Effect |
| --- | --- |
| `ci-runner:create <node> <name> --repository=<owner>/<repository> [--label=…]` | Records the runner, creates its container, registers it, and starts it. |
| `ci-runner:list [--node=…]` | Lists records with their status. |
| `ci-runner:show <runner>` | Shows one record and the status that GitHub reports for it. |
| `ci-runner:destroy <runner>` | Stops the runner, deregisters it from GitHub, and deletes its container. |

`ci-runner:create` refuses a Node without the role with `ci_runner.role_missing`.

Each container has a `runner` user with no `sudo` and no group beyond its own, as the shard containers have today. Every tool a job needs comes from the `ci-runner` profile of the [machine software recipe](/reference/node-provisioning). Today a roleless Node selects that profile with [`node:converge-recipe --profile=ci-runner`](/reference/node-provisioning#converge-a-node-to-a-recipe). The role selects it instead, and the `--profile` option goes away. Runners are persistent: one runner takes job after job in the same container. Ephemeral runners need a new ADR.

### Registration through the GitHub App

The Gateway mints each registration and removal token through its [GitHub App](/reference/github-app), with only `administration: write`, for the runner's repository. The App's manifest gains that permission, and existing installations must accept it before a runner registers. A refused permission fails with `ci_runner.permission_missing`.

The Gateway sends the token to the Node on the standard input of the role's command, and never stores, logs, or returns it. Two exposures remain, and this decision accepts both:

- `config.sh` takes the token as an argument, so it shows in the container's process list for a few seconds. A registration token is valid for one hour and only registers a runner.
- The runner stores its own long-lived credentials in its container, as every self-hosted runner does. Deleting the container and deregistering the runner revokes them.

### Removal and Doctor

`node:role:remove <node> ci-runner` refuses while CI runners exist, unless `--force` is given; then it destroys each runner first. `--purge-data` also deletes the project, its images, the bridge, the ACL, and the ufw rule. `--offline` removes only Gateway state and leaves the runners registered with GitHub; the operator removes them there.

Doctor compares each CI runner record with its container and reports a missing, stopped, or unexpected runner. It does not call GitHub.

### Migration

The existing runners are recreated, not adopted:

1. Add the role to Sabre, Beast, and Shark.
2. Create `sabre-1` and `sabre-2` with the labels `sabre` and `orbit-shard`, `ci-beast-1` and `ci-beast-2` with `orbit-shard`, and `ci-shark-1` with `orbit-shard`.
3. Remove the hand-made runners and their GitHub registrations, including the offline `ci-shark-1`.
4. On Sabre, remove the `github-runner` user, its runner services, the `github-runner-egress` unit, and its nftables rule.
5. Remove `orbit-ci/*` from `task_vms.operator_owned` on Beast (Node 9) and Shark (Node 13).

The pull request that builds this decision deletes `tools/ci-runners/`, rewrites the two runner sections of the implementation loop, and makes Sabre's unsharded Gateway job leave out the `privileged` group, as the shards do.

## Rejected alternatives

- Run runners on the host of a dedicated Node: a job that gains root acts as the Node toward the Gateway, and the role needs a second mode, a mode-aware conflict check, and a second egress path.
- Give the runner user `sudo`: no CI step on a self-hosted runner needs it, and it widens what a job can change inside its container.
- Operator-supplied registration tokens: each add and repair needs a fresh one-hour token, and removal cannot deregister.
- Organization targets: no runner pool for an organization exists, and they need another App permission. A new ADR adds them when one does.
- A `provider` field for GitLab: it has one value. The ADR that adds GitLab adds it.
- Runners as fields of a Node setting, like task VMs: per-runner status, errors, and removal have no home.
- Adopt the existing runners: adoption code must read and trust hand-made state, for a one-time move.

## Consequences

- Runners become visible to Doctor, the API, and agents, and removal deregisters them.
- Sabre's jobs lose `sudo` and run in a container, so a job no longer acts as Sabre.
- The GitHub App gains `Administration: write`, which also covers repository settings. Orbit requests it only for runner tokens.
- Privileged tests run only on the GitHub-hosted privileged job.
- Persistent runners keep warm caches, and a job can still leave state for the next job.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs, apps/e2e
- ADRs: none
- Detail: a new reference page, CI runner role, written by the implementation pull request; [Role compatibility](/reference/node-provisioning#role-compatibility); [GitHub App](/reference/github-app#what-the-app-is); [Implementation loop](/reference/implementation-loop#self-hosted-gateway-runner)
- Verify: Gateway feature tests for the role conflicts, records, token scope, and removal; CLI command surface test; an Incus E2E that adds the role, creates a runner against a test repository, and destroys it
