---
name: auditing-documentation
description: Use when checking that maintained documentation matches current Orbit behavior, for one issue or for the whole corpus.
---

# Auditing Documentation

Find where maintained pages under `docs/` disagree with current code, tests, accepted ADRs, or each other, and fix or report each finding. The audit does not change product code, tests, ADRs, or Linear.

## Scope

The default scope is one issue. Run `composer docs-context -- --component=<label> --concept=<term>` from the repository root for each of the issue's component labels and the concepts its outcome names, and audit only the pages it returns plus the pages the issue's `docs` label requires you to write.

The whole corpus is the scope only when the caller asks for it, with a phrase such as "audit all documentation". Then audit every page under `docs/` except `decisions/` and `generated/`, in the authority order from `writing-documentation`.

A finding outside the scope is recorded, never fixed in passing.

## Check each page

- **Behavior:** every stated behavior matches the code and tests on the current branch. Read the command, the controller, or the test that proves it; do not trust the page.
- **Decisions:** every statement that follows from an ADR matches the accepted record, and no Decision bullet is restated.
- **Authority:** the page agrees with every page above it in the authority order.
- **Ownership:** each mechanism is stated on exactly one page. A second copy is drift even when both copies agree today.
- **Currency:** no change narration, no issue IDs in body text, no "check the code" caveats.
- **Coverage:** every command, field, failure code, and limit the scope's code exposes is on a page a reader can find from `docs/README.md`.

## Fix or report

Fix a finding when the correct statement is derivable from code, tests, or an accepted ADR, following `writing-documentation`. Report a finding, without fixing it, when the right statement needs a product decision, when it is outside the scope, or when it needs an ADR.

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

Run `composer docs-lint` and `composer docs-build` from the repository root after fixes. The audit is complete when the plan's Documentation section, or the pull request body when no plan exists, lists every fixed page and every reported finding, and each reported finding is a separate Linear issue, or an open question when it is a fact the repository cannot answer.
