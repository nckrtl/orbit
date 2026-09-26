---
title: "ADR 0160: Push each approved subtask and remove the finished workspace clone"
sidebarTitle: "0160 Push approved subtasks and remove the clone"
description: "Proposed. After each approved subtask commit, the Gateway pushes that stored commit, not HEAD, with the GitHub App installation token. Push and removal retries back off. Cancelling or completing a group deletes its workspace clone, including when the Node is unreachable, and a failed removal asks for assistance and retries."
---

# ADR 0160: Push each approved subtask and remove the finished workspace clone

After the Gateway commits an approved subtask, it pushes that stored commit to `origin/task-{group id}` with the GitHub App installation token. The refspec names the commit, never `HEAD`. Push and removal retries back off. Cancelling an unreachable Node still marks the group `cancelled` and keeps the Instance attached. Completing a group whose removal fails still ends the group. The sweep retries removal, and the Instance row stays until the checkout is gone.

## Status

Proposed.

This amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt), which pushes the task branch when the final pull request opens, and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), which removes the Instance on complete. The final approval still opens the pull request. The forced Instance remover still deletes a development checkout.

## Context

The Gateway commits each approved subtask in the workspace and pushes `origin/task-{group id}` when it opens the pull request after the last approval. Every earlier approved commit exists only in that clone. A lost workspace then loses those commits. Cancel of a settling group without a pull request pushes once, at the moment the clone is about to be removed, so a Node that is already gone cannot send them.

Cancel and complete are documented to remove the workspace. The remover deletes a development checkout only after it accepts the Instance. When removal of a route-free workspace that never became active is refused, the Gateway deletes the Instance row and leaves the checkout on disk. Doctor compares recorded Instances, so a deleted row hides the clone.

On 2026-09-26 the clones `/fast/apps/orbit/task-102`, `task-103`, `task-104`, `task-105`, and `task-106` on beast were still on disk after their groups were cancelled or completed. Those groups had no `app_instances` rows and no removal records. Groups 91, 101, 107, and 108 had removal records.

## Decision

- After the Gateway commits an approved subtask, it pushes that stored commit to `refs/heads/task-{group id}` on `origin`. The refspec is `<commit_sha>:refs/heads/task-{group id}`, where `<commit_sha>` is the commit stored on the approval. The push never uses `HEAD`. It uses the Gateway GitHub App installation token with `contents: write` and `pull_requests: write`, passed on the SSH process standard input, and `git push --quiet origin <commit_sha>:refs/heads/task-{group id}`.
- A failed push is a communication failure for that subtask. The commit stays in the workspace, and the approval comment keeps `commit_sha`. The Gateway does not reset the branch. The fifth consecutive failure asks for assistance with a reason prefixed `Approved commit publication failed: `. The tick keeps retrying the push on a backoff of 1 minute, then 2, 5, 10, and 30 minutes. Further failures stay at 30 minutes. The tick does not push on every 10-second run. A cache read or write that fails is logged, and that attempt runs as if no backoff is stored.
- A successful push, and a successful open on the last subtask, clears assistance only when the reason starts with `Approved commit publication failed: `. An assistance request with any other cause stays, on the subtask and on the group.
- The subtask stays in review until the push succeeds. The Gateway then starts the next subtask. On the last approval it opens the pull request against the Project default branch, as [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) decides, and moves the group to `settling` only after it stores `pr_url`. The open pushes the same stored commit again. A branch that already points at that commit is left in place. A failed open uses the same backoff and the same assistance prefix.
- Cancelling or completing a group deletes the workspace clone on the Node through the forced Instance remover. The checkout directory at the recorded path is gone, and the removal record shows that `source_finalization` deleted it. This includes a non-visitable task workspace in `source_resolved` with no Route. The Instance row is deleted only after that deletion is recorded.
- When the Node is unreachable, `tasks:cancel` still marks the group `cancelled` and keeps the Instance attached. It records assistance with the prefix `Workspace removal failed: ` and returns the group in that state. It does not wait for a push that the Node cannot accept. A push that fails while the Node answers still returns HTTP 502 `tasks.push_failed` and leaves the group uncancelled. Before removal of a reachable settling group that has an approved subtask, cancel pushes the latest stored approved commit with the same refspec. Uncommitted changes are not pushed.
- When `tasks:complete` cannot remove the workspace, it still marks the group `completed`, keeps the Instance attached, records assistance with `Workspace removal failed: `, and reports the removal failure on that completed group. A permanently lost Node does not block either command: cancel ends a cancellable group, and complete ends a `settling` group, without a successful removal.
- When removal fails for a group the command does not end, the command returns an error and does not report success. A merged pull request keeps the reason prefix `Merged pull request cleanup failed: `. Cancel, complete, and the tick's removal of a leftover workspace use `Workspace removal failed: `. The checkout and the Instance row stay.
- The tick's sweep retries removal for a `cancelled` or `completed` group that still has a workspace, attached or found by the `task-{group id}` name and branch, for a `settling` group whose merged pull request cleanup failed, and for a failed manual complete that has already been marked `completed` with its Instance attached. For a cancelled group that still holds an unpushed stored approval, the sweep pushes that commit before it deletes the checkout. The per-Instance backoff is 1 minute, then 2, 5, 10, and 30 minutes, and further failures stay at 30 minutes. A tick starts no new removal after 60 seconds. Repeating `tasks:cancel` or `tasks:complete` retries at once. Success clears assistance only when the reason starts with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. Another cause stays. The tick does not remove the workspace of a group that is still active. A cache read or write that fails is logged, and that removal runs as if no backoff is stored.

## Rejected alternatives

- Push only when the final pull request opens: rejected because the earlier approved commits then exist only on the Node, and losing that clone loses them.
- Push `HEAD`: rejected because HEAD can move after the approval stores its commit, and the remote branch would then receive a different commit.
- Retry a failed push or removal on every tick: rejected because a failing Node or GitHub would be contacted every 10 seconds.
- Clear every assistance request after a successful push: rejected because a blocked question, a pull request attention reason, or a removal failure would disappear with the publication failure.
- Leave a group uncancelled when its Node is unreachable: rejected because the operator cannot end the group, and a permanently lost Node would keep it open.
- Give the agent a token and have the agent push: rejected because agents never receive a GitHub token, as the [GitHub App](/reference/github-app) reference states.
- Delete the Instance row when removal of a route-free workspace is refused, and leave the checkout for Doctor: rejected because the beast clones `task-102` through `task-106` remained with no Instance row, no removal record, and no assistance request.
- Scan the Node apps root for directories named `task-*`: rejected because a checkout with no Instance row has no owner record. Orbit removes the recorded checkout.

## Consequences

- `origin/task-{group id}` receives the stored approved commit, not whatever HEAD is at push time, before the next subtask starts and before the pull request opens.
- A push failure delays the next subtask or the pull request, keeps the commit, and waits out the backoff before the next attempt.
- A successful publish leaves an assistance request that has another cause still set.
- A successful cancel or complete leaves no checkout directory for that group's workspace.
- Cancel of an unreachable Node, and complete whose removal fails, end the group and leave the checkout named by the attached Instance until the sweep deletes it.
- A failed removal stays visible as assistance until the checkout is gone. Clearing that assistance does not clear a request with another cause.
- A checkout that already has no Instance row and no removal record, including `/fast/apps/orbit/task-102` through `task-106` on beast on 2026-09-26, has no path in this decision. An operator deletes that directory.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks#pull-request-and-settle-metrics), [Tasks](/reference/tasks#complete-and-cleanup), [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request)
- Verify: `composer docs-lint`; Gateway tests for the approval push of the stored commit rather than `HEAD`, a failed push that retries on the backoff without dropping `commit_sha` or clearing another assistance cause, cancel of an unreachable Node that marks the group `cancelled` and keeps the Instance, a failed complete that marks the group `completed` and is retried by the sweep, and cancel and complete of a `source_resolved` task workspace that delete the checkout or ask for assistance and retry
