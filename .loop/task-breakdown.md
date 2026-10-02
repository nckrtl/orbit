# ORB-859: GitHub review feedback and bounded fixups

## Dispatch status

Proposal only. ORB-860 prepares the contract and this breakdown for independent review. No implementation subtask is approved for dispatch. The operator must approve the contract, open choices, and granularity after the reviewer checks agreement. This document does not claim that review or approval has happened.

Group start and impact base: `7fbf9ca2876d4598664559ab94cbe3c8c727d9b5`, the merge base with `origin/main`. ORB-677's formal exact-head approval workflow is already present on that base. Retain admin bypass, external final-review identity and consent, and the rule that Orbit does not merge.

## Inspection before the proposal

- `GitHubApi`, `RepositoryPullRequestAccess`, and `HttpGitHubApi`: App publishing/check tokens exist; PR and one-page check reads exist; review retrieval does not. A new token narrowed to `pull_requests: read` fits the existing App grant without changing permissions.
- `HttpTaskPullRequestWatcher` and its feature tests: health separates conflicts, genuine failures, young pending checks, and infrastructure failures. The check cache lasts 60 seconds. Review errors must remain distinct from empty/healthy review results.
- `TaskScheduler` and `TaskSchedulerTickTest`: settling resumes waiting work before appending, locks the managed group and subtasks for creation, handles interrupted starts and unchanged heads, and enforces two-per-identity/three-per-window caps. The window resets only after a completed non-fixup operator subtask. Review deduplication must survive that reset.
- `TaskSettlingFixup` and its unit tests: conflict precedes check failures; deliverables snapshot the Project check. Findings need their own mandatory review deliverable as well as the optional command.
- `TaskReviewPacketBuilder`, `TaskReviewPacket`, `TaskReviewContext`, and packet tests: compact prompts can cut a brief, but `.git/orbit/context.md` retains it. Acceptance must prove the full findings remain available to the internal reviewer.
- `TaskData`, `TaskResponseFixturesTest`, SDK task responses, and generated-contract scripts: Gateway/MCP `fixup_problem` and shared `brief` can carry the new identity and packet. The SDK/CLI retain brief/deliverables without a new identity property. No new response field or public trust input is needed. CLI public brief inputs remain limited to 8,000 characters; the scheduler's generated packet has a separate 64 KiB byte bound.

- `UpdateTaskAction`: authorized `todo` edits remain valid. The immutable ledger records the automatic source packet, not a ban on human rescoping. Operator overrides do not change consumption or reset caps; started tasks retain their locks.
- `DestroyTaskAction` and `TaskGroupBacklogTest`: subtask destruction is backlog-only. A queued/cancelled feedback fixup cannot be deleted through the API after dispatch; preserve HTTP 409 `tasks.not_in_backlog`. Cancellation retains rows and cap charges. Null links are defensive corruption/cleanup fixtures, never a supported deletion flow.
- Approval observation needs durable source/status records and a local read-only inspection command; a watcher return value alone supplies no operator evidence. The added observation/report subtask owns this mechanism and its tests before scheduler integration.

## ADR allocation

Planning originally allocated ADR 0193 after checking all 616 local/remote branch refs and the retired-slug registry; the highest allocated number was 0192. Allocation evidence is `.orbit-artifacts/feedback/adr-allocation.json`. The operator-directed merge of main found that concurrent work had retired number 0193. The unchanged review-feedback decision is now ADR 0194, allocated after checking all 874 available refs and the retired-slug registry without fetching. Reallocation evidence is `.orbit-artifacts/lifecycle/relay-adr-allocation.json`. Recheck for concurrent collisions before implementation merges. The final contract task absorbs 0194 into `docs/reference/tasks.md#trusted-reviews-are-input-not-merge-authority`, removes the live record, registers its retired slug, adds its redirect/overview row, and updates inbound links.

## Open choices for operator approval

Each choice has a concrete recommendation in the contract; none is an implementer TODO.

1. **Trust location:** approve a default-empty Gateway config map `orbit.tasks.github_reviewers`, scoped by lower-case repository name and numeric account IDs. This avoids public Project/SDK/CLI inputs and branch-controlled authority, at the cost of a Gateway configuration reload. No account is enrolled by this planning subtask.
2. **Effective decisions:** approve latest decisive review per account across all heads, ordered by submitted time/ID. Comments do not replace decisions; dismissal is neutral without fallback; a newer stale decision does not revive an older exact-head one. An approval from another account does not cancel requested changes. Approvals persist source provenance and current/historical/unverified status for local inspection, without merge authority.
3. **Scope and edits:** approve complete findings with 1,000-review/500-comment/64-KiB bounds and immediate assistance on overflow/empty findings. Unconsumed edits use fresh data; consumed edits/dismissal do not rewrite or cancel running work. Preserve backlog-only deletion rejection and use cancellation. Defensive missing-row cleanup retains consumption and its cap charge in the creation window. An operator stops or rescopes work through existing APIs. Authorized `todo` edits remain human overrides and never change the ledger or reset caps/consumption.
4. **Budget and priority:** approve `review:{reviewer_id}` as the cap identity, a permanent review-ID consumption key, conflict then genuine CI failure then feedback, and waiting on pending/infrastructure CI. Keep existing shared caps; a new review ID cannot buy unlimited repairs.
5. **Proof boundary:** approve separate Incus proofs with production watcher/scheduler/workspace code and fixture-driven GitHub review transport. Source fixtures come from sanitized real read-only GitHub API output. Do not post reviews to live/shared repositories. Record that mocked transport does not prove GitHub platform permissions or live reviewer behavior. Any real GitHub write proof needs a separately allocated disposable repository and explicit ownership evidence, not this planning authorization.

Before dispatch, ask the operator: Is this granularity right? Is every dependency real? Should any task be merged or split? Do you approve the five recommendations above and this dependency-ordered breakdown? Keep the group un-dispatched until the answer is recorded. Reviewer confirmation is not operator consent.

## Proposed subtasks

Every task has at most five typed deliverables. Local plan keys in the JSON are not allocated Orbit task IDs; only ORB-860 is assigned. Work runs serially on the shared group branch. Planned paths include nonexistent classes/tests/proof scripts and generated verification inputs; paths are scope estimates, not mandatory class names for implementers. Update the impact inventory if implementation chooses other paths.

### 1. Define the feedback and fixup contract (ORB-860)

**Goal:** Let the operator review a complete trust, review-consumption, and bounded repair proposal before any product work.

**Contract:** `docs/reference/tasks.md#settling`, `#trusted-github-feedback`, `#fix-a-settling-pull-request`; `docs/reference/implementation-loop.md#final-review-of-an-orbit-task-pull-request`; `docs/reference/github-app.md#how-orbit-watches-a-task-pull-request`; ADR 0194.

**Builds on:** ORB-677's formal approval decision, already present at the group base.

**Deliverables:** Tasks contract; new In progress ADR; docs build/lint command; full planned-path impact command; reviewer confirmation of agreement, paths, open choices, and task briefs. Operator approval remains required before dispatch.

**Acceptance:** Inspect production code and tests first. Update all impacted pages and include generated context. Run docs build/lint, site validation/broken-link checks, complete planned-path impact, and root `composer check`. No product code, live rules, GitHub review writes, or merge.

### 2. Retrieve complete bounded GitHub review records

**Goal:** Supply trustworthy typed review and inline-finding data through the existing App without broad credentials or partial success.

**Contract:** ADR 0194 "Retrieve complete bounded findings and revalidate"; Tasks `#retrieve-the-findings`; GitHub App `#read-review-records`.

**Builds on:** Approved ORB-860 contract and operator-approved breakdown.

**Deliverables:** Read-only App review token and typed review/comment API reads; complete bounded pagination with validated endpoints and fields; tests for permission/transport/malformed/overflow failures; Gateway affected tests and quality checks.

**Acceptance:** Capture sanitized real read-only API shapes for fixtures, recording their source. Cover multiple pages, tie fields, empty lists, pending/dismissed records, renamed accounts, inline locations and absent optional fields, hostile pagination targets, last-page next links, invalid IDs/states/times/heads, permission denial, and rate/transport failure. Errors must not become empty lists; no token leaks or review write API is introduced. No scheduler dispatch is enabled by this retrieval-only task.

### 3. Observe trusted effective exact-head reviews

**Goal:** Let the watcher identify eligible requested changes deterministically, with safe default-empty trust and informational approval/comment observations.

**Contract:** ADR 0194 "Trust is explicit repair authority" and "Select the latest decisive record before testing its head"; Tasks `#trusted-github-feedback` and `#retrieve-the-findings`.

**Builds on:** Task 2 for typed bounded reads.

**Deliverables:** Repository-scoped numeric-ID trust policy; effective-review selection; watcher review observations/cache and uncached candidate revalidation; regression tests and Gateway checks.

**Acceptance:** Cover empty/invalid/revoked config, no wildcard/login/association trust, renames, accounts/repositories kept separate, multiple reviewers, out-of-order records and equal times, commented/pending records after a decision, dismissed latest records, a newer stale decision shadowing an older current-head one, untrusted/stale requested changes, and approval without lifecycle/merge action. Verify a 60-second cache scoped to repository/PR/head/trust and fresh reads before consumption. Distinguish absent configuration from unreadable/overflow review data. No fixup is dispatched yet.

### 4. Record and inspect approval observations

**Goal:** Let an operator inspect durable approval evidence and distinguish current, historical, and unverified records without treating them as merge consent.

**Contract:** ADR 0194 "Persist inspectable approval evidence"; Tasks `#inspect-approval-observations`; implementation-loop final-review boundary.

**Builds on:** Task 3's trusted effective review results and uncached complete scans.

**Deliverables:** Durable approval source/scan/status records; local read-only `orbit:tasks:github-reviews` Gateway console report; provenance/status/freshness/race/restart regression tests; Gateway affected tests and checks.

**Acceptance:** Record group/repository/PR, reviewer/review IDs, source login/URL/commit/submission/first-observed time, latest login/state/selected decision/head/trust/check time. Test unique repeat reads, restart persistence, historical stale-head/dismissal/supersession/trust-removal/missing/closed records, no fallback after dismissal, and a commented review not superseding approval. Test incomplete/failing reads and scan age over 60 seconds report unverified with last confirmed evidence retained; reject out-of-order scan writes atomically. The JSON report reads local state only, exposes source/latest/status/reasons/scan times, sorts deterministically, and distinguishes empty/disabled/unreadable/unknown-group outcomes. No merged-ready flag, aggregate approval verdict, internal approved receipt, lifecycle transition, or GitHub call/write. Complete watcher-driven persistence and reporting here, without repair dispatch. Task 6 must preserve those updates when it adds fixup consumption.

### 5. Build findings-scoped fixup packets and deliverables

**Goal:** Give each internal implementer/reviewer a complete immutable source scope, without treating review prose as authority.

**Contract:** ADR 0194 retrieval/consumption/lifecycle sections; Tasks `#retrieve-the-findings` and `#review-fixup-lifecycle`.

**Builds on:** Task 3 for the trusted selected source.

**Deliverables:** Bounded canonical packet with source metadata, full body and same-reviewer inline findings; review fixup plan with `review:{reviewer_id}`, mandatory `review-findings`, optional snapshotted `project-check`; safety and full-context tests; Gateway checks.

**Acceptance:** Cover body-only/inline-only/combined/empty findings, comment ID ordering, other authors/reviews/replies excluded, original/outdated locations preserved, optional metadata absent, UTF-8 byte boundaries, exact cap/overflow, quoted malicious instructions, and no linked fetch. Verify compact prompts still point reviewers to the full immutable packet in `.git/orbit/context.md`. Check scope conflicts require assistance, all findings are reviewed, and changing Project checks does not rewrite existing deliverables. Plans are tested directly; scheduler dispatch belongs to Task 6.

### 6. Consume each review once through the settling scheduler

**Goal:** Turn an eligible review into one recoverable checked fixup on the same PR, without duplicate work or a larger automatic repair budget.

**Contract:** ADR 0194 "Couple durable consumption to the existing fixup lifecycle" and "Re-review stays external"; Tasks `#consume-once-and-recover`, `#review-fixup-lifecycle`, and existing fixup guards.

**Builds on:** Tasks 3, 4, and 5 for revalidated selection, durable approval observations, and complete fixup plans; Task 2 is transitive.

**Deliverables:** Durable unique ledger and atomic locked append; integrated scheduler selection/lifecycle and retry recovery; failure/assistance isolation; concurrency/deduplication/cap/CI interaction test matrix; Gateway affected tests and checks.

**Acceptance:** Test before/after-commit crash points, two concurrent ticks, lost start acknowledgement, stranded `todo`, workspace/check/review/push failure, repeated ticks/restarts, edits/dismissal/supersession after consumption, cancelled work, deletion rejection outside backlog, defensive missing-row cleanup, head changes, trust changes before append, authorized `todo` overrides, and operator window resets. Revalidate external source outside locks and recheck local status/caps inside locks. Verify no partial ledger/work, no repeat consumption, retained cancelled rows/cap charges and defensive null-link cap charges without double-counting, one fixup per tick, unchanged-head/no-change guards, conflict/CI priority, capped identity skipping, pending/infrastructure waits, shared per-reviewer/group caps, and source-specific assistance recovery. A closed/merged PR never gets new work or an unsafe push. The push returns to settling; stale decisions/approval observations do not finish, merge, or write GitHub reviews. Persist and refresh Task 4's approval records even when no repair is eligible, and never let recorded approval trigger lifecycle or merge actions. This task completes product behavior; proofs and contract generation must not fill missing runtime behavior.

### 7. Independently prove retrieval, fixup delivery, and external re-review

**Goal:** Reproduce the full happy path and ignored review classes on an allocated Incus topology, independently from implementation tests.

**Contract:** Tasks trust/retrieval/fixup/re-review sections; GitHub App read contract; implementation-loop exact-head external review boundary.

**Builds on:** Task 6's complete engine behavior.

**Deliverables:** Reusable fail-closed proof script and fixture transport helper; command-driven Incus proof; independent review of source/evidence and limitations; E2E checks and owned-fixture cleanup.

**Acceptance:** Pin the reviewed commit and running Gateway/Node sources. Use allocated disposable Project/group/workspace fixtures and read-only-source GitHub responses; do not post live reviews or change shared config. Use an owned bare Git remote for real pushes and fixture PR/review state; record that neither GitHub platform writes nor live re-review are proved. Prove trusted exact-head requested changes carry body and inline findings into one normal fixup, mandatory internal findings review and Project check occur, push updates the same PR/branch, and the group returns to settling. Show informational commented/untrusted/stale/dismissed records create no work; a new-head approval is durably recorded and locally inspectable, not a merge. Inspect reviewer/review/commit provenance, historical stale/dismissed/superseded approvals, and unverified results after an incomplete read; no inspection changes task state. Record every state transition, source IDs/head/digest, command status, credentials absent from packets, transport limitations, and leftovers after cleanup. Detailed evidence stays in `.orbit-artifacts/`, not Git.

### 8. Independently prove deduplication, recovery, and shared bounds

**Goal:** Show that interrupted consumption and repeated feedback cannot duplicate work, erase assistance, or evade the repair budget.

**Contract:** Tasks `#consume-once-and-recover`, `#review-fixup-lifecycle`, and `#trusted-reviews-are-input-not-merge-authority`; ADR 0194 consequences.

**Builds on:** Task 6 and Task 7's checked-in owned-fixture transport helper. This is a separate scenario and independent judgment, not a repetition of Task 7's happy-path proof.

**Deliverables:** Fail-closed recovery/caps proof script; command-driven independent Incus recovery proof; reviewer judgment of evidence/limitations; E2E checks and owned-fixture cleanup.

**Acceptance:** Pin commit/environment. Exercise interruption after atomic creation but before agent start, repeat ticks and a scheduler restart, edits/dismissal/renames after consumption, cancellation with retained row/charge, API deletion rejection for queued/cancelled fixups outside backlog, defensive missing-row cleanup, and unchanged-head/no-change guards. Prove only one ledger/work pair or defensively retained null link, missing-row cap retention in the creation window without double-counting, no recreated review after operator cap reset, two-per-reviewer/three-total sharing with CI/conflict, and genuine-new-review behavior under caps. Exercise missing/overflow GitHub data and recovery, CI/conflict continuing through review-read failure, and unrelated assistance remaining intact. No raw error/token leaks. Use only owned disposable fixtures and mocked read transport; record limitations and cleanup.

### 9. Verify external contracts and absorb the completed decision

**Goal:** Deliver checked public contracts and lasting documentation for the proved feature, ready for whole-PR maintainer review.

**Contract:** Tasks `#review-fixup-lifecycle` and rationale; API generation, response fixtures, MCP, SDK, web types; ADR lifecycle in `docs/decisions/README.md`.

**Builds on:** Tasks 7 and 8 with independent findings resolved, plus Task 6's completed behavior.

**Deliverables:** Gateway-recorded review-fixup fixture and SDK replay tests; generated-contract command; Tasks rationale absorbs ADR 0194 with retirement/redirect/inbound-link updates; whole-PR independent review/evidence/limitations; root candidate gate.

**Acceptance:** Record fixtures from real Gateway output, not invented JSON. Prove existing `fixup_problem`, `brief`, and deliverables carry the feature with no public trust/merge input or response shape change. Run `bin/docs-openapi`/`--check`, `bin/api-fixtures --check`, `bin/mcp-tools`/`--check`, `bun run types` in `apps/web`, exact generated-type comparison, and docs build/lint/site checks. Commit changed generated outputs; unchanged output is explicit evidence. Absorb and delete ADR 0194, add its redirect and retired-slug/overview row, update every inbound link, and regenerate context. Run affected tests/checks in changed projects and root `composer check`. Submit a complete PR with proof commits/environments/results/limitations and all findings decided. Request the external final review; do not approve through the author's identity, merge, change rules, or add runtime merge enforcement.

## Impact and checks

`.loop/planned-paths.json` includes all estimated implementation/test files, nonexistent proof and ledger paths, the fixture, OpenAPI/navigation/context, MCP/task-action manifests, and web generated types. `TaskData.php` is an unchanged-schema verification input, not a promise to add a DTO field. `DestroyTaskAction.php` is a guard-verification input, not a planned relaxation of deletion. Observation model/scan migration/report/tests are included; the Gateway console report is separate from the unchanged public API. Existing generator scripts/outputs listed for regeneration can remain byte-identical. Run the complete report, not only the current documentation diff:

```bash
python3 -c 'import json,subprocess; b=subprocess.check_output(["git","merge-base","HEAD","origin/main"],text=True).strip(); p=json.load(open(".loop/planned-paths.json")); subprocess.run(["bin/docs-impact","--base",b]+[v for path in p for v in ("--paths",path)],check=True)'
```

`.loop/docs-impact.json` stores the complete report. Every impacted page is updated, including shared Gateway config owners and the generated web type owner. Generator statuses must be current/passed with no unresolved paths. Docs build/lint and Mintlify site/link checks run for this planning turn. Root `composer check` is required before handoff. Check logs and allocation evidence live under `.orbit-artifacts/feedback/`; generated context is committed. No evidence log is committed.

## Planning verification

- `composer docs-build && composer docs-lint`: passed with no warnings or errors; generated context included.
- Complete planned-path impact: 71 paths, including 24 nonexistent paths at planning time; seven impacted pages updated, no errors, and all three required generators current/passed. The complete report is `.loop/docs-impact.json`.
- `npx mint validate` and `npx mint broken-links` in `docs/`: passed; no broken links.
- Root `composer check`: passed, including all five projects' Composer validation, checks, and affected tests. Final reruns are recorded under `.orbit-artifacts/feedback/` and `.git/orbit-checks/`.
- Breakdown structure: nine dependency-ordered tasks, each with at most five deliverables; JSON and Markdown agree. Reviewer confirmation and operator approval are still pending. No product code, live configuration, GitHub review, or merge changed.

## Reviewer findings addressed

1. Approval observation now means durable reviewer/review/commit provenance with first observation and latest checked status, explicit current/historical/unverified rules, scan failure/age/order handling, and read-only local Gateway inspection. New task 4 owns persistence/reporting and direct regression tests; tasks 6/7 integrate/prove it without granting merge authority.
2. `DestroyTaskAction`'s backlog guard is preserved. Task 6/8 tests/proofs cover cancellation with retained row/charge and HTTP 409 deletion rejection outside backlog. Missing links are defensive corruption/cleanup fixtures only, retaining consumption and creation-window cap charges without double-counting. There is no new deletion API behavior. Independent reviewer confirmation and operator approval of the revised nine-task breakdown remain pending.
