---
title: "ADR 0180: Absorb built decisions into the documentation"
sidebarTitle: "0180 Absorb built decisions"
description: "In progress. The documentation is the single source of truth. An ADR lives only while its decision is being built, and the pull request that completes it retires the ADR into the documentation."
---

# ADR 0180: Absorb built decisions into the documentation

The documentation is the single source of truth for Orbit's current architecture, components, behavior, and boundaries. An ADR holds a decision only while the decision is being built. The pull request that completes the decision writes it into the owning documentation page and retires the ADR.

## Status

In progress.

Principle: this decision serves [the documentation describes the present](/mission#principles) and [lean](/mission#principles). It also makes [no exceptions and no legacy](/mission#principles) visible, because every ADR must state any exception it needs.

This record stays until the docs-lint rule in the Verify line lands. The pull request that adds that rule absorbs this record into the [decisions overview](/decisions/overview) and retires it.

## Context

Orbit kept every ADR forever. By 2026-09-27 there were 169 records, and most had been amended several times. The current rule for a feature was spread across a chain of records, and the reference pages linked into that chain instead of stating the rule. Agents and the maintainer had to read the history to learn the present. Many records that said `Proposed.` were built and merged, so a record's status did not tell a reader whether the decision applied.

A consolidation on 2026-09-27 moved the built decisions of every domain into the documentation and retired 131 records. Without a rule, new records would grow the same history again.

## Decision

The documentation is the single source of truth. Each domain page states the current behavior, and a "Why it works this way" section keeps the reasons and rejected alternatives that still matter.

An ADR lives on main only while its decision is being built:

- The docs-first subtask of a task group writes the ADR, when one is needed, together with the documentation change. A new ADR has the status `In progress.`
- The pull request that completes the decision absorbs it. It writes the behavior into the owning page and the lasting reasons into that page's "Why it works this way" section. It then deletes the ADR, adds a redirect from the ADR path to the absorbing section, adds a row to "Retired decisions", and points every inbound link at the absorbing section.
- A decision built in one pull request never stays on main. An ADR stays on main only while its decision spans more than one pull request.
- A number is never reused. The "Retired decisions" table and the redirects resolve every retired number, so code comments and old links keep working.

Every ADR names the mission principle that it serves. An ADR that needs an exception to a principle names the principle and states the exception and its reason on the Principle line of its Status section.

The docs-impact check and `covers:` frontmatter from [ADR 0175](/decisions/0175-docs-impact-check-in-orbit-repo) keep the documentation in step with the code between decisions.

## Rejected alternatives

- Keep every ADR as permanent history: the current rule is spread across amendment chains, and Git history already keeps every past record.
- Mark ADRs `Superseded` and keep them in place: the Records list keeps growing, and a reader still has to follow the chain to find the present rule.
- Retire ADRs in a periodic cleanup: the documentation stays behind the code between cleanups, and each cleanup is a large rewrite.

## Consequences

- The Records list stays short and holds only decisions in flight. The documentation is complete without it.
- A pull request that completes a decision does more documentation work, and the reviewer checks the absorbed section and the redirect.
- The reasons for a decision survive only where the owning page's "Why it works this way" section keeps them. Git history keeps the full record.
- Records written before this decision still say `Proposed.` or `Accepted.` The docs-lint rule allows those until their domain is consolidated, and that allowance shrinks to zero.

## Affects

- Components: apps/docs
- ADRs: extends [ADR 0175](/decisions/0175-docs-impact-check-in-orbit-repo)
- Detail: [Architecture decisions](/decisions/overview)
- Verify: a docs-lint rule that fails when a retired number still has a file, when a retired record has no redirect, or when a record on main does not say `In progress.`, with an allowance that only shrinks for records written before this decision
