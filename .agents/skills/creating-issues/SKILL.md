---
name: creating-issues
description: Use when refining an Orbit request into a Linear issue.
---

# Creating Issues

Refine an approved request or production report into one or more current,
verifiable Linear contracts. Accepted repository ADRs own architectural
decisions. Linear owns requested outcomes, scope, acceptance criteria,
relationships, affected components, proof requirements, and live status.

This skill produces issue contracts only. It does not create an implementation
plan or assume how, when, or by whom the issue will be implemented.

## Issue contract

```text
Outcome:

Readiness:             # Backlog only; omit for Todo
- ...

In scope:
- ...

Out of scope:
- ...

Acceptance criteria:
- ...

Components:
- ...

Documentation: required  # or none: concise rationale

ADR: none

Proof: incus           # only when a real machine is required; omit otherwise

Source: none
```

Set the Linear status field directly to `Backlog` or `Todo`; do not mirror live
status in the description.

- Use observable behavior. Each acceptance criterion must have one available
  proof action.
- component names are repository-owned. Use the smallest affected set:
  `apps/cli`, `apps/docs`, `apps/gateway`, `packages/php-sdk`, or `apps/e2e`.
- Paths under `proofs/` are proof artifacts, not components.
- Name `apps/e2e` only for a dedicated harness issue. Product feature issues do
  not include harness changes.
- Add `Proof: incus` when acceptance depends on a real OS, service manager,
  privilege boundary, network, certificate, filesystem ownership, or
  multi-node behavior. Omit it for automated-only work.
- Record the source issue, report, review, or discussion when one exists.
- Use `Documentation: required` when durable behavior, terminology,
  architecture synthesis, an operational or public contract, agent context, or
  reusable knowledge changes. Otherwise use `none` with a concise rationale.

## Issue maturity

Use `Backlog` when the request is incomplete. State the exact `Readiness`
condition: missing product decision, architecture, dependency, compatibility,
proof path, component boundary, inventory, or acceptance detail.

Use `Todo` only when the contract is current, every governing ADR is accepted on
`origin/main`, every criterion is verifiable, every required component is
allowed, and every real prerequisite and documentation impact is explicit.
Remove `Readiness` before moving the issue to `Todo`; resolved history belongs
in relations, comments, or `Source`, not in the active contract.

A Todo issue may depend on unfinished work; the relationship communicates that
fact without weakening the contract. When the prerequisite becomes `Done`,
re-read current `origin/main` and revalidate the issue before promotion.

## Atomicity and splitting

If independently shippable parts can be implemented, proved, and reviewed
separately, split them into ordered issues. In particular, do not silently
combine a platform semantic change, a repository-wide sweep, state migration or
rollback design, and broad historical regression proof unless one explicit
shared invariant makes the change atomic.

Every issue must be implementable and provable at its graph position without
redesigning its accepted contract. If an acceptance criterion requires changing
an excluded component, authorize and name that component before Todo or create
an earlier prerequisite issue.

## ADR boundary

Use `ADR: none` only when no ADR applies. Otherwise link every governing decision
with its canonical `docs/decisions/` URL on `origin/main`.

Stop issue creation when the request changes architecture, ownership, security,
a cross-component contract, or another choice that materially constrains future
work. Draft an ADR as `Proposed`, revise its actual text with the user, and mark
it `Accepted` only after exact-text approval.

Accepted ADRs remain immutable. A later direction becomes a new ADR that names
the decision it extends, amends, or supersedes.

## Complete-set feasibility

When a request needs multiple issues, refine the complete set against current
`main`. Inspect relevant product, migration, proof, and harness code.

Verify that:

- product behavior is decided, including ownership, migration, compatibility,
  failure, rollback, and removal boundaries;
- every criterion has one available automated or Incus proof action;
- current proof machinery can express those actions without mixing product and
  harness changes;
- the dependency graph is explicit and acyclic;
- relationships encode real prerequisites, not staffing preference, shared
  files, or possible merge conflicts; and
- each issue can be implemented and proved at its graph position.

When a product contract and verifier would otherwise require mutually blocking
hard cutovers, define a compatibility bridge first. Follow with the product
change and remove the fallback only after migration.

## Production reports

Create a Linear bug for a post-release defect. Include deployed commit,
environment, expected and observed behavior, and evidence. Set `Source` to the
original report or GitHub issue.

## Verification

Before saving, confirm bounded outcome, explicit scope, observable criteria,
valid smallest components, applicable ADRs, correct documentation impact, exact
proof venue, real relations, atomicity, and no unresolved product or
architectural choice.
