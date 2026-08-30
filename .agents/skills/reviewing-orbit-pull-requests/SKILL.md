---
name: reviewing-orbit-pull-requests
description: Use when independently reviewing an Orbit pull request against its Linear issue and current candidate commit.
---

# Reviewing Orbit Pull Requests

Review the candidate independently in one review anchored to the current
pull-request head. Independent means a separate review agent session; it does
not require a separate GitHub account. Reviewing from the same GitHub account
that authored the pull request is acceptable. Review starts when the pull
request opens and does not wait for CI. The reviewer can approve while CI is
pending. Do not implement fixes, push commits, merge, close the issue, or
touch any topology. The reviewer performs no mutation. The feature worker
runs discovery `release` and `prove` for its own issue; the project manager
keeps post-merge cleanup only.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
linked and otherwise governing ADRs, and the worker handoff. Proof venue must
be `automated` or `incus`. Return `blocked` when the candidate cannot be
reviewed.

## Review

1. Confirm that the pull request head equals the candidate SHA and that every
   linked or otherwise governing ADR is already on `main`, not introduced by
   the feature pull request.
2. Confirm the Linear contract: outcome, scope, acceptance criteria,
   components, and, for `Proof: incus`, a supported `Composition`.
3. Read the root and nearest project `AGENTS.md` files. Inspect the full diff in
   context for correctness, scope, security, tests, and maintainability.
4. Confirm focused checks and every affected-project full suite and quality
   check passed for the candidate SHA. Record current-head CI as `pending`
   when it has not finished; a failed CI run blocks approval.
   When the diff touches `apps/e2e/app/**`, `apps/e2e/resources/guest/**`,
   `apps/e2e/tests/Live/**`, or `bin/e2e-*`, require live-suite evidence for
   the candidate SHA in the handoff `checks` and the pull request body: the
   `bin/e2e-live <candidate-sha> --rolling` command with the assertion count
   and duration of both the lifecycle and the rolling suite. A
   harness-touching diff without that evidence is a blocking finding.
5. For `venue: incus`, read `bin/e2e-topology status ISSUE ATTEMPT --json`.
   Confirm the active proof topology uses the issue's recipe, its proof record
   has status `proved`, and its candidate commit equals the pull-request head.
   Do not sync, exec, diagnose, or release it.
   When the plan calls a fixture under `/var/lib/orbit-e2e/proof/`, confirm
   the file exists in `apps/e2e/resources/proof/<issue>/` at the candidate
   and that the record's `proof_fixtures.roles` lists the same digest for
   every role.
6. Map every acceptance criterion to an observed result in the proof record or
   to reproducible automated evidence in the pull request. Focused evidence
   states the command, the observed result, and a link or attached output when
   the venue produces one.
7. Confirm every manual action from discovery has one disposition: repository
   behavior, proof setup, a `post_deployment_actions` entry with `target`,
   `operation`, `reason`, `recovery`, and `verification`, or diagnostic-only.
8. Confirm that Compound updates are useful and correctly placed. Accept no
   documentation change only when the worker gives a specific durable-learning
   reason.
9. Check every existing review thread. Unresolved actionable comments block
   approval. Add only concrete findings with a location, impact, and required
   correction.

Request changes for defects or failed gates. State only actionable findings
that a worker must address; omit non-blocking observations. Return `approved`
only for a passing review. The same feature worker owns all corrections;
review the new candidate again after it responds.

### Submitting the approval

Submit a formal GitHub approval (the hosting service's approve event) when the
review account differs from the pull request author. When the review account
is the same as the pull request author, GitHub rejects a formal approve event
from that account on its own pull request; submit a comment-type review whose
body is exactly `Approved.` instead. That exact comment is the required
approval evidence for a self-authored pull request.

The `Approved.` review carries no gate table, verification recap, or findings.
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
gate output, and no non-blocking observation.

## Handoff

Return this YAML block after submitting the GitHub review, or immediately when
missing input prevents a review:

```yaml
role: reviewer
status: approved|changes_requested|blocked
issue: NCK-123|null
pull_request: URL|null
candidate_sha: full-sha|null
review_id: id|null
review_body: Approved.|text|null
findings:
  - location: path:line|null
    summary: text
gates:
  candidate: pass|fail|not-assessed
  linear_contract: pass|fail|not-assessed
  worker_handoff: pass|fail|not-assessed
  review: pass|fail|not-assessed
  proof_topology: pass|fail|not-applicable|not-assessed
  proof: pass|fail|not-applicable|not-assessed
  acceptance: pass|fail|not-assessed
  manual_actions: pass|fail|not-assessed
  checks: pass|pending|fail|not-assessed
  adrs: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  comments: pass|fail|not-assessed
blockers: []
```

`findings` must be empty for `approved`. `checks: pending` is allowed with
`status: approved`; the merge verifier still requires passing current-head
CI. `review_body` must be exactly `Approved.` for a self-authored approved
pull request. For `changes_requested`, it records the `Changes requested:`
body actually posted. Use `not-applicable` for the proof gates of automated
work. Approval does not end the development cycle.
