---
title: "Architecture decisions"
description: "How Orbit proposes, reviews, and accepts architectural decisions with feature pull requests."
---

# Architecture decisions

Architecture decision records explain why Orbit chose a significant product or technical direction. They preserve rationale and alternatives. Code, tests, and user-facing documentation describe the delivered behavior.

## When to write a record

Write an ADR for a cross-component contract, an architecture boundary, a security or ownership model, or an operational choice that is costly to reverse. Tactical implementation choices belong in code and tests.

Read affected decisions before writing documentation and implementing the feature. When changing a decision, explain the departure and its consequences in a new ADR that names the decision it extends, amends, or supersedes. Preserve the original rationale. Editorial corrections can update an accepted record while preserving its decision.

## Propose and accept

Draft an ADR as `Proposed.` on the feature branch. The feature PR contains the proposed decision, implementation, tests, and documentation.

The maintainer reviews the complete feature. Merging the approved PR accepts the exact proposed decision together with its implementation.

For records introduced under this convention, `Proposed.` describes the proposal at submission. A PR approved and merged by the maintainer establishes its acceptance on main; the merged PR records the date and approved revision. Existing `Accepted on YYYY-MM-DD.` records keep their recorded acceptance. Reviewers use the branch and merge history to establish decision status.

A standalone ADR PR is available when agreement would help several dependent changes. It follows the same maintainer review and merge convention.

## Record format

Use the next available four-digit number and a short kebab-case name. Check for a collision before merging concurrent additions.

```text
0001-short-decision-name.md
```

Use the [ADR template](https://github.com/nckrtl/orbit/blob/main/.agents/skills/writing-documentation/templates/adr.md). Records contain `Status`, `Context`, `Decision`, `Rejected alternatives`, `Consequences`, and `Affects`. Historical records before 0020 retain their original structure.

Keep one decision in each record. Explain why it won, the alternatives, and the costs. Link mechanisms such as CLI syntax, field lists, error behavior, and verification procedures from the affected maintained reference page. Run `composer docs-build` when context changes and `composer docs-lint` before submitting.

The [feature delivery reference](/reference/implementation-loop) describes contribution and review. [ADR 0076](/decisions/0076-deliver-features-through-complete-pull-requests) records this decision and the contributor rules it replaces. Historical ADRs remain available as decision history.
