---
name: grill-with-docs
description: Use when shaping an Orbit feature idea through discussion, architectural decisions, and documentation before implementation.
---

# Grill With Docs

Turn a feature idea into agreed behavior, proposed ADRs, and documentation.

Read the request, affected documentation, ADRs, code, and tests. Establish repository facts yourself.

Ask the user about choices that affect the feature. Start with decisions that other choices depend on. Use short rounds, recommend an answer, and explain its main trade-off.

Work through concrete examples. Clarify terms, relationships, ownership, and what happens during normal use, failure, recovery, and removal. Challenge ambiguous language and disagreements with the existing architecture.

As choices settle, update the feature's documentation and draft significant ADR changes in the same branch. Use the [documentation guide](../writing-documentation/SKILL.md) for writing and checks. For CLI behavior, follow the [CLI standard](../../../docs/reference/cli-ux.md).

Finish with the agreed behavior, consistent ADRs and documentation, check results, and any open questions. Keep the summary brief enough for the user to confirm or correct.
