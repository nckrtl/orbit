---
name: writing-documentation
description: Use when writing, updating, or auditing Orbit documentation and ADRs.
---

# Writing Documentation

Write or check maintained pages under `docs/` against the feature's intended behavior, ADRs, code, and tests.

## Find and check the pages

Use existing pages and links, or `composer docs-context` filtered by component or concept. Check accuracy, missing coverage, terminology, and consistency. During a review, return findings to the author. For requested edits, fix the affected pages.

## Write

Answer the reader's question early. Use common words, short sentences, clear actors, and the terms in `docs/concepts.md`. Describe the behavior delivered by the branch in the present tense.

| Location | Content |
| --- | --- |
| Core pages | Mission, architecture, concepts, and technical overview |
| `domains/`, `reference/` | User guides, commands, inputs, outputs, errors, and limits |
| `solutions/` | Reusable lessons and their verification |
| `decisions/` | Architectural choices, alternatives, and consequences |

Link to the page that owns an explanation. Use tables for command options, fields, and error codes. Keep change history in ADRs and Git, and contributor workflow in contributor guidance.

Write each Markdown paragraph on one line for the prose linter. Use root-relative Mintlify links without `.md` or `.mdx`, such as `/reference/apps`. Use GitHub URLs for repository files outside `docs/`.

## Architectural decisions

Follow the [ADR guide](../../../docs/decisions/README.md) and [template](templates/adr.md). Explain the choice, alternatives, consequences, and affected behavior. New decisions start as `Proposed.` and ship with the feature PR.

Write a superseding ADR for a substantive change to an accepted decision. Keep its earlier rationale and acceptance history. Wording corrections can update the existing record.

## Check

Run `composer docs-build` when pages, titles, components, concepts, or ADR links change. Include generated context changes. Run `composer docs-lint` and fix its findings.

For navigation, link, or MDX changes, run `npx mint validate` and `npx mint broken-links` from `docs/`. Return the changed pages or findings and check results.
