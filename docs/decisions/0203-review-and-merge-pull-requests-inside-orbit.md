# ADR 0203: Review and merge pull requests inside Orbit

A Project can opt in to a review-and-merge flow. Orbit reviews the whole change before it pushes anything, reviews pull requests that its maintainer opens, and merges a pull request through the GitHub App when CI is green on a head that Orbit fully reviewed.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). The maintainer opts a Project in and chooses whose pull requests Orbit reviews. Orbit then reviews, repairs, and merges without a person in the loop. It also serves [deterministic first](/mission#principles): code decides whether a head was reviewed and whether CI passed. Only the review verdict comes from a model.

## Context

A task's subtasks each get a fresh reviewer, and Orbit pushes every approved subtask to `task-{id}` ([Review a subtask](/reference/tasks#review-a-subtask)). Nobody reviews the whole branch before the pull request opens. The [final DevOps review](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request) runs outside Orbit, through the maintainer's GitHub CLI profile, and a person merges.

On 2026-10-08 the maintainer decided:

1. Orbit does not use or rely on cloud agents (Cursor, Codex, Copilot) to review or fix Orbit pull requests. When a pull request needs changes, Orbit applies them itself.
2. All review happens inside Orbit before anything is pushed. Every commit Orbit pushes is already fully reviewed and needs no further approval.

Pull requests on `nckrtl/orbit` come from Orbit (`app/orbit-nckrtl`, `task-*` branches) or from the maintainer's account (by hand, from Claude sessions, and from Cursor agents on `cursor/*` branches). The live Gateway [releases every green main commit](/reference/gateway-recovery#automatic-releases), so a merge is a deploy.

GitHub forbids an account, an App included, to approve its own pull request. The main ruleset requires only the `Required checks` status from GitHub Actions. The App holds `Contents: write`, `Pull requests: write`, and `Workflows: write`. GitHub documents `PUT /pulls/{number}/merge` under `Contents: write` and `Pull requests: write`, and `POST /pulls/{number}/reviews` under `Pull requests: write`. An App merge is a push by an App, so it starts the `push` workflow on main, which a `GITHUB_TOKEN` merge would not.

## Decision

The Gateway Tasks extension owns the flow. It changes when a flow task pushes, adds a final review subtask, creates tasks for incoming pull requests, and merges through the App.

### Opt in per Project

Two Project fields switch the flow on: `review_and_merge` (boolean, default `false`) and `merge_check` (the name of the check run that must pass, such as `Required checks`). The flow needs a `merge_check`, `source_access: github_app`, and `task_compute: shared`. While it is off, Tasks behave as before. The maintainer is asked before the live `orbit` Project is switched on.

### Final review before every push

In a flow task, an approved subtask is committed but not pushed. When the task has no open subtask and holds approved work that no final review approved, Orbit appends a **final review**: a subtask of type `final_review`, titled `Final review`. It skips the implementer and the task check, and starts in `reviewing` with a fresh reviewer.

The final review's start commit is the merge base of the workspace `HEAD` and `origin/{default branch}`. Its packet is the branch diff against that base, the task brief, and the earlier approvals. Its one deliverable is the review deliverable `final-review`. When the task has no pull request yet, the final review's approval carries the pull request fields, and Jev checks coverage then. Ordinary subtask approvals no longer need them.

| Final review outcome | Orbit |
| --- | --- |
| `approved` | Records the unchanged `HEAD` as reviewed, pushes it, and opens the pull request when none exists. The task settles |
| `changes_requested` | Completes the final review and appends the fixup subtask `Address final review` with the findings as its brief. A new final review follows the fixup |
| `blocked` | Asks for direction, as any reviewer does |

At most three final-review fixups run in one window. The window is the one the [settling fixup caps](/reference/tasks#fix-a-settling-pull-request) use. A fourth set of findings asks for assistance.

Every push after the first follows the same path. A conflict fixup, a failed-check fixup, and a trusted-feedback fixup are subtasks, so their approval is committed, a final review follows, and only its approval pushes. A flow task never asks GitHub to update its branch, because GitHub's merge commit would be a commit Orbit did not review. A conflict gets a merge fixup at once. Cancel pushes only a fully reviewed commit.

### Incoming pull requests

`ORBIT_TASKS_PULL_REQUEST_AUTHORS` lists, for each repository, the numeric GitHub account IDs whose pull requests Orbit reviews, in the format of `ORBIT_TASKS_GITHUB_REVIEWERS`. At most once a minute, Orbit lists the open pull requests of each flow Project. A pull request is eligible when its author ID is listed, its head is in the same repository, it is not a draft, its base is the default branch, and no open task already reviews it.

For each eligible pull request, Orbit creates a task in `todo` with `pr_url`, `pr_branch` (the pull request's head branch), and one final review. The workspace branch is still `task-{id}`. Before a final review of a pull request head starts, Orbit fetches `pr_branch` and moves `task-{id}` to that head. It does this only when the workspace has no approved work that Orbit has not pushed.

| Incoming final review outcome | Orbit |
| --- | --- |
| `approved` on a head nobody changed | Records the head as reviewed and posts an `APPROVE` review with `commit_id` set to that head |
| `approved` after Orbit's own fixups | Pushes the reviewed commit to `pr_branch`, records it, and posts `APPROVE` for it |
| `changes_requested` | Posts the findings as a `REQUEST_CHANGES` review on the current head, then applies them through a fixup subtask, as above |

Orbit never reviews a pull request from another author, a fork, or a draft. It hands no work to a cloud agent.

### Merge on green

Each tick, for a flow task that is `settling` with an open pull request, no open subtask, and no assistance, Orbit merges when all of these hold on the current head:

1. The head SHA is one Orbit recorded as fully reviewed for this task.
2. The `merge_check` check runs on that SHA pass by the [green-commit rules](/reference/github-app#find-the-newest-green-commit): at least one run, every run on that SHA, completed with `success`, created by `github-actions`.
3. GitHub reports the pull request mergeable, without a conflict.
4. A complete review read finds no trusted account (`orbit.tasks.github_reviewers`) whose effective decision is `CHANGES_REQUESTED`, on any head.

The App merges with `PUT /pulls/{number}/merge`, `merge_method: merge`, and `sha` set to that head, so GitHub refuses when the head moved. The next tick sees the merge and completes the task.

A head that Orbit did not record means someone else pushed. On an incoming pull request, Orbit appends a final review of that new head. On an Orbit task branch, Orbit asks for assistance and does not merge.

### Records

`task_reviewed_commits` stores each reviewed SHA for a task: how Orbit reviewed it, the final review subtask, when Orbit pushed it, and the GitHub review Orbit posted. The task stores the merge status (`waiting`, `merged`, or `refused`), the reason, and the merged SHA. Activity records each final review outcome, each push of a reviewed commit, each GitHub review Orbit posts, each merge, and each change of the merge reason. `tasks:show` and `tasks:status` show the flow state.

## Rejected alternatives

- A cloud agent reviews or fixes the pull request: the maintainer ruled it out, and Orbit already owns an implementer, a task check, and a reviewer.
- Push each subtask and review only before merge: a pushed commit is unreviewed, and a person or a fleet reviewer acts on what GitHub shows.
- A separate final-review state machine on the task: it would duplicate the reviewer thread, receipt, reminder, restart, topology, and direction handling that a subtask already has.
- A second branch for each incoming pull request: Orbit already pushes a stored commit to a named ref. One `pr_branch` column keeps the workspace on `task-{id}`, so workspace provisioning, removal, and the rubric stay unchanged.
- Let GitHub update a behind or conflicting branch: GitHub's merge commit would land on the branch without Orbit's review.
- Merge with the maintainer's CLI profile: it carries admin bypass and a personal identity. The App merge is bound to the ruleset and starts main CI.
- Require Orbit's own GitHub approval before merge: GitHub forbids an App to approve its own pull request, and the reviewed-commit record is the stronger fact.
- A new App permission: the existing grants cover reading, reviewing, and merging pull requests.

## Consequences

- The flow replaces the external final review and the maintainer's merge for opted-in Projects. The maintainer still steers through briefs, direction answers, and trusted requested changes.
- A merge deploys the Gateway, so a wrong final review ships. The merge check and trusted requested changes stay as the guard.
- Approved work waits in the workspace until the final review approves. A lost workspace loses that work, and cancel does not push it.
- A trusted request for changes blocks the merge until that account approves or the review is dismissed, even after Orbit's fixup.
- Each final review is a reviewer turn and each incoming pull request takes a workspace slot.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: none
- Detail: [Tasks: Review and merge](/reference/tasks#review-and-merge), [GitHub App](/reference/github-app#how-orbit-reviews-and-merges-a-pull-request), [Projects](/reference/projects)
- Verify: Gateway feature tests for the final review, deferred pushes, incoming pull requests, and the merge gate; `composer check` and `composer test:affected` in apps/gateway, apps/cli, and packages/php-sdk; `composer docs-lint`
