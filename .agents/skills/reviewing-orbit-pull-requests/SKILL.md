---
name: reviewing-orbit-pull-requests
description: Use when independently reviewing an Orbit pull request against its Linear issue and current candidate commit.
---

# Reviewing Orbit Pull Requests

Review the candidate independently. Comment on the pull request and approve it
only when every gate passes. Do not implement fixes, push commits, merge, close
the issue, or clean the worktree or Incus topology.

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
4. For Incus proof, confirm that the issue names a registered profile and that
   the evidence comes from the required checkout roles and candidate SHA.
5. Confirm that Compound updates are useful and correctly placed. Accept no
   documentation change only when the worker gives a specific durable-learning
   reason.
6. Check every existing review thread. Unresolved actionable comments block
   approval. Add only concrete findings with a location, impact, and required
   correction.

Request changes for defects or failed gates. Approve only the current candidate
when all gates pass. The same feature worker owns all corrections; review the
new candidate again after it responds.

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
findings:
  - severity: blocking|non-blocking
    location: path:line|null
    summary: text
gates:
  candidate: pass|fail|not-assessed
  worker_handoff: pass|fail|not-assessed
  acceptance: pass|fail|not-assessed
  proof: pass|fail|not-assessed
  checks: pass|fail|not-assessed
  adrs: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  comments: pass|fail|not-assessed
blockers: []
```

Approval ends the review cycle. It does not end the development cycle.
