---
title: "ADR 0115: Run Project setup commands on development Instances"
sidebarTitle: "0115 Run Project setup commands on development Instances"
description: "Proposed. A Project stores named setup and teardown commands. Development Instance creation runs them and removes the Instance when setup fails."
---

# ADR 0115: Run Project setup commands on development Instances

A Project stores one ordered list of setup commands and one ordered list of teardown commands. Development Instance creation runs the setup list and removes that Instance when a command fails. Development removal runs the teardown list first and stops when a command fails.

## Status

Proposed.

## Context

Operators need application commands, such as dependency installation, to run while a development Instance is created, and to see which command failed. A single script reports one block of output. A file in the checkout is a path, not the command the operator recorded. Old Orbit stored each command as a row and ran that string in the instance directory.

The command family is the Instance lifecycle. `process:create --project` already records a Project-owned definition under the family of the thing that runs. [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) names owned records with `create` and `destroy`. [ADR 0073](/decisions/0073-store-deploy-steps-as-named-appinstance-records) stores each production deploy command as a named row with a timeout and an explicit order. [ADR 0027](/decisions/0027-adopt-local-git-sources-into-appinstance-ownership) makes registration an ownership transfer of a checkout the operator already has, and makes `instance:destroy` the removal of that source.

Production preparation already runs deploy steps. Production removal retains application content.

## Decision

- A Project owns two ordered lists of named command rows: setup steps and teardown steps. Each row has a name unique within its list, a command string, and a timeout.
- The CLI, Gateway, and SDK must expose each list with `create`, `update`, `destroy`, and `list` under `instance:setup-step` and `instance:teardown-step`. `--project` selects the Project. The Gateway must order an unplaced step at the end of its list and must place a step with exclusive `--before` or `--after`.
- Orbit must not write a script file into the checkout. Orbit runs each stored command string from the instance directory through the fixed non-interactive shell used for deploy steps.
- `instance:create` must run the Project setup list after source, PHP selection, Laravel URL configuration, and Route publication. The first command that exits non-zero or times out stops the remaining setup commands. Orbit then runs the full teardown list and removes the Instance, including the checkout created for that attempt, whether or not a teardown command fails. Confirmed command failure triggers cleanup. A transport failure, exhausted API deadline, changed source ownership, or cleanup failure retains the Instance for inspection.
- An identical `instance:create` for an already active Instance returns that Instance and does not run setup.
- `instance:register` must not run setup unless the caller passes `--setup`. A failed `--setup` command leaves the Instance and the checkout in place.
- `instance:setup` runs the current setup list against one development Instance and leaves that Instance in place when a command fails.
- `instance:setup` and a new `instance:create` run every setup command from the start of the list.
- `instance:destroy` of a development Instance must run the teardown list after removal preflight accepts the source and before it deletes the Route, source, or Instance record. The first teardown command that exits non-zero or times out stops removal and leaves the Instance in place.
- `instance:clone` and production removal must not run either list.
- The Gateway must apply the deploy-step name, command, count, and timeout bounds to each list. The default timeout is 600 seconds.
- Activity records must omit command text and command output.

## Rejected alternatives

- One script body: rejected because the failure report cannot name one command, and reordering means editing the whole body.
- A path in the repository: rejected because the operator records the command string on the Project.
- Per-instance copies of the rows: rejected because execution reads the Project rows the operator recorded. A second list would diverge from that record.
- A `project:setup-step` family: rejected because the commands run for an Instance. `--project` selects the owning Project, as it does for a process definition.
- Resuming a create that failed during setup: rejected because a failed setup removes the Instance.
- Running setup on every `instance:register`: rejected because registration adopts a checkout whose application setup is already present.
- Continuing `instance:destroy` after a teardown failure: rejected because the teardown command is the operator's cleanup, and removal waits until that command exits zero.
- Running either list for production clone or production removal: rejected because deploy steps prepare production and production removal retains application content.

## Consequences

- Editing a Project step changes the next `instance:create`, `instance:setup`, and development `instance:destroy`.
- Provisioning checkpoints before setup still resume. A setup failure is not a checkpoint.
- Create rollback deletes the checkout from that attempt when setup left it dirty or unpublished, without a separate `--force` confirmation.
- Create rollback runs the full teardown list even when setup stopped on an earlier command. A confirmed teardown command failure during rollback does not retain the Instance. Unconfirmed execution or incomplete cleanup does.
- `instance:register --setup` and `instance:setup` report the failed step and keep the Instance.
- A teardown failure during development removal reports that step and keeps the Instance. The operator fixes or destroys the step, then runs `instance:destroy` again.
- Commands run again from the first step on every `instance:setup` and on every new create.
- Production Instances do not run these lists.
- The shared API deadline still applies to provisioning, setup, and cleanup. Commands use protected input, discard output, and terminate their process group on timeout.
- Setup and removal share source and environment locks. Removal captures fresh source evidence after teardown and refuses changed source ownership.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0027](/decisions/0027-adopt-local-git-sources-into-appinstance-ownership), [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk), and [ADR 0073](/decisions/0073-store-deploy-steps-as-named-appinstance-records)
- Detail: [Instance setup and teardown](/reference/instance-setup)
- Verify: `composer docs-lint`; Gateway, PHP SDK, and CLI tests for step records and for create, register, setup, and destroy; Incus proof that a failing setup command removes the new Instance and a failing teardown command leaves a development Instance in place
