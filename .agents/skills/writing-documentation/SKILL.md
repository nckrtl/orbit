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

Link to the page that owns an explanation. Use tables for command options, fields, and error codes. Keep change history in Git, and contributor workflow in contributor guidance.

Write each Markdown paragraph on one line for the prose linter. Use root-relative Mintlify links without `.md` or `.mdx`, such as `/reference/apps`. Use GitHub URLs for repository files outside `docs/`.

## Architectural decisions

The documentation is the single source of truth. Follow the [ADR guide](../../../docs/decisions/README.md) and [template](templates/adr.md). A new ADR says `In progress.`, names the mission principle it serves, and states any exception to a principle on its Principle line in Status.

The pull request that completes a decision absorbs it. Write the behavior into the owning page and the lasting reasons and rejected alternatives into that page's "Why it works this way" section. Then delete the ADR, add a redirect from its path to the absorbing section in `docs/docs.json`, add a row to "Retired decisions" in `docs/decisions/overview.mdx`, and point every inbound link at the absorbing section. Never reuse a number.

## Check

Run `composer docs-build` when pages, titles, components, concepts, or ADR links change. Include generated context changes. Run `composer docs-lint` and fix its findings.

For navigation, link, or MDX changes, run `npx mint validate` and `npx mint broken-links` from `docs/`. Return the changed pages or findings and check results.
