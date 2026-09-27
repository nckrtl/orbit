---
title: "ADR 0169: Start each subtask review in a fresh thread"
sidebarTitle: "0169 Fresh subtask reviews"
description: "Proposed. Each subtask review starts a fresh reviewer thread with a short packet. The planner stays the planner. The planner and the reviewer use the MCP search endpoint."
---

# ADR 0169: Start each subtask review in a fresh thread

Each subtask review starts a fresh reviewer thread on the group's reviewer driver and model. The thread opens with a packet of at most about 4 thousand tokens. A `changes_requested` re-review of that subtask continues the same thread. The reviewer does not run a check that Orbit already passed. The planner thread stays the planner. The planner and the reviewer use the Gateway MCP search endpoint.

## Status

Proposed.

This amends the long-lived reviewer in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), the single reviewer thread in [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) and [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt), and the planner-as-reviewer rule in [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner). It amends which thread is acting during review in [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question), the fixup reviewer in [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask), and the review-request tail in [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit) when the packet cap cuts it. It uses the search endpoint from [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools). The handoff result comes from [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off) and [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff).

## Context

One reviewer thread serves every subtask in the group. [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) starts that thread at the first handoff. [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) stores the planner in the same thread and sends every review to it. The thread title is `Orbit task #{group id} · Planner: {title}`, its role is `reviewer`, and its `task_id` is null. A group without a planner starts one reviewer at the first handoff and reuses it. Each new review therefore inherits the planning conversation and every earlier subtask.

[ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) rejected a fresh reviewer because the planner holds the decisions, and left a new decision for when long transcripts weaken reviews. Groups 109 through 125, measured on 2026-09-26, are that evidence. The same groups are the token baseline in [ADR 0165](/decisions/0165-record-per-thread-token-metrics).

| Measure | Value |
| --- | --- |
| Reviewer tokens | 128 million |
| Inherited across subtasks | 65 percent of those reviewer tokens |
| Largest review-turn start | 219 thousand tokens of context |
| Commands per review | about 17, mostly checks Orbit had already passed |
| Target reviewer tokens | about 50 million for the same work |
| Target review-turn start | under about 25 thousand tokens of context |

Sixty-five percent of 128 million is about 83 million tokens of context carried from earlier subtasks. The other 35 percent is about 45 million. The target of about 50 million sits just above that remainder. A fresh thread with a packet of about 4 thousand tokens is what makes a start under about 25 thousand tokens reachable. The driver prompt and the MCP tools sit on top of the packet.

The handoff already runs the Project task check and the deliverable tests and commands. `composer check` is that task check when the Project is configured with it. The review request does not need those commands run again.

The planner's untracked `.mcp.json` points at `{gateway origin}/mcp`. That endpoint lists every API tool. [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools) also serves `/mcp/search`, which lists `search_tools` and `execute_tools`, for a client that loads every listed tool into its context. The planner and the reviewer are such clients. A group without a planner writes no `.mcp.json`, so the reviewer has the file only when the repository or the Node provides one.

## Decision

Each subtask review uses a fresh reviewer thread. The thread uses the group's reviewer driver, model, and effort. It does not use the planner thread or an earlier subtask's reviewer. A `changes_requested` re-review of the same subtask continues that subtask's reviewer thread. A `blocked` resolution continues that thread when it exists. When it does not, the resolution is not sent to the planner or an earlier subtask's reviewer. The Gateway clears assistance without marking the review requested, and the next tick starts the fresh reviewer with that resolution in the opening packet. The next subtask starts a fresh thread.

### Fresh reviewer thread

The thread title is `Orbit task #{group id} · Review: {subtask title}`. Its role is `reviewer`. Its `task_id` is that subtask. `reviewer_agent_thread_id` on the group points at this thread once the review has started.

On a planning group, before the first review, `reviewer_agent_thread_id` points at the planner thread. That is how the group remembers the planner. The planner keeps `task_id` null and the title `Orbit task #{group id} · Planner: {title}`. The first review replaces `reviewer_agent_thread_id` with the new reviewer thread. The planner row stays in `agent_threads`. The scheduler sends a review only to the thread it started for that subtask, or continues that thread. It never sends a review to the planner. A resolution during review follows the same rule. When the subtask has no started reviewer thread yet, including a reserved row that has not started, the resolution waits in that fresh thread's opening packet.

The opening turn of a fresh thread is the review packet. The thread id goes into the generated instructions before the packet is capped. Diff text and brief text are not rewritten to add it. Orbit reserves the thread row before that prompt, so the prompt and `.git/orbit/turn.json` can name the Orbit thread id. Its external id starts with `pending:` until the conversation starts. A spawn that does not start the turn deletes the row and stores no thread id. Any other failure while creating the conversation deletes that row too, so the next attempt does not reuse it. The reserved row is not listed, measured, or broadcast, and Orbit does not read it from the driver. The next tick tries again. When a continued thread cannot take a turn, Orbit starts a fresh thread and sends a full packet.

The acting thread while a subtask is `reviewing` is that subtask's reviewer. The planner does not defer the subtask. An operator talking to an earlier reviewer does not defer the new review. [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question) still pauses only for the acting thread or a real question. The Gateway sends no turn to a working thread. On a continued reviewer, the task still moves to `reviewing` and the request waits until that thread stops.

The run receipt names the thread that wrote it. Before the turn starts, `.git/orbit/turn.json` records the acting thread's Orbit id, and the instructions pass that id as `--thread`. The script stores the id on `.git/orbit/run.json`. The tick applies the receipt only when the id is the acting thread. A receipt from an earlier reviewer, or a receipt with no thread id, does not approve, request changes, or block the current subtask. The implementer turn is bound the same way.

The acting thread is the subtask's reviewer or its implementer, not only the id in the turn file. A turn file that still names the previous reviewer does not apply that receipt, and it does not hide a receipt from the acting thread. A legacy turn file with no thread id is rewritten for the acting thread, and Orbit sends that thread the bound run command instead of applying the unidentified receipt. If that turn file cannot be written for a replacement reviewer, the replacement does not start.

A fixup is a subtask. Its review follows this rule: a fresh reviewer thread, not the planner and not an earlier subtask's reviewer.

The group `tokens` total sums each subtask's `tokens` and the reported tokens of every started thread whose role is reviewer. That includes the planner and each subtask reviewer. A reserved row is not included. The tick keeps reading the planner thread while the group is in Backlog, Todo, running, or reviewing, so that count stays current. The read asks Jev nothing.

### Review packet

The packet is at most 16,000 characters. That is about 4 thousand tokens, counting four characters as one token. No part is exempt. A part under its cap leaves the spare characters for the diff body. The diff body also stops at 16,384 bytes. [Tasks](/reference/tasks#review-a-subtask) records the same caps and the same retrieval commands. The parts and their caps are:

- Group brief: at most 2,000 characters, cut from the end. `tasks-show` returns the full brief.
- Subtask brief: at most 2,000 characters, cut from the end. `tasks-show` returns the full brief.
- Deliverables: at most 2,000 characters. Each line is at most 240 characters: the id, the type, and the description cut to 160 characters. Lines that still do not fit are dropped from the end, and one line names how many were omitted. `tasks-show` returns every field.
- Earlier approved subtasks: at most 1,500 characters. One line each, in position order, at most 200 characters: the title and the approval summary. The oldest lines drop first. One line names how many were omitted. `tasks-comment-list` returns each approval summary.
- Diff stat: at most 1,500 characters. The first line is the file count and the insertion and deletion counts, including untracked files. Paths follow until the cap. One line names how many paths were omitted. The stat command prints the rest.
- Handoff result: at most 2,000 characters. The status, then one line per command the check ran. The command text on that line is cut to 160 characters, and the line includes the directory and the exit code. The commands are the Project task check, each deliverable `test` and `command`, and a base run when `fails_on_base` is set. When that base run fails, the line includes the failure kind. A message tail is included only while it fits in this cap. Lines that still do not fit are dropped from the end. `.git/orbit/check.log` holds the command text and any cut tail. `.git/orbit/check.json` stores the exit codes. That is the limit this record adds to the review-request lines in [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit).
- Diff body: the characters left in the packet, and at most 16,384 bytes, cut from the end. The diff command prints the rest, including the content of untracked files.
- Retrieval commands: reserved first, at most 1,000 characters, and never cut. The packet puts the subtask's start commit in place of `START`.

The diff command prints tracked changes and the content of each untracked file. It does not update the index. `git diff START` prints no untracked file, so the loop prints that content. `git diff --no-index` exits 1 when a file differs from empty, and `|| true` keeps the loop going:

```bash
git diff START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index -- /dev/null "$path" || true; done
```

The stat command prints the tracked stat and a stat for each untracked file. It does not update the index either:

```bash
git diff --stat START; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index --stat -- /dev/null "$path" || true; done
```

`git status` is not a retrieval command. It prints paths, not the content that was cut.

Orbit does not send a review when it cannot read the diff. That attempt is a communication failure, and the next tick tries again. Any other failure while requesting that review is also a communication failure for that subtask. The tick still reviews the other groups. It does not describe that failure as zero files changed. When the captured stat output is cut, the summary counts stay complete, the path list is omitted, and the packet says the stat command prints the rest. The retained tail of a cut capture is not shown as the whole diff. The diff and the stat replace bytes that are not valid UTF-8 before the caps are applied, both when the diff fits and when it is cut.

Orbit records the subtask's start commit when the subtask starts, before the implementer's first turn. When that read fails, Orbit leaves the commit empty. The next tick tries the read again until that turn starts. Once the turn has started, Orbit leaves the start commit empty. The review diff and the fails_on_base base run then use the same fallback: the previous subtask's approved commit, or the workspace starting commit for the first subtask.

The opening packet names the feature contract: the ADRs and documentation this branch changes against the Project default branch. A continued turn does not repeat that sentence.

A continued turn does not repeat the group brief, the deliverables, the earlier approved lines, or a resolution carried on the opening packet. The thread already has them. Those caps are spare for the diff body. The turn carries the new diff stat, the capped diff, both retrieval commands, the new handoff result, and the check rule below. When that thread cannot take a turn, Orbit starts a fresh thread and sends a full packet.

### Checks the reviewer does not repeat

The reviewer does not re-run the Project task check. That is `composer check` when the Project task check is `composer check`. It does not re-run the deliverable `test` files or `command` deliverables the handoff already passed. It runs another command only when it needs evidence the handoff result does not give, and the `approved` or `changes_requested` summary says why.

The reviewer prompt states that rule on the opening packet and on a continued turn. Orbit does not parse the summary to enforce it. The prompt still says the turn is read-only, that the implementer has no web access, and that the reviewer confirms framework and library usage against documentation for the Project's versions. Those checks are evidence the handoff result does not give.

### MCP search endpoint

The untracked `.mcp.json` Orbit writes for a task workspace points at `{gateway origin}/mcp/search`. That endpoint lists `search_tools` and `execute_tools`. The planner and the reviewer connect to it. They do not connect to `/mcp`, which lists the full tool catalogue.

Orbit writes the file before the planner starts, and before a reviewer starts when the workspace has no `.mcp.json`. It adds the file to the checkout's Git exclude list. A repository that tracks its own `.mcp.json` keeps that file unchanged. The planner and the reviewer then reach `/mcp/search` only when that file or the Node provides it. The full catalogue stays available at `/mcp` for every client.

## Rejected alternatives

- Keep one reviewer thread and shorten each new request: the inherited context is already in the thread. Review turns started at up to 219 thousand tokens, and 65 percent of the 128 million reviewer tokens was that inherited context.
- Put the full diff and the planning transcript in the fresh packet: that restores the context the cap removes.
- Keep the planner as the reviewer: [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) left a fresh reviewer for when long transcripts weaken reviews. Groups 109 through 125 are that evidence. The planner thread stays for planning, and reviews follow the fresh-thread rule.
- Re-run `composer check` and the deliverable tests and commands on every review: reviewers ran about 17 commands per review, mostly repeating checks Orbit had passed. The handoff result already carries the status, the commands, and the exit codes.
- Point the planner and the reviewer at `/mcp`: that endpoint lists every tool, and these clients load every listed tool into context. `/mcp/search` lists `search_tools` and `execute_tools`.
- Start a fresh thread after `changes_requested`: the reviewer loses the findings it already wrote. Continuing that subtask's thread keeps them.

## Consequences

- A review turn starts from the packet. The target is a start under about 25 thousand tokens of context, and about 50 million reviewer tokens for work the size of groups 109 through 125, down from 128 million.
- The reviewer does not see earlier diffs or the planning conversation. It sees one line per approved subtask. A mistake that spans subtasks waits for a following review or the pull request.
- A `changes_requested` re-review grows only that subtask's thread.
- **Amendment note — thread archiving and stale reservations:** Orbit archives a subtask's reviewer thread when the subtask completes or is cancelled, and archives the planner and any remaining group threads when the group completes or is cancelled. If a T3 archive command fails, the scheduler continues the status transition and retries the command on a subsequent tick. The Orbit `agent_threads` rows and metrics remain. Pi session files on the Node are out of scope. A tick also removes a stale `pending:` row left behind by a failed spawn when it is older than the spawn attempt that created it.
- The operator keeps the planner thread for planning. Reviews are separate threads on the same driver and model.
- The planner and the reviewer search the catalogue before an unfamiliar Orbit action. The full catalogue at `/mcp` stays for other clients.
- A tracked `.mcp.json` stays unchanged. Those agents reach `/mcp/search` only when that file or the Node provides it.
- The group token total adds every started thread whose role is reviewer, including the planner and each subtask reviewer. A reserved row is not included.
- The acting reviewer is the subtask's reviewer thread. The planner does not defer a subtask. A review resolution is not sent to the planner or to an earlier subtask's reviewer.
- A run receipt applies only when its thread id is the acting thread. A receipt with no thread id does not move the current subtask. Orbit rewrites a legacy turn file and sends the bound run command.
- When a reviewing subtask has no reviewer thread, a resolution clears assistance without marking the review requested. The next tick starts the fresh reviewer, and the opening packet includes the resolution.
- Every packet part has a character cap, so a long diff stat, deliverable list, or command list is cut instead of pushing the packet past 16,000 characters. The packet names `tasks-show`, `tasks-comment-list`, `.git/orbit/check.log`, or the retrieval command that holds the rest.
- A message tail from the base run that does not fit the handoff cap stays in `.git/orbit/check.log`. The packet still carries that command, cut to 160 characters, its exit code, and its failure kind.

## Affects

- Components: apps/gateway, apps/cli, apps/web, apps/docs
- ADRs: amends [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers), [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt), [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner), [ADR 0132](/decisions/0132-pause-only-for-the-acting-thread-and-a-real-question), [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit), and [ADR 0164](/decisions/0164-heal-a-settling-pull-request-with-a-fixup-subtask); uses [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools), [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off), and [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)
- Detail: [Tasks](/reference/tasks#review-a-subtask), [MCP server](/reference/mcp), [tasks:agents](/cli/tasks#orbit-tasksagents), [Task events](/reference/events#tasks)
- Verify: `composer docs-lint`; Gateway tests that a subtask review starts a new reviewer thread and does not reuse the planner or an earlier subtask's reviewer, that a `changes_requested` re-review continues that subtask's thread, that the packet stays within 16,000 characters and the diff body within 16,384 bytes even when the diff stat, the deliverables, or the command list pass their caps, that the packet names `tasks-show`, `.git/orbit/check.log`, and a diff command that prints untracked file content without updating the index, that the reviewer prompt forbids re-running the passed task check and deliverable commands, that `.mcp.json` points at `/mcp/search`, and that a planning group's review is not sent to the planner thread, and that a review resolution for a subtask with no reviewer thread is not sent to an earlier thread and is included in the fresh reviewer's opening packet, and that a run receipt from another reviewer thread is not applied
