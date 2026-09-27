---
title: "ADR 0165: Record per-thread token metrics"
sidebarTitle: "0165 Per-thread token metrics"
description: "Proposed. Each agent thread records uncached input, cached input, output, model calls, and peak context beside the cumulative token total. Null means that driver did not report the field."
---

# ADR 0165: Record per-thread token metrics

Each agent thread records five fields beside the cumulative `tokens` total: `input_tokens`, `cached_input_tokens`, `output_tokens`, `model_calls`, and `peak_context_tokens`. A field is null when that driver did not report it. The web task board keeps showing the total. `tasks:agents` and the agent-threads API show the five fields.

## Status

Proposed.

This extends the `AgentThread` record in [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers), the cumulative Pi total in [ADR 0116](/decisions/0116-run-task-implementers-on-pi), and the cumulative T3 total in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension). Those totals stay. This record adds the split on the thread.

## Context

`tokens` is one cumulative total. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) takes it from the T3 snapshot: `totalProcessedTokens` when that value is present, otherwise `usedTokens`. [ADR 0116](/decisions/0116-run-task-implementers-on-pi) takes it from Pi's cumulative session usage. The Pi server snapshot already returns `usage.input`, `usage.output`, `usage.cacheRead`, `usage.cacheWrite`, and `usage.total`. The Gateway stores `usage.total` and discards the rest.

### What the total hides

A pipeline change can raise that total by growing the prompt, missing the cache, or writing more output. The total does not say which. Groups 109 through 125, measured on 2026-09-26, are the baseline for the next comparison:

| Measure | Value |
| --- | --- |
| Tokens | 596 million |
| Cached share of input | 94 percent of uncached input plus cached input, with cache writes counted as uncached |
| Implementer context per call | 118 thousand, average |
| Reviewer context per call | 107 thousand, average |
| Median implementer subtask | 5.97 million tokens |

The cached share is `cached_input_tokens / (input_tokens + cached_input_tokens)`. Average context per call is `(input_tokens + cached_input_tokens) / model_calls`. The next pipeline change is comparable only when each thread still reports that split.

### What each runtime records

Pi sessions record each model call in `~/.pi/agent/orbit-sessions/*.jsonl`. An assistant message carries `usage.input`, `usage.cacheRead`, `usage.cacheWrite`, `usage.output`, and `usage.totalTokens`. `totalTokens` equals `input + cacheRead + cacheWrite + output`. Pi's `input` excludes both the cache read and the cache write. Orbit's uncached input is `input + cacheWrite`, because a cache write was not read from cache. A `reasoning` count on that message is already inside `output`.

Codex rollouts record each model call as a `token_usage_record`. The call's `usage` and the cumulative `thread_token_usage` both carry `input_tokens`, `cached_input_tokens`, `cache_write_input_tokens`, `output_tokens`, `reasoning_output_tokens`, and `total_tokens`. `input_tokens` includes `cached_input_tokens`. `total_tokens` equals `input_tokens + output_tokens`. Reasoning is already inside `output_tokens`.

The T3 thread snapshot, `GET /api/orchestration/threads/{threadId}` on T3 0.0.42, has no thread-level usage object. Token figures sit on `context-window.updated` activities. The payload has `usedTokens`, and these optional fields: `totalProcessedTokens`, `inputTokens`, `cachedInputTokens`, `outputTokens`, `reasoningOutputTokens`, `lastInputTokens`, `lastCachedInputTokens`, `lastOutputTokens`, `lastReasoningOutputTokens`, `maxTokens`, `toolUses`, and `durationMs`. On a Codex thread, `totalProcessedTokens` equals the rollout's `thread_token_usage.total_tokens`. `inputTokens`, `cachedInputTokens`, and `outputTokens` on one activity are that update's call, with `inputTokens` including the cached input, and `last*` repeats those same numbers. Summing every activity double-counts updates that repeat a call. On a Claude thread the same activities omit `cachedInputTokens`, `inputTokens` is the current window, and `totalProcessedTokens` repeats and decreases. That snapshot does not report a cache split or one row per call.

## Decision

The Gateway stores the five fields on `agent_threads`. Task and TaskGroup keep today's `tokens`, `line_diff`, and `duration_ms` only. The Coder settle webhook stays on that total. A failed read keeps the last stored value for each field, as it does for `tokens`. A thread that has not been observed with a reporting driver stores null, not zero. The next observation fills the fields. Orbit does not read session files to backfill rows written before that observation.

`input_tokens` is uncached input summed across model calls. A cache write is uncached input, not cached input. `cached_input_tokens` is input read from cache, summed across those calls, and it does not include cache writes. `output_tokens` is output summed across those calls, reasoning included once. `model_calls` is the number of those calls. `peak_context_tokens` is the largest single-call context. Context is that call's uncached input plus its cached input, so a Pi cache write is inside the peak, and output is excluded. Average context per call is `(input_tokens + cached_input_tokens) / model_calls`. The cached share of input is `cached_input_tokens / (input_tokens + cached_input_tokens)`. Cache writes sit in that denominator with the other uncached input. The 94 percent baseline uses this share.

The Gateway does not recompute `tokens` from the split. For a Pi thread that reports all three sums, `input_tokens + cached_input_tokens + output_tokens` equals `usage.total`. For a Codex thread that reports the split, the same sum equals the rollout `thread_token_usage.total_tokens`, which is the `totalProcessedTokens` Orbit already stores as `tokens`.

### Pi

The Pi server adds `calls` and `peakContext` to `usage` on `GET /sessions/{id}` and on stream `state` events. `calls` counts assistant messages with numeric `input`, `output`, `cacheRead`, and `cacheWrite`. `peakContext` is the maximum of `input + cacheRead + cacheWrite` over those messages, which is that call's uncached input plus its cached input. The existing sums stay: `input`, `output`, `cacheRead`, `cacheWrite`, and `total`. Pi's `input` remains the portion of uncached input that is not a cache write.

The Gateway maps one snapshot or state event as follows. `tokens` stays `usage.total`. `input_tokens` is `usage.input + usage.cacheWrite`. `cached_input_tokens` is `usage.cacheRead`. `output_tokens` is `usage.output`. Each of those three is null when its source is not an integer, and a null `cacheWrite` makes `input_tokens` null rather than treating the missing write as zero. `model_calls` is `usage.calls` when that value is an integer, and otherwise null. `peak_context_tokens` is `usage.peakContext` when that value is an integer, and otherwise null. A Pi server that has not sent the two new keys still fills the three sums from the usage object it already returns.

### T3

The Gateway keeps today's `tokens` rule and does not open Codex rollout files. It derives the five fields from `context-window.updated` activities in snapshot order.

The split is null when any `totalProcessedTokens` is an integer lower than an earlier `totalProcessedTokens` on that snapshot. It is also null when a call that advances the total lacks integer `inputTokens`, `cachedInputTokens`, and `outputTokens`, or when `cachedInputTokens` is greater than `inputTokens` on a counted call. Zero counted calls leave the five fields null.

A counted call is an activity with integer `inputTokens`, `cachedInputTokens`, and `outputTokens` where either `totalProcessedTokens` is an integer greater than every earlier `totalProcessedTokens`, or no earlier activity has an integer `totalProcessedTokens` and the triple differs from the previous counted call. Any other activity is not a call. `last*` is not a total, and `reasoningOutputTokens` is not added.

Over the counted calls, `input_tokens` is the sum of `inputTokens - cachedInputTokens`, `cached_input_tokens` is the sum of `cachedInputTokens`, `output_tokens` is the sum of `outputTokens`, `model_calls` is the number of calls, and `peak_context_tokens` is the maximum `inputTokens`. On a Codex thread this matches the rollout: one counted call per `token_usage_record`, and the sums match `thread_token_usage`. A Claude snapshot omits `cachedInputTokens`, so the five fields stay null and `tokens` stays the cumulative total.

### Where the fields appear

`GET /api/v1/task-groups/{group}/agents` returns the five fields on every thread, including the planner, the reviewer, and each implementer. Each value is present and null when unknown. `tasks:agents` prints them in that order after tokens: Input, Cached, Output, Calls, and Peak. An unknown value is a blank cell. JSON output includes the same fields. The web task board keeps showing `tokens` for the group and the subtask. It does not show the split. A change to these fields does not by itself broadcast `task_group.updated`, which is the same rule [ADR 0151](/reference/events#tasks) uses for `tokens`.

## Rejected alternatives

- Keep only `tokens`: rejected because a higher total does not say whether the prompt grew, the cache missed, or the output grew. The 94 percent cached share is invisible.
- Read Codex rollout files or Pi session files from the Gateway: rejected because the driver boundary is the snapshot. The Pi server already holds the session. The T3 snapshot already carries the Codex per-call activities.
- Sum every `context-window.updated` activity: rejected because a repeated update is not another model call, and a Claude window size is not a billable call.
- Store `lastInputTokens` as the thread's `input_tokens`: rejected because `last*` is the latest update. The latest window is about one hundred thousand tokens, and the thread total is the sum of every call.
- Copy the five fields onto Task and TaskGroup: rejected because the thread spent the tokens. The group view sums the threads. A second stored sum would drift from the thread.
- Show the split on the web task board: rejected because the board is the status surface. `tasks:agents` and the agents API are where a pipeline change is compared.
- Add the split to the Coder settle webhook: rejected because that body is the settle notice. The comparison record is the thread.
- Store zero when the driver did not report a field: rejected because an unreported Claude cache split would count as no cached input and move the cached share.

## Consequences

- A Pi thread reports all five fields once the Pi server sends `calls` and `peakContext`. Before those keys exist, the three sums still come from `usage`, and `model_calls` and `peak_context_tokens` stay null.
- A T3 Codex thread reports all five fields from the snapshot activities. A T3 thread whose activities omit `cachedInputTokens`, including Claude, keeps the five fields null.
- `tokens` on the thread, the subtask, and the group stays the cumulative total. The webhook and the web board stay on that total.
- Rows observed before this record stay null until the next successful observation.
- The cached share, the average context per call, and the median of implementer `tokens` can be computed from the stored fields. The cached share is `cached_input_tokens / (input_tokens + cached_input_tokens)`, and `input_tokens` includes cache writes. Groups 109 through 125 on 2026-09-26 are the baseline: 596 million tokens, 94 percent cached input on that share, 118 thousand tokens of implementer context per call, 107 thousand for the reviewer, and a median implementer subtask of 5.97 million tokens.
- A decrease in `totalProcessedTokens`, or a counted call without the three integers, publishes no split rather than a short sum.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers), [ADR 0116](/decisions/0116-run-task-implementers-on-pi), and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks#thread-token-metrics), [Pi server](/reference/pi-server#token-usage), [tasks:agents](/cli/tasks#orbit-tasksagents)
- Verify: Gateway tests map Pi `usage` onto the five fields and aggregate T3 `context-window.updated` activities by the counted-call rule; the `tasks:agents` contract shows the fields; `composer docs-lint`
