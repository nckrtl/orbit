---
name: writing-documentation
description: Use when creating or changing a maintained page under docs/.
---

# Writing Documentation

Write or change one maintained page under `docs/` so that a reader learns current Orbit behavior from it without opening code, an ADR, or Git history. Pages are read first by people and then by agents that plan, implement, and review issues.

Accepted ADRs own decisions and their reasons. Code and tests own behavior. A page describes the behavior that exists on the branch it ships with, in the present tense, once.

## Authority

When pages disagree, the earlier page in this order is right and the later one is drift: `mission.md`, `architecture.md`, `concepts.md`, `tech-stack.md`, pages under `reference/`, pages under `solutions/`. An accepted ADR outranks every page for the decision it records, and no page restates a Decision bullet; it links the ADR.

## Page kinds

| Kind | Lives in | Holds | Never holds |
|---|---|---|---|
| Core | `mission.md`, `architecture.md`, `tech-stack.md` | reader-first prose and links to the page that owns each detail | a fact another page owns |
| Concept | `concepts.md` | one term, one self-sufficient definition | a definition that only works after following a link |
| Reference | `reference/<topic>.md` | the contract: what a command or role does, its inputs, outputs, failure codes, and limits, as tables where a list of options exists | rationale, rejected alternatives, history, issue IDs, class names |
| Solution | `solutions/<slug>.md` | problem, cause, solution, limits, verification, for a lesson that recurs | the story of one issue or pull request |

A domain page under `domains/` exists only when a feature needs a guide that no reference page can hold. A per-command page, a per-domain concept file, and a decisions ledger outside `docs/decisions` never exist.

## Write

1. Name the reader and the question the page answers in its first paragraph.
2. State behavior as `<actor> <verb> <observable result>`. The Gateway refuses, the CLI sends, Doctor reports. Never "is refused" without the actor.
3. Use the term from `concepts.md` and its capitalization: Node, Cluster, App, AppInstance, Route, Router, Ingress, Gateway, Doctor. Expand an acronym on first use in the page.
4. Put a list of commands, fields, options, or failure codes in a table. Open every section with one sentence of prose before a table or list.
5. Keep one mechanism in one place. If a fact already lives on another page or in an ADR, link it.
6. Remove change narration. A page never says what was, what is no longer, or what comes later. History is in ADRs and Git.
7. Write markdown paragraphs as one line each. Hard wraps break the prose rules' sentence measurement.

## Verify

Run `composer docs-lint` from the repository root and fix every finding. The prose rules, `orbit.docs_narrative`, and the link rules apply to every page. Run `composer docs-build` when a page was added or its title, components, or concepts changed, and commit `docs/generated/context.json`. A lint rule enforces a corpus-wide writing invariant and never a product fact; product facts are tested in the product.
