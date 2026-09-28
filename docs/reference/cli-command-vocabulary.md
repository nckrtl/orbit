---
title: "CLI command vocabulary"
description: "The verbs, family actions, and noun-ending commands that name every CLI command and its Gateway route."
covers:
  - apps/cli/app/Support/CommandVocabulary.php
  - apps/cli/tests/Feature/CommandSurfaceTest.php
  - apps/gateway/routes/api.php
---

# CLI command vocabulary

This page tells an operator or agent how to name a CLI command. It lists the verbs, how ownership selects a verb pair, the actions that only some families have, and the commands that end in a noun. The CLI lives in `apps/cli`. The [CLI design standard](/reference/cli-ux) covers input and output.

Each command is one noun family and one last segment. The last segment is a verb from the pairs below, an action that its family lists, or a noun-ending command. The vocabulary also allows one hidden internal command, `internal:database-local`. Command-specific safety options do not create a new family action: for example, `gateway:add --accept-ca-change` explicitly accepts a replacement pinned root CA; see [Gateway trust](/reference/gateway-trust).

`CommandVocabulary` in `apps/cli` holds these lists. `CommandSurfaceTest` fails when a registered command, or a Gateway route that serves one, breaks them. It also fails when this page and the lists differ. Keep this test's command count and family names aligned when commands are removed as part of legacy cleanup.

## Verb pairs

The CLI uses one pair for each kind of change.

| Pair | The CLI uses it when |
| --- | --- |
| `create` and `destroy` | The Gateway brings the resource into existence and tears it down. |
| `add` and `remove` | The command attaches or detaches things that exist independently. |
| `install` and `remove` | The command installs or removes a [Tool](/reference/tools). `github:app:install` also uses `install`, because GitHub calls it that. |
| `enable` and `disable` | The command turns a toggle on or off. |
| `set` and `unset` | The command writes or clears a single-valued slot. |
| `update` | The command applies a partial change. |
| `list` and `show` | The command reads a collection or one record. |

## Ownership

Use `create` and `destroy` when the Gateway owns the resource lifecycle. Use `add` and `remove` when the command records an association between things that already exist.

| Family | Pair | What the command changes |
| --- | --- | --- |
| `project` | `create` and `destroy` | A [Project](/reference/apps) record |
| `cluster` | `create` and `destroy` | A Cluster record |
| `cluster:node` | `add` and `remove` | A [Node](/reference/node-provisioning) in a Cluster |
| `database` | `create` and `destroy` | A [Database connection](/reference/database-connections) record |
| `database:user` | `create` | A MySQL user and database on a Node Docker Process, then a connection record |
| `gateway` | `add` and `remove` | A Gateway profile in the CLI configuration |
| `github:app` | `install` and `destroy` | The Gateway's [GitHub App](/reference/github-app) |
| `instance` | `create` and `destroy` | An Instance |
| `instance:database` | `add` and `remove` | A Database connection on an Instance |
| `instance:deploy-step` | `create` and `destroy` | A named [deploy step](/reference/deployments) |
| `instance:setup-step` | `create` and `destroy` | A named [setup step](/reference/instance-setup) on a Project |
| `instance:teardown-step` | `create` and `destroy` | A named [teardown step](/reference/instance-setup) on a Project |
| `node` | `add` and `remove` | A Node in the fleet |
| `node:access` | `add` and `remove` | An access grant between Nodes |
| `node:excluded-project` | `add` and `remove` | A [development exclusion](/reference/development-node-exclusions) of one Project on a Node |
| `node:role` | `add` and `remove` | A role on a Node |
| `project:excluded-node` | `add` and `remove` | A [development exclusion](/reference/development-node-exclusions) of one app-dev Node for a Project |
| `process` | `create` and `destroy` | A [Process](/reference/app-processes-and-schedules), or a Project Process definition with `--project` |
| `route` | `create` and `destroy` | A [Route](/reference/routes) |
| `schedule` | `create` and `destroy` | A [Schedule](/reference/schedules), or a Project Schedule definition with `--project` |
| `tasks` | `create` | A [task group](/reference/tasks). `tasks:cancel` and `tasks:complete` end it. |
| `tasks:comment` | `create` | A typed comment on a Task |
| `tasks:subtask` | `create` and `destroy` | A Task in a task group |
| `tool` | `install` and `remove` | A Tool on a Node |

`route:create` takes an Instance ID and domain for an app Route. It does not take a Project ID or an explicit Node or Cluster scope. A custom proxy Route instead takes a domain, serving Node, and upstream or Process. See [Route creation](/reference/routes#create-and-change-targets).

`cluster:router` and `route:target` use `set` and `unset`, because each holds one slot. `extension`, `instance:analytics`, `metrics`, `metrics:exporter`, `proxycli`, and `tasks` use `enable` and `disable`. `schedule:enable` turns a Schedule on. A Project target selects a definition and never creates a Process or Schedule.

## Family-specific actions

Some families have actions outside the pairs above. Each action belongs only to the family that lists it.

| Family | Actions | Result |
| --- | --- | --- |
| `database` | `describe`, `query`, `schema`, `tables` | The CLI inspects a registered [Database connection](/reference/database-connections). |
| `dns` | `resolve` | The CLI writes a caller-local TLD or exact private Route resolver mapping. |
| `doctor` | `doctor` | [Doctor](/cli/doctor) compares the state the Gateway expects with each Node's state. |
| `env` | `import`, `sync` | The CLI imports or synchronizes Instance environment values. |
| `firewall` | `allow`, `deny` | The CLI writes an allow or deny firewall rule. |
| `gateway` | `status`, `trust`, `use` | The CLI reports Gateway status, pins the root certificate, or selects a profile. |
| `instance` | `clone`, `deploy`, `logs`, `register`, `rollback`, `scan`, `setup`, `transfer` | The CLI clones, deploys, registers, rolls back, or transfers an Instance, reads its application log, scans its dependencies, or runs its Project setup steps. |
| `metrics` | `status` | The CLI reports Metrics role status. |
| `proxycli` | `status` | The CLI reports the fleet CLIProxyAPI quota collector. |
| `node` | `relocate`, `rename` | The CLI moves a relocatable singleton role (`gateway`, `websocket`, or `metrics`) to another Node, or changes a Node's unique name. |
| `process` | `logs`, `restart`, `start`, `stop` | The CLI reads Process logs or changes Process runtime state. |
| `profile` | `profile` | The CLI profiles one HTTP request from the operator machine. |
| `realtime` | `tail` | The CLI streams decoded realtime Gateway events as they arrive. |
| `schedule` | `logs`, `run` | The CLI reads Schedule logs or runs a Schedule once. |
| `tasks` | `cancel`, `complete`, `status` | The CLI cancels or completes a task group, cancels a running Task with `tasks:subtask:cancel`, or reports whether the Tasks extension is on. |

`doctor` and `profile` are one-segment commands. Each family name is the command.

## Noun-ending commands

Five commands end in a noun.

| Command | Result |
| --- | --- |
| `analytics:credentials` | The CLI shows whether a Plausible Stats API key is stored, stores one, or clears it. It never prints the key. |
| `metrics:credentials` | The CLI shows or resets Metrics Grafana credentials. |
| `node:metrics` | The CLI shows one Node metrics snapshot. |
| `node:settings` | The CLI writes typed [Node settings](/reference/node-settings). |
| `tasks:agents` | The CLI lists the agent threads of a [task group](/reference/tasks). |

## Gateway route names

The Gateway lives in `apps/gateway`, and it records each route name as the Activity command. A named route must carry the name of its CLI command when two conditions hold. Its name without the last segment is the prefix of a CLI command, and its last segment is allowed for that family. `CommandSurfaceTest` checks every named route in `routes/api.php`.

Some routes have no CLI command. `instance:dependencies:show` reads the stored dependency inventory for the API and SDK. The CLI has `instance:dependencies:scan` and `instance:dependencies:update`, as [Instance dependencies](/reference/instance-dependencies) describes. `tasks:agent-stream` is a server-sent event stream for the web task board.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Two pairs split by ownership

An agent can derive a command from its family and the ownership of its resource, without a command list. `add` and `remove` for every family is a rejected alternative, because `add` would name the creation of an Instance, a Cluster, or a Route. `create` and `destroy` for every family is also rejected, because `destroy` would name the removal of a Node, which leaves the machine intact. `node:create` and `node:destroy` stay free for a future command that creates and destroys machines at a hosting provider.

### No aliases

The CLI keeps no alias for a renamed command. The consumers of the CLI are this repository's agents, harness, and operator. An alias doubles the surface that the command-surface test must cover.

### Definitions in the process and schedule families

A Project definition has the same fields as the Process or Schedule it produces. So the `process` and `schedule` families serve it with `--project`. A separate definition command under the `project` family is a rejected alternative, because it would hide four verbs behind flags.
