---
title: "ADR 0110: Route task sessions with Laravel AI Jev"
sidebarTitle: "0110 Route task sessions with Laravel AI Jev"
description: "Proposed. Gateway TaskScheduler ticks observe idle, pending-input, and finished T3 task threads, ask TypeSafe Jev for one next_action Choice, execute that action mechanically, and notify Coder only on escalate or CLEAN-ready settle."
---

# ADR 0110: Route task sessions with Laravel AI Jev

The Gateway `tasks` extension routes idle, pending-input, and finished implementer and reviewer T3 sessions without Coder or Nick in the loop. Code gathers structured facts. Laravel AI Classification with the TypeSafe Jev provider picks one `next_action`. The scheduler executes that action. Coder is notified only on `escalate_coder` or a CLEAN-ready settle.

## Status

Proposed.

This extends [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension). Persistence, ceilings, MCP create, shared Instance provisioning, T3 spawn, pull-request open, and complete stay. Commander is not the task runner.

## Context

LIVE Orbit task groups sat idle or waiting for T3 input until a human or Coder drained or kicked the session. Nick wants Gateway plus Laravel AI / Jev for deterministic routing. Tom-on-Mini is out of scope.

Commander `/fast/apps/commander/main` only has the environment keys `TYPESAFE_API_KEY` and `TOOLBAR_TYPESAFE_ENABLED`. It has no `laravel/ai` package, no `config/ai.php`, and no application code that reads those keys. Gateway must introduce Laravel AI Classification and the TypeSafe Jev provider itself. Copying a Commander AI stack would invent a source that does not exist.

T3 0.0.42 already taught the wire: `thread.turn.start` `message` is a struct, not a flat string; `turn.start` includes `modelSelection`; Codex option id is `reasoningEffort`; the driver is fixed at `thread.create`; pending input is often visible on subscribeThread activities, not only on the HTTP snapshot.

What matters is a fail-closed Choice over observed task-thread facts, mechanical execution of that Choice, and a Coder notify path that stays silent unless the scheduler escalates or the group is CLEAN-ready.

## Decision

- A scheduler tick observes only `running` and `reviewing` Task groups. It builds one structured observation per stored reviewer or implementer thread id. Non-task threads never appear. Facts include session status, pending approval and user-input request ids, last assistant and user text excerpts, whether the workspace has new commits since thread start, and `pr_url` / CI summary when Gateway already has them.
- Observation reads the T3 HTTP snapshot and merge subscribeThread activity projections when those projections are present. Pending user-input ids come from activities when the snapshot omits them.
- Gateway depends on `laravel/ai` and introduces `config/ai.php` with TypeSafe as the classification provider. The key is `TYPESAFE_API_KEY`. Missing key fails closed with a clear error and never invents a next action. Pest fakes Classification so CI never calls TypeSafe. laravel/ai 1.x Classification cannot be installed while laravel/boost pins `laravel/mcp` below 1.0. Gateway therefore owns the Classification + Choice + fake client that matches that 1.x shape and posts to TypeSafe `POST /v1/systemone`.
- One Choice over the observation selects `next_action` among `drain_approval`, `drain_user_input`, `continue_implementer`, `relay_review_to_implementer`, `mark_subtask_done`, `settle_group`, `escalate_coder`, and `noop`. Confidence below the configured threshold becomes `escalate_coder`.
- Execution is mechanical. Approvals dispatch `thread.approval.respond` with `decision=acceptForSession`. User-input dispatch `thread.user-input.respond` with answers that continue the current brief and refuse scope expansion. Continue and relay dispatch `thread.turn.start` with the T3 0.0.42 message struct `{messageId, role:user, text, attachments:[]}` plus `modelSelection`. `mark_subtask_done` reuses acceptReview, settleImplementer, and next-subtask spawn. `settle_group` reuses the existing settle path. `noop` dispatches nothing.
- New implementer threads use `instanceId=codex`, `model=gpt-5.6-luna`, and option `reasoningEffort=low`. New reviewer threads use `instanceId=claudeAgent`, `model=claude-opus-5`, and option `effort=high`. `thread.meta.update` cannot switch those drivers.
- Coder notify reuses the HMAC CoderSettleNotifier pattern. The scheduler posts only for `escalate_coder` or a CLEAN-ready settle. Ordinary drains, continues, relays, and noops stay silent.

## Rejected alternatives

- Keep a human or Coder in every idle drain: rejected because LIVE groups sat until someone noticed them.
- Copy Commander's Laravel AI stack: rejected because Commander has no `laravel/ai` package, no `config/ai.php`, and no application code that reads `TYPESAFE_API_KEY`.
- Generate the next action with an unconstrained LLM: rejected because Jev Choice plus a confidence gate is the typed, fail-closed contract Nick asked for.
- Route through Commander or Tom-on-Mini: rejected because Commander is sunset as the task runner and Tom is out of scope.
- Invent answers when `TYPESAFE_API_KEY` is missing: rejected because a missing key must fail closed.

## Consequences

- An enabled Gateway with `TYPESAFE_API_KEY` ticks running and reviewing groups, observes their T3 task threads, asks Jev for one next action, and executes it.
- A missing TypeSafe key refuses classification with a clear error. The tick does not invent `drain_approval`, `continue_implementer`, or settle.
- Low-confidence Choices escalate to Coder. CLEAN-ready settle still posts the existing settle webhook when `notify_coder` is true.
- Fleet key mint for TypeSafe stays an Ops step after CLEAN.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks)
- Verify: `apps/gateway/tests/Feature/Domain/Tasks/TaskSessionObserverTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/LaravelAiTaskSessionClassifierTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSessionRouterTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSchedulerTickTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/HttpCoderSettleNotifierTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/T3AgentSpawnerTest.php`
