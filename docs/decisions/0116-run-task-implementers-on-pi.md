---
title: "ADR 0116: Run task implementers on Pi"
sidebarTitle: "0116 Pi implementer driver"
description: "Proposed. Add a Pi AgentDriver backed by an Orbit-owned Pi server on each Node. Implementers can run on Pi with subscription-authenticated models. The reviewer stays on T3."
---

# ADR 0116: Run task implementers on Pi

Orbit adds a `pi` [AgentDriver](/decisions/0112-isolate-agent-threads-behind-drivers). An Orbit-owned Pi server on each Node runs Pi sessions through the Pi SDK. Implementers can run on Pi with subscription-authenticated models. The reviewer stays on T3. Orbit compares Pi with the T3 baseline before it changes a default.

## Status

Proposed.

This amends the single driver selection per TaskGroup in [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers). It keeps the thread states, observation rules, and driver boundary from that decision. It keeps the rubric in [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks).

This slice starts after the ADR 0115 pilot (verify task evidence before review) has run on T3 and recorded baseline measurements. ADR 0115 is proposed on a separate branch.

## Context

T3 runs every task thread today. It hosts the Claude Code and Codex harnesses and gives Orbit one HTTP and WebSocket server per Node. Orbit controls the model and effort. It does not control the harness system prompt, the tool set, or how much context each turn sends.

[Pi](https://github.com/earendil-works/pi) is a minimal coding harness built for programmatic use. It has a TypeScript SDK and an RPC mode. It stores each session as a JSONL file and reports turn, message, tool, and usage events. Callers can set the session ID, system prompt, tools, and skills. Pi has no approval prompts and no built-in MCP client.

The operator uses subscriptions, not per-token API billing. That constraint limits which models Pi can run:

| Provider | Subscription login in Pi | Provider terms |
| --- | --- | --- |
| OpenAI Codex | Built in: ChatGPT OAuth, including device code | OpenAI allows ChatGPT plans in third-party harnesses and names Pi in its [Codex for Open Source](https://developers.openai.com/community/codex-for-oss) program |
| xAI Grok | Built in: SuperGrok or X Premium device code | Offered openly by several third-party tools. No formal xAI statement was found |
| Anthropic Claude | Built in, but not permitted | Anthropic [allows subscription OAuth only in its own applications](https://code.claude.com/docs/en/legal-and-compliance), including unmodified Claude Code |

T3 therefore remains the only permitted way to run a Claude subscription model in this workflow.

Pi runs in one process per session and reports live events only to that process. Orbit needs a network service that owns many threads, returns snapshots, and streams events. That is the role T3 fills today.

## Decision

Orbit adds a `pi` driver and a Pi server. Driver selection becomes per role.

### Driver selection per role

A TaskGroup records `implementer_agent_driver` and `reviewer_agent_driver` instead of one `agent_driver`. The migration copies the existing value into both fields. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select the drivers for new groups. Each defaults to `ORBIT_TASKS_AGENT_DRIVER`. An unknown key rejects group creation with `tasks.agent_driver_unavailable`, as it does today.

Placement requires a Node that allows both selected drivers. A thread keeps its recorded driver for its lifetime. The reviewer driver stays `t3` in this slice.

### Pi server

`apps/pi-server` is a TypeScript service that uses the Pi SDK. It runs as an Orbit-managed systemd Process named `pi-server` on `app-dev` Nodes. It listens on the Node's WireGuard address and requires a bearer token. The token follows the T3 pattern: `ORBIT_PI_TOKEN` on the Gateway, overridden by `pi` settings on a Node record. The server takes its settings as command-line flags, because Orbit's systemd Processes pass only arguments, and reads the token from a file. A `pi-server login` command runs a provider's subscription sign-in on the Node. The Gateway never receives provider credentials.

The server owns these operations:

| Operation | Behavior |
| --- | --- |
| Create | Starts a session in the workspace checkout with the requested model, thinking level, role prompt, and tools. The session ID is the external ID Orbit stores |
| Send | Starts a turn. Carries an idempotency key. A repeated key returns the original result and does not start a second turn |
| Interrupt | Aborts the active turn |
| Snapshot | Returns the messages, state, pending turn, error, and cumulative usage for one session |
| Stream | Starts with a full snapshot, then sends entry, state, and usage changes |
| Capabilities | Lists installed Pi version and authenticated providers |

Sessions persist as Pi JSONL files in the service user's Pi directory. After a restart, the server reloads session history from those files. A turn that was active during the restart reports `Failed` with a restart error. The server keeps each loaded session in one process and unloads idle sessions after a configurable period.

`PiNodeEligibility` allows a Linux Node with a WireGuard address and an active `pi-server` Process whose desired state is `running`. This matches the recorded-state rule that T3 uses. Create fails explicitly when the requested provider is not authenticated on that Node.

### State and metrics

The driver maps Pi events to the five thread states:

| Pi evidence | State |
| --- | --- |
| Session with no completed turn | `Idle` |
| `agent_start` until `agent_settled` | `Working` |
| `agent_settled` and the last assistant message stopped normally | `Done` |
| `agent_settled` with a provider error, an abort, or a restart during the turn | `Failed`, with the error |

Pi threads never report `AskingForInput`, because Pi has no approvals and this slice registers no question tool. `respond` fails with an explicit unsupported error.

Tokens come from Pi's cumulative session usage. Pi has no checkpoints, so per-thread line counts are unavailable. The group line diff still comes from `git diff --numstat` in the shared checkout.

### Authentication

An operator signs in once per provider on each Node with Pi's own login, as the service user. Pi stores those credentials on the Node. Orbit does not create, read, copy, or refresh them. Implementer models use Pi's `provider/model` form, such as `openai-codex/<model>` or `xai/<model>`.

### Implementer tools

Pi threads use Pi's read, write, edit, and bash tools. The server adds one Orbit tool that calls the ADR 0115 task verification action through the Gateway API. It replaces the MCP call that T3 agents make. The tool accepts only the fields that action accepts.

The server loads the repository `AGENTS.md` and its agent skills. Documentation lookup uses command-line tools, not MCP: the Context7 CLI for general libraries, and Laravel Boost's documentation search through `php artisan boost:execute-tool` for version-matched Laravel docs. `boost:execute-tool` is a hidden internal Boost command. A Boost upgrade must pass a check that this command still works.

### Evaluation

Validate the integration with the same Codex model the T3 baseline uses. This keeps the model fixed, so the comparison measures the harness.

Run the same task briefs on T3 and on Pi. Use the ADR 0115 measurements: unnecessary reminders, missed blockers, review rounds, time to handoff, and assistance requests. Also record subscription usage per task. Pi becomes the default implementer driver only if it matches or improves on T3 for review rounds and assistance requests.

This decision does not choose other models for Pi. A model such as Grok runs through the same driver after the operator signs in to that provider on the Node.

## Rejected alternatives

- Start Pi in RPC mode over SSH from the Gateway: the process lifetime would depend on one SSH connection, and a reconnect would lose the live event stream.
- Read Pi session files over SSH for observation: this gives history without live events and adds polling load.
- Add an MCP adapter to Pi for Orbit and Boost tools: it adds a third-party dependency on each Node. A native Orbit tool covers the verification action. Documentation lookup works through commands that the agent runs in its shell.
- Build the coding agent with Laravel AI: Laravel AI supports API keys only and has no coding tools, edit logic, or context compaction. [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) keeps it for scheduler classification.
- Run the reviewer on Pi with a Claude subscription: Anthropic's terms do not permit it.

## Consequences

- Orbit maintains a TypeScript service and pins a Pi version.
- Each Node needs a manual subscription login per provider. A Node without the required login cannot host a Pi implementer.
- Pi threads lose T3 checkpoints, per-thread line counts, and approval requests.
- Transcripts live in Pi session files on the Node. As with T3, removing the Node loses them.
- Other subscription models depend on each provider's terms. The context table records the terms found for Codex, Grok, and Claude.
- Boost's documentation command is internal and can change without notice.

## Affects

- Components: apps/gateway, apps/docs, apps/e2e
- New project: `apps/pi-server`, the Pi server.
- Web consumer: none. `apps/web` already renders normalized thread data.
- ADRs: [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), ADR 0115 (proposed separately)
- Detail: [Tasks](/reference/tasks)
- Verify: driver contract tests shared with `T3Driver`, `PiDriverTest` for state mapping and idempotent send, Pi server tests for restart recovery and stream snapshots, `TaskTablesMigrationTest` for per-role drivers, and an Incus run of one task group with a Pi implementer and a T3 reviewer
