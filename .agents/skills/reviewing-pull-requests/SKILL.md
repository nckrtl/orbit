---
name: reviewing-pull-requests
description: Use when independently reviewing one exact Orbit PR head.
---

# Reviewing Pull Requests

Independently review one exact remote PR head against the issue's `Acceptance` checklist, its `Scope`, the attached ADRs, repository invariants, tests, and proof. Do not expand the contract or edit code.

The plan and the retained proof live in the implementer's worktree and are not in Git. This review reads the plan only when that worktree is at hand, and inspects proof on the host that holds it; the pull request body is the carrier of everything else.

## Steps

1. **Bind the candidate.** Record the exact remote PR head SHA. Require a clean local checkout equal to it.
2. **Check current main.** Fetch `origin/main` and confirm the candidate includes it. The reviewer must not merge or rebase `main`. If it does not, stop until the candidate is updated and pushed, then review the new head in a new pass.
3. **Read.** The issue named by the PR body's `Issue:` line, with labels and attachments; the exact diff; every attached ADR's `Decision` bullets; nearest `AGENTS.md`; the plan when its worktree is at hand; and the PR body's per-item evidence, documentation list, and deviations. Product feature diffs do not touch `apps/e2e` or `bin/e2e-*`. A harness diff requires a dedicated issue with the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof.
4. **Inspect proof.** With the `proof:incus` label, run `bin/e2e-topology status <ISSUE>` on the proving host and verify the retained immutable proof: current proof-plan fingerprint, proof-input manifest, one action per Incus-proved `Acceptance` item, and zero exit for every action. When the proof names an earlier SHA, require an immutable `exact` or `equivalent` report from `bin/e2e-topology equivalence <ISSUE>` bound to this head and current `origin/main`; `stale` or `indeterminate` requires complete reproof. Discovery is not proof. For automated-only changes, require green CI and the relevant local checks.
5. **Review against the issue.** Walk the `Acceptance` checklist in order. For each item, confirm the diff implements it and the named proof shows it. Then confirm the diff stays inside `In`, touches nothing named in `Out`, changes only components the issue is labeled with, where pages under `docs/` and files under `proofs/` are not components, violates no attached ADR `Decision` bullet, and changes maintained documentation only for the pages the `docs` label requires and the drift fixes and deviations the PR body lists, with `composer docs-lint` passing. Check correctness, regressions, and repository conventions last.
6. **Report.** Collect every blocking finding in one pass. Each cites an `Acceptance` item, `Scope` bullet, label, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work, not a finding. When nothing blocks, post a review whose body is exactly `Approved.` bound to the reviewed SHA.

## Rules

- A new commit invalidates approval and requires a new pass; it invalidates proof unless recorded inputs stay exact or equivalent.
- Do not drip findings across rounds.
- Do not merge, promote, release a proved topology, or modify the topology snapshot.
