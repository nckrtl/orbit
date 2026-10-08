# Optional test and dead-code audit

Reduce maintenance cost while preserving evidence of current behavior. Optimize for useful regression detection, not deletion counts or a coverage percentage.

Use this procedure for a requested cleanup or suite-wide audit. Ordinary PR reviews use project testing guidance; they do not require a campaign.

## Scope and guidance

Read root and scoped `AGENTS.md`, the project's rule index, matching rules, and test configuration. Follow the [contributor guide](../../../../docs/contributor-guide.md) for checks and delivery. In Laravel projects, read the local `testing-best-practices` skill and the applicable rules, especially `rules/review.md`. Do not copy or edit Boost-generated guidance to install this procedure. The SDK stays framework-neutral.

Honor the user's scope: planning, read-only discovery, or implementation. Existing authorization to remove proven redundant tests is sufficient; do not ask again because generic testing guidance says to obtain approval. A request for findings alone does not authorize cleanup. Do not treat a convention or a difference from Boost's preferred style as a defect.

For PHP work, read [PHP discovery and validation](test-audit-php.md). For a whole-project or repository sweep, also read [Campaigns](test-audit-campaign.md). Discover non-PHP suites from CI and package manifests and apply their own tools and scoped guidance.

For the proposed implementer self-check script, read [Self-check next slice](test-self-check.md). That script is not implemented by this procedure; do not claim automated checks ran.

## Value and retention

For each test, identify the observable behavior or independent contract, a credible regression that fails it, and why another test does not already detect that regression. Read the entire test, datasets, setup, production owner, entry points, callers, relevant history, and overlapping coverage before deciding.

Look for:

- No meaningful assertion, a self-comparison, or an expected value computed by the code under test.
- A fake that supplies the result, ordering, receipt, or state change the production owner should produce.
- A negative test rejected by an unrelated guard before reaching the behavior it names.
- A repeated matrix across layers with no distinct transport, wiring, lifecycle, or security risk.
- Source-string or private-method checks that only freeze an implementation detail.
- Tests of retired contracts, unreachable behavior, and support code or production seams with no remaining purpose.

These are discovery signals, not automatic deletion rules. Retain public API, protocol, configuration, migration, storage, security, platform, packaging, generated-contract, and architecture checks when they independently detect a defect. Exact bytes and call ordering can be observable contracts. Assertion counts omit exception expectations, mock expectations, and architecture assertions; inspect those before calling a test assertion-free.

Keep a boundary check when a lower-level matrix cannot show that the real request invokes the rule. Keep fast local evidence when an Incus scenario runs under different conditions or does not run in the same gate. Treat existing failures as possible product defects. Never remove a failure just to turn the suite green.

## Findings

Write evidence before editing. Use one row per test declaration; split dataset rows when they need different decisions. Mark each row:

| Mark | Decision | Evidence |
| --- | --- | --- |
| R | Retain | Contract and distinct regression caught |
| F | Repair | Contract retained, weakness, and proposed assertion |
| C | Consolidate | Named retained test and cases to move first |
| D | Remove | Remaining proof, or evidence the contract is retired |
| U | Unresolved | Missing evidence and next investigation |

Each cleanup candidate records the exact path and test name, actual failure detected, production owner and non-test callers, overlap, history and original reason, remaining proof, deletion unlocked, risk, and validation command. Distinguish confirmed dead code from suspected code. No internal caller is not proof that a public API is unused. An unresolved row is not ready for deletion.

## Apply and verify

Change one coherent feature boundary at a time. Move distinct cases into the retained suite before deleting duplicates. Remove unused support and production code only when caller and contract analysis supports it. Do not add wrappers, compatibility aliases, or replacement tests merely to preserve obsolete seams.

For a repair or a material consolidation, show that a credible defect makes the retained assertion fail for the intended reason. Use a historical failing revision or a small deliberate mutation in an isolated checkout when practical. Restore the mutation before checks; do not mutate a checkout while tests run. A passing test alone does not prove its assertion is effective.

Run the project's required tests and checks. Confirm actual execution, not just cached TIA results. Before handing off a cleanup, run fresh full suites in affected projects and check cross-project consumers and CI routing. Keep live Incus verification separate and follow [using-incus-topologies](../../using-incus-topologies/SKILL.md) when needed. Do not create or remove fleet resources as a side effect of a read-only audit.

Report findings, retained false positives, baseline and final results, unresolved risks, production versus test/support LOC, measured runtime, and follow-ups. State the audited scope and unexamined scope explicitly. Follow the user's authorization and Orbit's existing review and merge workflow; this procedure does not authorize publication or merging.

## Sources

Adapted for Orbit from OpenClaw's [test-audit workflow](https://github.com/openclaw/openclaw/blob/main/.agents/skills/test-audit/SKILL.md) and [campaign guide](https://github.com/openclaw/openclaw/blob/main/.agents/skills/test-audit/CAMPAIGN.md). Laravel's [Boost testing guidance](https://laravel-news.com/laravel-boost-2-6-0) supplies the complementary test-design rules. Read Orbit's committed guidance for the installed version rather than assuming the article describes current local behavior.
