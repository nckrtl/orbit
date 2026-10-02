---
title: "Architecture decisions"
description: "How Orbit records a decision while it is being built, and how the decision moves into the documentation once it is built."
---

# Architecture decisions

The documentation is the single source of truth for Orbit's current architecture, components, behavior, and boundaries. An architecture decision record (ADR) holds a decision only while that decision is being built. When the decision is built, the pull request that completes it moves the decision into the documentation and retires the record.

## When to write a record

Write an ADR for a cross-component contract, an architecture boundary, a security or ownership model, or an operational choice that is costly to reverse. Tactical implementation choices belong in code and tests.

Read the [mission](/mission) and the affected documentation pages first. Every ADR names the mission principle that it serves. A decision that needs an exception to a principle states the exception on its Principle line, so that the maintainer can see it.

## Write and review

The docs-first subtask of a task writes the ADR, when one is needed, together with the documentation change. The ADR and the changed pages are the contract for the later subtasks. The [contributor guide](/contributor-guide) describes the docs-first subtask and the docs-impact check.

A new ADR has the status `In progress.` The maintainer reviews it together with the feature. A change to a decision that is still in progress edits that ADR. A change to built behavior edits the documentation directly, and needs a new ADR only when it is itself a significant decision.

## Absorb and retire

The pull request that completes a decision absorbs it into the documentation and retires the ADR in the same change:

1. Write the behavior into the page that owns it.
2. Write the reasons and the rejected alternatives that still matter into that page's "Why it works this way" section.
3. Delete the ADR file and remove it from the Records navigation.
4. Add a redirect from the ADR path to the section that absorbed it.
5. Add a row to "Retired decisions" in the [overview](/decisions/overview). Record the deleted filename slug in the number's list in `apps/docs/config/adr-retired-slugs.php`.
6. Point every inbound link to the absorbing section.

Older records can share a number. Record each retired slug separately under that number, and keep a live record in the legacy allowlist until it retires. Docs-lint checks the redirect against each slug, not against any redirect that shares the number. This keeps a deleted Incus ADR distinct from a live Tasks ADR that used the same number.

Most decisions are built in one task, so their ADR is retired in the same pull request that adds it. An ADR stays on main only while its decision spans more than one pull request. A code comment can keep an ADR number, because the overview resolves every retired number.

## Retired decisions

The [overview](/decisions/overview#retired-decisions) lists all retired decisions. Their old paths redirect to the sections that absorbed them.

| Record | Retired path | Now in |
| --- | --- | --- |
| 0192 | `/decisions/0192-run-task-agents-on-pi-only` | [Tasks: Task agents run on Pi](/reference/tasks#task-agents-run-on-pi) |
| 0192 | `/decisions/0192-stop-a-group-whose-pull-request-ended` | [Tasks: A watched pull request is not the reviewed pull request](/reference/tasks#a-watched-pull-request-is-not-the-reviewed-pull-request) |
| 0193 | `/decisions/0193-run-task-agents-as-a-dedicated-user` | [Pi server: One user for every task agent](/reference/pi-server#one-user-for-every-task-agent); [GitHub App: The checkout cannot inherit the token](/reference/github-app#the-checkout-cannot-inherit-the-token) |

## Record format

Use the next available four-digit number and a short kebab-case name. A number is never reused. Check for a collision before merging concurrent additions.

```text
0001-short-decision-name.md
```

Use the [ADR template](https://github.com/nckrtl/orbit/blob/main/.agents/skills/writing-documentation/templates/adr.md). Keep one decision in each record. Run `composer docs-build` when context changes and `composer docs-lint` before submitting.
