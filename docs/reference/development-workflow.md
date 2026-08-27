# Development workflow

The Orbit development cycle starts with a Ready Linear issue and ends when its
approved pull request is merged and its development resources are removed.
Deployment is a separate operations cycle.

## Issue contract

Use `.agents/skills/creating-orbit-issues` to create the issue. Linear owns the
outcome, scope, acceptance criteria, component list, ADR links, proof venue,
Incus profile, and checkout roles.

A required ADR must already be on `main` before the issue becomes Ready. An
issue uses automated proof unless live operating-system or multi-node behavior
requires a registered Incus profile.

## Work and compound

1. The Slack project-manager agent claims a Ready issue.
2. It creates one monorepo worktree with `bin/worktree-create`.
3. If the issue selects Incus, it creates the named disposable profile before
   implementation starts and records its identity.
4. The implementation agent changes the live topology first when that gives
   faster feedback, then codifies the proven behavior in the repository.
5. The same agent runs focused checks, full affected suites, and required live
   proof. It creates the pull request.
6. Before handoff, that agent compounds durable learning into an existing ADR,
   reference document, solution note, or repository rule when appropriate.

Do not create documentation only to fill the Compound section. State why no
durable update is needed when the work produced no reusable learning.

## Review loop

Review is independent from implementation. A reviewer comments on the pull
request. The Slack project-manager agent sends each review signal back to the
same implementation agent. The loop repeats until the reviewer approves.

The merge agent then verifies:

- the Linear outcome and acceptance criteria;
- required checks and proof evidence;
- an independent approval;
- every ADR link is already on `main`; and
- the Compound result is useful or correctly marked as not needed.

Only then can it merge the pull request.

## GitHub and Slack events

GitHub Actions is for CI. Do not add notification-only workflows. Install the
official GitHub for Slack app and subscribe the channel with:

```text
/github subscribe nckrtl/orbit pulls reviews comments
```

This gives people visible notifications and can provide a bootstrap wake-up.
Production orchestration must also use a GitHub App webhook receiver. It needs
these structured events:

| Signal | GitHub webhook | Filter |
| --- | --- | --- |
| Pull request created | `pull_request` | `opened` |
| Review submitted | `pull_request_review` | `submitted` |
| Diff review comment | `pull_request_review_comment` | `created` |
| Conversation comment | `issue_comment` | `created` |
| Pull request merged | `pull_request` | `closed` and `merged=true` |
| Pull request closed | `pull_request` | `closed` and `merged=false` |

Verify webhook signatures, deduplicate deliveries by delivery ID, and make
handlers idempotent. Slack text is not the machine event source.

Official references:

- [GitHub for Slack notification configuration](https://github.com/integrations/slack#customize-your-notifications)
- [GitHub webhook events and payloads](https://docs.github.com/en/webhooks/webhook-events-and-payloads)
- [GitHub App webhook handling](https://docs.github.com/en/apps/creating-github-apps/writing-code-for-a-github-app/building-a-github-app-that-responds-to-webhook-events)

## Cleanup and boundary

After merge, the Slack project-manager agent removes the disposable Incus
topology first and verifies that its instances, networks, and storage are gone.
It then runs `bin/worktree-remove`. The cleanup command refuses an unmerged or
dirty branch.

The development issue closes after merge and cleanup. A separate operations
process deploys and verifies the merged code. A post-deploy defect creates a
GitHub issue with deployment evidence. Planning then creates a new Linear bug;
it does not reopen the completed feature.
