---
title: "ADR 0175: Decide the deterministic docs-impact check"
sidebarTitle: "0175 Deterministic docs impact"
description: "Proposed. Every group uses a deterministic docs-impact report to decide which documentation must change before implementation."
---

# ADR 0175: Decide the deterministic docs-impact check

Every Orbit group runs a deterministic docs-impact check in its docs-first subtask. The check reports public-surface changes, covered documentation pages, generator-owned surfaces, and one of two verdicts: `docs_required` or `no_docs_change`.

## Status

Proposed.

This decision extends the docs-first requirement in the [contributor guide](/contributor-guide) and the task lifecycle in the [Tasks reference](/reference/tasks). It does not replace the review of documentation or the checks for generated surfaces that `bin/docs-openapi`, `bin/api-fixtures`, `bin/mcp-tools`, `bin/cli-contract`, and the Docs project already own.

## Context

Every group starts with a docs subtask, and implementation does not begin until that subtask updates the docs or records an honest, reviewer-confirmed `no_docs_change` result. That review is valuable: across 27 groups, docs-first reviews found 25 real contract defects in 31 findings. It must not be replaced by an agent's guess that a change is unimportant.

The current process also spends too much time on reference-only docs work. Eight groups spent 31.6M tokens and 131 blocked minutes on docs subtasks that only concluded that no page needed changing. Orbit already has deterministic checks for several surfaces, but it does not combine them into a report that a planner, implementer, and reviewer can share.

The check must work before implementation, when a brief has planned paths but no changed files, and again before a pull request opens, against the complete group diff. A generated API or MCP document must remain covered by its generator; a hand-written reference page must remain covered by the code paths it explains.

## Decision

Orbit adds `bin/docs-impact`. It accepts `--base <commit>` to compare the candidate against a group's start commit and repeatable `--paths <path>` values for planned paths that do not exist yet. The report is JSON with stable ordering, so the docs-first subtask can attach it as evidence without asking a model to classify the change.

The extractor takes the union of changed paths from the base comparison and the planned paths. It detects these public surfaces and records the documentation page that owns each one, or the generator that owns it:

| Surface | Deterministic signal | Exact documentation owner or generator |
| --- | --- | --- |
| API operations and schemas | Gateway routes, controllers, requests, data, enums, PHP SDK types, and CLI descriptions feed `bin/docs-openapi`. Match complete URIs, HTTP methods, group prefixes, and controller actions. | The generated operation key is `METHOD /api/v1/{uri}`. Its exact operation page owns it; the generator owns OpenAPI and API navigation. |
| CLI command signatures and options | Parse each `protected $signature` under `apps/cli/app/Commands/**/*.php`. Changes to tokens, arguments, options, descriptions, and registrations count. | The command family owns `docs/cli/{family}.mdx`. Unknown public families are extractor errors. `bin/cli-contract --changed` checks command fixtures. |
| Configuration and environment keys | Parse literal `env('NAME')`, top-level config keys, and non-comment `NAME=` entries in `.env.example` files. Preserve case and report source location. | Every key belongs to `/reference/environment-variables`; Instance `.env` commands also belong to `/cli/env`. |
| Error codes and Doctor issue codes | Extract literal error identifiers, string constants, and enum cases that implement `DoctorIssueCode`. Match identifiers with `^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$`. | API and CLI errors belong to their operation and family pages. Doctor codes belong to `/cli/doctor`. Unowned identifiers require `covers`. |
| Migrations | Parse `Schema::create`, `Schema::table`, and complete Blueprint call chains, including `change()` and `rename*()`. | Pages covering the migration or model resource own the change. Resolve the model by its explicit table or Laravel's table convention. An uncovered migration is unowned. |
| Scheduled and Artisan commands | Parse literal `Schedule::command()` and `Artisan::command()` names and any schedule expression. | Colon commands use their CLI family page. Schedule-domain timing belongs to `/reference/schedules`; known non-CLI names have explicit owners. |
| MCP tools | Compare manifest tool names, titles, and descriptions with the selected base; identify changes by the manifest `name`. | `/reference/mcp` owns tool descriptions. `bin/mcp-tools --check` owns the generated manifest. |

The CLI family map is `activity`, `analytics`, `app`, `cluster`, `database`, `dns`, `doctor`, `env`, `extension`, `firewall`, `gateway`, `github`, `instance`, `metrics`, `node`, `process`, `profile`, `project`, `proxycli`, `realtime`, `route`, `schedule`, `tasks`, `tool`, and `workspace`. Each family owns the same-named page under `docs/cli/`; for example, `instance:dependencies:*` belongs to `docs/cli/instance.mdx`. The known non-CLI commands are `tasks:tick→/reference/tasks`, `annotations:dispatch→/reference/agent-annotation`, and `orbit:activity-finalize-interrupted→/cli/activity`. `bin/api-fixtures --check` validates generated API fixtures.

The extractor uses these rules rather than a generic phrase such as “the owning reference page.” A report lists the exact page path for every owner, the source path and line, and the identifier or glob that selected it. CLI signatures use the first command token before arguments and options to determine the family. A missing family, missing generator output, unknown scheduled command, unresolved planned path, or unowned migration or error is itself `docs_required` and appears in the report's `errors` list. Planned directory paths expand to their files. Removed public surfaces are extracted from the selected base commit for deleted files and for symbols removed from files that remain. Generated API component ownership follows the generator's Data-to-schema naming and schema-reference dependencies.

The extractor reports each surface's path, kind, owner, reason, and generator status. It runs each applicable generator check and reports `passed`, `stale`, `failed`, or `missing`, plus a `current` boolean. A generator-owned surface is not silently ignored: the report says which generator must pass and whether its generated output is current. The report's `surfaces_handled_by_generator` array makes that work visible. A stale or changed generated output is `docs_required` until the generated documentation is updated; a current generator output does not require a second hand-written page. API changes identify the exact `METHOD /api/v1/{uri}` operation pages, not a generic API navigation owner.

Reference, CLI, and solution pages may declare the code they explain in frontmatter:

```yaml
---
title: "Tasks"
covers:
  - "apps/gateway/app/Domain/Tasks/**"
  - "bin/review-check"
---
```

`covers` is a list of repository-relative glob patterns. The docs lint validates that it is a list of non-empty strings, rejects absolute paths before normalizing separators, rejects `..` traversal, and requires every pattern to match at least one tracked file or directory. The linter and impact report use the same normalized safe patterns. It reports the page and invalid pattern on failure. `bin/docs-impact` matches the candidate paths against these declarations and lists every impacted page with the matching pattern as its reason. A page without `covers` remains eligible for surface ownership but is not inferred to cover arbitrary code. An otherwise unowned error identifier may use a matching `covers` page as its owner. The committed allowlist at `apps/docs/config/docs-covers-ratchet.php` records pages that have adopted `covers`; lint compares it with the merge-base branch baseline and fails if an entry is removed, a listed page is missing, or a listed page drops the field. The initial seed `docs/reference/tasks.md` is also a fixed minimum, so editing the allowlist cannot erase the first entry. The list may only grow as other pages adopt coverage during domain documentation work.

The JSON report contains `base`, `paths`, `impacted_pages`, `surfaces`, `surfaces_handled_by_generator`, `errors`, and `verdict`. Each impacted page includes `page` and a sorted `reasons` list. Reasons identify either a surface and its owner or a matching `covers` pattern. Each generator entry includes its status and whether the output is current. `verdict` is `docs_required` when a maintained page must change, a generator check is stale or missing, or an extractor reports an unowned surface; it is `no_docs_change` only when none of those conditions apply.

The docs-first subtask always runs. Before code exists, it runs `bin/docs-impact --base <start-commit> --paths <planned-path>` for the paths in the brief. If the verdict is `docs_required`, the subtask updates the listed pages or runs and commits the named generator. If the verdict is `no_docs_change`, it hands off the JSON report as its evidence; the reviewer confirms that the planned paths are complete and records the report with the approval. This is fast because the review packet carries the machine-generated report and needs no new ADR or prose-only investigation.

Before the pull request opens, the final gate runs the same check against the whole group diff. Approval fails with the complete list of impacted pages and surfaces that the diff did not update. The only exception is an explicit reviewer confirmation in the approval using the exact form `docs-unaffected: <page> — <reason>`. Orbit records those lines and includes them in the pull request description. A confirmation does not suppress a required generator check or permit a stale generated document.

A Jev yes/no question per impacted page may provide a signal about whether the diff changes the behavior that section describes. That signal is recorded under ADR 0173 when that record exists, and never replaces this deterministic verdict. The first version does not run Jev.

## Rejected alternatives

- Agent or reviewer judgment without a report: It repeats the slow, inconsistent `no docs change` decision and can omit important information.
- A list of manually maintained pages in every task brief: It duplicates ownership, goes stale, and cannot detect a new public surface in the diff.
- Only running existing generators: It misses configuration, migrations, schedules, errors, and code paths covered by hand-written pages.
- Making Jev the deciding check: A model can provide useful context but cannot be the deterministic contract or replace reviewer confirmation.

## Consequences

- Every docs-first subtask has a quick, reviewable JSON artifact for both the planned-path and final-diff decisions.
- Public surfaces handled by generators remain visible, while covered hand-written pages are found from their repository paths.
- Documentation owners must keep `covers` frontmatter accurate, and docs lint gains a repository-path validation rule.
- The first version requires the extractor and docs-lint support to be implemented before it can enforce this decision; a Jev signal is an optional optimization outside this version.

## Affects

- Components: apps/docs, apps/cli, apps/gateway, apps/web, packages/php-sdk
- ADRs: ADR 0173 when available; docs-first guidance in the [contributor guide](/contributor-guide)
- Detail: [Tasks reference](/reference/tasks), [Contributor guide](/contributor-guide), and [architecture decisions](/decisions/overview)
- Verify: `bin/docs-impact --base <start-commit> --paths <planned-path>`, `bin/docs-impact --base <start-commit>`, `composer docs-lint`, and `composer docs-build`
