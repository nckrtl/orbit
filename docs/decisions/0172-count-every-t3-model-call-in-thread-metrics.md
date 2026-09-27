---
title: "ADR 0172: Count every T3 model call in thread metrics"
sidebarTitle: "0172 Count T3 model calls"
description: "Proposed. The Gateway accumulates T3 per-call metrics from the thread event stream with durable, idempotent checkpoints and marks unrecoverable gaps as partial."
---

# ADR 0172: Count every T3 model call in thread metrics

The Gateway accumulates T3 thread metrics from each `context-window.updated` event in the T3 event stream, rather than from the bounded thread snapshot. It persists the running sums, the greatest `totalProcessedTokens` counted, a separate greatest observed `totalProcessedTokens`, and the event cursor per thread. Replays and restarts do not count an event twice. A stream gap preserves the cumulative token total, but the per-call split is marked partial when missed calls cannot be reconstructed.

## Status

Proposed.

This amends the T3 collection rule in [ADR 0165](/decisions/0165-record-per-thread-token-metrics). The metric meanings, Pi collection, API fields, and reporting surfaces in ADR 0165 stay unchanged; this record changes how the Gateway collects T3 metrics so that a snapshot's truncated activity history cannot undercount a thread.

## Context

ADR 0165 derives T3 metrics from `context-window.updated` activities in the response to `GET /api/orchestration/threads/{id}`. That response is a bounded snapshot, not an event history. On T3 0.0.42, the snapshot can return many activities while retaining only the latest `context-window.updated`. A T3 thread with 56 model calls had 56 such events in T3's `state.sqlite`, but the Gateway saw only the latest one, so it reported one call and only that call's split. `totalProcessedTokens` in the latest event still held the cumulative total of 5,498,639; the corresponding Codex rollout summed to 5,481,373 input tokens, 5,303,296 cached input tokens, and 17,266 output tokens.

The Gateway already consumes T3's thread event stream through its driver. The stream has ordered event sequences and a snapshot baseline; the Gateway carries an `AgentObservation` cursor and can resume subscriptions from a sequence. T3 0.0.42 does not provide an HTTP history endpoint to fill a gap. Disconnects, ticks, and process restarts therefore require durable accumulation and explicit treatment of events the Gateway did not observe.

## Decision

The Gateway consumes every `context-window.updated` payload from the T3 thread event stream and uses it as the source of T3's five split fields. It retains today's `tokens` rule: use `totalProcessedTokens` when present, otherwise `usedTokens`. It does not treat a bounded snapshot as the complete history or use the snapshot to replace accumulated per-call sums.

For each thread, the Gateway durably stores the running input, cached-input, and output sums, the number of calls counted, the largest context seen, the greatest `totalProcessedTokens` counted, a separate greatest observed `totalProcessedTokens`, the latest committed event sequence, and whether the split is partial. These values are updated atomically with the cursor. Processing is idempotent: a replay in the same stream lineage is ignored at or below the stored sequence, and a payload is eligible only when its integer `totalProcessedTokens` advances beyond the observed-total watermark. The observed-total watermark advances for every advancing payload, including one with an invalid or incomplete split. A replay at that same total is ignored, even when the replay contains valid split fields. A valid advancing payload is counted once; after a cursor reset, the observed-total watermark remains the deduplication guard. For a counted payload, `input_tokens` adds `inputTokens - cachedInputTokens`, `cached_input_tokens` adds `cachedInputTokens`, `output_tokens` adds `outputTokens`, `model_calls` increments once, and `peak_context_tokens` is the maximum `inputTokens`. A counted payload must contain non-negative integer `inputTokens`, `cachedInputTokens`, and `outputTokens`, with cached input no greater than input. Invalid or incomplete payloads advance the observed-total watermark and make the split partial, but do not produce a fabricated split.

The event stream cursor and metric checkpoint survive ticks and Gateway restarts. The Gateway resumes at the saved sequence, ignores replayed events, and commits each new event and its metric changes together. A first observation establishes its snapshot sequence as the baseline and processes subsequent events; it does not count the baseline as a call. If that baseline has an advancing cumulative total that includes calls before the saved checkpoint, the split is partial because those calls cannot be reconstructed. A reconnect that cannot resume from the saved cursor establishes a new baseline, advances the observed-total watermark from the baseline, and marks the split partial whenever the baseline includes calls not represented by the saved checkpoint rather than replaying the incomplete snapshot as event history.

When events were missed, the Gateway uses an observed cumulative `totalProcessedTokens` increase to advance `tokens` by that delta. It cannot infer an exact number of calls or an exact input/cache/output split from that total alone, so it marks the split partial and does not invent call rows or token categories. It retains all known sums and the count of known calls internally, but the existing null-based API marks all five split fields unavailable while the split is partial. `model_calls` must never be replaced with a smaller number than the count already observed and counted; a partial split is not presented as a complete per-call total. If the cumulative total decreases, or its baseline is unavailable, the Gateway preserves the previous cumulative metric checkpoint and marks the split partial rather than subtracting or recounting. When an event advances the cumulative total but its payload lacks a valid split, the total remains authoritative and the split becomes partial.

The null split is the partiality marker in the existing thread metric contract. Until a complete per-call history has been established, the internal accumulated values are lower bounds for calls observed and their associated usage, not estimates of all calls. `peak_context_tokens` is the maximum observed call context and may likewise be below the true peak after a gap.

## Rejected alternatives

- Read T3's `state.sqlite` over SSH: rejected because it couples the Gateway to T3's private storage schema and remote filesystem, bypasses the driver boundary, and is not the supported API. T3 already emits the events through the stream the driver consumes.
- Read Codex rollout files: rejected because it couples Gateway metrics to one runtime's private transcript format and filesystem. The metric contract belongs to the T3 driver, not Codex-specific storage, and the event stream provides the per-call updates needed for normal collection.
- Continue deriving the split from `GET /api/orchestration/threads/{id}`: rejected because its bounded activity list is not a history and can omit all but the latest call update.
- Sum every activity in each snapshot: rejected because snapshots overlap and repeated activities would double count, while older entries may be truncated. The Gateway deduplicates with the event sequence and the greatest cumulative total it has counted.
- Treat a missed-call total delta as a complete split or infer a call count from token magnitude: rejected because total processed tokens contain no reliable input/cache/output or call-boundary information.

## Consequences

- Every delivered T3 call update contributes once, even when a stream reconnects, a tick repeats work, or the Gateway restarts.
- The Gateway requires a durable metric checkpoint for each thread. It must update that checkpoint and the event cursor atomically.
- A stream gap can leave the total accurate while the split is explicitly partial. The Gateway cannot reconstruct a missing split using T3 0.0.42's HTTP API.
- Known calls and sums are preserved internally across gaps; the public split is null while partial, so it never presents fewer calls than the known count. Context peak represents the observed maximum until missing history is available.
- The task metrics reference documents the event source, cumulative checkpoint behavior, and partial-data limitation.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends the T3 collection rule in [ADR 0165](/decisions/0165-record-per-thread-token-metrics)
- Detail: [Tasks](/reference/tasks#thread-token-metrics)
- Verify: Gateway tests cover T3 stream accumulation, repeated events, reconnects, restart checkpoints, and gaps; `composer docs-lint`
