---
title: "Instance setup and teardown"
description: "How a Project stores named setup and teardown commands, and when Orbit runs them for a development Instance."
---

# Instance setup and teardown

This page tells an operator how a Project stores named setup and teardown commands and when Orbit runs them. [ADR 0115](/decisions/0115-run-project-setup-commands-on-development-instances) owns the decision. [Production release layout](/reference/deployments) owns production deploy steps. [Instance removal](/reference/appinstance-removal) owns source deletion after teardown succeeds.

A setup step is one database row: a name, a command string, a timeout, and a position in the Project's setup list. A teardown step is the same kind of row in the teardown list. Orbit reads the row and runs that command string. It does not write a script into the checkout.

Both lists belong to the Project. `--project` selects it. An Instance does not keep a separate copy. The next `instance:create`, `instance:setup`, or development `instance:destroy` runs the lists as they are recorded at that moment.

## Record a step

Record each command on the Project before creating an Instance.

```bash
orbit instance:setup-step:create install-php --project=4 --command='composer install --no-interaction'
orbit instance:setup-step:create install-js --project=4 --command='npm ci' --after=install-php
orbit instance:teardown-step:create drop-sqlite --project=4 --command='rm -f database/database.sqlite'
```

| Command | Result |
| --- | --- |
| `instance:setup-step:create NAME --project=ID --command=COMMAND` | Appends one setup step. Add `--timeout=SECONDS` and exclusive `--before=NAME` or `--after=NAME` to set the timeout and position. |
| `instance:setup-step:list --project=ID` | Lists the setup steps in order. |
| `instance:setup-step:update NAME --project=ID` | Changes the named setup step. Add `--command`, `--timeout=SECONDS`, and exclusive `--before=NAME` or `--after=NAME`. |
| `instance:setup-step:destroy NAME --project=ID` | Removes the named setup step. Remaining steps keep their relative order. |
| `instance:teardown-step:create NAME --project=ID --command=COMMAND` | Appends one teardown step. The timeout and position options match setup. |
| `instance:teardown-step:list --project=ID` | Lists the teardown steps in order. |
| `instance:teardown-step:update NAME --project=ID` | Changes the named teardown step. |
| `instance:teardown-step:destroy NAME --project=ID` | Removes the named teardown step. |

`instance:setup-step:destroy` and `instance:teardown-step:destroy` require confirmation. The prompt names the Project and step and defaults to No. `--yes` confirms without prompting. JSON and noninteractive calls require `--yes`.

| Field | Rule |
| --- | --- |
| `name` | Unique within that Project list. The same pattern as a deploy step name. |
| `command` | One nonempty UTF-8 command of at most 16 KiB with no NUL byte. The operating agent owns this command. |
| `timeout_seconds` | Whole seconds from 1 through 900. The default is 600. |
| `before`, `after` | Exclusive placement by step name within the same list. Omit both to append. |

The Gateway refuses a duplicate name, a placement that names an unknown step, both placement options, a thirty-third step, a timeout over 900 seconds, or a list whose timeouts sum to more than 3,600 seconds. It stores no change. The setup list and the teardown list each have their own count and timeout total. Authorized reads return commands. Activity records omit command text and command output.

An empty list skips that phase.

## Run setup

`instance:create` runs the setup list after the source, PHP selection, Laravel URL configuration, and Route are ready. Each command runs from the instance directory on the Instance Node, through the fixed non-interactive shell used for deploy steps, as the Node's managed runtime user. Orbit runs the list from the first step. Command output follows the [deployment command output limits](/reference/deployments#use-deployment-commands).

The first command that exits non-zero or times out stops the remaining setup commands. Orbit then runs the full teardown list, including when setup stopped before the last step. It then removes the Instance and deletes the checkout created for that attempt, including a dirty or unpublished tree. A teardown command that fails during this removal does not keep the Instance. The command exits non-zero with `instance.setup_step_failed` and the setup step name. When a teardown command also failed, the error includes that teardown step name.

The failed attempt leaves no Instance. The next `instance:create` starts on an empty placement. Provisioning checkpoints before setup still resume. A setup failure is not one of those checkpoints.

An identical `instance:create` for an Instance that is already active returns that Instance and does not run setup.

`instance:register` adopts the checkout and does not run setup. `instance:register --setup` runs the setup list after adoption. A failed command exits non-zero with `instance.setup_step_failed`, names the step, and leaves the Instance and the checkout in place. Teardown does not run. `instance:setup` runs the list again.

```bash
orbit instance:setup <instance>
```

`instance:setup` runs the current setup list against one development Instance, from the first step. A failed command leaves the Instance in place and returns `instance.setup_step_failed`.

`instance:clone` does not run the setup list.

## Run teardown

`instance:destroy` of a development Instance confirms removal and preflights the source under the [removal rules](/reference/appinstance-removal). After preflight accepts the source, Orbit runs the teardown list from the instance directory. It then deletes the Route, source, and Instance record.

The first teardown command that exits non-zero or times out stops removal. The Route, source, and Instance record stay. The command exits non-zero with `instance.teardown_step_failed` and the step name. Fix the command, or destroy that step, then run `instance:destroy` again.

Production removal does not run the teardown list. It retains application content as [Instance removal](/reference/appinstance-removal) describes.

## Failure codes

These codes identify the command that failed. The surrounding sections state whether the Instance remains.

| Code | Result |
| --- | --- |
| `instance.setup_step_failed` | A setup command exited non-zero or timed out. After `instance:create`, the Instance has been removed. After `instance:register --setup` or `instance:setup`, the Instance remains. |
| `instance.teardown_step_failed` | A teardown command exited non-zero or timed out during `instance:destroy`. The Instance remains. |
