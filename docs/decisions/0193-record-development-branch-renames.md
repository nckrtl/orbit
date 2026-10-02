---
title: "ADR 0193: Record development branch renames"
sidebarTitle: "0193 Development branch renames"
description: "In progress. Create arbitrary new development branches, clean up failed creation, and record an externally renamed branch while moving the Instance Route."
---

# ADR 0193: Record development branch renames

Orbit creates a missing development branch from the fetched Project default branch. An explicit rename operation records a branch that the caller has already checked out and can move the Instance's Route. Orbit does not rename the Instance or change its checkout.

## Status

In progress.

Principle: this decision serves [agents operate, humans steer](/mission#principles), [one way, one name](/mission#principles), and [deterministic first](/mission#principles). Agents can create and reconcile their own branch names through one supported operation. No principle exception is needed.

## Context

T3 Code creates an Instance such as `t3-1a2b3c4d` on branch `t3code/1a2b3c4d`. After the first message, it runs `git branch -m t3code/login-redirect` in the checkout and asks Orbit to record the branch and serve `login-redirect.orbit-website.test`.

The old create rule only creates a missing explicit branch when it equals a non-default Instance name. Branches containing `/` cannot meet that rule. A failure during source resolution can leave a `checkout_prepared` Instance, a failed Route, and a directory containing only `.git`. Removal rejects that state. After an external branch rename, removal also rejects the branch mismatch, even with `--force`.

The [Applications](/domains/applications#create-a-development-instance), [Instance removal](/reference/instance-removal#checks-before-removal), and [Routes](/reference/routes#change-an-instance-route-domain) pages own the resulting behavior. This decision changes branch selection and pre-activation removal; it does not weaken the recorded-branch check for resolved source.

## Decision

The Gateway owns branch selection, guarded cleanup, and an explicit rename operation. The CLI, SDK, and MCP expose the same inputs and Instance response.

### Select a branch

Resolve the requested branch name from `branch`, otherwise from the Instance name, except that `default` without an override selects the Project `default_branch`.

After fetching origin, use the requested origin branch when it exists. Otherwise use the local branch when it exists; do not reset it to the default commit. When neither exists, create the requested branch from the fetched `default_branch` commit, including explicit branches that differ from the Instance name. A missing Project default branch still fails with `instance.branch_resolution_failed`; the implicit `default` selection must resolve that branch, not create an unrelated fallback. Validate branch names before source changes with `validation.failed` for an invalid request.

Keep `selected_branch`, `branch_override`, and `starting_commit` with their existing meanings. Branch names do not choose Instance paths or generated domains.

### Clean up a failed create

A create that reports a failure before activation removes the Instance and the artifacts that attempt owns: its checkout, pending or failed Route and projections, runtime, dependency-copy staging paths, and any owned database copies. It runs no teardown, because setup has not run, and it never cascades into another Instance. Return the original failure code rather than a cleanup success. A subsequent create is a fresh request and can select a different branch.

A fresh reservation records a unique source preparation ID before remote work. Preparation refuses any pre-existing destination, creates it exclusively, and records that ID with the directory's device and inode in its Git metadata after cloning. Cleanup and retry require this receipt for a new reservation whose directory exists. Matching origin and managed account ownership alone do not establish attempt ownership. A lost preparation response can be recovered from the receipt. An interruption before the receipt is written retains the unconfirmed directory and reports incomplete cleanup; neither retry nor forced removal adopts or deletes it. Legacy reserved rows without preparation evidence cannot adopt existing source on retry.

Cleanup uses the same path, ownership, layout, and origin boundaries as removal. It must not delete an unmanaged directory or another Instance's artifacts. If the Node is unreachable or a guard or cleanup step fails, retain enough Instance and removal state to retry, return the original error with `details.cleanup = "incomplete"`, and name the Instance and the recovery command. Never delete the row first and hide the remaining resources. An interruption can also leave a pre-activation Instance; identical create retries may resume it under the existing placement identity rule.

Removal accepts development Instances in `reserved`, `checkout_prepared`, and `source_resolved`, as well as `active` Instances, and resumes `removing` through the existing removal record. Production eligibility is unchanged. Pre-activation removal accepts no Route or the Instance's own pending or failed Route. It skips teardown. A healthy unrouted task workspace, recorded with `task_workspace_routed=false`, settles in `source_resolved` and still runs its normal Project teardown; it is not a failed create. A `reserved` Instance may have no directory; a prepared repository may have no resolved branch or commit. These absences do not require `--force`. A missing recorded directory for an active Instance remains a refusal.

When source has been resolved, removal still checks the recorded branch, including in forced mode. Normal removal still checks dirty or unpublished resolved source. For an unresolved prepared repository, no nonexistent recorded branch or `HEAD` needs to pass those checks; the identity and ownership guards still apply. An incomplete transfer, clone candidate, or foreign worktree remains a refusal. Successful cleanup leaves no owned artifacts or Instance row.

An unfinished domain replacement owns its requested destination until it completes. Requesting the original domain is a conflict, not a no-op, both before and after cutover. Reject it with `route.domain_change_conflict` before recording a branch or updating stored or application environment values.

### Rename request and fields

Keep the T3 client's names:

- API: `POST /api/v1/instances/{instance}/rename`.
- Body: `{"branch":"t3code/login-redirect","domain":"login-redirect.orbit-website.test"}`. Both fields are optional, but at least one must be supplied as a nonempty string. Null and empty values are invalid.
- CLI: `orbit instance:rename <instance> [--branch=BRANCH] [--domain=HOST] [--json]`.
- MCP: `instance-rename`, with `instance` and optional `branch` and `domain`.
- Response: the full Instance representation returned by `GET /api/v1/instances/{instance}`, not a new rename response envelope.

Only an active development Instance with `source_layout=checkout` is eligible. A linked worktree or production Instance is not eligible. The operation keeps the Instance ID, name, path, layout, starting commit, and Project and Node placement. It uses the Instance lifecycle and environment operation owners; it must not race create, setup, removal, transfer, clone, or another rename.

### Record a branch already checked out

For a supplied branch, inspect the managed checkout locally on the Node. Require a symbolic `HEAD` on that exact local branch. A different branch or detached `HEAD` returns `instance.branch_not_checked_out`. Check path, ownership, repository layout, and Project origin identity without fetching or contacting origin. The branch may contain `/`, and may be dirty or unpublished: recording it is not removal and does not discard source.

A changed branch updates both `selected_branch` and `branch_override` to the supplied branch. This explicit selection also pins a `default` Instance instead of silently following a subsequent Project default-branch update. Supplying the already recorded branch is a no-op and leaves its current override alone. Domain-only rename does not change branch fields. Orbit runs no Git command that renames a branch, switches the checkout, resets `HEAD`, or changes refs.

Removal, cloning, and Doctor use the newly recorded branch. Their other safety rules stay in force: recording an unpublished branch does not make its commits published for normal removal.

### Move the Instance Route

For a supplied domain, require the Instance's own single-target Project Route. Normalize and validate the domain and check its availability before changing the branch record or remote projections. Branch-only rename needs no Route. A domain request for an Instance without such a Route returns `instance.route_required`; Orbit does not create one or change a custom proxy or analytics Route.

Use the existing Route replacement and convergence path, including for a Route with `provenance=generated`. Keep provenance, generation basis, Project, scope, publication, and target; do not edit an existing Route's immutable domain or turn a generated Route into an explicit one. A subsequent Project slug or effective-TLD change can therefore recompute this generated domain. Ordinary `route:update` still refuses a request to change a generated domain; rename explicitly permits the change for the Route that the Instance owns.

For Laravel, update `APP_URL` in the stored environment and `.env` and refresh cached configuration through the existing URL synchronization step. Prepare Caddy, certificates, and DNS through Route convergence. On success, Instance output selects the replacement Route, and the old domain no longer serves the Instance. The Route ID can change; the Instance ID does not.

### Refusals and retry

Validate all supplied fields, Instance eligibility, checkout identity and supplied branch, and Route domain availability before mutation. In particular, a combined request with a wrong branch or occupied domain changes neither branch record nor Route. Commit a changed branch record only after requested domain convergence succeeds. Infrastructure failures follow the existing Route recovery rules, not an impossible transaction over files, Caddy, DNS, and the database.

| Code | Refusal or failure |
| --- | --- |
| `validation.failed` | No field supplied, null or empty field, wrong field type, or invalid Git branch name. |
| `instance.id_invalid` | The CLI Instance selector is not a positive numeric ID. |
| `instance.rename_unsupported` | The Instance is production or has a layout other than a development checkout. |
| `instance.rename_inactive` | The development checkout is not active and is not an in-progress removal. |
| `instance.lifecycle_busy` | Another lifecycle operation owns the Instance, including removal, an incomplete transfer, or clone. |
| `instance.source_identity_invalid` | The local inspection cannot establish the managed checkout's path, ownership, layout, or Project origin identity. |
| `instance.branch_not_checked_out` | The supplied branch is not the current symbolic `HEAD`, including detached `HEAD`. |
| `instance.route_required` | A domain was supplied but the Instance has no own single-target Project Route. |
| `route.domain_invalid` | The supplied domain is invalid. |
| `route.domain_conflict` | Another Route owns the normalized domain, or it is reserved. |
| `route.domain_change_conflict` | A different domain replacement is already in progress. |
| `instance.source_profile_missing` | Domain convergence has no recorded source profile for URL synchronization. |
| `env.owner_changed` | Route targets or environment ownership changed while acquiring the operation owner. |
| `route.reconciliation_required` | An incompatible Route or placement reconciliation is in progress. |
| `route.domain_change_failed` | A Route projection or URL synchronization step failed; Route inspection names the failed step. |

The rename surface reports operation-owner contention as `instance.lifecycle_busy`, including contention from the environment owner. Authentication, access, missing-record errors, and more specific Route projection failures keep the existing API error contract. The [Route reference](/reference/routes#resume-or-refuse-a-change) owns infrastructure recovery and its recorded failure details.

An identical retry after a completed rename returns the Instance without creating an additional Route, including when the caller lost the success response. An already recorded branch is a no-op that preserves its existing override. Incomplete Route replacements follow the existing recovery rules instead of reporting lifecycle contention with themselves. Before cutover, recovery restores the old domain. A full rollback deletes the failed replacement, so an identical retry can reserve a fresh replacement. After cutover, recovery completes forward. If a domain failure left the branch unrecorded, a successful retry records it only after finishing domain convergence. A different domain while replacement recovery is incomplete is refused with `route.domain_change_conflict`.

## Rejected alternatives

- Require branch and Instance names to match: agent branch names contain `/` and are independent of the managed path.
- Fall back to a different existing branch: that silently gives the agent the wrong source. Only a genuinely missing requested branch is created from the default commit.
- Leave every failed create for manual deletion: failures before activation must not occupy a name, path, or domain. Guarded recovery state remains only when cleanup itself cannot complete or the process is interrupted.
- Disable branch checks during forced removal: force permits discarding unpublished or dirty source, not deleting a checkout with a different identity.
- Infer branch renames in Doctor or removal: those checks would mutate intent and hide a checkout switch. An explicit operation states what the caller wants to record.
- Run `git branch -m` inside Orbit: T3 already performs the rename. Repeating it introduces races and makes Orbit own an agent's source-control workflow.
- Rename the Instance and move its directory: T3 needs branch reconciliation and a readable URL, not a filesystem move that disrupts agents and Processes.
- Add another Route update endpoint or edit the domain in place: the existing replacement lifecycle already owns Caddy, DNS, certificates, URL synchronization, and retry.
- Convert a generated Route to explicit provenance: provenance records how the Route originated and is immutable. Retaining it also keeps existing slug and TLD recomputation rules.
- Promise atomic rollback after Route cutover: infrastructure changes cannot share a database transaction; the existing forward recovery is the truthful contract.

## Consequences

- Agents can use temporary branch names, then explicitly reconcile a readable name without losing checkout identity.
- Failed pre-activation creation normally leaves nothing behind, and interrupted attempts have a supported removal path.
- The caller must rename or switch to the requested branch first and keep it checked out until Orbit records it.
- A supplied domain on a generated Route can change again when its generation inputs change.
- Product implementation, behavior tests, OpenAPI, SDK, CLI, and the MCP catalogue must implement one contract. This docs-first subtask changes no product code.
- The implementation subtask must prove new and existing-origin branches, pre-activation failure and removal, branch-only, domain-only and combined rename, every refusal, normal removal after rename, Laravel URL and cached config, and retries. The group's beast proof includes its authorized stale Instance 282 cleanup; this decision does not authorize deleting any other shared fixture.

## Affects

- Components: apps/gateway, apps/cli, apps/docs, packages/php-sdk
- ADRs: none. [ADR 0191](/decisions/0191-clone-the-default-instance-database-for-each-new-instance) keeps its database-clone contract.
- Detail: [Applications: Record a renamed branch](/domains/applications#record-a-renamed-branch), [Instance removal: Pre-activation removal](/reference/instance-removal#pre-activation-removal), [Routes: Change an Instance Route domain](/reference/routes#change-an-instance-route-domain), [instance:rename](/cli/instance#orbit-instancerename)
- Verify: Gateway and CLI behavior tests, generated OpenAPI and MCP catalogue checks, SDK tests, the group live proof, `composer docs-build && composer docs-lint`, and `composer check`. The subtask that completes the implementation absorbs this ADR into the owning sections, then retires it under the ADR guide.
