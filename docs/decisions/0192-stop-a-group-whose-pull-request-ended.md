---
title: "ADR 0192: Stop a group whose pull request ended"
sidebarTitle: "0192 Stop when the pull request ends"
description: "In progress. While subtasks are open, Orbit watches the pull request on task-{id} without storing it in pr_url. A merge or close starts no new subtask and asks for assistance. tasks:complete can finish that task."
---

# ADR 0192: Stop a group whose pull request ended

Orbit watches a pull request on `task-{id}` while subtasks are open, stores it apart from `pr_url`, and stops starting subtasks when that pull request merges or closes. The operator completes or cancels the task. The [Tasks reference](/reference/tasks) calls this record a task.

## Status

In progress.

Principle: this decision serves [agents operate, humans steer](/mission#principles) and [lean](/mission#principles). Orbit stops and asks the operator when the pull request ends, and it adds no continue command.

## Context

Orbit pushes each approved subtask to `task-{id}` and opens the reviewed pull request when the last subtask is approved. It stores that pull request in `pr_url` and reads it after the task is `settling`. A pull request on that head can merge or close before then. The task keeps starting subtasks, and the next publication fails or a review continues against a pull request that has ended.

The operator approved this decision on 2026-10-01 after two cases. In ORB-152, pull request #844 merged while subtask 713 was open. Publication then failed, and the task was cancelled. In ORB-155, pull request #853 merged while subtask 731 was in review.

## Decision

Three rules apply. The migration that adds `watched_pr_url`, `watched_pr_completion`, and the notice columns uses the suffix `add_watched_pr_url_to_tasks`.

### Watch the branch while subtasks are open

While a task has a subtask in `todo`, `running`, or `reviewing`, Orbit watches any pull request whose head is `task-{id}`, in any state, at most once a minute. The read is the GitHub App list-by-head read: `GET /repos/{owner}/{repo}/pulls` with `head={owner}:task-{id}` and `state=all`, using a token that asks only for `pull_requests: read`. The Gateway caches that repository's installation id for this read. The id is not a column on the task. A refused token drops the cached id, resolves the installation again, and retries the list once.

Orbit selects the first open pull request in GitHub's default order. When the page has no open pull request, it selects the first pull request on the page. Merged means `merged_at` is set. Closed means GitHub state `closed` and no `merged_at`. Orbit stores the selected URL in `watched_pr_url` and does not write `pr_url`. An empty or unreadable list leaves the stored URL and the assistance flag unchanged.

`pr_url` stays the reviewed pull request. The last approval still requires its description, Jev still checks `brief_coverage`, and cancel still treats only a `settling` task with `pr_url` as published.

### Stop when that pull request ends

When the watched pull request is `merged` or `closed` and a subtask is still `todo`, `running`, or `reviewing`, Orbit starts no new subtask and asks for assistance. The task stays in its status. The settling auto-complete does not run while such a subtask exists. The reason is `Watched pull request ended: {url} is {state}. Open subtasks: {list}.` `{list}` is `#{id} {title}` for each open subtask, in position order, separated by commas. The flag and the reason show on the task and on the `running` or `reviewing` subtask. This reason replaces an assistance reason that was already set. While it is set, Orbit does not start a subtask, a reviewer, or a push.

Orbit does not interrupt a running agent. It does not stop the turn and it does not call the driver interrupt. Each running implementer or reviewer gets one notice, delivered the way a resolution is delivered. Before the send, Orbit commits one pending notice for that thread: the thread id, one stable send key, and state `pending`. Every retry uses that same key. Orbit sends only after the turn has stopped. A failed send, a lost response, or a crash leaves the notice `pending`, and the next tick retries that key. A repeated key does not deliver a second notice. Pi returns `duplicate: true` when it already accepted the key. On T3 the key is the command id and the message id, and T3 returns the existing receipt. Acceptance sets the notice to `delivered`. A delivered notice is not sent again. The notice does not clear the assistance flag and is not a resolution. After the reason is set, a list result does not replace `watched_pr_url` and does not clear the assistance. A resolution comment is stored and is not sent, and it does not clear this reason.

### Complete the task by hand

`tasks:complete` also completes a `running` or `reviewing` task whose watched pull request has ended. It reads `watched_pr_url` unless `watched_pr_completion` is already stored. When the read reports `merged` or `closed`, Orbit commits that value in `watched_pr_completion` before it changes a subtask. The branch watch does not write this column. Resume, including the scheduler tick, uses the stored value and does not read GitHub again. A missing URL, an open pull request, or an unreadable pull request returns HTTP 409 `tasks.not_settling` when `watched_pr_completion` is null, as does any other status that is not `settling`. A `settling` task still completes by hand without that read.

Resume marks each `todo`, `running`, and `reviewing` subtask `cancelled` and marks the task `completed` in one database transaction. A failed transaction rolls back. A crash cannot leave a `running` or `reviewing` task with no open subtasks, and the receipt stays for the resume. Workspace removal runs after the commit. A failed removal leaves the task `completed` and asks for assistance with `Workspace removal failed: `. Completing a `completed` task retries removal and does not read GitHub. Orbit adds no `tasks:continue` operation. Cancel and a new task cover continuing the work.

## Rejected alternatives

- Store the watched pull request in `pr_url`: Reusing `pr_url` turns off the pull request description fields and the Jev `brief_coverage` check on the last approval, and it changes how cancel decides a task is unpublished.
- Interrupt the running agent: The turn holds work that is not handed off. Orbit sends one notice after the turn stops and leaves that turn running.
- Add `tasks:continue`: Cancel and a new task already cover continuing the work. A continue operation would be a second path for the same recovery.

## Consequences

- A `running` or `reviewing` task whose pull request ends stays in that status and asks for assistance until the operator completes or cancels it.
- The running turn finishes. The acting thread receives one notice after the turn stops. A failed or lost send retries the same key and does not deliver a second notice.
- `tasks:complete` stores the ended state, then cancels the open subtasks and marks the task `completed` in one transaction. Resume does not read GitHub again.
- Cancel still uses `pr_url` alone to decide that a `settling` task is published.
- The Gateway lists that head at most once a minute and caches the installation id for the repository.

## Affects

- Components: apps/gateway, apps/cli
- ADRs: none
- Detail: [Tasks: watch the branch while subtasks are open](/reference/tasks#watch-the-branch-while-subtasks-are-open), [Tasks: complete and cleanup](/reference/tasks#complete-and-cleanup), and [GitHub App: how Orbit watches a task pull request](/reference/github-app#how-orbit-watches-a-task-pull-request)
- Verify: Gateway feature tests for the branch watch, the list-by-head read, and `tasks:complete` of an ended watched pull request. Notice failures: stop after the pending key is committed and before send; the driver send throws; the driver accepts the key and the process stops before `delivered`; the turn is still running. Completion failures: stop after `watched_pr_completion` is committed and before any cancel; the parent update fails inside the completion transaction; stop after the task is `completed` and before workspace removal. Each completion resume asserts that GitHub is not called.
