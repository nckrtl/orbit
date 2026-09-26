---
title: "ADR 0133: Verify typed subtask deliverables at handoff"
sidebarTitle: "0133 Typed subtask deliverables"
description: "Proposed. Every new subtask carries typed deliverables that Orbit checks at handoff. A test deliverable names one exact Pest file. A reviewer turn is read-only, and a workspace change is a reminder."
---

# ADR 0133: Verify typed subtask deliverables at handoff

Every new subtask carries a typed list of deliverables next to its prose brief. The implementer's run receipt must confirm each deliverable by ID. Before the reviewer is asked, Orbit verifies the `file`, `test`, and `command` deliverables against the subtask's diff and its own check. A `test` deliverable names one exact Pest file. The reviewer confirms each `review` deliverable in its approval, and a reviewer turn does not change the workspace. A handoff that misses a deliverable goes back to the implementer with the reason.

## Status

Proposed.

This extends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) (run receipts), [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) (completion rubric), [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner) (planner), and [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off) (handoff check). The rest of those records stays.

## Context

Task implementers hand off work that misses explicit items in their brief. In task group 58, the implementer of subtask 87 skipped documents that its brief named, missed an explicit gate, and wrote a test that passes whether or not the feature works. The handoff check passed, because `composer check` passed. The reviewer then had to find each gap by reading the brief again.

The run receipt carries only an outcome and a summary. Nothing mechanical ties a handoff to what the subtask asked for. A brief is prose, so Orbit cannot check it. A planner can name each required item as a structured record when it writes the subtask.

On 2026-09-26, task group 115 stored a `test` deliverable whose `file` was `tests/Feature/**/*.php`. Create accepted the glob. The handoff check treated that string as a path. The workspace passed the check with a symlink named `**/*.php`. Pull request #755 merged that result.

The group's reviewer reported that it had normalized an equivalent test symlink spelling to satisfy the harness. A reviewer that edits the workspace can make a deliverable pass, or can change what the approval commits. The implementer owns the edit. The reviewer asks for it.

## Decision

A subtask stores a list of deliverables. The Gateway writes the list into each turn, the run script requires a confirmation for each one, and the scheduler verifies the mechanical ones before review.

### Deliverables

Each deliverable has a stable `id`, a `type`, a short `description`, and fields for its type. The ID is a lowercase slug, unique within the subtask.

| Type | Fields | Verified by |
| --- | --- | --- |
| `file` | `path`, a path or glob from the workspace root; `change`: `created`, `modified`, or `any` | The subtask's diff |
| `test` | `project`, a directory such as `apps/gateway`; `file`, one exact Pest test file in that project, ending in `.php`, with no glob and no `..`; `name`, a substring of the test name | The subtask's diff and a run in Orbit's check |
| `command` | `command`; `directory`, relative to the workspace root, default `.` | A run in Orbit's check |
| `review` | none | The reviewer's approval |

The Gateway stores the list as a JSON column on the task. The list is always read and replaced as a whole, and nothing queries a single deliverable, so a separate table would add joins without a use.

A `test` deliverable's `file` is one Pest test file path relative to its `project`. The path contains no `*`, `?`, `[`, or `{`. It contains no `..`. It ends in `.php`. Group create, subtask create, and subtask update refuse any other value with HTTP 422 `validation.failed`. The error details name that deliverable's `id`. Only a `file` deliverable's `path` accepts a glob. `project`, `directory`, and a `test` `file` do not.

### When deliverables are required

Moving a group to `todo` refuses with `tasks.subtask_deliverables_missing` while any of its subtasks has no deliverables. Create with `status: todo` refuses the same way. A subtask created after its group left Backlog also needs deliverables. Groups that were already past Backlog keep working: a subtask with an empty list skips every deliverable step.

Deliverables of a `todo` subtask can change after its group left Backlog, so an operator can add them to a group that is already running. The subtask's title, brief, and position still change only in Backlog. A subtask that has started keeps its deliverables.

### The run receipt

When the Gateway prepares a turn, it writes the subtask's deliverables into `.git/orbit/turn.json` with their ID, type, and description. The agent confirms each deliverable with `--deliverable=ID=evidence`, where the evidence says where or how the deliverable is met.

- The implementer's `ready_for_review` needs a confirmation for every deliverable.
- The reviewer's `approved` needs a confirmation for every `review` deliverable. It may confirm other deliverables too.
- The script refuses an unknown ID, a repeated ID, empty evidence, and `--deliverable` with any other outcome.

The receipt stores the confirmations as `deliverables`, an object from ID to evidence. The Gateway stores them on the receipt's comment. A hand-written receipt that misses a required ID fails the `deliverables` rubric item.

### Verification at handoff

The subtask's diff runs from its start commit, which the Gateway records when the subtask starts, to the working tree that the check sees. It includes uncommitted and untracked files that Git does not ignore.

When the handoff check starts for a subtask with deliverables, the Gateway gives the check script the start commit, the `test` deliverables, and the `command` deliverables. After `composer check` passes, the script:

1. records the diff as a list of paths with their Git status;
2. runs each `test` file by its path with `vendor/bin/pest FILE --log-junit=...` in its project, and records each test case with its status;
3. runs each `command` in a login shell in its directory, and records its exit code and the end of its output.

Pest turns test impact analysis off when a run names a file, so every recorded test case executed in that run. A cached result from a replay never counts as evidence.

The scheduler then checks each deliverable:

| Type | Passes when |
| --- | --- |
| `file` with `created` | A path in the diff matches and Git reports it as added |
| `file` with `modified` | A path in the diff matches and Git reports it as modified |
| `file` with `any` | A path in the diff matches and Git reports it as added or modified |
| `test` | The test file is added or modified in the diff, and the check's run of that file has at least one test case whose name contains `name`, all of which passed |
| `command` | The check's run of the command exited with 0 |

In a `file` deliverable's `path`, `*` matches within one directory, `**` matches across directories, and `?` matches one character. A `test` `file` is not a glob. The check runs that exact path.

A deliverable that fails adds the `deliverables` item next to the existing items. The item's reminder names each failing deliverable and why, so it appears in the implementer's reminder and in the assistance reason. The existing rules apply: one reminder per completion attempt, then assistance. Only when every deliverable passes does the reviewer start.

### Review

The review request lists the subtask's deliverables and names the `review` deliverables that the approval must confirm. When an approval misses one, the reviewer gets the `deliverables` item in its reminder.

A reviewer turn is read-only. The reviewer prompt says that the reviewer does not create, edit, or delete workspace files.

When the Gateway sends the review request, it records the workspace HEAD and the working-tree hash. The hash is the one the handoff check already stores: the whole working tree, uncommitted and untracked files included, without touching the Git index. The Gateway reads that pair at send time and stores it on the task for the review attempt. It does not copy the hash from an earlier check row.

When the reviewer's receipt arrives, the Gateway reads HEAD and the hash again. A difference fails `workspace_unchanged`. The Gateway keeps the receipt and does not apply `approved`, `changes_requested`, or `blocked`. The reminder tells the reviewer to revert its changes and to request changes from the implementer instead. The review sends one reminder. A second change on that review asks for assistance. When HEAD and the hash match, the Gateway applies the outcome under the rules above. A failed read is a communication failure, not a change.

### Planner and agent instructions

The planner prompt and the `implementing-in-orbit` skill tell the planner to give every subtask at least one deliverable and to turn each explicit item of the brief into one. The planner prompt says that a `test` `file` is one exact Pest path with no glob. The implementer prompt and the review request list the deliverables. The reviewer prompt says that the turn is read-only. The run script instructions explain `--deliverable`.

## Rejected alternatives

- Deliverables as prose in the brief: Orbit cannot check prose, which is the problem.
- Evidence from Pest's JUnit output of `composer check`: `composer test:affected` replays unaffected tests from the test impact cache, and a replayed test looks the same as an executed one in JUnit. It also works only for Projects whose check runs Pest that way. Running the named test file directly works for every Pest project and always executes the test.
- Verifying deliverables in the run script: the agent controls the workspace, so an edited script would pass anything. The Gateway verifies against its own check.
- A deliverables table: the list is always replaced as a whole, so a table adds joins and ordering rules without a use.
- Requiring deliverables for every existing subtask: running groups would stop at their next handoff. An empty list skips verification instead, and an operator can add deliverables to a `todo` subtask.
- Free-form test commands for Pest tests: a `test` deliverable ties the evidence to a named test in the diff. A `command` deliverable covers other ecosystems.
- A glob in a `test` `file`, expanded at check time: the expansion matches a path created to satisfy the pattern, including a symlink named `**/*.php`. One exact path names the file the check runs.
- Accept the reviewer's workspace edits in the approval commit: the commit would include work the implementer did not hand off, and the reviewer would act as another implementer.
- Revert the reviewer's edits and apply the outcome: a silent revert hides the edit. The reviewer reverts the files and asks the implementer for the change.
- Copy the handoff check's stored hash instead of reading at send time: the review request waits while the reviewer is working. The pair recorded with the request is the tree the reviewer was asked to review.
- Compare only HEAD: an uncommitted file or symlink would not show. Those files are part of the hash the check stores.

## Consequences

- A handoff is tied to the items the subtask asked for. A missing file, a missing or failing test, or a failing command returns to the implementer before a reviewer spends a turn.
- The handoff check takes longer by the time of each test file and command.
- A `test` deliverable proves that a named test exists in the diff and passes. It does not prove the test can fail. A reviewer still judges the test.
- A planner must write deliverables before the group moves to Todo.
- Groups that were past Backlog before this change run without verification until an operator adds deliverables to their `todo` subtasks.
- A `test` deliverable runs one Pest file. A subtask that needs more than one file uses more than one deliverable, at most five, or a `command` deliverable.
- A reviewer that changes the workspace does not finish that review on the changed tree. The first change is a reminder. The second asks for assistance.
- Sending the review request reads the workspace tree, and the receipt reads it again.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks), [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt), [ADR 0124](/decisions/0124-plan-backlog-groups-with-a-t3-planner), [ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)
- Detail: [Tasks](/reference/tasks)
- Verify: API validation tests for each type and the Todo refusal, including a `test` `file` with a glob, `..`, or a suffix other than `.php` refused as `validation.failed` naming the deliverable id; run script tests for missing, unknown, and complete confirmations; scheduler tests for each deliverable type, a passing handoff, an empty list, a reviewer workspace change reminded once, and a second change asking for assistance; a test that runs the real check script against a local Git checkout with a Pest test and a command
