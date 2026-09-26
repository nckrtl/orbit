---
title: "ADR 0160: Push each approved subtask and remove the finished workspace clone"
sidebarTitle: "0160 Push approved subtasks and remove the clone"
description: "Proposed. After each approved subtask commit, the Gateway pushes the task branch with the GitHub App installation token. Cancelling or completing a group deletes its workspace clone on the Node, and a failed removal asks for assistance and retries."
---

# ADR 0160: Push each approved subtask and remove the finished workspace clone

After the Gateway commits an approved subtask, it pushes that commit to `origin/task-{group id}` with the GitHub App installation token. Cancelling or completing a group deletes the workspace clone on the Node. A failed removal asks for assistance and retries, and the Instance row stays until the checkout is gone.

## Status

Proposed.

This amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt), which pushes the task branch when the final pull request opens, and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), which removes the Instance on complete. The final approval still opens the pull request. The forced Instance remover still deletes a development checkout.

## Context

The Gateway commits each approved subtask in the workspace and pushes `origin/task-{group id}` when it opens the pull request after the last approval. Every earlier approved commit exists only in that clone. A lost workspace then loses those commits. Cancel of a settling group without a pull request pushes once, at the moment the clone is about to be removed, so a Node that is already gone cannot send them.

Cancel and complete are documented to remove the workspace. The remover deletes a development checkout only after it accepts the Instance. When removal of a route-free workspace that never became active is refused, the Gateway deletes the Instance row and leaves the checkout on disk. Doctor compares recorded Instances, so a deleted row hides the clone.

On 2026-09-26 the clones `/fast/apps/orbit/task-102`, `task-103`, `task-104`, `task-105`, and `task-106` on beast were still on disk after their groups were cancelled or completed. Those groups had no `app_instances` rows and no removal records. Groups 91, 101, 107, and 108 had removal records.

## Decision

- After the Gateway commits an approved subtask, it pushes `HEAD` to `refs/heads/task-{group id}` on `origin`. The push uses the Gateway GitHub App installation token with `contents: write` and `pull_requests: write`, passed on the SSH process standard input, and the same `git push --quiet origin HEAD:refs/heads/task-{group id}` as `GitHubTaskPullRequestPublisher`.
- A failed push is a communication failure for that subtask. The next tick retries the push. The commit stays in the workspace, and the approval comment keeps `commit_sha`. The Gateway does not reset the branch. The fifth consecutive failure asks for assistance, and the tick keeps retrying the push.
- The subtask stays in review until the push succeeds. The Gateway then starts the next subtask. On the last approval it opens the pull request against the Project default branch, as [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) decides, and moves the group to `settling` only after it stores `pr_url`. The open pushes the branch again with the same token. A branch that already matches `HEAD` is left in place.
- Cancelling or completing a group deletes the workspace clone on the Node through the forced Instance remover. The checkout directory at the recorded path is gone, and the removal record shows that `source_finalization` deleted it. This includes a non-visitable task workspace in `source_resolved` with no Route. The Instance row is deleted only after that deletion is recorded.
- When removal fails, the command returns an error and does not report success. The group asks for assistance. A merged pull request keeps the reason prefix `Merged pull request cleanup failed: `. Cancel, complete, and the tick's removal of a leftover workspace use the reason prefix `Workspace removal failed: `. The checkout and the Instance row stay.
- The next tick retries removal for a `cancelled` or `completed` group that still has a workspace, attached or found by the `task-{group id}` name and branch, and for a `settling` group whose merged pull request cleanup failed. The per-Instance backoff applies: 60 seconds after the first failure, doubling until `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS`, with a tick starting no new removal after 60 seconds. Repeating `tasks:cancel` or `tasks:complete` retries at once. The assistance request clears when the checkout is gone. The tick does not remove the workspace of a group that is still active.

## Rejected alternatives

- Push only when the final pull request opens: rejected because the earlier approved commits then exist only on the Node, and losing that clone loses them.
- Give the agent a token and have the agent push: rejected because agents never receive a GitHub token, as the [GitHub App](/reference/github-app) reference states.
- Delete the Instance row when removal of a route-free workspace is refused, and leave the checkout for Doctor: rejected because the beast clones `task-102` through `task-106` remained with no Instance row, no removal record, and no assistance request.
- Scan the Node apps root for directories named `task-*`: rejected because a checkout with no Instance row has no owner record. Orbit removes the recorded checkout.

## Consequences

- `origin/task-{group id}` contains every approved subtask before the next subtask starts and before the pull request opens.
- A push failure delays the next subtask or the pull request and keeps the commit.
- A successful cancel or complete leaves no checkout directory for that group's workspace.
- A failed removal stays visible as assistance until the checkout is gone.
- A checkout that already has no Instance row and no removal record, including `/fast/apps/orbit/task-102` through `task-106` on beast on 2026-09-26, has no path in this decision. An operator deletes that directory.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks#pull-request-and-settle-metrics), [Tasks](/reference/tasks#complete-and-cleanup), [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)
- Verify: `composer docs-lint`; Gateway tests for the approval push, a failed push that retries without dropping `commit_sha`, and cancel and complete of a `source_resolved` task workspace that delete the checkout or ask for assistance and retry
