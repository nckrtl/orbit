# Development workflow

The Orbit development cycle starts with a Ready Linear issue and ends when its
approved pull request is merged and its development resources are removed.
Automated proof is mandatory for every change. An issue that needs a real OS,
service manager, privilege boundary, network, certificate, filesystem
ownership, or multi-node behavior adds Incus proof on a disposable topology.
Production release and post-deploy operations remain a separate cycle.

The development-proof boundary is governed by
[ADR 0006](../decisions/0006-topology-led-feature-development.md): separate
disposable discovery, fresh proof of the exact candidate commit, immutable
successful proof, review in parallel with CI, and exact cleanup before
prepared-state refresh.
[ADR 0005](../decisions/0005-rolling-incus-development-topology.md) defines the
prepared topology and its refresh rules.
[ADR 0002](../decisions/0002-candidate-deployment-proof-boundary.md) is
historical context; its ownership and production-separation principles
continue in ADR 0006.

## Agent contracts

Project-manager orchestration stays outside this repository. It claims issues,
starts role sessions, routes GitHub events, creates and releases topologies on
request, and owns post-merge cleanup. This repository supplies the versioned
behavior and machine-readable handoff for each development role:

| Role | Repository skill | Successful signal |
| --- | --- | --- |
| Issue author | `.agents/skills/creating-orbit-issues` | Ready Linear issue |
| Feature worker | `.agents/skills/developing-orbit-features` | `review_ready`, then `changes_addressed` |
| Reviewer | `.agents/skills/reviewing-orbit-pull-requests` | `approved` |
| Merge verifier | `.agents/skills/merging-orbit-pull-requests` | `merged` |

The project manager must pass each skill's required input and consume its
YAML handoff. It must send `changes_requested` back to the same feature-worker
session. It must not infer success from Slack message text.

## Issue contract

Use `.agents/skills/creating-orbit-issues` to create the issue. Linear owns the
outcome, scope, acceptance criteria, component list, ADR links, and the Incus
proof contract. An Incus issue adds exactly two lines:

```text
Proof: incus
Composition: gateway + app-dev + app-prod
```

Automated-only work omits both lines. `Composition` names physical nodes only;
repository code owns resource names, images, sizes, and networks. A normal
issue cannot use an unsupported operating system, role combination, or
topology. An issue that adds official support must include Gateway support,
Gateway tests, an E2E recipe, harness support, and live acceptance in scope.

Every linked or otherwise governing ADR must already be on `main` before the
issue becomes Ready, implementation starts, or a dependent workflow contract
changes. A feature pull request must not introduce or modify its governing ADR.

## The 14-step flow

1. **Prepare issue.** The issue author prepares the Linear issue.
2. **Claim issue.** The project manager claims the Ready issue, creates one
   monorepo worktree with `bin/worktree-create`, and starts the feature worker.
3. **Select recipe.** The worker maps the `Composition` to Gateway-supported
   node types and the `apps/e2e` recipe `gateway_app-dev_app-prod`. An
   unsupported requirement blocks normal feature work.
4. **Create discovery.** The project manager runs
   `bin/e2e-topology acquire ISSUE WORKTREE --json`. Discovery mounts the
   worktree read-write at `/home/orbit/orbit` on `gateway` and `app-dev`
   (about 21 to 23 s from the promoted standby).
5. **Learn desired state.** The worker changes code and task-owned guest state
   until the topology shows the required behavior, with
   `bin/e2e-topology exec ISSUE ATTEMPT ROLE --argv-file=PATH --json`. It can
   use subagents at any point. Discovery output is not proof evidence.
6. **Codify required state.** Every manual action becomes repository
   behavior, proof setup, a `post_deployment_action`, or diagnostic-only work.
7. **Harden candidate.** The worker implements, tests, runs each changed
   project's `composer check` and root `bin/test`, compounds durable
   learning, and commits the clean candidate. That commit is the code freeze.
8. **Remove discovery.** The project manager runs
   `bin/e2e-topology release ISSUE ATTEMPT --json`. The worker waits for
   verified absence before proof.
9. **Prove fresh.** The worker runs
   `bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH --json`
   (about 33 s). The harness creates a new proof attempt, synchronizes the
   exact commit from Git, verifies clean guest checkout identity, converges,
   runs the declared setup and acceptance checks, and records `proved` or
   `diagnosis`. Proof never mounts host state. A proved attempt is immutable
   through review and merge.
10. **Open a normal pull request.** The worker pushes and opens the pull
    request from its template only after proof succeeds. Orbit does not use
    draft pull requests. CI and review start immediately and run in parallel.
11. **Handle corrections.** A new commit changes the head, so the prior proof
    is stale. The worker moves the old proof to diagnosis only when useful,
    requests its release, reruns local gates, completes fresh proof, then
    pushes the new candidate and posts one comment for that SHA:

    ```text
    Review feedback addressed in <full-sha>. Ready for re-review.
    ```

12. **Merge.** The merge verifier merges only when every gate passes.
13. **Clean development resources.** The project manager cleans up in the
    order below.
14. **Deploy separately.** The production process deploys the merged code and
    performs and verifies `post_deployment_actions`.

## Review loop

Review is independent from implementation: a separate review agent session,
not necessarily a separate GitHub account. The reviewer uses
`.agents/skills/reviewing-orbit-pull-requests`. One review is anchored to the
candidate commit. It verifies the exact candidate, the Linear contract, the
active proof topology, the proof result, the acceptance mapping, manual-action
dispositions, Compound, and existing comments. It performs no mutation and can
approve while CI is pending.

Approval is a formal GitHub approval when the review account differs from the
pull request author. When the account is the same, GitHub rejects a formal
approval on its own pull request, so the reviewer posts a comment-type review
whose body is exactly `Approved.`. A non-approving review posts only a
`Changes requested:` line followed by one `path:line — required correction`
bullet per finding.

The `issue_comment.created` event with the worker's re-review comment tells
the project manager to send the new candidate to the reviewer.

## Merge gate

The merge verifier uses `.agents/skills/merging-orbit-pull-requests` and
requires:

- the current pull-request head equal to the candidate SHA;
- approval for that head and no unresolved actionable comments;
- passing current-head CI;
- an active proof topology whose proof status is `proved` for that candidate,
  with observed results for every acceptance check;
- every ADR link already on `main`;
- a useful or correctly marked Compound result; and
- complete post-deployment actions.

The merge verifier performs no cleanup and no topology mutation.

## Post-merge cleanup order

After merge, the project manager:

1. releases the proof topology with `bin/e2e-topology release ISSUE ATTEMPT
   --json` and verifies its exact absence;
2. computes `bin/e2e-standby fingerprint --main-sha=SHA` for merged `main` and
   refreshes prepared state with `bin/e2e-standby refresh --main-sha=SHA`
   only when the fingerprint changed, recording `unchanged`, `promoted`, or
   `failed`;
3. removes the worktree with `bin/worktree-remove`; and
4. closes the Linear issue.

A refresh failure produces `merged_refresh_blocked`. It leaves proof absent
and keeps the worktree and issue open for maintenance triage. It does not
revert merged code. Lock contention can be retried by the project manager;
there is no repository refresh queue or worker. `bin/worktree-remove` refuses
an unmerged or dirty branch.

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

## Boundary

A separate operations process releases the merged code in production,
performs and verifies `post_deployment_actions`, and completes post-deploy
verification. Production never reuses a disposable proof topology. A
post-deploy defect creates a GitHub issue with deployment evidence. Planning
then creates a new Linear bug; it does not reopen the completed feature.
