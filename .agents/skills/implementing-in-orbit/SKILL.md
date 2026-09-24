---
name: implementing-in-orbit
description: Use when the operator asks to implement a feature in Orbit instead of in this session.
---

# Implementing in Orbit

Implement work inline in this session by default. Use this skill only when the operator asks to implement the work in Orbit.

1. Find this repository's Project with the Orbit MCP tool `project-list`. Match its repository to this checkout's `origin`. Ask the operator when no Project or more than one Project matches.
2. Call `tasks-create` with the Project's `app_id`, a short title, a brief, and `plan: true`. Write the brief from the conversation: the problem, the intended behavior, decisions already made, open questions, and every explicit item the work must deliver, such as named documents, tests, gates, and commands.
3. Tell the operator the group ID and the planner thread title, `Orbit task #{group id} · Planner: {title}`. The thread appears in their T3 client.
4. Stop working on the feature in this session. The planner shapes it in T3, gives each subtask typed deliverables from those items, and moves the group to Todo when the operator agrees the plan is ready. Orbit refuses Todo while a subtask has no deliverables and checks them at each handoff. The [tasks reference](../../../docs/reference/tasks.md#deliverables) describes the deliverable types.

When `tasks-create` fails, report the error code and message. The [tasks reference](../../../docs/reference/tasks.md#plan-a-group-with-a-planner) explains each planning error.
