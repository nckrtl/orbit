---
name: creating-issues
description: Use when refining an Orbit request into a Linear issue.
---

# Creating Issues

Refine an approved request or production report into one or more verifiable
Linear contracts. Accepted repository ADRs own architectural decisions. Linear
owns requested outcomes, scope, acceptance criteria, relationships, affected
components, and proof requirements.

This skill produces issue contracts only. It does not create an implementation
plan or assume how, when, or by whom the issue will be implemented.

## Issue contract

```text
Status: Todo           # use Backlog when the Readiness condition is unmet

Outcome:

Readiness:             # required when Status is Backlog
- ...

In scope:
- ...

Out of scope:
- ...

Acceptance criteria:
- ...

Components:
- ...

ADR: none

Proof: incus           # only when a real machine is required; omit otherwise

Source: none
```

- Use observable behavior. Each acceptance criterion must have one available
  proof action.
- Name the smallest affected components: `apps/cli`, `apps/gateway`, or
  `packages/php-sdk`.
- Name `apps/e2e` only for a dedicated harness issue. Product feature issues do
  not include harness changes.
- Add `Proof: incus` when acceptance depends on a real OS, service manager,
  privilege boundary, network, certificate, filesystem ownership, or
  multi-node behavior. Omit it for automated-only work.
- Record the source issue, report, review, or discussion when one exists.

## Issue maturity

Use `Backlog` when the request is rough or incomplete. State the exact readiness
condition: missing product decision, architectural direction, dependency,
compatibility boundary, proof path, or acceptance detail.

Use `Todo` when the contract is complete, every governing ADR is accepted on
`origin/main`, every acceptance criterion is verifiable, and every real
prerequisite is explicit. A Todo issue may still depend on unfinished work; the
relationship communicates that fact without weakening the issue contract.

Always set `Backlog` or `Todo` explicitly instead of relying on a configurable
team default.

## ADR boundary

Use `ADR: none` only when no ADR applies. Otherwise link every governing decision
with its canonical `docs/decisions/` URL on `origin/main`.

Stop issue creation when the request changes architecture, ownership, security,
a cross-component contract, or another choice that materially constrains future
work. Draft an ADR as `Proposed`, revise its actual text with the user, and mark
it `Accepted` only after exact-text approval.

Accepted ADRs remain immutable. A later direction becomes a new ADR that names
the decision it extends, amends, or supersedes.

An approved ADR may be committed directly when the user approved its exact
text, the commit contains only that ADR, local `main` matches the current remote
base, and unrelated work is untouched. A pull request remains optional.

## Complete-set feasibility

When a request needs multiple issues, refine the complete set against current
`main` before finalizing it. Inspect relevant product, migration, proof, and
harness code rather than inferring feasibility from prose alone.

Verify that:

- product behavior is decided, including ownership, migration, compatibility,
  failure, rollback, and removal boundaries that affect the outcome;
- every acceptance criterion has one available automated or Incus proof action;
- current proof machinery can express those actions without mixing product and
  harness changes;
- the dependency graph is explicit and acyclic;
- relationships encode real prerequisites, not staffing preference, shared
  files, or possible merge conflicts; and
- each issue can be implemented and proved at its graph position without
  redesigning the request.

When a product contract and its verifier would otherwise require mutually
blocking hard cutovers, define a compatibility bridge first. Follow with the
product change and remove the fallback only after migration.

## Production reports

Create a Linear bug for a post-release defect. Include the deployed commit,
environment, expected and observed behavior, and evidence. Set `Source` to the
original report or GitHub issue.

## Verification

Before saving issues, confirm that every issue has a bounded outcome, explicit
scope, observable criteria, smallest component set, applicable ADR links,
correct proof venue, real relationships, and no unresolved architectural choice.
