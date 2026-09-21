---
title: "ADR 0112: Isolate agent threads behind drivers"
sidebarTitle: "0112 Agent threads and drivers"
description: "Proposed. Orbit owns persistent AgentThread records and normalized activity states. AgentDriver implementations own external agent protocols, starting with T3."
---

# ADR 0112: Isolate agent threads behind drivers

Orbit represents a persistent agent conversation as an `AgentThread`. An `AgentDriver` connects that thread to an external agent runtime. T3 is the first driver. The scheduler, API, and web app use Orbit contracts for agent activity and conversation data.

## Status

Proposed. Implemented on this feature branch; pending review.

This amends the T3 integration boundary in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) and the observation and execution boundary in [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev). Task scheduling, review policy, and Jev classification remain Gateway responsibilities.

## Context

T3 details reach into task observation, action execution, scheduler exceptions, Node eligibility, metrics, and browser event parsing. The existing `AgentSpawner` abstraction covers only part of that integration. `TaskAgentSession` records conversation links, while Task and TaskGroup also store T3 thread IDs as scheduler pointers.

Orbit needs to support other agent runtimes without teaching each consumer another protocol. A Task describes work to complete. A thread describes a conversation that can receive several requests and outlive an individual subtask. The long-lived reviewer makes that distinction necessary.

Laravel AI supplies model providers and a Laravel-managed agent loop, including tools and conversations. Its MCP tool integration does not supply an ACP client or the lifecycle of an external coding agent. Orbit already uses Laravel AI for TypeSafe Jev classification; that responsibility fits its existing abstractions.

## Decision

Orbit owns conversation identity and activity semantics. Drivers own communication with external agent runtimes.

### Thread identity

`AgentThread` replaces `TaskAgentSession` as the persistent conversation record. It has an Orbit ID, a driver key, an opaque external conversation ID, and the context needed to find its runtime and workspace. Task links, role, model, and effort remain available. Driver choice is separate from model choice: a T3 thread can use different model providers.

One reviewer thread spans the TaskGroup. Each subtask gets a separate implementer thread. A new conversation creates a new record and preserves earlier attempts. Scheduler references point to Orbit AgentThread records instead of copying external thread IDs onto Task and TaskGroup. The driver stays fixed for a conversation; changing drivers requires a new thread.

Existing conversation links migrate to the T3 driver with their external IDs and ownership intact. External IDs are scoped to the driver and runtime endpoint; they are not globally unique across all runtimes. Removing a workspace preserves thread links. Transcripts remain owned by the external runtime, so retaining a link does not guarantee that its transcript remains available.

The migration preserves existing record IDs and converts the current reviewer and implementer pointers to foreign keys. It is forward-only: an older Gateway cannot read the new schema. Roll back with a database backup or a reviewed forward migration, not `migrate:rollback`.

### Activity state

`AgentThreadState` expresses observed activity and the latest turn outcome:

| State | Meaning |
| --- | --- |
| `Working` | The agent is executing a turn |
| `AskingForInput` | An unresolved question or approval requires a response |
| `Idle` | The thread is ready without an active turn, pending input, or reported turn outcome, such as before its first turn |
| `Done` | The latest turn completed successfully and no new turn has started |
| `Failed` | The runtime reports that the latest turn failed |

Pending input for the active turn takes precedence over working or idle signals. Input requests retain their kind, identity, and response requirements so questions and approvals remain distinguishable. Resolved requests and requests from earlier turns do not override the latest turn outcome.

`Done` and `Failed` remain visible until a new turn starts. A follow-up or retry can return the same persistent thread to `Working`. Drivers preserve reported completion and failure instead of mapping both to `Idle`. A driver must not infer `Done` from inactivity alone.

Thread state does not decide Task status. `Done` does not complete a Task or count as reviewer sign-off, and `Failed` does not by itself fail the TaskGroup. The scheduler applies the workflow policy to the observed outcome. Failure details accompany `Failed` so the scheduler and operator can understand the error.

Observation freshness and connection health are separate from these five states. A failed read preserves the last known state and marks it stale or unavailable; it does not establish `Failed`. A thread with no successful observation has no known state. The scheduler must not infer idle, completion, or execution failure from a missing observation.

### Driver boundary

`AgentDriver` covers conversation creation, follow-up messages, question and approval responses, interruption, state observation, transcript events, and available usage metrics. Driver capability and placement checks tell the Gateway whether the selected runtime can run on a candidate Node. Unsupported operations and unavailable metrics are explicit; an absent metric is not zero.

`T3Driver` owns T3 commands, snapshots, event schemas, transport, credentials, external IDs, model-option formatting, and protocol errors. Its implementation can use smaller transport and mapping classes. Driver registration and configuration select it without spreading T3 branches through domain services.

Drivers return normalized Orbit observations, input requests, conversation events, metrics, and errors. The scheduler and Jev classifier consume those observations. The API and web app consume the same activity semantics and normalized conversation data. Browser code does not parse T3 snapshots or infer agent activity from the viewer connection.

Orbit chooses the next action and decides whether an input request may be answered. The driver translates and executes that action. Existing Gateway access checks, server-side credentials, bounded streaming, and reconnect behavior remain requirements across drivers.

T3 streaming starts each connection with a complete snapshot, then projects ordered runtime events into normalized snapshots. The browser replaces its transcript from each snapshot. Cursors are opaque outside the driver; a T3 reconnect obtains a fresh baseline instead of replaying deltas without their original state.

### Laravel AI and additional drivers

Laravel AI remains the implementation for scheduler classification through TypeSafe Jev. `AgentThread` and `AgentDriver` are Orbit contracts; they do not implement Laravel AI's model-provider or conversational contracts to represent an external runtime.

T3 is the first implementation. OpenCode and Codex App Server can be added as separate integrations. An ACP driver can share protocol handling among compatible agents. This decision does not assume that every runtime speaks ACP or implement those additional drivers.

## Rejected alternatives

- Name the conversation `AgentTask`: this overlaps with Orbit's existing Task and obscures a reviewer conversation that spans subtasks.
- Abstract only spawning: protocol details would remain in observation, execution, metrics, placement, and the UI.
- Wrap external runtimes as Laravel AI model providers: model inference contracts do not express the external conversation lifecycle Orbit needs.
- Collapse completion and execution failure into idle: the scheduler and operator need the reported turn outcome.
- Treat a disconnected agent as idle or failed: missing evidence does not establish an execution outcome.

## Consequences

- Adding a driver requires protocol translation and registration while existing consumers retain their Orbit contracts.
- The refactor includes persistence, scheduler references, Node eligibility, observations, actions, metrics, API data, and browser events.
- Driver mappings must preserve input request identity, transcript ordering, reconnect behavior, and metric meaning. Protocol differences cannot be hidden by silently dropping unsupported behavior.
- `ORBIT_TASKS_AGENT_DRIVER` selects a registered driver for new groups and defaults to `t3`. Each group retains its selection. Additional drivers need their own capability and runtime verification.

## Affects

- Components: apps/gateway, apps/docs
- Web consumer: `apps/web` adopts normalized observations and events.
- ADRs: [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev)
- Detail: [Tasks](/reference/tasks)
- Verify: `AgentDriverTest`, `T3ProjectionTest`, `AgentThreadsTest`, and `TaskTablesMigrationTest` cover the generic driver workflow, state mapping, stale observations, migration, and normalized streaming. Existing scheduler and T3 transport tests cover the retained workflow. Web unit and browser tests cover the viewer, all five states, snapshot replacement, and subscription cleanup. Live T3 and Incus acceptance remain release checks.
