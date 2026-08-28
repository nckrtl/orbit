---
name: reviewing-orbit-pull-requests
description: Use when independently reviewing an Orbit pull request against its Linear issue and current candidate commit.
---

# Reviewing Orbit Pull Requests

Review the candidate independently. Independent means a separate review agent
session; it does not require a separate GitHub account. Reviewing from the
same GitHub account that authored the pull request is acceptable. Comment on
the pull request and approve it only when every gate passes and no finding
remains. Do not implement fixes, push commits, merge, close the issue, or
clean the worktree or Incus topology.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
linked ADRs, proof venue, and worker handoff. Return `blocked` when the candidate
cannot be reviewed, including when required Incus evidence is unavailable.

## Review

1. Confirm that the pull request head equals the candidate SHA and that linked
   ADRs are already on `main`, not introduced by the feature pull request.
2. Read the root and nearest project `AGENTS.md` files. Inspect the full diff in
   context for correctness, scope, security, tests, and maintainability.
3. Map every acceptance criterion to focused evidence in the pull request.
   Current-head CI can prove full-suite and quality checks, but it does not
   replace focused proof or the acceptance mapping. Confirm that every CI result
   belongs to the candidate SHA. Focused evidence must state reproducible
   commands or manual steps, the observed result, and a link or attached output
   when the proof venue produces one.
4. For Incus proof, confirm that the issue names the exact
   `gateway_app-dev_app-prod` profile, that generation and topology identity
   are recorded, and that evidence comes from the `gateway` and `app-dev`
   checkout roles at the candidate SHA and tree.
5. Confirm that Compound updates are useful and correctly placed. Accept no
   documentation change only when the worker gives a specific durable-learning
   reason.
6. Check every existing review thread. Unresolved actionable comments block
   approval. Add only concrete findings with a location, impact, and required
   correction.

Request changes for defects or failed gates. State only actionable findings
that a worker must address; omit non-blocking observations. Approve only the
current candidate when every gate passes and no finding remains. The same
feature worker owns all corrections; review the new candidate again after it
responds.

### Submitting the approval

Submit a formal GitHub approval (the hosting service's approve event) when the
review account differs from the pull request author. When the review account
is the same as the pull request author, GitHub rejects a formal approve event
from that account on its own pull request; submit a comment-type review whose
body is exactly `Approved.` instead. That exact comment is the required
approval evidence for a self-authored pull request.

An approval, formal or the `Approved.` comment, carries no gate table,
verification recap, or findings of any kind. It communicates the outcome only.
Put verification detail in the `gates` and `findings` fields of the YAML
handoff below, never in the posted review body.

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
status: approved|changes_requested|blocked
issue: NCK-123|null
pull_request: URL|null
head_sha: full-sha|null
review_id: id|null
review_body: Approved.|text
findings:
  - location: path:line|null
    summary: text
gates:
  candidate: pass|fail|not-assessed
  worker_handoff: pass|fail|not-assessed
  review: pass|fail|not-assessed
  acceptance: pass|fail|not-assessed
  proof: pass|fail|not-assessed
  checks: pass|fail|not-assessed
  adrs: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  comments: pass|fail|not-assessed
blockers: []
```

`findings` must be empty when `status` is `approved`. When `status` is
`approved` and the review account is the same as the pull request author,
`review_body` must be exactly `Approved.`. When `status` is
`changes_requested` and the review account is the same as the pull request
author, `review_body` must be the `Changes requested:` body actually posted.
Otherwise it records the formal approval or changes-requested review actually
submitted.

Approval ends the review cycle. It does not end the development cycle.
