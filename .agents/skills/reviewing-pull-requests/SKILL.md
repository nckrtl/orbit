---
name: reviewing-pull-requests
description: Use when independently reviewing one exact Orbit PR head.
---

# Reviewing Pull Requests

Independently review one exact remote PR head against the issue's `Acceptance` checklist, its `Scope`, the attached ADRs, repository invariants, tests, and proof. Do not expand the contract or edit code.

The plan, proof plan, and fixtures are tracked under `.loop/` on the first review head. Retained proof state stays on the proving host and is never committed.

The execution mode is `publish` by default, or `handoff` when the caller supplies it. Both modes review and prove the same exact head and produce the same complete formal-review payload. `publish` submits that payload to the pull-request surface. `handoff` must not invoke `gh` or create, edit, comment on, review, approve, or otherwise mutate any GitHub surface; it returns the payload to the caller instead.

## Steps

1. **Bind the candidate.** Record the exact remote PR head SHA. Require a clean local checkout equal to it.
2. **Check current main.** Fetch `origin/main` and confirm the candidate includes it. The reviewer must not merge or rebase `main`. If it does not, stop until the candidate is updated and pushed, then review the new head in a new pass.
3. **Read.** The issue named by the PR body's `Issue:` line, with labels and attachments; the exact diff; every attached ADR's `Decision` bullets; nearest `AGENTS.md`; the tracked `.loop/plan.md`, `.loop/proof/<ISSUE>.json`, and every fixture on the first review head; and the PR body's per-item evidence, documentation list, and deviations. Product feature diffs do not touch harness code: everything under `apps/e2e` and `bin/e2e-*` except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. A harness diff requires a dedicated issue with the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof.
4. **Inspect proof.** With the `proof:incus` label, run `bin/e2e-topology status <ISSUE>` on the proving host and verify the retained immutable proof: current proof-plan fingerprint, proof-input manifest, one action per Incus-proved `Acceptance` item, and zero exit for every action. When the proof names an earlier SHA, require an immutable `exact` or `equivalent` report from `bin/e2e-topology equivalence <ISSUE>` bound to this head and current `origin/main`; `stale` or `indeterminate` requires complete reproof. Discovery is not proof. For automated-only changes, require green CI and the relevant local checks.
5. **Review against the issue.** Walk the `Acceptance` checklist in order. For each item, confirm the diff implements it and the named proof shows it. Then confirm the diff stays inside `In`, touches nothing named in `Out`, changes only components the issue is labeled with, where pages under `docs/` and files under `.loop/proof/` are not components and a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`, violates no attached ADR `Decision` bullet, and changes maintained documentation only for the pages the `docs` label requires and the drift fixes and deviations the PR body lists, with `composer docs-lint` passing. Check correctness, regressions, and repository conventions last.
6. **Publish or hand off the formal review.** Collect every blocking finding in one pass. Each cites an `Acceptance` item, `Scope` bullet, label, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work, not a finding. Bind the payload to the exact reviewed SHA and include the verdict, complete findings, per-acceptance evidence assessment, proof status and binding, checked documentation and deviations, and every limitation. When nothing blocks, the review body is exactly `Approved.`

   In `publish` mode, submit that formal review to the pull-request surface. In caller-supplied `handoff` mode, make no GitHub mutation and return the complete formal-review payload to the caller. After the first approval, review the removal head separately: require its sole parent to be the approved workspace head, require `git diff <approved-sha> <head> --name-status` to contain only deletions below `.loop/`, require no `.loop/` entry at the new head, and require retained proof to be `exact` or `equivalent`. The second `Approved.` is a fresh review bound to the exact removal-head SHA and is published or handed off according to the selected mode.

## Rules

- A new commit invalidates approval and requires a new pass; it invalidates proof unless recorded inputs stay exact or equivalent.
- Do not drip findings across rounds.
- Do not merge, promote, release a proved topology, or modify the topology snapshot.
- Mode selection changes review publication only; it never changes the exact head, review depth, proof checks, verdict, findings, or formal payload.
