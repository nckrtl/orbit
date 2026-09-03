---
name: creating-issues
description: Use when refining an Orbit request into a Linear issue, or returning an issue to Backlog.
---

# Creating Issues

Refine an approved request or production report into Linear issues that a builder can implement and a reviewer can verify without reading anything else but the linked ADRs. Accepted ADRs own the reasons and the invariants. An issue owns the outcome, the scope boundary, and the acceptance criteria. It never restates a Decision bullet from an ADR.

This skill produces issue contracts only. It does not plan the implementation or assume who implements it.

## Inputs

- The approved request, report, review, or discussion.
- Every accepted ADR under `docs/decisions` that governs the change.
- Current `origin/main`, including product, migration, proof, and harness code the change touches.

Stop when the request changes architecture, ownership, security, or a cross-component contract. That is an ADR first, through `recording-decisions`. An ADR needs no issue of its own.

## Shape

Copy `template.md` beside this skill into the description. Everything else is a Linear field:

| Fact | Where it lives |
|---|---|
| Type | exactly one of the `Feature`, `Improvement`, or `Bug` labels |
| Components | one label per affected component, where a component is one of the five Composer projects: `apps/cli`, `apps/docs`, `apps/e2e`, `apps/gateway`, `packages/php-sdk`; `bin/e2e-*` counts as `apps/e2e`; no component label when only pages under `docs/` or paths outside every component change, such as `bin/`, `.agents/`, `AGENTS.md`, `README.md`, the root `composer.json`, or `.github/` |
| Real-machine proof | the `proof:incus` label |
| Documentation impact | the `docs` label when maintained documentation under `docs/` changes |
| Governing ADRs | one link attachment per ADR, using its canonical `docs/decisions/` URL on `origin/main` |
| Source | a link attachment for a report, review, or discussion; a `related` relation for a Linear issue |
| Order and dependency | `blocks` and `blocked by` relations |
| Grouping | a parent issue with sub-issues |
| Maturity | the `Backlog` or `Todo` status |

The description never mirrors status, relations, labels, or ADR lists. The outcome may link an ADR only when the same ADR is an attachment.

## Write the issue

1. Title the task as an imperative sentence a reader can scan. No domain prefix or sequence number; the parent and relations carry those.
2. Write the outcome as the result a user or operator observes. Link the governing ADR when the outcome follows from one.
3. Write `Scope` as the smallest set of surfaces that change and the adjacent behavior that does not. An `In` bullet names what changes; it never describes how, and it never repeats a criterion.
4. Write `Acceptance` as a checklist. Each item is one observable behavior with one proof action that exists today. `Proof:` names a test file or suite, a command, or an Incus proof action; only the last is an action in `.loop/proof/<ISSUE>.json`. A sentence that fits both Scope and Acceptance is written once, as a criterion. Write every bullet on one line, never hard-wrapped, and put quoted output in backticks.
5. Use the `proof:incus` label when a criterion depends on a real OS, service manager, privilege boundary, network, certificate, filesystem ownership, or multi-node behavior. Omit it for automated-only work.
6. Use the `docs` label when durable behavior, terminology, architecture synthesis, an operational or public contract, or agent context changes. Without the label, the outcome changes no documented behavior; the audit can still fix drift it finds in the issue's scope, listed in the plan or the pull request body.
7. Name `apps/e2e` on a dedicated harness issue, or on an issue whose change under `apps/e2e` stays inside `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`, which are not harness code. Product issues never include harness changes.
8. Keep `Readiness` only in `Backlog`. State the exact missing product decision, ADR, dependency, proof path, component boundary, or acceptance. Delete the section before `Todo`.

## Parents and children

- A parent issue holds the feature outcome and its ordered children. It has no `Acceptance` section and is never implemented directly.
- Only a leaf issue is claimable. An issue with sub-issues is not a task, whatever its status.
- Children carry the labels, relations, and acceptance. Order between children is a `blocks` relation.
- The parent completes when every child is `Done`.

## Split

If independently shippable parts can be implemented, proved, and reviewed separately, they are separate children. In particular, a platform semantic change, a repository-wide sweep, a state migration or rollback design, and broad regression proof are separate unless one explicit shared invariant makes them atomic.

Every issue must be implementable and provable at its position in the relation graph without redesigning its contract. If a criterion requires an excluded component, add the label before `Todo` or create an earlier child.

## Maturity

Use `Backlog` while the contract is incomplete. Use `Todo` only when every governing ADR is accepted on `origin/main`, every criterion is verifiable, every component label is present, every real prerequisite is a relation, and `Readiness` is gone.

A `Todo` issue may be `blocked by` unfinished work; the relation carries that fact. When the prerequisite becomes `Done`, re-read current `origin/main` and revalidate before it is claimed.

An existing issue in an earlier description shape is rewritten to `template.md` with this skill before it is planned. When a plan review returns `BLOCK`, a planner or implementer reports a stop, or `recording-decisions` hands over an accepted ADR that intersects the issue, revalidate the contract against current `origin/main`: return a conflicting or incomplete issue to `Backlog` and write the `Readiness` section that names the gap, and cancel obsolete work only with the repository owner's explicit authority. Correct a label the plan's Documentation section or a pull request body reports as wrong, and create the issues a merged pull request body reports as owners of documentation findings. The issue comes back to `Todo` only through the rules above.

## Complete-set feasibility

When a request needs several issues, refine the whole set against current `main` before saving any of them. Verify that product behavior is decided, including ownership, migration, compatibility, failure, rollback, and removal; that every criterion has one proof action the current machinery can run without mixing product and harness changes; that the relation graph is explicit and acyclic; and that relations encode real prerequisites, not staffing, shared files, or merge-conflict avoidance.

When a product contract and its verifier would otherwise require mutually blocking cutovers, define a compatibility bridge child first, then the product change, then the fallback removal.

## Production reports

Create a `Bug` for a post-release defect in the same shape. The outcome states expected and observed behavior with the deployed commit and environment, `Scope` names the surface, and each `Acceptance` item is the expected behavior with the reproduction as its proof. Attach the evidence as a link. Save it as `Backlog` with a `Readiness` section when the cause or the fix boundary is still unknown.

## Verify

Write the draft to a file and run `composer issue:lint -- <file>` from `apps/docs`, or `composer issue:lint -- --parent <file>` for a parent. It rejects a missing or reordered section, a criterion without a proof action, and the same blocked phrases as an ADR. Fix every finding before saving to Linear. Then confirm, in Linear: one type label, component labels, the `proof:incus` and `docs` labels where they apply, one attachment per governing ADR, relations for every prerequisite, and a status matching maturity.
