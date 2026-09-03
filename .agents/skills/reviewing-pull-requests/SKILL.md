---
name: reviewing-pull-requests
description: Use when independently reviewing one exact Orbit PR head.
---

# Reviewing Pull Requests

Independently review one exact remote PR head against its issue, governing ADRs,
repository invariants, tests, and proof. Do not expand the requested contract or
edit code.

## Steps

1. **Bind the candidate.** Read the PR and record its exact remote PR head SHA.
   Require the local checkout to be clean and equal to that remote head.
2. **Check current main.** Fetch `origin/main` and inspect whether the candidate
   includes it. The reviewer must not merge or rebase `main`. If current main is
   not in the candidate, stop until the candidate is updated and pushed, then
   review the new remote head in a new pass.
3. **Read.** Read the issue, exact diff, relevant ADRs, nearest `AGENTS.md`,
   documentation-impact classification, relevant documentation context, and any
   supplied Feature plan. Product feature diffs do not touch `apps/e2e` or
   `bin/e2e-*`. A harness diff requires a dedicated issue with
   repository-owner-approved behavior and issue-specific proof.
4. **Inspect proof.** For an issue labeled `proof:incus`, verify the retained immutable proof,
   current proof-plan fingerprint, proof-input manifest fingerprint and
   completeness, complete declared action sequence, and zero exit for every
   action. When the proof names an earlier SHA, require an immutable `exact` or
   `equivalent` report bound to the exact remote head, current `origin/main`,
   unchanged proof inputs, and the `retained-proof` promotion path. `stale` or
   `indeterminate` requires complete reproof. Discovery may remain active and
   is not proof evidence. Inspect a harness issue's additional issue-specific
   proof exactly as its approved contract defines it. For automated-only
   changes, require green CI and relevant local checks.
5. **Review the code.** Check correctness, acceptance coverage, regressions,
   repository conventions, documentation reconciliation, and proof
   completeness. Require `composer docs-lint` when documentation is required.
   Collect every blocking finding in one pass. Each finding must cite an issue
   criterion, ADR, existing invariant/test, or repository rule. A new
   requirement is separate work.
6. **Report.** Post all blocking findings with evidence. When none exist, post a
   review whose body is exactly `Approved.` and bind it to the reviewed SHA.

## Rules

- A new commit invalidates approval. It requires a new equivalence decision and
  review pass; it invalidates proof unless recorded inputs remain exact or
  equivalent.
- Do not drip known findings across rounds.
- Do not merge, promote, release a proved topology, or modify the topology snapshot.
