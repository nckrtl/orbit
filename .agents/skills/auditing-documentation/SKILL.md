---
name: auditing-documentation
description: Use when checking that maintained documentation matches current Orbit behavior, for one issue or for the whole corpus.
---

# Auditing Documentation

Find where maintained pages under `docs/` disagree with current code, tests, accepted ADRs, or each other, and fix or report each finding. The audit does not change product code, tests, ADRs, or Linear; it names the owner of what it cannot fix.

## Scope

The default scope is one issue. Run `composer docs-context -- --component=<label> --concept=<term>` from the repository root with every component label of the issue and every `concepts.md` term its outcome and `Scope` bullets name, and audit only the pages it returns plus the pages the issue's `docs` label requires you to write. An issue with no component label and no `concepts.md` term skips the command, and a non-zero exit means the index matched nothing; in both cases the scope is only the pages the `docs` label requires and the `concepts.md` entries for the issue's terms. The command is never run without a filter, because an unfiltered run returns the whole corpus.

The whole corpus is the scope only when the caller asks for it, with a phrase such as "audit all documentation". Then audit every page under `docs/` except `decisions/` and `generated/`, in the authority order from `writing-documentation`. Fixes from a whole-corpus audit ship through a `docs`-labeled `Improvement` issue with no component label, whose one `Acceptance` item is the audit report with `Proof: composer docs-lint`. Create that issue and its worktree first and run the audit there, committing the fixes as `docs:` commits on its branch, so the fixes are the issue's own change.

A finding outside the scope is recorded, never fixed in passing.

## Reference

The reference for a page is the code and tests on the current branch. Inside the planner's preflight the reference is the code plus the current issue and its ADRs: a statement that matches an `Acceptance` item of the current issue is not drift, and drift is a statement that matches neither the code nor the issue.

## Check each page

- **Behavior:** every stated behavior matches the reference. Read the command, the controller, or the test that proves it; do not trust the page.
- **Decisions:** every statement that follows from an ADR matches the accepted record, and no Decision bullet is restated outside a `concepts.md` entry.
- **Authority:** the page agrees with every page above it in the authority order.
- **Ownership:** each mechanism is stated on exactly one page. A second copy is drift even when both copies agree today.
- **Currency:** no change narration, no issue IDs in body text except as part of a `proofs/<ID>/` fixture path, no "check the code" caveats.
- **Coverage:** every command, field, failure code, and limit the scope's shipped code exposes is on a page a reader can find from `docs/README.md`.

## Fix or report

Fix a finding when the correct statement is derivable from code, tests, or an accepted ADR, following `writing-documentation`. Report a finding, without fixing it, when the right statement needs a product decision, when it is outside the scope, or when it needs an ADR. A reported finding names its owner: a Linear issue, an ADR through `recording-decisions`, or a product decision for the repository owner. A caller that may change Linear creates the issue through `creating-issues` at once; the planner may not, so it lists the finding in the plan's Documentation section, the implementer carries it into the pull request body, and `creating-issues` creates the issue from the merged pull request body.

Report in this shape:

```markdown
## Documentation audit

Scope: <issue id and pages, or "whole corpus">

Fixed:
- <page>: <what was wrong> → <what it states now>

Reported:
- <page>: <what is wrong>, <why it is not fixed here>, <owner: issue, ADR, or product decision>

Verification: `composer docs-lint` <passed | findings>
```

## Verify

Run `composer docs-build` and then `composer docs-lint` from the repository root after fixes. The audit is complete when the plan's Documentation section, or the pull request body when no plan exists, lists every fixed page and every reported finding with its owner.
