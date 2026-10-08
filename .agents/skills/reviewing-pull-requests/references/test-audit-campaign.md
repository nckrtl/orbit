# Whole-project and repository campaigns

Use this workflow when the requested scope is a complete subsystem, project, or repository. A pattern search or sample is not a full sweep.

## Baseline and inventory

Pin the commit and record working-tree changes. Establish test results before cleanup. Record pre-existing failures and environment limits separately. Measure test, support, and production LOC separately and record runtime under comparable conditions. Do not promise a percentage reduction before discovery.

Inspect CI, manifests, test configurations, scripts, and shared fixtures to enumerate the surface. Include PHP projects, web unit and browser tests, annotation, Pi server, Rust agent tests, and tooling when the scope is all Orbit. Discover the current paths rather than relying on this list alone.

Assign every test file and scenario to one feature boundary. Include cross-project tests owned by that feature. Track unassigned files, disabled tests, excluded groups, and separate live scenarios so they cannot disappear from the audit.

## Complete the ledger

Read each test and its datasets in full, then its production owner, callers, history, and overlapping tests. Apply the R/F/C/D/U marks from [the audit procedure](test-audit.md). Store the ledger with task evidence or in a local audit artifact, not as permanent product documentation. Include the source SHA, commands, and limitations so another reviewer can reproduce a finding.

For each boundary, name the retained suite for each contract and explain the distinct risk of any other layer. Only after this pass choose the cleanup batch. Keep uncertain candidates as U. A large count of source matches does not replace declaration-level review.

## Cleanup and preservation review

Apply one coherent batch at a time. Keep changes to shared helpers under one owner. Move needed assertions before removing a redundant layer. Update explicit test paths in CI, architecture gates, and inventories when files move or disappear. Do not remove a gate merely because it catches a failure.

Use Orbit's independent review workflow for cleanup delivery. The reviewer compares deleted assertions and dataset rows with the named retained tests and looks for missing contracts and assertions that pass for the wrong reason. Demonstrate repaired gaps with a caught mutation or a failing historical control. Record the reviewed commit and any Incus evidence and limitations required for the affected behavior.

Treat newly found product bugs as separate findings. If fixing one is in scope, isolate the fix and show a failing control and passing candidate. Refresh discovery after upstream changes; inspect new regressions before resolving conflicts involving deleted tests. Never preserve a deletion automatically when upstream added a distinct contract.

## Completion

A discovery sweep is complete when every in-scope declaration has an evidence-backed mark, all files and scenarios are accounted for, and unresolved findings are explicitly listed. This does not mean all candidates are safe to delete.

A cleanup batch is complete when its retained contracts are verified, required checks pass, and preservation findings are resolved. A campaign is complete only when all planned batches meet that bar or the remaining work is explicitly deferred by the user. Report discovery and implementation progress separately.

Hand off scope, commit, inventory reconciliation, findings by decision, retained false positives, dead-code evidence, baseline and final counts and timings, checks actually executed, review state, and named remaining work. Do not describe an unrun suite or an unaudited project as verified.
