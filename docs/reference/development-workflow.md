# Development workflow

The Orbit development cycle starts with a Ready Linear issue and ends when its
approved pull request is merged and its development resources are removed.
Candidate deployment and rollout to the registered live topology are part of
feature development. Production release and post-deploy operations remain a
separate cycle. Development proof venues are automated checks or live proof.
The development-proof boundary is governed by
[ADR 0006](../decisions/0006-topology-led-feature-development.md): separate
disposable discovery, fresh proof of the exact candidate commit, immutable
successful proof, review in parallel with CI, and exact cleanup before
prepared-state refresh.
[ADR 0002](../decisions/0002-candidate-deployment-proof-boundary.md) is
historical context. Its shared-live rollout, pre-rollout review, and
restored-pre-state rules are superseded; its ownership and
production-separation principles continue in ADR 0006. The role contracts
below still describe the ADR 0002 flow until a dependent change activates the
ADR 0006 commands and handoffs.

## Agent contracts

Project-manager orchestration stays outside this repository. It claims issues,
starts role sessions, routes GitHub events, and owns resource cleanup. This
repository supplies the versioned behavior and machine-readable handoff for
each development role:

| Role | Repository skill | Successful signal |
| --- | --- | --- |
| Issue author | `.agents/skills/creating-orbit-issues` | Ready Linear issue |
| Feature worker | `.agents/skills/developing-orbit-features` | staged `review_ready` |
| Reviewer | `.agents/skills/reviewing-orbit-pull-requests` | `rollout_approved`, then `approved` |
| Merge verifier | `.agents/skills/merging-orbit-pull-requests` | `merged` |

The external orchestrator must pass each skill's required input and consume its
YAML handoff. It must send `changes_requested` back to the same feature-worker
session. It must not infer success from Slack message text.

## Issue contract

Use `.agents/skills/creating-orbit-issues` to create the issue. Linear owns the
outcome, scope, acceptance criteria, component list, ADR links, proof venue,
applicable live nodes, and required checkout identity evidence.

Every linked or otherwise governing ADR must already be on `main` before the
issue becomes Ready, implementation starts, or a dependent workflow contract
changes. A feature pull request must not introduce or modify its governing ADR.
An issue uses automated proof unless live operating-system or multi-node
behavior requires live proof. Live proof selects exact active applicable nodes
with `orbit node:list --json` and records node identity, roles, access,
ownership, recovery, and checkout identity requirements.

Incus is optional development acceleration. The rolling topology, refresh, and
retirement rules are defined in
[ADR 0005](../decisions/0005-rolling-incus-development-topology.md).

## Work and compound

1. The Slack project-manager agent claims a Ready issue.
2. It creates one monorepo worktree with `bin/worktree-create`.
3. The feature worker uses `.agents/skills/developing-orbit-features`. For live
   proof, it selects active applicable nodes with `orbit node:list --json` for
   read-only preflight. It inspects the gap via Orbit CLI, Gateway API, or pinned
   direct SSH and records rollout intent, ownership, recovery, and cleanup plans
   before changing code. It does not deploy or mutate live state at this stage.
   It then inspects `/home/nckrtl/orbit-old` for applicable prior implementation.
4. The feature worker implements the change and compounds durable learning into
   an existing ADR, reference document, solution note, or repository rule when
   appropriate. It then runs focused checks, each affected project's full suite
   and quality check, and root `bin/test`. It commits and pushes a clean
   candidate, creates or updates the pull request, and waits for current-head CI.
5. For live proof, a separate reviewer approves the exact candidate and rollout
   intent with `review_phase: pre_rollout` and `status: rollout_approved`. Any
   later commit invalidates this event. No rollout or live mutation occurs before
   this review.
6. Immediately before every rollout command and every other live mutation, the
   feature worker runs and inspects `orbit node:list --json`. A prior listing
   cannot authorize a later mutation. It fails closed on identity, topology,
   access, applicability, or ownership drift. Mutations are serial. Each record
   names the fresh topology request or snapshot, exact node, candidate SHA,
   mutation, task-owned resources, pre-state, recovery, result, and cleanup.
7. After live proof, the feature worker restores the pre-state, removes every
   task-owned proof and recovery resource, and verifies absence. Shared and
   pre-existing state must still match the ownership baseline. It then requests
   `post_proof` review. Automated proof goes directly to this review after step
   4.

After worktree creation, the external orchestrator can acquire the registered
`gateway_app-dev_app-prod` Incus profile. It records generation and topology
identity and synchronizes Gateway and app-dev before each relevant iteration.
The worker releases the disposable topology and verifies exact absence before
`post_proof` review.

Do not create documentation only to fill the Compound section. State why no
durable update is needed when the work produced no reusable learning.

## Review loop

Review is independent from implementation: a separate review agent session,
not necessarily a separate GitHub account. A reviewer uses
`.agents/skills/reviewing-orbit-pull-requests`. Live proof has two review events
anchored to the exact candidate. The `pre_rollout` event posts a comment-type
review whose body is exactly `Rollout approved.` and returns
`rollout_approved`. It authorizes live proof only. The `post_proof` event occurs
after proof and verified task-resource absence and can return final `approved`.
Automated proof uses only `post_proof`. The Slack project-manager agent sends
each review signal back to the same feature worker.

After the feature worker commits and pushes requested corrections and
current-head CI passes, it posts this pull request conversation comment once
for that candidate SHA:

```text
Review feedback addressed in <full-sha>. Ready for re-review.
```

The resulting `issue_comment.created` event tells the project-manager agent to
send the new candidate to the reviewer. The feature worker records the comment
URL in its handoff.

Neither successful review body carries a gate table, verification recap, or
findings. Final `post_proof` approval is a formal GitHub approval when the review
account differs from the pull request author. When the account is the same,
GitHub rejects a formal approval on its own pull request, so the reviewer posts
a comment-type review whose body is exactly `Approved.`. A non-approving review
posts only a `Changes requested:` line followed by one
`path:line — required correction` bullet per finding, with no verdict preamble,
account explanation, evidence recap, gate output, or non-blocking observation.

The merge agent uses `.agents/skills/merging-orbit-pull-requests` and verifies:

- the Linear outcome and acceptance criteria;
- required checks and proof evidence;
- the exact-SHA `post_proof` reviewer handoff with final approval and, for live
  proof, its retained `pre_rollout` review event;
- every ADR link is already on `main`; and
- the Compound result is useful or correctly marked as not needed;
- read-only verification that every task-owned resource is absent; and
- no ownership or shared-state drift from the recorded baseline.

Any remaining resource, uncertain ownership, stale absence evidence, or live
drift blocks merge. The merge verifier never cleans or mutates live state. Only
after all gates pass can it merge the pull request.

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
| Review corrections ready | `issue_comment` | `created` and body matches the worker template |
| Pull request merged | `pull_request` | `closed` and `merged=true` |
| Pull request closed | `pull_request` | `closed` and `merged=false` |

Verify webhook signatures, deduplicate deliveries by delivery ID, and make
handlers idempotent. Slack text is not the machine event source.

Official references:

- [GitHub for Slack notification configuration](https://github.com/integrations/slack#customize-your-notifications)
- [GitHub webhook events and payloads](https://docs.github.com/en/webhooks/webhook-events-and-payloads)
- [GitHub App webhook handling](https://docs.github.com/en/apps/creating-github-apps/writing-code-for-a-github-app/building-a-github-app-that-responds-to-webhook-events)

## Cleanup and boundary

Task-owned live resources must be absent before final review and merge. After
merge, the Slack project-manager agent verifies absence and shared-state
integrity again. It then fingerprints merged `main` and refreshes the stopped
Incus standby only when prepared state changed. It records `unchanged`,
`promoted`, or `failed`. A failed refresh produces `merged_refresh_blocked` and
leaves worktree removal and issue closure pending. Lock contention can be
retried by the external orchestrator. There is no repository refresh queue or
worker. After an `unchanged` or `promoted` result, the orchestrator runs
`bin/worktree-remove`. It does not defer live-resource cleanup until after
merge. The cleanup command refuses an unmerged or dirty branch.

The development issue closes after merge, the final absence check, and worktree
removal. A separate operations process releases and verifies the merged code in
production. A post-deploy defect creates a GitHub issue with deployment
evidence. Planning then creates a new Linear bug; it does not reopen the
completed feature.
