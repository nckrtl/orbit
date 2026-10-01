---
title: "ADR 0190: Run task agents on Pi only"
sidebarTitle: "0190 Run task agents on Pi"
description: "In progress. Implementers and reviewers run on the Pi driver only. Claude is unavailable for those agents. Annotations stay on the operator's T3 threads."
---

# ADR 0190: Run task agents on Pi only

Orbit runs task agents, the implementer and the reviewer, on the Pi driver only. Claude is unavailable for those agents. Annotations stay on the operator's T3 threads.

## Status

In progress.

Principle: this decision serves [one way, one name](/mission#principles) and [no exceptions and no legacy](/mission#principles). Task agents have one runtime, Pi. The T3 task-agent path is removed rather than kept beside it.

## Context

The [Tasks engine](/reference/tasks#drivers) records an implementer driver and a reviewer driver. Both default to `t3`, and `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select them. The reviewer default model is `claude-opus-5`.

The [Pi driver](/reference/pi-server#claude-is-unavailable-for-task-agents) refuses Claude. Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. Claude task agents therefore run on T3's `claudeAgent` provider. Any other T3 model runs on `codex`.

That split is a second task-agent runtime. T3 has its own restart errors, a `tasks:collect-t3-metrics` collector, and a `tasks:archive-threads` archive. A claim accepts a Node when each recorded driver has its Process: `t3-code` for T3, `pi-server` for Pi.

T3 also serves a different job. [Agent annotation](/reference/agent-annotation) sends each annotation to a T3 thread the operator already has open in an Instance checkout. That path reads the thread and starts one turn. It does not claim a task workspace, and it does not use the task-agent driver registry.

### The two accounts

A T3 task thread runs as the operator's Unix user and has that user's full access. A Pi task agent runs as a dedicated `orbit-agent` account. The operator approved that split on 2026-10-01. T3 Code stays installed as the operator's own tool.

## Decision

Implementers and reviewers run on the `pi` driver only.

- `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select the two roles. Both default to `pi`. A new managed task stores those values. Any other value returns `tasks.agent_driver_unavailable` and stores no task. The Gateway registers `pi` as the only task-agent driver.
- Deployment selects Pi for implementers with `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER=pi` on the Gateway, and for reviewers with `ORBIT_TASKS_REVIEWER_AGENT_DRIVER=pi`.
- A managed task whose recorded driver is not `pi` does not start or resume an agent turn.
- A Node fits a claim when it has an active `pi-server` Process with desired state `running`. A `t3-code` Process is not a claim requirement.
- Both roles default to `gpt-5.6-luna` at effort `high`. `ORBIT_TASKS_IMPLEMENTER_MODEL` and `ORBIT_TASKS_REVIEWER_MODEL` override the model. The Pi driver refuses a Claude model, including a name that starts with `claude` and a `provider/model` whose provider is `anthropic`, and the turn does not start. `pi-server login anthropic` stays refused.
- A definition write still accepts any non-empty model name. When the ProxyCli model list is available, the definition view reports a model no driver can run. A model is known when ProxyCli offers it through a provider Pi runs. A Claude model is not known, and neither is a listed model whose provider Pi does not run, such as `claude` or `google`.
- The scheduler runs `tasks:tick`, `problems:collect`, and `problems:file`. It does not run `tasks:collect-t3-metrics` or `tasks:archive-threads`. Pi reports token metrics on the session usage object. Pi sessions stay as files on the Node. Orbit keeps the thread row and its metrics.
- Restart recovery applies only to the Pi error `The Pi server restarted during the turn.`
- Stored T3 task-thread rows, their metrics, and the `t3_*` columns on `agent_threads` stay. The agents list still returns those rows. `GET /api/v1/task-groups/{group}/agents/{session}/stream` for a `t3` thread returns HTTP 409 `tasks.agent_transcript_unavailable` and does not open a stream.
- `ORBIT_T3_PORT`, `ORBIT_T3_TOKEN`, and a Node `t3` object stay for annotations. A Node with a `t3` object uses `t3.token`, and `t3.url` when set. It never falls back to `ORBIT_T3_TOKEN`, and a missing token fails closed. Without that object, the base URL is `http://{wireguard_ip}:{ORBIT_T3_PORT}` and the port default is `3773`.

This replaces the task-agent driver choice in [ADR 0177](/decisions/0177-remove-the-app-compatibility-surface). Annotation delivery is unchanged.

## Rejected alternatives

- Keep T3 as a selectable task-agent driver: rejected because two runtimes keep two restart rules, two metric paths, and two archive paths, and task agents do not run on T3.
- Run Claude task agents through CLIProxyAPI on Pi: rejected because Anthropic permits those subscription credentials only in its own applications.
- Keep the reviewer default `claude-opus-5`: rejected because that model cannot start on Pi, so every new review would fail.
- Finish a T3 task thread that is still open: rejected because that keeps the T3 task-agent path. A managed task that records `t3` does not start or resume an agent turn. Its stored row stays.
- Remove `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER`: rejected because deployment selects Pi with `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER=pi`, and each role keeps its own setting. Both default to `pi`.
- Drop stored T3 task-thread rows or the `t3_*` columns: rejected because those rows are the record of work that already ran. A transcript request returns `tasks.agent_transcript_unavailable` instead of calling T3.

## Consequences

- Task agents need a `pi-server` Process. They do not need `t3-code`.
- A managed task that records `t3` does not start or resume. The operator cancels it or replaces it.
- Claude cannot implement or review a task. The definition view reports that model.
- Annotations still reach the operator's T3 threads with the same connection settings. T3 Code stays installed as the operator's own tool.
- Pi task agents run as the dedicated `orbit-agent` account, not as the operator's Unix user.
- The T3 task-agent driver, `tasks:collect-t3-metrics`, and `tasks:archive-threads` are removed. Stored T3 task-thread rows, their metrics, and the `t3_*` columns stay.

## Affects

- Components: apps/gateway, apps/web
- ADRs: replaces the task-agent driver choice in [ADR 0177](/decisions/0177-remove-the-app-compatibility-surface), and the Claude model-finding sentence in [ADR 0182](/decisions/0182-start-tasks-from-project-task-definitions)
- Detail: [Tasks: Drivers](/reference/tasks#drivers) and [Pi server: Claude is unavailable for task agents](/reference/pi-server#claude-is-unavailable-for-task-agents)
- Verify: `composer docs-lint`
