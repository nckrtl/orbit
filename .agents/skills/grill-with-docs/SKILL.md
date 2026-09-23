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

## Split the work into subtasks

When the feature runs as an Orbit task group, give each subtask one concise goal that an implementer finishes and a reviewer verifies in one turn.

- Cover one component or one behavior. Put different projects, such as the Gateway, a Rust service, and the web app, in different subtasks. Keep CI and release work apart from product code.
- Keep the deliverables few. A subtask with more than five deliverables, or a title that needs "and" twice, is too large. Split it.
- Make each subtask testable on its own. Its tests prove its goal, and the branch passes its checks after every subtask.
- Order subtasks by dependency. A later subtask may build on an earlier one, but it never finishes an earlier one's work.
- Name the exact ADR sections and documentation pages that each subtask implements.

For example, an agent with two watchers and a protocol client needs three subtasks, not one: the protocol client with its fake-server tests, the first watcher, then the second watcher.
