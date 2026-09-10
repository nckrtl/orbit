---
name: reviewing-pull-requests
description: Use when independently reviewing one exact Orbit PR head.
---

# Reviewing Pull Requests

Independently review one exact remote PR head against the issue's `Acceptance` checklist, its `Scope`, the attached ADRs, repository invariants, tests, and proof. Return the complete formal review to the external orchestrator. Do not expand the contract or edit code.

The plan, proof plan, and fixtures are versioned on the PR's candidate-bound artifact ref. Fetch it with `bin/loop-artifacts fetch <ISSUE> --candidate=<head>`, verify its SHA against the handoff, and inspect `.loop/` with `git show <artifact-sha>:<path>`. Require its only parent to be the candidate and its diff to add only `.loop/` paths. The candidate must carry no `.loop/` entries. Retained proof state stays on the proving host and is never committed.

The external orchestrator owns review publication and every other pull-request mutation. The reviewer must not invoke `gh` or create, edit, comment on, review, approve, merge, or otherwise mutate any GitHub surface.

## Delivery flow

Run `bin/loop-flow status` in the issue worktree and read [Implementation loop](../../../docs/reference/implementation-loop.md). Name the selected `discovery` or `proof` flow in every handoff. The separately published `.loop/flow.json` binds the choice to the candidate; a missing selection defaults to `discovery`. Proof is opt-in: select `proof` explicitly before planning or reviewing. A repository-default change does not change an existing worktree.

## Steps

1. **Bind the candidate.** Record the exact remote PR head SHA. Require a clean local checkout equal to it.
2. **Check current main (proof flow only).** In `discovery`, skip this step: main movement does not invalidate review or approval. Fetch `origin/main`, record its SHA, and confirm the candidate includes it. The reviewer must not merge or rebase `main`. If it does not, stop until the candidate is updated and pushed, then review the new head in a new pass. If main advances during review, withhold approval but return any completed assessment bound to the reviewed head and included main; mark incomplete items explicitly. Base freshness alone does not erase completed review work.
3. **Read.** The issue named by the PR body's `Issue:` line, with labels and attachments; the exact diff; every attached ADR's `Decision` bullets; nearest `AGENTS.md`; `.loop/plan.md` and `.loop/flow.json` from the bound artifact commit, plus `.loop/proof/<ISSUE>.json` and every fixture when the proof flow requires them; and the PR body's per-item evidence, documentation list, and deviations. Product feature diffs do not touch harness code: everything under `apps/e2e` and `bin/e2e-*` except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`. A harness diff requires a dedicated issue with the `apps/e2e` label, repository-owner-approved behavior, and issue-specific proof.
4. **Inspect evidence.** In `discovery`, inspect per-acceptance tests, CI, and discovery observations; discovery is a development tool and is not immutable proof. Do not run proof, equivalence, candidate convergence, main-freshness checks, or snapshot operations. In `proof` with the `proof:incus` label, run `bin/e2e-topology status <ISSUE>` on the proving host and verify the captured immutable proof, even after its VMs are released: current proof-plan fingerprint, proof-input manifest, one action per Incus-proved `Acceptance` item, and zero exit for every action. Verify the observed-input choice and any opt-out reason; absent observations require the broad static policy, not inferred non-overlap. When the proof names an earlier SHA, require an immutable `exact` or `equivalent` report from `bin/e2e-topology equivalence <ISSUE>` bound to this head and current `origin/main`; `stale` or `indeterminate` requires complete reproof. On `promotion_path: candidate-convergence`, also require a successful immutable candidate result bound to this head, included main, and equivalence fingerprint, with zero-exit convergence and verification evidence. Discovery is not proof. For automated-only changes, require green CI and the relevant local checks.
5. **Review against the issue.** In `proof`, use the main-delta mode below only when its prerequisites hold; otherwise perform a full review. Walk the `Acceptance` checklist in order. For each item, confirm the diff implements it and the named proof shows it. Then confirm the diff stays inside `In`, touches nothing named in `Out`, changes only components the issue is labeled with, where pages under `docs/` and files under `.loop/proof/` are not components and a path outside every component, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/`, needs no label and is bounded by `Scope`, and `bin/e2e-*` counts as `apps/e2e`, violates no attached ADR `Decision` bullet, and changes maintained documentation only for the pages the `docs` label requires and the drift fixes and deviations the PR body lists, with `composer docs-lint` passing. Check correctness, regressions, and repository conventions last.
6. **Return the formal review.** Collect every blocking finding in one pass. Each cites an `Acceptance` item, `Scope` bullet, label, ADR bullet, invariant, test, or repository rule. A new requirement is separate Linear work, not a finding. Bind the payload to the exact reviewed SHA and include the verdict, complete findings, per-acceptance evidence assessment, proof status and binding, checked documentation and deviations, and every limitation. When nothing blocks, the review body is exactly `Approved.` The external orchestrator publishes the returned payload.


## Discovery conflict corrections

After actual merge conflicts, inspect the updated candidate, published artifacts, conflict-resolution diff, and affected tests with green CI on that head. Reuse unchanged acceptance assessment from the prior review when its contract and evidence still apply; review affected behavior again. Do not restart preflight or add proof checks. Bind the returned approval to the new head. Immediately before approval, recheck the remote candidate head; do not withhold discovery approval because main advanced.

## Main-delta review

This section applies only to `proof`.

A fresh independent reviewer may reuse a completed acceptance assessment, never its approval. Require the complete prior independent review artifact bound to workspace head `R` and included main `B`: either an approval or a completed assessment whose only blocker was main freshness. Require the same issue contract, labels, governing ADRs, and acceptance/proof requirements. An incomplete assessment, unresolved substantive finding, changed contract, or missing binding requires full review.

For the narrow main-delta mode, let `M` be current `origin/main` and `C` the new workspace head. Require `B` to be an ancestor of both `R` and `M`, and `C` to have exactly two parents in order: `R`, `M`. Require `git merge-tree --write-tree R M` to exit zero and its tree to equal `C^{tree}`. This excludes extra edits and manual conflict resolutions; a clean merge alone does not establish proof equivalence. A rebase or another commit shape uses full review.

Perform steps 1–4 and current-head CI checks afresh. With Incus proof, require `exact` or `equivalent` evidence for `C` and `M`, including candidate convergence when selected. Inspect `git diff R C`, incoming main commits `B..M`, and the resulting feature diff `M...C` for interactions and regressions, including changes to documentation, tests, and instructions. Reassess every acceptance item affected by the incoming changes; if the impact cannot be bounded, use full review. Do not infer review equivalence from runtime proof equivalence.

Return the complete per-acceptance assessment, marking which items were reassessed and which inherit evidence from the identified prior artifact. Record `R`, `B`, `M`, `C`, the merge-tree check, evidence fingerprints, review scope, and limitations in the handoff. Recheck current remote head and main before returning approval. If either moved, withhold approval and preserve the completed assessment. A new `Approved.` review is always bound to `C`; neither an old approval nor an inherited assessment authorizes its merge.

## Rules

- A new commit invalidates approval and requires a new pass, scoped to corrections when possible. In `proof`, it invalidates proof unless recorded inputs stay exact or equivalent. Main movement alone does not invalidate discovery approval.
- Do not drip findings across rounds.
- Do not merge, promote, release a proved topology, or modify the topology snapshot.
- The reviewer never publishes its own review; the external orchestrator owns that mutation.
