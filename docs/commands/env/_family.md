---
title: "env"
description: "Import, update, and synchronize the Gateway-owned environment configuration of one App instance."
commands:
  - env:import
  - env:update
  - env:sync
---

The Gateway stores the environment configuration of every App instance as encrypted keys and values. The `env` family imports an existing `.env`, changes one stored value, and renders the complete stored configuration back into the workload file. Deployments, clones, and domain changes render the same stored configuration.

The [App instance environment variables reference](/reference/environment-variables) owns selectors, validation limits, placeholders, and the protected file writer that these commands use.

## Commands

| Command | Result |
| --- | --- |
| [`env:import`](#orbit-envimport) | Import the workload `.env` into stored configuration. |
| [`env:update`](#orbit-envupdate) | Add or replace one stored value. |
| [`env:sync`](#orbit-envsync) | Replace the workload `.env` from the complete stored configuration. |

Every command accepts `--json`. Import and update change stored configuration only; the workload file stays unchanged until you run `env:sync`. Human output is a shared detail tree of operation metadata. Gateway calls show progress. Values never appear.

## Select an App instance

Every command takes `--instance=SELECTOR`, where the selector is a positive App instance ID or the exact Route domain of that App instance. A selector that matches no App instance returns HTTP 404, and a domain that has several App instance targets returns `env.target_ambiguous`.

The Gateway accepts an active App instance only after its placement is complete and no source migration or Route domain change is pending. An App instance without a recorded source profile returns `instance.source_profile_missing`; repeat its creation command with `--recover-source-profile` to record one.

{/* commands */}

## Output

Success output contains the App instance ID, the operation, whether the owned boundary changed, the total stored key count, and the `request_id`. Responses, activity records, errors, and debug output never contain an environment value.

## Related

- [`instance:database:add`](/cli/instance#orbit-instancedatabaseadd) writes prefixed database keys into the same stored configuration.
- [`instance:deploy`](/cli/instance#orbit-instancedeploy) renders stored configuration into the production `.env` before it runs deploy steps.
- [`doctor`](/cli/doctor) reports when the workload file differs from the stored projection.
