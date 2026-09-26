---
title: "ADR 0164: Heal a settling pull request with a fixup subtask"
sidebarTitle: "0164 Heal a settling pull request"
description: "Proposed. When a settling group's open pull request conflicts with its base or a check on its head fails, the Gateway appends one fixup subtask and returns the group to running. Each problem gets at most two fixups, and a group at most three. No fixup follows until the head changed and its checks completed. When the caps are reached, it asks for assistance. The coordinator merges."
---

# ADR 0164: Heal a settling pull request with a fixup subtask

When a settling group's open pull request conflicts with its base branch or a check run on its head fails, the Gateway appends one fixup subtask and returns the group to `running`. The same pull request stays open. Each problem gets at most two fixups, and a group gets at most three. No fixup follows another until the head changed and its checks completed. A cancelled check, or one that failed to start, is infrastructure and gets no fixup. When the caps are reached, the Gateway asks for assistance. The coordinator still reviews and merges.

## Status

Proposed.

This amends [ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks), which detects the conflict and the failed check and asks for assistance on the first occurrence. Detection, the closed-pull-request path, and the assistance text stay in that record. This record changes the response while a fixup remains for the problem.

It also amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) for an approval after `pr_url` is stored: that approval does not open a pull request and does not require pull request fields. The push stays the non-force refspec in [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone).

## Context

[ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks) reads an open settling pull request on each tick. A conflict or a failed check asks for assistance, and the group stays `settling`. The workspace, the branch, and the reviewer are still there. Repairing the branch is the same cycle as any other subtask: a fresh implementer, the handoff check, the persistent reviewer, a commit, and a push.

[ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) still completes a merged pull request and asks for assistance when the pull request closes without merging. A closed pull request has no open head to repair.

On 2026-09-26 the coordinator appended subtask 201 to settling group 123 after a blocking review finding. The group stayed `settling`. Subtask 201 stayed `todo`, so the finding never reached an implementer.

## Decision

- The Gateway keeps ADR 0140's detection, with the two refinements in the next two points. A conflict is `mergeable` `false` or `mergeable_state` `dirty`. A null `mergeable` is not a conflict. A check fails when a run on the head completes with `failure`, `timed_out`, `cancelled`, `startup_failure`, or `action_required`. Unreadable check runs are skipped. A failed GitHub read changes nothing. A merged pull request completes the group. A pull request that closes without merging asks for assistance. A settling group with no `pr_url` asks for assistance. None of those three paths append a fixup or start a `todo` subtask.
- A rollup check, `Required checks`, is ignored while another failed check explains the failure. One real failure is then one problem. A rollup that fails alone stays a problem.
- Only `failure`, `timed_out`, and `action_required` are genuine failures. `cancelled` and `startup_failure` are infrastructure and never get a fixup. When they are the only problems, the group waits and re-evaluates on [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone)'s backoff of 1, 2, 5, 10, and 30 minutes. When they persist after the fifth wait, the Gateway asks for assistance and adds `Those checks were cancelled or could not start, and did not recover. Re-run them.` to the reason. Genuine failures next to them get fixups as usual.
- A conflict's identity is `conflict:` plus the pull request's `base.ref`. A failed check's identity is `check:` plus the check run name. The URL is not part of the identity. Comparison is case-sensitive.
- The Gateway appends at most two fixup subtasks for one identity on a group. It counts every subtask with that `fixup_problem`, in any status, including `cancelled` and `failed`. It does not append a third fixup for that identity.
- A group keeps at most three Gateway fixups in total, in any status. When a problem still has a fixup left but the group has three, the Gateway asks for assistance and adds `Orbit already appended 3 fixups to this group.` to the reason.
- Each fixup stores the pull request head SHA it was created for. The Gateway appends no new fixup while the head is still the SHA of the group's latest fixup. After the head changes, it appends no check fixup until every check run on the new head has completed. A conflict does not wait for checks.
- When the head is still the latest fixup's SHA and that fixup changed nothing, the Gateway asks for assistance instead of a second fixup on the same failed run. A fixup changed nothing when it has no approved commit, or its approved commit is that same SHA. The reason adds `Fixup subtask #{id} changed nothing, so Orbit does not try again on the same result.` When the fixup did commit, the Gateway waits for GitHub to report the new head.
- One tick appends at most one fixup: the first current problem with fewer than two fixups. A conflict comes first. Then failed checks that have a reproduction row below, in the order GitHub returned. Then failed checks with no reproduction row, in that same order.
- The fixup is a `todo` subtask at the next position. It stores `fixup_problem`. Show returns that field, or null when the subtask is not a Gateway fixup. An operator's `tasks:subtask:create` cannot set it.
- A conflict fixup's title is `Merge origin/{base}`. Its brief is `Merge origin/{base} into the task branch and resolve the conflicts. Do not rebase and do not force-push.`
- A check fixup's title is `Fix {name}`, truncated to 160 characters. Its brief is `Check {name} failed: {url}. Do not rebase and do not force-push.` When the run has no URL, the brief is `Check {name} failed. Do not rebase and do not force-push.`
- Every fixup includes a `command` deliverable with id `composer-check`, description `Run composer check`, command `composer check`, and directory `.`. When the Project slug is `orbit` and the check name has a row below, the fixup also includes id `reproduce-check`, description `Reproduce {name}` truncated to 500 characters, and that row's command and directory. When that row is `composer check` in `.`, the fixup stores `composer-check` only. A conflict fixup has no `reproduce-check`. Any other Project has no reproduction rows.

| Check name | Command | Directory |
| --- | --- | --- |
| `CLI` | `composer check` | `apps/cli` |
| `Docs` | `composer check` | `apps/docs` |
| `Gateway` | `composer check` | `apps/gateway` |
| `E2E` | `composer check` | `apps/e2e` |
| `PHP SDK` | `composer check` | `packages/php-sdk` |
| `API reference` | `bin/docs-openapi --check && bin/mcp-tools --check` | `.` |
| `Web` | `copy=$(mktemp) && cp src/api/schema.d.ts "$copy" && bun run types && git diff --exit-code --no-index "$copy" src/api/schema.d.ts && bun run check && bun run test && bun run build` | `apps/web` |
| `Pi server` | `bun run check && bun run test && bun run build` | `apps/pi-server` |
| `Agent annotation` | `bun run check && bun run build && bun run test` | `packages/agent-annotation` |
| `Rust agent` | `cargo fmt --all -- --check && cargo clippy --locked --all-targets -- -D warnings && cargo test --locked` | `apps/agent` |

- The reproduction command is the job's check steps, not its setup. PHP rows do not include the Pest run that follows `composer check` in CI. The Rust agent row does not include the cross build. The Web row includes the schema check from CI, `bun run types && git diff --exit-code src/api/schema.d.ts`. CI diffs against HEAD on a clean checkout. The fixup copies `src/api/schema.d.ts` first and diffs that copy with `git diff --exit-code --no-index`, because the handoff tree is uncommitted. A generator rewrite fails. A schema that already matches the generator passes.
- The tick that appends the fixup returns the group to `running` and starts that subtask before the tick finishes, unless a `todo` subtask was already waiting. An existing `todo` subtask stays first, and the Gateway does not append a fixup on that tick.
- A check fixup or an operator subtask becomes `running` in the same commit that returns the group to `running`. The implementer starts after that commit. A conflict fixup returns the group to `running` before its base fetch, and becomes `running` only after that fetch succeeds. When a tick stops after the group is `running` and before the implementer exists, the next tick starts that same subtask. It does not append another fixup.
- A `todo` subtask appended to a `settling` group, by the Gateway or by an operator, is started by the tick when the pull request is open. The tick sets the group to `running` and starts the lowest-position `todo` subtask. It uses the same baseline rule as the subtask after an approval. The implementer is a new thread. The reviewer is the group's existing reviewer. The handoff check, the review, the commit, and the push then follow the normal cycle.
- `tasks:subtask:create` only appends the subtask. It does not change the group status. The following tick performs the return to `running`.
- Before a resumed subtask leaves `todo`, the Gateway fetches `origin/task-{group id}` with the pull request token. When the workspace is strictly behind that ref, it fast-forwards the workspace with `git merge --ff-only`. A workspace that is level, ahead, or diverged is left alone. The Gateway never forces.
- Before a conflict fixup leaves `todo`, the Gateway also fetches with the pull request token: `git fetch --quiet origin {base}`, where `{base}` is one argument, `base.ref`. The fetch updates the remote-tracking ref and does not change the task branch. A failed fetch leaves the subtask `todo`, and counts as a communication failure of that subtask. The next attempt waits out ADR 0160's backoff. The fifth consecutive failure asks for assistance. The implementer starts only after the fetch succeeds. The implementer merges `origin/{base}` and resolves conflicts. It does not rebase.
- The approval push uses ADR 0160's refspec, `<commit_sha>:refs/heads/task-{group id}`. It is not a force push. The Gateway does not rebase. When `pr_url` is already stored, the push updates that pull request in place. The Gateway does not open a second pull request and does not change `pr_url`. The reviewer does not send `--pr-summary`, `--pr-change`, or `--pr-breaking`, and the brief-coverage check does not run for that approval.
- Before that push, the Gateway reads the pull request state again. When the pull request merged or closed, it does not push. It asks for assistance with a reason that starts with `An approved commit is not on the pull request: ` and names the commit. An unreadable state is a publication failure with its backoff.
- When the group returns to `settling`, the Gateway reads the pull request again. When it merged and its head is not the group's latest approved commit, the group asks for assistance naming that commit. The tick does not complete that group, so the workspace stays until an operator completes or cancels it.
- The Gateway does not merge the pull request. The coordinator reviews and merges.
- When the tick starts the resumed subtask, it clears a group assistance request whose reason starts with `The pull request needs attention: `. Another cause stays. While that other cause is set, the tick does not start a `todo` subtask and does not append a fixup.
- When every current problem already has two fixups, the Gateway asks for assistance with ADR 0140's reason: the prefix `The pull request needs attention: ` and one sentence per current problem. It writes that reason and notifies Coder only when the text differs from the stored one. A healthy open pull request clears that request only. A merge clears it before completion, as ADR 0140 decides.
- When the group returns to `settling` and `pr_url` is already stored, the Gateway refreshes settle metrics and does not post `task_group.settled` again.

## Rejected alternatives

- Ask for assistance on the first conflict or failed check: rejected because the workspace and the reviewer can repair the branch, and the group would wait for an operator on every conflict with its base.
- Rebase the task branch and force-push: rejected because a force push rewrites commits the open pull request already published. ADR 0160 pushes a named commit and does not replace the branch.
- Open a new pull request for the fixup: rejected because the coordinator's review is the open pull request. A second pull request splits that review.
- Auto-merge when checks are green: rejected because the coordinator still reviews and merges.
- Append a fixup for every current problem on one tick: rejected because a conflict is repaired by a merge, and the check results on the old head are stale until that merge is pushed.
- Treat each new base commit, or each new check URL, as a new problem: rejected because a pull request that never becomes healthy would receive a new implementer whenever `main` moves or the agent pushes.
- Count every failed check name as its own problem with no group limit: rejected because one real failure also fails the rollup check, and a GitHub Actions outage fails every job. Each would start two implementers per check name before anyone is asked.
- Append the next fixup as soon as the old failure is still visible: rejected because a fixup that commits nothing leaves the same failed run on the same head. The next tick would spend the second fixup at once, with no new CI run.
- Re-run cancelled checks from the Gateway: rejected because the App holds `checks: read` only.
- Reset the count after the pull request is healthy: rejected because a check that fails, passes, and fails again would receive two more implementers on every cycle, with no limit on the group.
- Apply the cap to an operator's subtask: rejected because the cap would block the repair the coordinator appended. On 2026-09-26 subtask 201 was that repair for group 123.
- Start the implementer inside `tasks:subtask:create`: rejected because create does not read the pull request. A closed pull request must ask for assistance instead of starting work.
- Read the workflow file to discover a command: rejected because ADR 0140 reads check names and URLs only.

## Consequences

- An open unhealthy pull request returns the group to `running` for one implementer and one review. The stored pull request stays open and gains the fixup commits.
- A problem at its cap does not receive another fixup. When every current problem is at that cap, or the group has three fixups, the group stays `settling` and asks for assistance.
- One real failure is one problem. A GitHub Actions outage asks for assistance after about 48 minutes and starts no implementer.
- A fixup that changes nothing asks for assistance at once, on the same head.
- A commit is never pushed to a pull request that already merged or closed. A commit that missed a merge is named in the assistance reason, and the workspace keeps it.
- A commit someone else pushed to the task branch no longer causes a non-fast-forward push, when the workspace is strictly behind.
- An operator can append more `todo` subtasks. Each one returns an open settling group to `running`. The cap does not count them.
- A merged pull request, a closed pull request, and a settling group with no `pr_url` do not start a waiting subtask.
- A conflict fixup fetches the base ref before the implementer starts. The implementer merges that ref. Orbit does not rebase and does not force-push.
- A check with no reproduction row still runs `composer check`. The brief names the check and its URL.
- PHP CI also runs Pest after `composer check`. A failure that appears only in Pest is not what `reproduce-check` runs. The Rust agent cross build is not what that row runs.
- Returning to `settling` does not send a second `task_group.settled` webhook.
- A settling group that already has a `todo` subtask and an open pull request, including group 123 with subtask 201, starts that subtask on the tick.
- A tick that stops after the group is `running` and before the implementer starts does not leave that subtask waiting. The next tick starts it and does not append another fixup.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks) and [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt); uses the push in [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone)
- Detail: [Tasks](/reference/tasks#fix-a-settling-pull-request), [GitHub App](/reference/github-app#how-orbit-watches-a-task-pull-request)
- Verify: `composer docs-lint`; Gateway tests that one conflict fixup returns the group to `running`, stores the brief, `fixup_problem`, and `composer-check`, fetches before the implementer, and pushes without opening a second pull request; that a third conflict asks for assistance when it is the only current problem; that a distinct check gets its own two fixups and the Orbit reproduction command; that no fixup follows while the head is the last fixup's head or checks on a new head are pending; that a fixup that changed nothing asks for assistance; that `Required checks` is ignored beside another failure; that cancelled checks wait with backoff and then ask for assistance; that the fourth fixup asks for assistance; that a merged or closed pull request gets no push; that a resumed subtask fast-forwards the workspace; that an operator subtask on an open settling pull request starts on the next tick and does not count toward the cap; and that a closed pull request asks for assistance and does not start a `todo` subtask
