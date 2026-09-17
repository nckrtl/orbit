---
title: "instance:deploy"
description: "Deploy the configured branch as a fresh release."
---

Deploy the configured branch of a production App instance. The Gateway creates a fresh release, fetches the branch, synchronizes stored environment values, runs the `before_activation` steps, atomically replaces `current`, refreshes the PHP runtime cache, and runs the `after_activation` steps.

```bash
orbit instance:deploy <instance> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |

Human output names each phase and step and labels standard output and standard error while a step is still running. `--json` writes the same events as NDJSON, one event per line, without prompts or prose.

| Event type | Fields |
| --- | --- |
| `phase` | `phase` is `source_preparation`, `environment_sync`, `before_activation`, `activation`, `php_refresh`, `after_activation`, or `rollback`. `step_name` is present only for a named step. |
| `output` | `stream` is `stdout` or `stderr`. `data_base64` carries at most 16 KiB of decoded bytes. |
| `result` | `status` is `succeeded` or `failed`, with nullable `failed_step`, `error_code`, and `selected_release`. This is the final line. |

Every event carries `type`, a monotonically increasing `sequence`, and the `request_id`. A failed `result` stays a `result` event; the CLI uses the one-line error envelope when the Gateway refuses the command before the stream opens or when the stream is malformed or truncated.

| Stream outcome | Exit status |
| --- | --- |
| The final event is a succeeded result. | `0` |
| The final event is a failed result. | Nonzero. The command names the failed boundary and the selected release. |
| The stream is malformed, truncated, or ends without a result. | Nonzero. The CLI never infers success from earlier events. |
| You press Ctrl-C. | Nonzero. The CLI closes its connection and submits no second request. |

```bash
orbit instance:deploy 15
orbit instance:deploy 15 --json > deploy.ndjson
```

A failure during release fetch, environment synchronization, or a `before_activation` step leaves the prior `current` selected. A failure during the PHP cache refresh or an `after_activation` step leaves the fresh release selected. Deployment, rollback, deploy-step changes, environment changes, and removal share one operation owner per App instance, so a competing request waits or returns a busy refusal.

<Note>
A development App instance cannot be deployed. With `--json` the stream ends with a failed `result` whose `error_code` is `deployment_config.unavailable`.
</Note>

## Use it when

Use this command to ship the configured branch of a production App instance. Check the steps first with [`instance:deploy-step:list`](instance-deploy-step-list.md).

## Check

1. Read the last line of the stream. It must be a `result` event.
2. `status: succeeded` with exit status `0` means the release is live, and `selected_release` names it.
3. `status: failed` names `failed_step` and `error_code`. Read the `output` events of that step; `data_base64` holds base64-encoded output.
4. A stream that ends without a `result` event is a failed deployment.

## After a failure

| Failed during | Live release | What to do |
| --- | --- | --- |
| Release fetch, environment sync, or a `before_activation` step | The previous release | Fix the cause and deploy again. |
| PHP cache refresh or an `after_activation` step | The new release | Decide with the user whether to fix forward or run [`instance:rollback`](instance-rollback.md). |

A `deployment_config.unavailable` result means the App instance is a development App instance. A busy refusal means another operation holds the App instance; retry after it finishes.
