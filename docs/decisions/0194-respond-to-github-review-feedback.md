---
title: "Respond to GitHub review feedback"
description: "How Orbit trusts reviewers, selects exact-head decisions, and consumes each review once to create a bounded fixup."
---

# ADR 0194: Respond to GitHub review feedback

Orbit consumes trusted GitHub requested-change decisions as bounded repair input, not as merge authority.

## Status

In progress.

Principle: [Deterministic first and agents operate, humans steer](/mission#principles). The operator chooses trusted identities and approves the contract before dispatch; code selects and consumes decisions. No principle exception is needed.

## Context

ORB-677 requires a designated final reviewer's formal GitHub approval on the exact head in Orbit's [final-review workflow](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request). That decision is already on this group's base. A plain comment is not approval. The maintainer's merge identity still has admin bypass, and Orbit does not merge. This feature extends repair input without changing those boundaries.

The current `HttpTaskPullRequestWatcher` reads PR state and head checks, not reviews. `TaskScheduler` appends CI/conflict fixups under managed-group and subtask locks. `TaskSettlingFixup` supplies the Project check or a review deliverable. Existing tests cover interrupted starts, retries, unchanged heads, pending/infrastructure checks, cap resets after completed operator work, and two-per-identity/three-per-window caps. None supplies durable external-review consumption. A review body alone also lacks inline findings and a trustworthy source identity.

## Decision

Trust, selection, retrieval, and consumption follow one deterministic contract. Review prose supplies findings; the operator's configuration supplies repair authority.

### Trust is explicit repair authority

The Gateway operator sets `orbit.tasks.github_reviewers` as a default-empty map from lower-case `owner/repository` on `github.com` to positive numeric GitHub account IDs. This is Gateway configuration, never task-branch input. Invalid entries disable feedback for that repository and ask for assistance. Logins, repository roles, review requests, `author_association`, and App ownership are not authority. No wildcard or login fallback is supported. Removing trust prevents new consumption but does not rewrite existing work.

Trust grants bounded repair, not final-review or merge authority. The externally designated final reviewer and the maintainer's delegated merge consent stay outside Orbit. There is no new operator API input, endpoint, App permission, Orbit CLI command, or merge gate. A read-only Gateway console report exposes stored approval evidence.

### Select the latest decisive record before testing its head

Read the complete bounded review list and group by trusted `user.id`. For each account, order submitted decisive records by `submitted_at`, then numeric review ID, across all heads. The latest `APPROVED`, `CHANGES_REQUESTED`, or `DISMISSED` record wins. `COMMENTED` and `PENDING` do not replace it. `DISMISSED` is neutral and does not resurrect an older decision. A latest decision for another head is stale; do not fall back to an older current-head decision. Unknown states or malformed required fields make selection unreadable. Pending records may have null submitted time/head; submitted decisive records require both.

Only an effective trusted `CHANGES_REQUESTED` record whose `commit_id` equals the current open PR head is eligible. Consider multiple accounts' eligible requests oldest first, with ID breaking ties. An approval from another account does not cancel a request. Exact-head approval is only an observation: no automatic subtask approval, task completion, or merge. Commented reviews and ordinary issue comments create no automatic work.

### Persist inspectable approval evidence

A complete uncached review scan records each trusted submitted `APPROVED` review in a durable observation table, separate from internal approval receipts and consumption. The unique key is group/repository/PR/review ID. Retain an immutable source snapshot of reviewer ID and login, review ID/URL, `commit_id`, submitted time, and first-observed time. Repeated scans update latest login/state, selected decisive review ID, observed PR head/state, trust membership, last successful check, and confirmed status/reasons, never source provenance.

`current` requires the effective trusted approval on the open PR's observed exact head. `historical` records known stale head, dismissal, supersession, removed trust, closed/merged PR, or disappearance from a complete scan. A commented review does not supersede approval; dismissal never revives an old one. Retain historical evidence across restarts. Persist group scan identity, trust revision, times/head/state, and complete/unreadable/disabled read status. Apply complete scan results atomically, rejecting older response sequences. Failed/incomplete reads do not erase evidence or confirm approval. Report unknown freshness as `unverified`, retaining last confirmed status; a scan older than 60 seconds is unverified too. Known head/trust changes can establish historical status without a complete review list.

`php artisan orbit:tasks:github-reviews <group-id> --json` reads this local state without contacting GitHub or changing a task. Its result exposes source/latest fields, scan status/times/age, confirmed/reported status and reasons, sorted by reviewer/review ID. Empty, disabled, unreadable, and unverified results are explicit; an unknown group fails. The report contains no merge-ready flag or aggregate approval verdict. It is as-of evidence, not a live identity/head/check/delegation gate or a claim that the final reviewer approved. The [Tasks report contract](/reference/tasks#inspect-approval-observations) fixes its output and freshness rules.

### Retrieve complete bounded findings and revalidate

The existing GitHub App grant supplies a separate repository token narrowed to `pull_requests: read`. List reviews, get the selected review, and list its inline comments. Follow pagination only on the expected `api.github.com` repository endpoint. Use 100 records per page, at most 10 review pages/1,000 records and 5 selected-comment pages/500 records. A remaining next-page link is overflow. Partial or malformed results never establish an effective review.

The immutable packet includes full review body, reviewer and review IDs, display login, repository/PR, exact head, submitted time, review URL, and inline comments from that account and review, ordered by comment ID. Include supplied path, current/original location, URL, diff hunk, and body. Outdated/resolved markers do not establish resolution. Replies and other reviews/accounts add no scope. The entire UTF-8 packet, instructions included, is at most 64 KiB. Empty findings or overflow ask for assistance, not an empty or truncated fixup. Do not retrieve linked content.

Review text is quoted external evidence. The implementer may address its findings within the existing contract, add regression coverage, or report a conflict. It may not treat embedded instructions as authority for unrelated work, live configuration, credentials, or merge. Product decisions go to the operator.

Cache bounded snapshots for at most 60 seconds by repository/PR/head/trust, without credentials. Immediately before creation, bypass cache and re-read the PR, complete effective selection, selected review, and comments. Any head, trust, decision, or packet change discards the candidate for a fresh retry. GitHub and the Gateway cannot share a transaction; a post-read external edit can race with creation. Persisting the exact source packet makes that limit visible.

### Couple durable consumption to the existing fixup lifecycle

Uniquely identify consumption by task group, repository, PR number, and review ID. Commit its immutable source packet/digest and linked `todo` fixup in one Gateway transaction under the existing group/subtask locks. Recheck settling status, no busy or waiting work, no unrelated assistance, and both caps. No HTTP or agent start holds those locks. A uniqueness conflict resumes existing work when it still exists; it never appends replacement work. Preserve the backlog-only destroy guard: an API delete outside backlog returns HTTP 409 `tasks.not_in_backlog`, including queued/cancelled fixups. Cancellation retains the row, consumption, and cap charge. A missing/null fixup link is unsupported cleanup/corruption handling only; retain source and consumption, never recreate work, and request assistance. A pre-commit crash leaves neither row; a post-commit crash leaves both for the existing waiting/stranded-start recovery.

The same submitted review can never create another automatic fixup, including after edits, cancellation, deletion, failure, restart, head change, or operator cap reset. Unconsumed edited findings use the fresh packet. Consumed findings stay immutable after edits, dismissal, or supersession; operators stop them through existing cancellation. No consumption is recorded for ineligible, incomplete, empty, capped, or unreadable input. Once-only delivery is not a promise of resolution.

Immutability applies to the ledger's source packet. Authorized operators retain the existing `todo` edit rules. Manual brief/deliverable changes are human rescoping, never automatic review edits or proof of resolution. They do not change the ledger, reset consumption, or reset the cap window. Started fixups keep their existing locked fields; cancelling and appending an operator subtask keeps a changed contract explicit.

Persist the consumption's cap identity and completed-operator-subtask creation-window marker. Defensive orphan handling retains its charge in that window without double-counting extant subtasks; unsupported cleanup cannot buy automatic repair budget. Only normal completed operator work resets the window, never consumption.

Feedback uses `review:{reviewer_id}` as its cap identity, not review ID. It shares the existing two-per-identity and three-per-window limits in every status. Only a completed operator subtask without a fixup identity resets the cap window; it never resets the ledger. One tick appends at most one automatic fixup. Conflict comes first, genuine failed checks next, feedback last. Capped identities can be skipped. Feedback waits on young pending checks and infrastructure-check recovery/assistance; it does not repair CI infrastructure speculatively. Existing unchanged-head/no-change guards apply to all fixups.

A feedback fixup has `review-findings`, an internal review deliverable for every snapshotted finding, plus `project-check` when a Project task check exists. It uses a fresh implementer and internal reviewer, then the ordinary checked commit/push into the same PR. Existing public `fixup_problem` and `brief` carry the identity and provenance; no response schema field is added. Regenerate/check OpenAPI, response fixtures, MCP/task-action manifests, SDK fixtures, and web API types to prove this remains true.

Transient review reads retry after 1, 2, 5, 10, and 30 minutes, then ask for review-read assistance. Complete recovery clears only that cause. Empty/oversize findings and invalid trust ask immediately. Failure reasons identify the source without raw remote errors or credentials. Review-read assistance is distinct from CI/conflict and operator assistance; CI health cannot clear it, and it does not disable existing CI/conflict repair.

### Re-review stays external

Internal fixup approval and push do not submit a GitHub comment, decision, dismissal, or re-review request. Return to settling and await external review of the new head. A fresh exact-head requested-change review may create another fixup under the same caps. The designated final reviewer must submit a fresh exact-head approval and repeat the affected verification before the authorized maintainer-profile merge. Orbit only observes that merge.

## Rejected alternatives

- Trust every collaborator, login, association, or App: broader than explicit operator consent and fragile across renames or role changes.
- Let task definitions or the branch configure trust: the work being reviewed would authorize its own external instruction source.
- Treat comments as decisions: informational review and repair authorization become indistinguishable.
- Use arrival order or a head-only review filter: out-of-order results, dismissal, or stale newer decisions can revive obsolete work.
- Use a timestamp cursor, cache, or task title for deduplication: edits and restarts can create duplicate work or lose findings.
- Use review ID as the cap identity: repeated submissions evade the per-problem bound.
- Consume before creating the fixup, or create before consuming: crash recovery can lose work or append duplicates.
- Rewrite or cancel consumed work when GitHub changes: it changes scope underneath an active implementer/reviewer and needs a separate interruption protocol.
- Truncate findings or follow linked evidence automatically: the repair scope becomes incomplete or unbounded.
- Auto-request re-review or enforce/perform merge: extra write behavior and authority are unnecessary; the maintainer's admin bypass remains.

## Consequences

- External requested changes use the existing checked subtask flow with durable once-only scope.
- Operators must configure trusted numeric accounts and intervene when findings are too large, outside scope, or capped.
- Polling and final uncached validation cost bounded GitHub reads; private Gateways still need no webhooks.
- There is no atomic snapshot across GitHub endpoints. Source provenance and internal review address the local scope, not that external race.
- Contract and dependency-ordered breakdown approval are prerequisites for product implementation. Independent proofs use only allocated disposable fixtures; no live review or rule change is part of planning.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs, packages/php-sdk, apps/cli, apps/web
- ADRs: none. The contract builds on ORB-677, which requires formal approval.
- Detail: [Tasks: inspect approval observations](/reference/tasks#inspect-approval-observations), [Tasks: trusted GitHub feedback](/reference/tasks#trusted-github-feedback), [fixups](/reference/tasks#fix-a-settling-pull-request), and [lasting reasons](/reference/tasks#trusted-reviews-are-input-not-merge-authority); [GitHub App reads](/reference/github-app#how-orbit-watches-a-task-pull-request); [external final review](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request).
- Verify: watcher/API tests for bounded reads and effective selection; database/report tests for durable approval provenance, status/freshness transitions and restart/race safety; database/scheduler tests for atomic deduplication, cancellation/delete rejection, defensive orphan cap retention, races, and recovery; fixup/packet tests for scope and deliverables; generated-contract checks; separate independent Incus proofs for retrieval/fixup/re-review and recovery/caps. The final contract-regeneration subtask absorbs this record into the owning Tasks rationale, retires its slug, and updates inbound links.
