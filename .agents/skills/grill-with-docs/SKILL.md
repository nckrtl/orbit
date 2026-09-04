---
name: grill-with-docs
description: Use only when explicitly asked to shape an Orbit feature through a thorough interview grounded in current docs, ADRs, code, and tests.
---

# Grill With Docs

Run `grilling` with `domain-modeling` as one shaping session before issue creation.

Start from current `origin/main`. Read `docs/concepts.md`, every relevant accepted ADR, maintained pages, current code and tests, and any source issue or report. Establish repository facts yourself. Then work the material decision frontier in rounds, recommend answers, and let the user decide.

Do not confirm the handoff while a material fact or product decision is unresolved. Produce one confirmed shaping handoff in the form required by `grilling`. Create no issue and make no repository or external write. When shaping selects a significant architectural decision, route that chosen decision to `recording-decisions`; dependent issue creation waits until its ADR is accepted on `origin/main`. Otherwise the confirmed handoff is ready for `creating-issues`.
