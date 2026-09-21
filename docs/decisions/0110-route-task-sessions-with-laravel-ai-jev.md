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

Commander `/fast/apps/commander/main` only has the environment keys `TYPESAFE_API_KEY` and `TOOLBAR_TYPESAFE_ENABLED`. It has no `laravel/ai` package, no `config/ai.php`, and no application code that reads those keys. Gateway must introduce TypeSafe Jev Classification itself. Copying a Commander AI stack would invent a source that does not exist.

T3 0.0.42 already taught the wire: `thread.turn.start` `message` is a struct, not a flat string; `turn.start` includes `modelSelection`; Codex option id is `reasoningEffort`; the driver is fixed at `thread.create`. When the HTTP snapshot omits a pending request id, subscribeThread activities still expose it.

What matters is a fail-closed Choice over observed task-thread facts, mechanical execution of that Choice, and a Coder notify path that stays silent unless the scheduler escalates or the group is CLEAN-ready.

## Decision

- A scheduler tick observes only `running` and `reviewing` Task groups. It builds one structured observation per stored reviewer or implementer thread id. A thread that is not a reviewer or implementer task thread stays out of the observation. Facts include session status, pending approval and user-input request ids, last assistant and user text excerpts, whether the workspace has new commits since thread start, and `pr_url` / CI summary when Gateway already has them.
- Observation reads the T3 HTTP snapshot and merge subscribeThread activity projections when those projections are present. Pending user-input ids come from activities when the snapshot omits them.
- Gateway depends on `laravel/ai` 1.x and configures its official TypeSafe classification provider in `config/ai.php`. The key is `TYPESAFE_API_KEY`. Missing key fails closed with a clear error and never invents a next action. Pest fakes the package Classification gateway, so CI never calls TypeSafe. The package client posts to TypeSafe.
- One Choice over the observation selects `next_action` among `drain_approval`, `drain_user_input`, `continue_implementer`, `relay_review_to_implementer`, `mark_subtask_done`, `settle_group`, `escalate_coder`, and `noop`. Confidence below the configured threshold becomes `escalate_coder`.
- Execution is mechanical. Approvals dispatch `thread.approval.respond` with `decision=acceptForSession`. User-input dispatch `thread.user-input.respond` with answers that continue the current brief and refuse scope expansion. Continue and relay dispatch `thread.turn.start` with the T3 0.0.42 message struct `{messageId, role:user, text, attachments:[]}` plus `modelSelection`. A refused drain, continue, or relay becomes `escalate_coder` instead of succeeding silently. `mark_subtask_done` reuses acceptReview, settleImplementer, and next-subtask spawn. `settle_group` reuses the existing settle path. `noop` dispatches nothing. After a successful `thread.create`, a refused opening `thread.turn.start` is retried once, logged at error, and leaves the thread id unset.
- New implementer threads use `instanceId=codex`, `model=gpt-5.6-luna`, and option `reasoningEffort=low`. New reviewer threads use `instanceId=claudeAgent`, `model=claude-opus-5`, and option `effort=high`. `thread.meta.update` cannot switch those drivers.
- Coder notify reuses the HMAC CoderSettleNotifier pattern. The scheduler posts only for `escalate_coder` or a CLEAN-ready settle. Ordinary drains, continues, relays, and noops stay silent.

## Rejected alternatives

- Keep a human or Coder in every idle drain: rejected because LIVE groups sat until someone noticed them.
- Copy Commander's Laravel AI stack: rejected because Commander has no `laravel/ai` package, no `config/ai.php`, and no application code that reads `TYPESAFE_API_KEY`.
- Generate the next action with an unconstrained LLM: rejected because Nick asked for a typed Jev Choice that fails closed when confidence is below the gate.
- Route through Commander or Tom-on-Mini: rejected because Commander is sunset as the task runner and Tom is out of scope.
- Invent answers when `TYPESAFE_API_KEY` is missing: rejected because a missing key must fail closed.

## Consequences

- An enabled Gateway with `TYPESAFE_API_KEY` ticks running and reviewing groups, observes their T3 task threads, asks Jev for one next action, and executes it.
- A missing TypeSafe key refuses classification with a clear error. The tick does not invent `drain_approval`, `continue_implementer`, or settle.
- Low-confidence Choices escalate to Coder. When a group settles and is ready for CLEAN, the existing settle webhook still posts if `notify_coder` is true.
- Fleet key mint for TypeSafe stays an Ops step after CLEAN.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks)
- Verify: `apps/gateway/tests/Feature/Domain/Tasks/TaskSessionObserverTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/LaravelAiTaskSessionClassifierTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSessionRouterTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSchedulerTickTest.php`, `apps/gateway/tests/Feature/Infrastructure/Tasks/HttpCoderSettleNotifierTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/T3AgentSpawnerTest.php`
