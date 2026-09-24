---
title: "ADR 0140: Watch settling pull requests for conflicts and failed checks"
sidebarTitle: "0140 Watch settling pull requests for conflicts and failed checks"
description: "Proposed. While a settling group's pull request is open, the Gateway asks for assistance when it conflicts with its base branch or a check run on its head commit fails, and withdraws that request when the pull request is healthy again. Check runs are read with a separate checks: read token."
---

# ADR 0140: Watch settling pull requests for conflicts and failed checks

While a settling group's pull request is open, the Gateway asks for assistance when the pull request conflicts with its base branch or a check run on its head commit fails. It withdraws only that request when the pull request is healthy again. The Gateway GitHub App gains `checks: read`, and the Gateway reads check runs with a separate token.

## Status

Proposed.

## Context

[ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) has each scheduler tick read the pull request of a settling group. A merged pull request completes the group. A pull request that closes without merging asks for assistance. An open pull request produced no signal at all.

An open pull request can stop moving without anyone noticing. Group 58 opened pull request 637. The pull request first conflicted with `main`, so CI never started. After the conflict was fixed, the "Rust agent" check failed. The group stayed `settling` and asked for nothing in both cases.

GitHub reports a conflict in the pull request itself: `mergeable` is `false`, or `mergeable_state` is `dirty`. `mergeable` is `null` while GitHub computes it. Check runs come from a separate endpoint that needs the App permission `checks: read`.

[ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) mints one token with `contents: write` and `pull_requests: write` to publish and watch the pull request. GitHub refuses the whole token request when it names a permission that the installation has not accepted. Existing installations do not have `checks: read`.

## Decision

- Each tick reads an open settling pull request with the pull request token: `merged`, `state`, `mergeable`, `mergeable_state`, `head.sha`, and `base.ref`. Merged and closed pull requests keep the behavior of ADR 0113.
- The pull request conflicts when `mergeable` is `false` or `mergeable_state` is `dirty`. A `null` `mergeable` is not a conflict.
- The Gateway lists the check runs of the head commit with a separate token that has only `checks: read`. A check run fails when it completes with `failure`, `timed_out`, `cancelled`, `startup_failure`, or `action_required`. Other conclusions, and runs that have not completed, are not problems.
- When GitHub refuses the checks token, the Gateway skips the check runs and still reports conflicts. An unreadable check list alone never asks for assistance.
- Problems produce one assistance reason. It starts with `The pull request needs attention: ` and has one sentence per problem, such as `It conflicts with main; merge main into the task branch and push.` or `Check Rust agent failed: <url>.`
- The Gateway writes the reason and notifies Coder only when the reason differs from the stored one. The same problems on the next tick change nothing.
- When the open pull request has no problems, the Gateway clears the assistance request only if its reason starts with that prefix. It leaves any other assistance request as it is, and it does not replace another request with its own.
- When the pull request merges, the Gateway clears an assistance request with that prefix before it completes the group. A request with another cause stays. A completion failure still replaces the request with its own cleanup reason.
- A GitHub failure while the Gateway reads the pull request or its check runs changes nothing on the group.
- The App manifest requests `checks: read`, so a newly registered App has it.

## Rejected alternatives

- Add `checks: read` to the pull request token: rejected because GitHub refuses the whole token for an installation without that permission. Pull request publishing and merge watching would stop.
- Read the combined commit status instead of check runs: rejected because GitHub Actions reports through check runs, not commit statuses.
- Ask for assistance when the check runs are unreadable: rejected because every existing installation would ask for assistance on each settling group until its owner accepts the new permission.
- Receive GitHub webhooks for check and pull request events: rejected because the Gateway App receives no webhooks ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
- Notify on every tick while a problem lasts: rejected because the scheduler ticks every 10 seconds, and Coder would receive the same message repeatedly.

## Consequences

- A conflict or a failed check on a settling pull request asks for assistance once, and the group reads healthy again after the fix.
- An existing App reports failed checks only after its owner grants the permission. Open the App settings on GitHub, set Permissions → Checks to Read-only, and save. Then accept the new permission on each installation. Until then, the Gateway reports conflicts only.
- The Gateway reads the check runs of one head commit at most once a minute, and caches only check names and URLs. The read costs three GitHub requests, an installation lookup, the checks token, and the check run list, so an open settling pull request adds about 180 requests an hour. A re-run on the same commit shows within a minute; a push reads at once.
- The Gateway reads up to 100 check runs per head commit. A failed check beyond that page is not reported.
- A completed group keeps no pull request assistance request from before the merge. Another cause of assistance stays on the completed group until an operator clears it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) for open settling pull requests; amends [ADR 0121](/decisions/0121-end-agent-turns-with-a-run-receipt) and [ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app) with the `checks: read` App permission
- Detail: [Tasks](/reference/tasks#pull-request-and-settle-metrics), [GitHub App](/reference/github-app#how-orbit-watches-a-task-pull-request)
- Verify: `apps/gateway` Pest tests `HttpTaskPullRequestWatcherTest`, `TaskSchedulerTickTest` settling cases, and `GitHubAppApiTest` manifest permissions
