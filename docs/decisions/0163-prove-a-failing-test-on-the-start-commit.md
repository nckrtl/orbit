---
title: "ADR 0163: Prove a failing test on the start commit"
sidebarTitle: "0163 Failing test on the start commit"
description: "Proposed. A test deliverable may set fails_on_base. Orbit runs that file on the start commit, with only the test file applied, and at least one named test must fail before the named tests pass on the working tree."
---

# ADR 0163: Prove a failing test on the start commit

A `test` deliverable may set `fails_on_base` to `true`. At handoff, Orbit runs that file twice. On the subtask's start commit, with only that test file taken from the working tree, at least one test whose name contains `name` fails. On the working tree, every such test passes. When no matching test fails and a matching test passes, the deliverable fails, and the reminder says that the test does not reproduce the bug.

## Status

Proposed.

This extends [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff), which checks a `test` deliverable by running it on the working tree. The rest of that record stays. A deliverable that omits `fails_on_base`, or sets it to `false`, keeps the single working-tree run from ADR 0133.

## Context

The layout bug on the iPhone home screen shipped twice. Nothing proved that the first fix addressed the real cause, WebKit bug 301108. The new test ran on the working tree and passed. A test written beside its fix passes there even when that same test also passes on the code the subtask started from.

[ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff) records the limit. A `test` deliverable proves that a named test exists in the diff and passes. It does not prove that the test fails before the fix.

The start commit is the commit Orbit records when the subtask starts. The diff and this extra run both use that commit.

### A killed base run

A base run that registered a temporary worktree left that worktree behind when the run was killed with SIGKILL, for example by a Node reboot or the OOM killer. The directory sat in the apps root and stayed a linked worktree of the task clone. Removing the clone was refused, because every linked worktree must be a registered AppInstance, and the sweep retried that removal.

A JUnit `error` and a JUnit `failure` were both stored as `failed`. A missing class on the start commit met the base run, and the reviewer saw the same status as an assertion failure. The base run also had no time limit, so a run that never finished held the check.

## Decision

A `test` deliverable accepts `fails_on_base`, a JSON boolean. Group create, subtask create, and subtask update store an omitted value as `false`. Show, the turn file, and the stored list include the boolean on every `test` deliverable.

The field is refused on `file`, `command`, and `review`. A value other than the JSON booleans `true` and `false` is refused. The response is HTTP 422 `validation.failed`, and the error names that deliverable's `id`.

### The two runs

When `fails_on_base` is `true`, the handoff check runs the test file twice with `vendor/bin/pest FILE --log-junit=…` in its project. Naming the file still turns Pest's test impact analysis off, so a cached result never counts.

| Run | Code under test | Result that passes |
| --- | --- | --- |
| Base | The start commit, plus only this test file from the working tree | At least one test whose name contains `name` has status `failed` |
| Working tree | The implementer's tree, including the rest of the diff | Every test whose name contains `name` has status `passed`, and at least one such test exists |

The base run reads the test file's bytes from the working tree, including an uncommitted or untracked file. No other path from the diff is present. Dependencies already installed in the task workspace stay available, so Pest can run. The base run does not change the task workspace: HEAD, the index, tracked files, and untracked files stay as they were.

The base run builds that tree in a directory under the clone's `.git/orbit/`. It archives the start commit and extracts the archive, then copies the test file and the project's installed dependencies. It does not register a Git worktree, and the directory is not in the apps root. The check removes the directory when the base run finishes or the check is cancelled. A base run killed with SIGKILL can leave the directory. Nothing is registered, so removing the clone removes the leftover with it.

At the start of a check, Orbit removes any `orbit-base-*` worktree this checkout registered earlier, prunes Git's worktree list, and removes a leftover base directory under `.git/orbit/`. That clears a worktree a check registered before this rule.

The check records a JUnit `failure` or `error` as `failed`, and a skip as `skipped`, as it does for the working-tree run. The base run meets the table when at least one matching test is `failed`. A matching pass or skip does not remove that failure. The file must also be added or modified in the diff, as ADR 0133 requires. An `error` meets the table, including a missing class.

Each failed case on the base run also records whether it was a `failure` or an `error`, and the tail of its message, at most 4096 characters. The review request shows those lines. The reviewer can tell a missing class from an assertion failure.

The base run stops after 600 seconds. A timed-out base run counts as failing on the start commit, including when it wrote no cases. The review request says that it timed out and names that limit.

The check records the two runs separately. The working-tree run keeps `exit_code` and `cases`. The base run adds `base_exit_code` and `base_cases` on that test's evidence. A case in `base_cases` that failed includes `kind` (`failure` or `error`) and `message` (the tail). A timed-out base run sets `base_timed_out` and `base_timeout_seconds` instead of requiring cases. When the test file cannot be placed on the start commit, the base run does not start, and the evidence records that placement failure instead of cases.

The diff check, the base run, and the working-tree run all contribute. A pass on the base is reported even when the diff check fails or the working-tree run fails. The reminder lists each failing part, in that order.

### Reminders

When no test whose name contains `name` fails, a passing match uses a reminder that says the test does not reproduce the bug. A skipped match is named only in that same case. A pass or skip beside a failing match does not fail the base run. These are the base-run sentences. `{name}` is the test case name, `{path}` is the test file from the workspace root, and `{needle}` is the deliverable's `name`.

```text
The test "{name}" passes on the start commit, so it does not reproduce the bug.
The test "{name}" was skipped on the start commit.
Orbit ran {path} on the start commit (exit code {code}), and no test name contains "{needle}".
Orbit did not run {path} on the start commit (exit code {code}).
Orbit did not place {path} on the start commit, so the base run did not start.
```

The failure text for the working tree stays the text from ADR 0133.

### What the reviewer sees

The review request includes the base run when a case failed or the run timed out. The lead is one sentence: an error, such as a missing class, is not an assertion failure. Each following line starts with the deliverable id. `{id}` is that id, `{name}` is the test case name, `{message}` is the stored tail, and `{seconds}` is the stored limit.

```text
Base run on the start commit. An error, such as a missing class, is not an assertion failure.
- {id}: "{name}" failed on the start commit with a failure: {message}
- {id}: "{name}" failed on the start commit with an error: {message}
- {id}: The base run timed out after {seconds} seconds, so it counts as failing on the start commit.
```

A failed case with an empty message omits the colon and the message. A pass or a skip is not listed. The timeout line is present when the base run timed out, including when cases were also recorded.

### Who sets the field

The [creating-tasks](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md) skill tells a planner that a bug group's first code subtask carries a `test` deliverable with `fails_on_base` set to `true`. The first code subtask is the first subtask that changes code. A docs-only subtask is not that subtask.

When a bug cannot be reproduced automatically, for example an iOS behavior that shows up only on a device, the brief says so. That subtask adds a `review` deliverable for the manual check, and it does not set `fails_on_base`.

When `fails_on_base` is `true`, the deliverable line in the implementer prompt says that at least one test whose name contains `name` must fail on the start commit, and that every such test must pass on the working tree.

## Rejected alternatives

- Trust a passing test on the working tree: that is the check that let the iPhone layout bug ship twice. The test never had to fail on the broken code.
- Run the whole diff on the start commit: the fix would be present, so the repro test would pass and would not show the bug.
- Require `fails_on_base` on every `test` deliverable: a test for new behavior passes on the start commit. Only a bug repro sets the flag.
- Add a separate deliverable type: `test` already names the project, the file, and the test name. A boolean selects the extra run.
- Leave the base pass for the reviewer: the reviewer already approved the iPhone fix while the test passed. Orbit fails the handoff before review.
- Treat any passing test in the file as a miss: other tests in that file cover behavior that already works. Only a test whose name contains `name` counts, and one failure among those tests is enough.
- Require every matching test to fail on the base: another test whose name contains `name` can already pass. The base run passes when at least one matching test fails.
- Check out the start commit in the task workspace: that replaces the implementer's tree. The base run leaves the workspace unchanged.
- Register a temporary worktree beside the clone: a base run killed with SIGKILL leaves that worktree registered. Removing the clone is then refused, because every linked worktree must be a registered AppInstance, and the sweep retries the removal.
- Put the base tree in the apps root without registering it: a leftover sits outside the clone, so removing the clone does not remove it.
- Treat a JUnit error as a miss: a load error is still a failure of the named test on the broken code. The review request shows the kind and the message so the reviewer can reject a missing class.
- Leave a timed-out base run as "did not run": which sends the handoff back: a run that never finishes on the broken code did not pass. It counts as failing on the start commit, and the review request says it timed out.

## Consequences

- A bug fix proves the named test fails on the code the subtask started from, with only the test file changed, and passes once the fix is present.
- The handoff check takes one extra Pest run for each deliverable with `fails_on_base` set to `true`.
- That run does not change the task workspace, so the working-tree run and the rest of the check see the implementer's tree.
- When no matching test fails on the start commit, the handoff returns to the implementer before a reviewer spends a turn. If a matching test passed, the reminder says that the test does not reproduce the bug.
- On the base, at least one test whose name contains `name` must fail. On the working tree, every such test must pass. A name that also matches a test which still passes after the fix fails that second run.
- A load error on the base is `failed`, the same status as an assertion failure. The result the reproduction reminder names is a pass. The review request shows that the case was an error and includes the tail of its message.
- A base run that runs longer than 600 seconds counts as failing on the start commit. The review request names that timeout.
- A killed base run does not register a worktree and does not block removal of the clone. A leftover directory under `.git/orbit/` is removed with the clone, and the next check removes one that an earlier check left behind.
- A bug with no automatic repro stays a `review` deliverable, and the brief says why.
- A stored `test` deliverable gains `fails_on_base`. An omitted input is `false` and keeps the single working-tree run.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)
- Detail: [Tasks](/reference/tasks#reproduce-a-bug-on-the-start-commit)
- Verify: `composer docs-lint`; Gateway validation tests that refuse a non-boolean `fails_on_base` and refuse the field on any other type as `validation.failed` naming the deliverable id; a handoff test where every named test passes on the start commit and the reminder says the test does not reproduce the bug; a handoff test where one matching test fails on the start commit while another matching test passes, and the base run still passes; a handoff test where a matching test fails on the start commit with only the test file applied, then every matching test passes on the working tree; a test that kills a base run with SIGKILL and then removes the workspace with nothing registered; a test that stores the failure kind and the tail of the message; a test that a timed-out base run counts as failing on the start commit
