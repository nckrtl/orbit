---
name: creating-tasks
description: Use when splitting an agreed Orbit feature into the subtasks of an Orbit task group, before the group moves to Todo.
---

# Creating Tasks

Split an agreed feature into subtasks that implementers finish one at a time. Each subtask has one concise goal, and an implementer and a reviewer can each handle it in one fresh context. Adapted from Matt Pocock's [to-tickets](https://github.com/mattpocock/skills/tree/main/skills/engineering/to-tickets) skill.

Start after [grill-with-docs](../grill-with-docs/SKILL.md): the behavior is agreed and the ADRs and documentation are written on the group's branch. They are the contract that every subtask implements.

## 1. Gather the contract

Read the group's brief, its ADRs, and the documentation pages the branch changes. Read the code the feature touches. Use the terms in `docs/concepts.md` and the ADRs in titles and briefs.

Look for prefactoring that makes the feature easier to build. Put it first, as its own subtask.

## 2. Draft the slices

Prefer a narrow vertical slice that a reviewer can verify from start to finish over a horizontal slice of one layer. When a behavior spans several projects, such as the Gateway, a Rust service, and the web app, the ADRs and documentation fix the contract between them. A slice per project is then fine, provided its tests prove its side of the contract.

Each subtask must meet all of these:

- **One goal.** It covers one component or one behavior. A title that needs "and" twice is too large.
- **Few deliverables.** At most five. Split a subtask that needs more.
- **Verifiable alone.** Its own tests prove its goal, and the branch passes its checks when the subtask is done.
- **Fits one context.** An implementer can read what it needs and finish in one session.
- **Separate concerns.** CI, release, and deployment work stay apart from product code.
- **Screenshots for UI.** A subtask that changes the web UI includes one screenshot `review` deliverable. [Verifying web UI](../verifying-web-ui/SKILL.md) is what the reviewer uses to judge the phone.

Orbit runs subtasks in position order on one shared branch. Order them by dependency. A later subtask may build on an earlier one, but it never finishes an earlier one's work.

A wide refactor is the exception to vertical slicing: one mechanical change that breaks many call sites at once. Sequence it as expand, migrate, contract. First add the new form beside the old, then move callers over in batches, then remove the old form once no caller remains. Each step is its own subtask and leaves the checks passing.

For example, an agent with two watchers and a protocol client is three subtasks, not one: the protocol client with its fake-server tests, then the first watcher, then the second.

## 3. Review the breakdown with the operator

Present the subtasks as a numbered list. For each one, show:

- **Title**: a short name.
- **Builds on**: the earlier subtasks it depends on, or none.
- **Goal**: the behavior it makes work, from the user's point of view.
- **Deliverables**: what the reviewer checks.

Ask whether the granularity is right, whether each dependency is real, and whether any subtask should be merged or split. Iterate until the operator approves.

## 4. Create the subtasks

Create them in dependency order with `tasks-subtask-create`, and fix any drift with `tasks-subtask-update` or `tasks-subtask-destroy`. Write each brief like this:

```markdown
**Goal:** the behavior this subtask makes work.

**Contract:** the ADR sections and documentation pages it implements, with anchors.

**Builds on:** the earlier subtasks it depends on, or "None".

**Deliverables:**
- One observable result per line, such as a behavior, an endpoint, a test that proves it, or a check that passes.
- For a web UI change, a screenshot `review` deliverable. The reviewer opens the phone and desktop PNGs and judges the phone.

**Acceptance:** the checks to run and what they must show.
```

Every subtask that changes the web UI gets that screenshot `review` deliverable, even when the UI work is only part of the subtask. Its type is `review`, so Orbit checks it when the reviewer approves, as the [tasks reference](../../../docs/reference/tasks.md#deliverables) describes. The description names the phone and desktop PNGs from `bin/web-verify` and the phone judgment in [verifying web UI](../verifying-web-ui/SKILL.md): the log or content starts near the top, controls are reachable, filters are not an awkward stack, and long lists and filters use native patterns such as infinite scroll and sheets. Reading the diff is not that judgment. It counts toward the limit of five.

Name file paths only where the contract fixes them, such as an install path or a documentation page. Leave other paths and code to the implementer, because they go stale.

Move the group to Todo only when the operator approves the plan.
