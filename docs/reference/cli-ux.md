# CLI design standard

This page tells authors and reviewers of commands in `apps/cli` how Orbit collects input and presents results. It defines the shared design standard. A command's adoption record states which requirements have been implemented and verified; publication of this standard does not establish compliance.

## Scope and authority

The current command contract supplies the command name, permitted selectors, required inputs, defaults, response schema, error codes, exit statuses, and supported modes. This standard supplies the interaction and presentation rules, including explicit destructive consent. A documented exception names its governing contract and verification. An implementation gap is not an exception.

Command vocabulary follows [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk). Product boundaries remain in their owning references and ADRs. A rendering change does not introduce a target fallback, a permission, a new transport, or a new public option.

## Input and output modes

Input mode determines whether prompts are available. Output mode determines how results are presented.

| Invocation | Input mode | Output |
| --- | --- | --- |
| Interactive terminal without a machine-output option | Interactive | Human |
| Noninteractive input or an explicit no-interaction option | Noninteractive | Human unless a machine mode is selected |
| JSON mode in any terminal context | Noninteractive | The command's existing JSON contract |
| A documented machine stream mode | Noninteractive | The command's existing frame contract |

Machine output contains no prompt, human heading, progress animation, or ANSI escape sequence. A command that already uses JSON lines keeps that contract. A final JSON command does not gain progress frames merely because it can take time. Human and machine output describe the same result, subject to documented redaction and human-only display limits.

Public product commands that return data support `--json`. Their existing final-response or streaming schema governs that mode. Framework and internal commands retain their separately documented output contracts.

Resolve and validate required fields before mutations. Validate a supplied value when it is read, and validate a prompted value when the user submits it. If a prompted value is invalid, ask again without an arbitrary retry cap. Stop on cancellation or end of input, using the command's failure status, before mutation begins.

In a terminal, a command prompts for every required input the caller omitted, and it offers a default where one exists, such as `root` for a bootstrap user or the App default branch. A destructive target is selected through the interactive data list, never inferred. A noninteractive or machine call refuses an omitted required input with the command's documented error code. Read-only lookups needed to resolve a prompt may run before consent. A slow lookup needs visible waiting feedback.

## Prompt selection

Choose a prompt by the values the command admits.

| Input | Primitive |
| --- | --- |
| Open value such as a new name, path, or hostname | Text |
| Open multiline value admitted by the command | Textarea |
| Secret input | Password with input echo disabled |
| Boolean choice | Confirmation |
| One value from a small closed scalar enum | Select |
| Several values from a small closed scalar enum | Multiselect |
| One choice from a dynamic searchable set | Search |
| Several choices from a dynamic searchable set | Multisearch |
| Open value with optional suggestions | Suggest |
| One existing registry entity shown in several columns | Interactive data list |

Use Laravel Prompts for prompts and tables. An interactive data list means its columnar datatable primitive, with stable selector keys, row highlighting, keyboard selection, and a `Press / to search` hint. Display columns never become identity. A grouped property display is not an interactive data list. Do not substitute the console framework's question or table helpers for these primitives.

Use a finite selector for an existing registry entity; an open text or numeric prompt must not replace it. Select and multiselect defaults belong to the admitted choices. Suggest accepts a valid value outside its suggestions. Multi-value prompts use the command's documented multi-value argument or option in noninteractive mode. An empty candidate set produces an explicit empty result or selection failure; it never invents a default target.

Search callbacks are read-only. A noninteractive caller supplies the same value through the documented argument or option. Input secrets are not echoed or logged. Only an explicit current credential-delivery contract may return secret values. Verify those commands with disposable credentials and keep raw artifacts private; remove credentials from shared report excerpts. For each prompt, the command contract states its ID, label, source argument or option, choices, default, validation timing, cancellation, and follow-up behavior.

## Consent

Resolve the subject before asking for destructive consent. State the target and the effect in the prompt. Wrap the full question at narrow widths; never truncate the target or effect to fit a confirmation label. Destructive confirmations and source-ownership transfers default to No. Declining or cancelling begins no mutation.

Keep an existing option that already supplies explicit consent. When a destructive command has no independent consent option, use `--yes` for automated consent and a default-No prompt for interactive consent. Noninteractive and machine modes require that explicit consent option; they never imply consent.

A force option may permit a separate destructive override, such as discarding source changes; preserve that meaning and do not reinterpret it as generic confirmation. That override still requires independent consent. A command may offer a dry run only when it defines a preview without side effects. Command-specific contracts state the consent option, refusal and cancellation failures, and the independent override conditions.

## Results

Select the display from the task the user is performing.

| Result | Display |
| --- | --- |
| Comparable read-only records | Table |
| Row selection with a documented follow-up | Interactive data list |
| Grouped read-only items with several properties | Property list |
| One entity | Detail tree |
| Multi-step or slow structured operation | Progress tree |
| Short wait without meaningful substeps | Spinner |
| Line-oriented logs or another continuous text output | The stream itself |

A list does not become interactive unless its command contract defines a selection and follow-up action. Tables use short uppercase headers. Data lists retain the exact documented column names. Empty lists state that no matching records were found. Missing display values use an em dash; JSON keeps its documented null or omission behavior. A property list uses a group heading, a primary item label, and indented labeled values.

When a human field represents a user and host together, display `user@host`; retain separate machine fields. Display a top-level domain with its leading dot. These formatting rules do not change accepted selectors or serialized values.

Do not dump nested JSON into human output as a replacement for a documented renderer. Avoid introductory lines that repeat the table or tree heading. Errors identify the failed field or operation in clear prose, preserve request correlation where provided, and give recovery guidance only when it is valid for the current product.

## Detail tree

A detail tree uses the following shape, with one empty terminal line before and after it.

```text

┌  Resource: example
│
├  Name     example
│
├  Status   ready
│
└  Owner    —

```

The title uses the singular human entity label and its selector. Align property values, use title-case labels, and separate properties with a blank continuation row. The last property uses the closing connector and has no continuation row after it. Dim the connectors when decoration is enabled; keep the title, labels, and values at normal intensity. Each value is one concise summary that may wrap within the tree at narrow widths; lists use comma-separated values. Do not add nested section headings or a `Showing ...` introduction. A command may add a separate related-record table when its contract calls for one.

## Progress and liveness

Show feedback before slow work starts. A human command that can take longer than one second uses a progress tree, except when its primary output is a log stream or its current contract defines a bespoke panel. A spinner serves a sub-second wait or an inner wait whose surrounding tree already supplies the context.

After input resolution and consent, render the applicable progress structure before mutation. Show the known steps; reveal conditional steps only when their branch is selected. A fast read may render its result directly. A read that can wait on slow external work needs waiting feedback, even though it does not mutate state.

| State | Glyph | Treatment |
| --- | --- | --- |
| Waiting | ○ | Dim glyph and label |
| Running | Alternating ○ and ◉ | Cyan glyph; full-strength label |
| Success | ● | Green glyph; full-strength label and result |
| Failure | ● | Red glyph; full-strength label and red failure message |
| Warning or skipped | ● | Orange glyph; full-strength label; dim secondary explanation |

Active indicators alternate about every 300 milliseconds and continue while a request blocks or between stream events. The pending footer is dim `Working...`. Active and completed labels are never dim. A terminal success footer names the outcome at full strength; a failure footer is red. Final footers are never dim. Plain output retains a readable outcome without color or cursor movement.

The tree has one title, a blank continuation row, one row for each known step with continuation rows between them, and a closing footer. Initial steps are waiting. Only admitted active work animates. Shared renderers own glyphs, color, spacing, repainting, and terminal cleanup so command families do not develop separate visual conventions.

Use operator-facing labels. Where steps have distinct states, use imperative, active, and completed forms such as Resolve resource, Resolving resource, and Resolved resource. Do not expose storage mechanics as progress labels.

Progress reflects evidence. Running work does not return to waiting, and terminal work does not become active again. Do not mark work active before it is admitted for execution. Stop sequential work at a failure. For one opaque request, show an indeterminate operation instead of inventing completed internal phases. Percentages require real completed and total units. A completed transport exchange does not by itself establish a successful product result.

Logs are their own output and do not need an enclosing progress tree. A bespoke panel may reuse the same state vocabulary when its current command requires a panel; it must not invent new backend progress or product operations.

## Failures and process status

A successful command returns zero. A handled command failure returns the current command's nonzero status; success-with-warning is successful only when the command contract defines that outcome. Keep parser usage errors distinct from handled product failures. Do not introduce new numeric error classes or rename stable error codes as a rendering change.

Preserve each command's documented stdout and stderr channels. Machine output must remain parseable on its result channel, including failures and stream termination. Human diagnostics and progress must not leak into that channel. Check the two streams separately with redirection; a combined PTY transcript cannot prove channel placement.

Partial work reports the verified outcome and remaining failure. A request that fails after mutation must not claim rollback unless rollback was verified. Offer a next command only when the current product supports that recovery. Prompt cancellation before mutation and interruption after work starts are distinct cases; the latter requires checking actual resulting state.

## Terminal behavior

Decorated terminals may repaint active output in place. Restore cursor visibility and terminal settings on success, failure, cancellation, and timeout. Handle interruption while an HTTP response is pending, without waiting for the remote operation to finish. SIGINT and SIGTERM remain cancellation (exit 128 plus the signal) even when a request-ID or transport layer wraps the throw. A client interruption does not establish that the server stopped or rolled back. Piped and undecorated output has no escape codes or repeated animation frames; emit readable settled results. Keep color selection separate from input availability and live terminal capability.

When stdin is a pipe, measure the selected output's terminal. Plain output may write one complete waiting line before admitted slow work, followed by its settled outcome. A forced color option cannot turn a pipe into a terminal.

Wrap panel content inside its borders. Account for visible character width rather than ANSI bytes. Long labels and errors must not collide with borders or depend on terminal auto-wrap. Summaries and detail lines have distinct jobs and do not repeat the same full error twice. A human issue cap reports omitted items and does not truncate the machine result.

Measure ordinary terminal cells without assuming emoji sequences collapse into one glyph. Keep each grapheme intact when wrapping, but count its visible base characters separately. Combining marks and joiners add no cells. Verify widths in the actual terminal. Text measurements do not prove terminal alignment.

Tables keep all supplied columns and wrap headers and values within their cells. Their minimum width includes borders, separators, padding, and room for each column's widest indivisible character. Below that width, a read-only table uses a plain labeled record display that preserves every field. An interactive data list reports the required width and stops without selecting a row. It never hides a column or truncates a selector to force a selection into the available space.

Prompt, progress, and interruption state belong to one invocation. Finishing a nested or sequential command restores the surrounding command's prompt configuration, output, cursor, terminal settings, and interrupt intent. A forced color option does not make a pipe repaintable or permit escape codes in machine output.

An interactive prompt without decoration still shows input edits, search results, and the current selection before submission. Append changed prompt frames without escape codes when repainting is unavailable. These user-driven updates are separate from animation. SIGINT and SIGTERM interrupt a waiting prompt, restore its terminal, and stop before the command can use a submitted value.

## Shared helpers

The shared implementation lives under `apps/cli/app/Support/Console`. `ConsoleMode` keeps machine output, prompt admission, decoration, repainting, and terminal width separate. `GatewayCommand` resolves these facts after input binding when a command requests its shared helpers. `CommandPrompts` takes a factory for a Laravel Prompts instance and owns its input and terminal scope. Commands still decide which inputs may be prompted and how to report a typed `PromptAborted` failure.

`HumanRenderer` returns terminal bytes for details, tables, properties, and failures. Write these bytes through `ConsoleWriter` so literal formatter tags remain data. `ProgressDisplay` admits known steps and runs each callback in the parent process. Its `during()` method returns the callback result without inferring product success. Set the terminal state with `complete()` after checking that result, then call `finish()` with the verified outcome. `SpinnerDisplay::during()` reports a completed wait and preserves the callback's value or exception. An inner spinner shares the active tree's rendering and animation clock.

These helpers do not migrate a command automatically. Keep its adoption verdict unverified until its documented input, output, and terminal cases pass.

## Canonical renderings

The agreed rendering of a command is its expected output under `apps/cli/tests/Expected`, which the CLI contract tests enforce against recorded Gateway responses. Copy the canonical example for a display instead of the nearest command, because some commands predate this standard. Every change to a rendering appears as a diff in these files, and the reviewer accepts that diff as the new agreed rendering.

| Display | Canonical command | Expected output |
| --- | --- | --- |
| Table | `node:list` | [default.human.txt](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-list/default.human.txt) |
| Detail tree | `node:show` | [default.human.txt](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-show/default.human.txt) |
| Progress tree | `node:add` | [created.human.txt](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-add/created.human.txt) |
| Failure after progress | `node:add` | [tld-required.human.txt](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-add/tld-required.human.txt) |
| JSON result | `node:list` | [default.json](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-list/default.json) |
| JSON failure envelope | `node:add` | [tld-required.json](https://github.com/nckrtl/orbit/blob/main/apps/cli/tests/Expected/nodes/node-add/tld-required.json) |

Streams and prompt flows have no recorded canonical rendering yet. The `design:node-add` sketch under `apps/cli/design` is the reference for a prompt flow until its real command lands. [Gateway response fixtures](/reference/gateway-response-fixtures) describes how a family gains recorded renderings.

## Verification and adoption

Each supported public command has an adoption record with its source identity, supported modes, applicable rules, contract-backed exceptions, checks, terminal artifacts, and verdict. Include extension-provided commands with the extension enabled. Account separately for internal commands that share input or output infrastructure.

Check success, empty results, invalid and missing input, cancellation, consent, warnings, partial results, and failures wherever the command supports them. Check human, JSON, streaming, noninteractive, piped, and decorated paths that exist for that command. Expected command failures are successful verification only when a verifier asserts the exact intended result and absence of forbidden side effects.

### Recording

Automated text assertions establish content. Terminal evidence establishes interactive selection, repainting, cadence, wrapping, and liveness. Record timestamped raw PTY output, decoded chunks, reconstructed frames, terminal size, terminal settings, candidate and launcher identity, exit status, first-output delay, and idle gaps. Verify real frame transitions rather than the presence of two glyphs in a transcript.

Use disposable fixtures for mutations. Exercise the real candidate in the assigned runtime and keep evidence tied to that candidate. A recording is stale after a fix changes the behavior it records. Do not declare a command compliant until every applicable check is supported by inspected evidence.

Run `bin/cli-contract --changed` to find and run the contract tests that a changed Gateway response reaches, and rewrite expected output only with `ORBIT_EXPECTED=update` after the diff is reviewed.

A regression check for a recovered UX requirement demonstrates both an accepted and a rejected case. Check observable behavior rather than similarity of wording or implementation style. For example, a state analyzer accepts a running row that reaches a terminal state and rejects the same row returning to waiting. Apply each check only to the surface it covers.
