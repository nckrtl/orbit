---
title: "ADR 0158: Continue long Gateway operations across bounded requests"
sidebarTitle: "0158 Continue long Gateway operations across bounded requests"
description: "Proposed. A Gateway operation that can outlast one request, such as instance:register, stops starting new phases at a work budget, persists its phase, and answers in_progress. The CLI repeats the same idempotent request until the operation completes, so no single request approaches the PHP-FPM limit."
---

# ADR 0158: Continue long Gateway operations across bounded requests

A Gateway operation that can outlast one HTTP request ends each request at a phase boundary once a work budget is spent. It persists the phase it reached and answers `in_progress`. The CLI sends the same idempotent request again until the operation answers with its result. No request is held open near the PHP-FPM limit, and the Gateway still runs no queue or background worker.

## Status

Proposed.

This extends the request-deadline rules in [PHP runtime](/reference/php-runtime) and keeps [ADR 0003](/decisions/0003-singleton-metrics-role)'s synchronous reconciliation.

## Context

PHP-FPM and Caddy end a Gateway request after 600 seconds. The API command deadline is 570 seconds, and forward work ends 20 seconds before it so rollback can run. In production, `instance:register` has taken up to 522 seconds and `instance:create` up to 470 seconds. Both are one synchronous call. A slower network, a larger repository, or a busier Node pushes them past the deadline. They then fail with `command.deadline_exceeded` after most of the work is done, and the operator starts again.

Deployments have the same bound. Deploy steps accept up to 900 seconds each and 3,600 seconds in total, yet the deployment runs inside one request and ends at that request's deadline. Streaming progress events do not change this: a streamed response is still one PHP-FPM request, and `request_terminate_timeout` ends it.

The Gateway rules forbid queues and Node agents that run commands ([ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes)). Registration and removal already record their phase and resume a matching retry, so most of the needed state exists.

## Decision

- An operation that can outlast one request declares ordered phases. Each phase is idempotent and records its completion on the operation's record before the next phase starts. Registration uses its existing `provisioning_step` and retry rules.
- The Gateway starts a new phase only while the request's work budget has room. The budget is half the command deadline (285 seconds). A phase that has started runs to completion within the command deadline. A single phase must fit that deadline, so step timeouts inside a phase follow the lifecycle-step limit of 540 seconds.
- When the budget is spent between phases, the Gateway answers `202` with `status: in_progress`, the completed phase, and the same operation identity. It records the Activity as `in_progress`, not as failed.
- The CLI and the PHP SDK repeat the identical request, with a fresh request ID and the same operation identity, until the Gateway answers with the final result or an error. Each repeat shows the completed phase as progress. The MCP tool returns `in_progress` to its caller, which repeats the call.
- A repeat that finds the operation already complete returns the stored result. A repeat that finds different input fails as a conflict, as registration retries do today.
- The first operations are `instance:register` and `instance:create`. Deployment follows, with its phases at release preparation, environment sync, each step, activation, and cache refresh. That also lowers the deploy step limit to 540 seconds.

## Rejected alternatives

- Raise the PHP-FPM and Caddy timeouts for long routes: rejected because it only moves the bound, holds one of eight Gateway workers for longer, and keeps a single failure point near the end of the work.
- Run the operation in a detached process or a transient systemd unit on the Gateway: rejected because it is a background worker in all but name, which the Gateway rules forbid, and it needs a new status channel and cleanup of orphaned runs.
- Stream progress events, as deployments do: rejected as the fix because a stream is still one PHP-FPM request with the same 600-second end.
- Let the CLI retry a failed operation automatically: rejected because a failure after the deadline has already rolled back work; the operation must end at a phase boundary on purpose, not by running out of time.

## Consequences

- No Gateway request approaches the PHP-FPM limit, whatever the total length of registration, creation, or deployment.
- A long operation survives a CLI that disconnects: rerunning the same command continues from the last completed phase.
- Every phase must be idempotent and record its completion. Registration and removal already do; creation and deployment need phase records.
- The API gains a `202 in_progress` response for these routes. The SDK, CLI, MCP catalogue, OpenAPI document, and response fixtures change together.
- Deploy step timeouts above 540 seconds are no longer accepted, and stored ones must be lowered by a migration.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli, apps/docs
- ADRs: extends [ADR 0003](/decisions/0003-singleton-metrics-role) and [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) constraints; no supersession
- Detail: [PHP runtime](/reference/php-runtime), [Deployments](/reference/deployments), [Instance setup and teardown](/reference/instance-setup)
- Verify: Gateway feature tests that stop at a phase boundary under a small budget and resume; SDK and CLI tests that repeat `in_progress`; an Incus proof of `instance:register` across several requests
