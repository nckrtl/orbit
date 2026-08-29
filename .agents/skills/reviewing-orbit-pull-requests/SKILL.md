---
name: reviewing-orbit-pull-requests
description: Use when independently reviewing an Orbit pull request against its Linear issue and current candidate commit.
---

# Reviewing Orbit Pull Requests

Review the candidate independently at the requested stage. Independent means a
separate review agent session; it does not require a separate GitHub account.
Reviewing from the same GitHub account that authored the pull request is
acceptable. A `pre_rollout` review authorizes live proof only. A separate
`post_proof` review can grant final approval. Do not implement fixes, push
commits, merge, close the issue, mutate shared live nodes, or remove task-owned
resources.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
linked and otherwise governing ADRs, proof venue, requested `review_phase`, and
worker handoff. Proof venue must be `automated` or `live`. Live proof requires a
`pre_rollout` review before mutation and a `post_proof` review after proof and
cleanup. Automated proof uses only `post_proof`. Return `blocked` when the
candidate cannot be reviewed.

## Review

1. Confirm that the pull request head equals the candidate SHA and that every
   linked or otherwise governing ADR is already on `main`, not introduced by
   the feature pull request.
2. Read the root and nearest project `AGENTS.md` files. Inspect the full diff in
   context for correctness, scope, security, tests, and maintainability.
3. Confirm focused checks, every affected-project full suite and quality check,
   and current-head CI passed for the candidate SHA. CI does not replace focused
   proof or the acceptance mapping.
4. For `pre_rollout`, map each acceptance criterion to the planned live proof.
   Confirm the rollout intent identifies exact nodes from `orbit node:list
   --json` by numeric ID, name, roles, active state, and access method. Confirm a
   pinned host-key fingerprint for each SSH path, the ownership baseline,
   task-owned resources, recovery plan, cleanup plan, and serial mutation plan.
   Confirm that no candidate deployment or other live mutation has occurred for
   this candidate. Any commit after this review invalidates it.
5. For `post_proof`, map every acceptance criterion to focused evidence in the
   pull request. Focused evidence states reproducible commands or manual steps,
   the observed result, and a link or attached output when the venue produces
   one. For live proof, require the earlier `pre_rollout` review event on the
   same candidate. Confirm each mutation records a fresh `orbit node:list
   --json` request or snapshot, exact node identity, checkout paths and full
   SHAs, task-owned resources, pre-state, recovery, result, and cleanup. Confirm
   every task-owned resource, including task-owned recovery artifacts, is
   absent; shared and pre-existing state matches the ownership baseline; and no
   state was deleted or adopted to make cleanup pass. Automated proof still
   requires reproducible commands and the candidate SHA.
   When a pull request includes optional Incus diagnostic evidence, also confirm
   its exact profile, generation, topology identity, and guest checkout SHA and
   tree.
6. Confirm that Compound updates are useful and correctly placed. Accept no
   documentation change only when the worker gives a specific durable-learning
   reason.
7. Check every existing review thread. Unresolved actionable comments block
   approval. Add only concrete findings with a location, impact, and required
   correction.

Request changes for defects or failed gates. State only actionable findings
that a worker must address; omit non-blocking observations. Return
`rollout_approved` only for a passing `pre_rollout` review. Return `approved`
only for a passing `post_proof` review. The same feature worker owns all
corrections; review the new candidate again after it responds.

### Submitting the rollout approval

For `pre_rollout`, submit a comment-type GitHub review whose body is exactly
`Rollout approved.`. Do not submit a formal approval event at this stage. That
body authorizes live proof for the exact candidate SHA only; it is not merge
approval.

### Submitting the approval

For `post_proof`, submit a formal GitHub approval (the hosting service's approve
event) when the review account differs from the pull request author. When the
review account is the same as the pull request author, GitHub rejects a formal
approve event from that account on its own pull request; submit a comment-type
review whose body is exactly `Approved.` instead. That exact comment is the
required final approval evidence for a self-authored pull request.

The `Rollout approved.` or `Approved.` review carries no gate table,
verification recap, or findings. It communicates the stage outcome only. Put
verification detail in the `gates` and `findings` fields of the YAML handoff
below, never in the posted review body.

### Submitting changes requested

Submit a formal GitHub changes-requested review (the hosting service's request
changes event) when the review account differs from the pull request author.
When the review account is the same as the pull request author, GitHub
rejects a formal request-changes event from that account on its own pull
request; submit a comment-type review instead.

Either way, the review body is brief and states only actionable findings:

```text
Changes requested:
- path:line — required correction
- path:line — required correction
```

The first line reads exactly `Changes requested:`. Each following line is one
bullet, one finding, in the form `path:line — required correction`, stating
the exact change the worker must make. The body carries no verdict preamble,
no explanation of account or event type, no evidence recap, no gate table or
gate output, and no non-blocking observation. Put verification detail in the
`gates` and `findings` fields of the YAML handoff below, never in the posted
review body.

## Handoff

Return this YAML block after submitting the GitHub review, or immediately when
missing input prevents a review:

```yaml
role: reviewer
review_phase: pre_rollout|post_proof|null
status: rollout_approved|approved|changes_requested|blocked
issue: NCK-123|null
pull_request: URL|null
head_sha: full-sha|null
review_id: id|null
review_body: Rollout approved.|Approved.|text|null
pre_rollout_review:
  status: rollout_approved|not-applicable|not-assessed
  review_id: id|null
  head_sha: full-sha|null
findings:
  - location: path:line|null
    summary: text
gates:
  candidate: pass|fail|not-assessed
  worker_handoff: pass|fail|not-assessed
  review: pass|fail|not-assessed
  acceptance: pass|fail|not-assessed
  rollout_intent: pass|fail|not-assessed
  proof: pass|fail|not-assessed
  task_owned_cleanup: pass|fail|not-assessed
  live_drift: pass|fail|not-assessed
  checks: pass|fail|not-assessed
  adrs: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  comments: pass|fail|not-assessed
blockers: []
```

Use `review_phase: null` only when missing input prevents the reviewer from
identifying the requested phase; submit no GitHub review and use
`review_body: null` in that case.
`findings` must be empty for `rollout_approved` and `approved`. A successful
`pre_rollout` handoff records its own review in `pre_rollout_review`. A live
`post_proof` handoff retains that same exact-SHA event there. For automated
proof, use `not-applicable` and null fields. `review_body` must be exactly
`Rollout approved.` for `rollout_approved`. For a self-authored pull request, it
must be exactly `Approved.` for final `approved`. For `changes_requested`, it
records the `Changes requested:` body actually posted. Otherwise it records the
formal final approval or changes-requested review actually submitted.

Each submitted outcome ends that review event. Rollout approval does not grant
merge approval, and final approval does not end the development cycle.
