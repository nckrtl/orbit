---
title: "ADR 0175: Keep the docs-impact check in Orbit's repository"
sidebarTitle: "0175 Docs impact in Orbit"
description: "Proposed. Orbit's repository check reports docs impact and enforces a reviewer-confirmed docs-first decision at every task handoff."
---

# ADR 0175: Keep the docs-impact check in Orbit's repository

Orbit groups start with a docs subtask. A deterministic check identifies impacted documentation so that the subtask can update it or provide a reviewer-confirmed `no_docs_change` report before implementation starts.

## Status

Proposed.

This decision sets Orbit repository policy and tooling. It does not add behavior to the generic Gateway Tasks engine. See the [contributor guide](/contributor-guide) for contributor workflow and the [Tasks reference](/reference/tasks) for handoff details.

## Context

A docs-first review has value, but asking an agent to guess whether a code change needs documentation is slow and inconsistent. Reviewers need a repeatable report that identifies affected pages and existing generators, including for a fast, honest no-change outcome.

The Gateway Tasks engine stores groups, subtasks, typed deliverables, and lifecycle state. Each Project supplies a command for its task check. The engine does not interpret that command or know about docs-first, ADRs, Composer, Pest, or which Project is Orbit. Each Project keeps its own task policy in its repository. Orbit's own task check is `composer check`, which runs `bin/review-check`.

## Decision

Orbit implements the docs-impact policy entirely in its repository. The Gateway remains unaware of the policy. Orbit runs enforcement through its own task check.

### Report and ownership

`bin/docs-impact` contains the command-line entry point, with extraction logic in `apps/docs`. It takes a base commit and repeatable planned paths. It combines planned paths, including paths not yet created, with changed paths since the group's start commit (the merge base with `origin/main`) and prints stable JSON.

The extractor reads maintained pages under `docs/reference/`, `docs/cli/`, and `docs/solutions/` for `covers:` ownership declarations. It finds API operations and schemas, CLI signatures and options, configuration and environment keys, error and Doctor codes, migrations, scheduled and Artisan commands, Gateway console command classes, and MCP tools. It maps each surface to its owning documentation page or to a generator that already documents it. Unknown or unowned changed surfaces are errors rather than guessed classifications.

The JSON report includes the base, input paths, impacted pages and reasons, extracted surfaces, generator-handled surfaces, errors, and one verdict: `docs_required` or `no_docs_change`. An impacted page or stale or missing generator output requires `docs_required`. `no_docs_change` means the report found no needed documentation or generator update; the reviewer still confirms the result.

The extractor uses repository-specific parsing and these deterministic surface owners. It matches API changes by complete HTTP method, URI, route-group prefix, and controller action; generated component ownership follows Data-to-schema naming and schema-reference dependencies. CLI extraction reads `protected $signature` from `apps/cli/app/Commands/**/*.php` and considers argument, option, description, and registration changes, but a comment-only edit does not impact the family page. It uses the first command token before arguments and options to select the family page. Gateway command extraction reads class signatures under `apps/gateway/app/Console/Commands/`; names need an explicit owner or a matching `covers:` declaration.

Configuration extraction reads literal `env('NAME')`, top-level config keys, and non-comment `NAME=` entries in `.env.example`, preserving case and source location. Error extraction reads literal identifiers, string constants, and enum cases implementing `DoctorIssueCode`; identifiers match `^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$`. Migration parsing handles `Schema::create`, `Schema::table`, and complete Blueprint chains, including `change()` and `rename*()`. It resolves models by explicit table or Laravel's table convention. Schedule extraction reads command registrations and schedule expressions from Laravel's `routes/console.php`, `bootstrap/app.php`, console kernels, and Gateway's `TaskSchedule` registration class. MCP manifest changes are compared to the selected base by tool `name`, including title and description.

For a changed source file, the extractor parses its current and base contents independently, then reports only surfaces and error identifiers added or removed between those snapshots. It compares identifiers without source line numbers, so moving an unchanged identifier does not create an error. Surface identity normalizes only the source path's line suffix; values such as `dailyAt('02:30')` remain significant. Comment-only edits do not impact command family pages.

| Surface | Signal and owner |
| --- | --- |
| API operations and schemas | Gateway routes, controllers, requests, data, enums, PHP SDK types, and CLI descriptions feed `bin/docs-openapi`. The exact `METHOD /api/v1/{uri}` operation page owns each operation; the generator owns `docs/openapi.json`, API navigation, and generated schemas. |
| CLI signatures and options | Command signature, argument, option, description, and registration changes map to `docs/cli/{family}.mdx`. `bin/cli-contract --changed` checks command fixtures. |
| Configuration and environment keys | Literal `env('NAME')`, top-level config keys, and non-comment `.env.example` entries map to `/reference/environment-variables`; Instance `.env` commands also map to `/cli/env`. |
| Error and Doctor codes | API errors map to operation pages; CLI errors to family pages; Doctor codes to `/cli/doctor`. Extracted but unowned codes require a matching `covers:` page or are errors. |
| Migrations | `Schema::create`, `Schema::table`, and Blueprint changes map through `covers:` or the affected model/resource page. Resolve models by explicit table or Laravel's table convention; unowned migrations are errors. |
| Scheduled, Artisan, and Gateway console commands | CLI names map to family pages; Gateway command classes require an explicit owner or matching `covers:` declaration; schedule registration changes map to `/reference/schedules`. Unknown changed names are errors. |
| MCP tools | Manifest name, title, and description changes map to `/reference/mcp`; `bin/mcp-tools --check` validates the generated manifest. |

The CLI family map is `activity`, `analytics`, `app`, `cluster`, `database`, `dns`, `doctor`, `env`, `extension`, `firewall`, `gateway`, `github`, `instance`, `internal`, `metrics`, `node`, `process`, `profile`, `project`, `proxycli`, `realtime`, `route`, `schedule`, `tasks`, `tool`, and `workspace`. The `internal` family is deliberately non-public and maps to `docs/reference/tasks.md` rather than a public CLI reference page. Each uses its same-named `docs/cli/` page; for example, `instance:dependencies:*` belongs to `docs/cli/instance.mdx`. `bin/api-fixtures --check` validates generated API fixtures.

The extractor reports each surface's source path and line, kind, owner, reason, and generator status. It expands planned directories to files and reads removed public surfaces from the selected base, including symbols removed from retained files. Test paths may still match `covers:` globs, but the extractor does not interpret test source as product behavior; this keeps fixture examples from creating false product surfaces or errors. An extractor error requires `docs_required` when it describes a surface added or removed by the diff; unchanged errors on existing code do not block unrelated work. An API source has no hand-written impact when `bin/docs-openapi`, `bin/api-fixtures --check`, and `bin/mcp-tools --check` all pass; the report removes both its unowned mapping error and impact. A missing CLI family, generator output, unknown changed command, unresolved planned path, or unowned changed migration or error is an extractor error and requires `docs_required`. Generator entries in `surfaces_handled_by_generator` report `passed`, `stale`, `failed`, or `missing`, with a `current` boolean. `bin/docs-openapi`, `bin/api-fixtures --check`, `bin/cli-contract --changed`, and `bin/mcp-tools --check` verify their generated API documents, API fixtures, CLI fixtures, and MCP manifest respectively. A current generator output needs no duplicate hand-written page; stale, failed, or missing output is visible in the report and requires `docs_required` until corrected.

### Coverage declarations

Pages can optionally declare `covers:` globs in their frontmatter. Globs associate repository paths with the pages that explain them. Docs-lint validates that patterns are safe and match tracked paths. A committed allowlist of covered pages is a ratchet: existing entries cannot be removed, and pages on the list cannot drop their declarations. `docs/reference/tasks.md` is the initial fixed entry. A separate effort to consolidate documentation will add declarations to other pages; this decision does not require coverage everywhere.

### Task handoff

At each subtask handoff, Orbit's task check runs `bin/docs-impact` on the candidate diff from the group's start commit. If the diff impacts a page that it does not change, `bin/review-check` fails and lists that page. A reviewer may confirm a waiver only when the branch under check adds the matching `page: reason` line to `docs/.docs-unaffected` against its base commit. A line already present in the base cannot waive the page again. Waivers are branch-scoped, so `main` keeps `docs/.docs-unaffected` empty; feature branches add their own lines when needed. This committed record makes the waiver auditable and does not waive generator checks.

The first subtask in every Orbit group is docs-first, before any implementation subtask. It runs `bin/docs-impact` against the group's start commit and every planned path named in the brief, including paths that do not exist yet. If it finds `docs_required`, it updates the listed pages or runs the named generator. If it finds `no_docs_change`, it submits the complete JSON report as evidence. The reviewer confirms that the report and planned paths are complete before implementation starts. That report is the fast path; the subtask does not need a prose defense of its conclusion. The [Orbit Tasks skill](https://github.com/nckrtl/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) carries this repository policy.

The first version does not use Jev.

## Rejected alternatives

- Put docs-specific decisions in the Gateway: this couples a generic engine to Orbit and its repository conventions.
- Use agent or reviewer judgment without a report: this is not repeatable and makes the no-change path slow.
- List page ownership in every task brief: duplicated lists become stale and do not detect new surfaces.
- Run only existing generators: they do not find all hand-written documentation affected by configuration, migrations, errors, or other surfaces.
- Require coverage declarations on every page now: optional declarations with a monotonic ratchet allow the separate docs-consolidation effort to seed coverage incrementally.

## Consequences

- Each docs subtask can hand off either updated documentation or a machine-produced report for reviewer confirmation.
- The task check repeats the impact analysis at every handoff, so a subsequent subtask cannot silently leave an impacted page unchanged.
- Reviewers confirm exceptions through a committed, page-specific reason.
- Each Project remains responsible for its own policy; Gateway behavior stays generic.

## Affects

- Components: apps/docs
- ADRs: none
- Detail: [Contributor guide](/contributor-guide), [Tasks reference](/reference/tasks), and [Orbit task-creation policy](https://github.com/nckrtl/orbit/blob/main/.agents/skills/creating-tasks/SKILL.md)
- Verify: `bin/docs-impact --base <start-commit> --paths <planned-path>`, `composer docs-lint`, and `composer check`
