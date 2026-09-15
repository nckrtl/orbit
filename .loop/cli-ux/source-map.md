# CLI UX recovery source map

Historical root: `/Users/nckrtl/orbit-old`. `source-manifest.json` binds 32 source files by working-file SHA-256 and records old HEAD. The prepared standard is `foundation/docs/reference/cli-ux.md`. This map records provenance and conflict resolution; it does not certify command adoption.

## Generic rules retained

| Old source | Reusable contract | Foundation destination |
| --- | --- | --- |
| `apps/docs/content/ux/commands/README.md` | Separate input and output modes; select the concrete primitive; monotonic progress; accepted and rejected evidence | Input/output modes, prompt selection, progress, verification |
| `inputs/README.md`, `text-prompt.md`, `password-prompt.md` under that corpus | Open input only for open values; finite selectors for existing entities; immediate field validation; no secret echo; cancellation before mutation | Prompt selection and input resolution |
| `inputs/select-prompt.md`, `multi-select-prompt.md` | Closed choice sets; defaults from choices; equivalent noninteractive value input | Prompt selection |
| `inputs/search-prompt.md`, `multi-search-prompt.md`, `suggest-prompt.md` | Read-only cancellation-safe lookup; searchable dynamic sets; suggestions do not constrain an open value | Prompt selection |
| `inputs/confirm-prompt.md` | Resolve subject first; describe target/effect; default-No destructive confirmation; refusal causes no mutation | Consent, within current command contract |
| `lists/table.md` | Laravel Prompts table; short uppercase headings; missing human values use em dash | Results |
| `lists/data-list.md`, `data-table-prompt.md` | Data list means Laravel Prompts datatable; exact columns; stable row keys; `/` search; documented immediate follow-through | Prompt selection and results |
| `lists/property-list.md` | Read-only group heading, item label, indented labeled values; no selection | Results |
| `details/show-detail.md` | Exact connectors, blank lines, title, aligned title-case properties, dim connectors, readable values | Detail tree |
| `progress/progress-tree.md`, `spinner.md`, `README.md` | Immediate progress above one second; tree versus short spinner; canonical dots/colors; 300 ms cadence; label tenses; full-strength terminal footer | Progress and liveness |
| `.agents/skills/command-designer/references/invocation-model.md` | JSON disables prompts; validate before side effects; retry field-local errors; cancellation; machine/human agreement; explicit failure status | Modes, consent, failures |
| `.agents/skills/command-designer/references/terminal-output.md` | Centralize animation and ANSI; keep indicators alive during blocking work; deterministic plain fallback; no redundant intro | Progress and terminal behavior |
| `.agents/skills/command-designer/SKILL.md` and other references | Contract-first design, exact prompt mapping, semantic audit, behavioral tests | `designing-cli-commands` |
| `.agents/skills/cli-output-pty-capture/SKILL.md` and script | Same-runtime PTY evidence, timing, dimensions/decoration, launcher identity, fresh capture after fixes, agent inspection before human review | `verifying-cli-output` and recording tools |
| `.agents/review-personas/cli-command.md` | Review full frames and exact machine structure; reject overflow, duplicate details, false scheduling, stale evidence, or unproved liveness | Verification skill and adoption matrix |
| Old cadence, update renderer, database-read and process-selector tests named in the manifest | Observable accepted/rejected cases, actual selector result, empty states, output shape, cadence and monotonicity | Regression and proof design; old product fixtures are not copied |

## Conflicts resolved or pending

| Conflict | Resolution |
| --- | --- |
| Old progress guide exempts all read/list/show commands; main progress authority requires feedback for slow external reads | Fast reads render directly. Slow reads receive truthful waiting feedback. |
| Old atomic operation helper animates every named remote phase during one blocking request | One opaque request gets one indeterminate operation. Separate phases require actual progress evidence; no invented concurrent work. |
| Old docs require durable WebSocket progress and `--stream-json` | Preserve current typed HTTP/SDK and existing stream contracts. Current deploy/rollback `--json` is NDJSON. No added stream option or transport. |
| Old JSON uses `success.data/meta` and `error.meta` | Preserve current command DTOs and error schema, request IDs, redaction, and null/omission semantics. |
| Old input rule prompts for every missing field | Preserve current explicit-only targeting and supported prompt paths. Within those paths, select the correct generic primitive. |
| Old destructive rule treats `--force` as universal consent | Approved: preserve existing force/override semantics and add separate `--yes` where independent destructive consent is missing. |
| Old default-No rule versus current ownership-transfer default Yes | Approved: ownership transfer defaults to No and requires explicit consent in automation. |
| Old `app:list` drill-down example versus current read-only list | Restore datatable where selection/follow-through is part of the current contract; do not add list interactions from an obsolete product example. |
| Old Doctor repair, adoption, persisted runs, family progress and recovery hints | Current verify-only synchronous Doctor contract governs. Show truthful waiting and bounded reports; no repair or invented progress. |
| Old examples use removed Workspace/Instance surfaces, default targets, role permissions, canonical host fixtures and helper paths | Excluded. Current registry, accepted ADRs, domain references and supported harness supply these. |
| Old tree example shows pending/running steps with a success footer | Illustrative anatomy is not a valid state trace. Completion footers appear only after the outcome is known. |
| Old recorder calls chunks “frames,” may split Unicode, lacks live input forwarding, and can consume exit status during timeout | Replacement captures raw bytes, incrementally decodes, reconstructs styled frames, forwards input, drains output, and separates command and recorder status. |
| Old recorder cadence evidence and initial prototype miss frozen active tails | Verifier requires observed terminal state, actual glyph transitions and a bounded trailing active interval. Independent reviewer confirmed fresh accepted/rejected fixtures. |

## Current validation

The recorder and analyzer have 23 passing behavioral tests. Independent forward review is retained at `/tmp/cli-pty-recheck.LwA88Y/review.md`, with hashes and fresh reproduction artifacts. This validates tooling only. No Orbit command migration, Solo/Incus command proof, installed foundation, or closeout is claimed.
