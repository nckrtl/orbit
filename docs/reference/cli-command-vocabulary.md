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

`process:create` and `process:update` accept `--no-restart-on-deploy` to opt out of a production deploy restart. This option is separate from `--restart`, which sets the runtime restart policy.

`process:create --user` selects the account for a Node systemd Process; it does not add a command or family action. [Processes](/cli/process#orbit-processcreate) lists the accepted combinations.

`CommandVocabulary` in `apps/cli` holds these lists. `CommandSurfaceTest` fails when a registered command, or a Gateway route that serves one, breaks them. It also fails when this page and the lists differ. Keep this test's command count and family names aligned when commands are removed as part of legacy cleanup.

`gateway:release:rollback` switches Gateway code to a retained release. It leaves the database in place; [Gateway recovery](/reference/gateway-recovery#roll-back) describes database restoration.

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
| `project` | `create`, `update`, and `destroy` | A [Project](/reference/projects) record |
| `cluster` | `create` and `destroy` | A Cluster record |
| `cluster:node` | `add` and `remove` | A [Node](/reference/node-provisioning) in a Cluster |
| `database` | `create` and `destroy` | A [Database connection](/reference/database-connections) record, and with `--server` the database itself |
| `database:server` | `create` and `destroy` | A [Database server](/reference/database-servers) and its Docker Node Process |
| `database:user` | `create` | A MySQL user on a database that lives on a [Database server](/reference/database-servers#add-a-user) |
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
| `project:dev-deploy-step` | `create` and `destroy` | A named [development deploy step](/reference/deployments#development-deploy-steps) on a Project |
| `project:excluded-node` | `add` and `remove` | A [development exclusion](/reference/development-node-exclusions) of one app-dev Node for a Project |
| `process` | `create` and `destroy` | A [Process](/reference/processes-and-schedules), or a Project Process definition with `--project` |
| `route` | `create` and `destroy` | A [Route](/reference/routes) |
| `schedule` | `create` and `destroy` | A [Schedule](/reference/schedules), or a Project Schedule definition with `--project` |
| `tasks` | `create` | A [task](/reference/tasks). `tasks:cancel` and `tasks:complete` end it. |
| `tasks:comment` | `create` | A typed comment on a subtask |
| `tasks:subtask` | `create` and `destroy` | A subtask of a task |
| `tool` | `install` and `remove`; `adopt` establishes ownership of an existing package | A Tool on a Node |

`route:create` takes an Instance ID and domain for an app Route. It does not take a Project ID or an explicit Node or Cluster scope. A custom proxy Route instead takes a domain, serving Node, and upstream or Process. See [Route creation](/reference/routes#create-and-change-targets).

`cluster:router` and `route:target` use `set` and `unset`, because each holds one slot. `extension` uses `enable` and `disable` for the Gateway-owned switch; `instance:analytics`, `metrics`, and `metrics:exporter` use `enable` and `disable`. `proxycli` uses `setup` and `teardown` for its fleet resources, while `proxycli:status` and `proxycli:models` remain family-specific reads. `vault` uses `setup` and `teardown` for the Gateway's Vaultwarden account, `status` for its state, and `sync` to publish at once. `tasks` uses `create` for tasks and `status` for assistance; its extension switch is `extension:enable tasks` or `extension:disable tasks`. The `project:document` and `project:document-storage` commands keep their names as the commands of the `documents` extension, and its switch is `extension:enable documents`. `schedule:enable` turns a Schedule on. A Project target selects a definition and never creates a Process or Schedule.

[Project Documents](/reference/project-documents#cli-contract) use `project:document:<verb>`. Content operations use `read`, `write`, `upload`, and `download`; browsing adds `search` and `versions`; lifecycle operations add `archive`, `restore`, and `restore-version`. `update` renames or moves an entry. Permanent removal uses CLI `remove` and HTTP operation `project-document-destroy`. HTTP `project-document-version-list` maps to CLI `versions`. These are transport names for the same operations, not extra endpoints.

The Gateway names its [Project Documents storage](/reference/project-documents#private-s3-boundary) routes `project:document-storage:show` and `project:document-storage:update`. Storage belongs to the Gateway, so neither operation takes a Project selector. `update` accepts partial changes after initial configuration and can rotate credentials; there is no `destroy`, `disable`, or `unset` operation for this configuration.

## Family-specific actions

Some families have actions outside the pairs above. Each action belongs only to the family that lists it.

| Family | Actions | Result |
| --- | --- | --- |
| `database` | `close`, `describe`, `query`, `schema`, `tables` | The CLI inspects a registered [Database connection](/reference/database-connections), or closes an [adopted Valkey server](/reference/database-servers#close-an-adopted-server) with `database:server:close`. |
| `dns` | `resolve` | The CLI writes a caller-local TLD or exact private Route resolver mapping. |
| `doctor` | `doctor` | [Doctor](/cli/doctor) compares the state the Gateway expects with each Node's state. |
| `env` | `import`, `sync` | The CLI imports or synchronizes Instance environment values. |
| `firewall` | `allow`, `deny` | The CLI writes an allow or deny firewall rule. |
| `fleet` | `resume`, `status` | The CLI shows the [fleet rollout](/reference/gateway-recovery#fleet-rollout) with `fleet:rollout:status`, or resumes a halted one with `fleet:rollout:resume`. |
| `gateway` | `deploy`, `resume`, `rollback`, `smoke`, `status`, `trust`, `use` | The CLI reports Gateway status, pins the root certificate, or selects a profile. The `gateway:release` commands deploy, roll back, and smoke-test the Gateway itself, and resume paused automatic releases. |
| `instance` | `clone`, `deploy`, `logs`, `register`, `rename`, `rollback`, `scan`, `setup`, `transfer` | The CLI operates on Instance source and runtime, reads logs, scans dependencies, and runs setup. Rename records the checked-out branch and can move its Route. |
| `metrics` | `status` | The CLI reports Metrics role status. |
| `proxycli` | `models`, `setup`, `teardown`, `status` | The CLI lists models from the collector snapshot, deploys or removes the fleet CLIProxyAPI quota collector, or reports its state. |
| `operation` | `cancel` | The CLI asks a running [operation](/reference/operations) to stop. `operation:list` and `operation:show` read operations. |
| `node` | `converge`, `relocate`, `rename` | The CLI re-applies a Node's [Gateway-rendered footprint](/reference/node-provisioning#converge-the-orbit-footprint), moves a relocatable singleton role (`gateway`, `websocket`, or `metrics`) to another Node, or changes a Node's unique name. |
| `process` | `logs`, `restart`, `start`, `stop` | The CLI reads Process logs or changes Process runtime state. |
| `profile` | `profile` | The CLI profiles one HTTP request from the operator machine. |
| `project` | `archive`, `download`, `read`, `restore`, `restore-version`, `search`, `upload`, `versions`, `write` | The CLI manages [Project Documents](/reference/project-documents) and their file content. |
| `realtime` | `tail` | The CLI streams decoded realtime Gateway events as they arrive. |
| `schedule` | `logs`, `run` | The CLI reads Schedule logs or runs a Schedule once. |
| `self-update` | `self-update` | [`self-update`](/cli/self-update) updates this machine to the Gateway's CLI release, and `orbit-agent` on a managed Node. |
| `tasks` | `cancel`, `close`, `complete`, `status` | The CLI cancels or completes a task, cancels a running subtask with `tasks:subtask:cancel`, closes a stale question with `tasks:question:close`, or reports task assistance and state. |
| `tool` | `adopt`, `scan` | The CLI reads installed Homebrew and Vite+ packages without taking ownership, or adopts one installed package without changing it. |
| `vault` | `setup`, `status`, `sync`, `teardown` | The CLI stores the Gateway's Vaultwarden account, reports the published Database items, publishes changes now, or deletes the items and the account. The commands belong to the `vault` extension. |

`doctor`, `profile`, and `self-update` are one-segment commands. Each family name is the command. Project display names, source defaults, and task settings use `project:update`; create and destroy remain the lifecycle commands.

`tool:scan` reads supported package-manager inventory without creating Tool intent. Its Gateway route is `GET /api/v1/tool-inventory`. `tool:adopt` establishes intent for one existing package without installing it. Both use the [Tool contract](/reference/tools).

## Noun-ending commands

Five commands end in a noun.

| Command | Result |
| --- | --- |
| `analytics:credentials` | The CLI shows whether a Plausible Stats API key is stored, stores one, or clears it. It never prints the key. |
| `metrics:credentials` | The CLI shows or resets Metrics Grafana credentials. |
| `node:metrics` | The CLI shows one Node metrics snapshot. |
| `node:settings` | The CLI writes typed [Node settings](/reference/node-settings). |
| `node:wireguard-key` | Set an unmanaged Node's public key and reconverge the WireGuard hub. |
| `tasks:agents` | The CLI lists the agent threads of a [task](/reference/tasks). |

## Gateway route names

The Gateway lives in `apps/gateway`, and it records each route name as the Activity command. A named route must carry the name of its CLI command when two conditions hold. Its name without the last segment is the prefix of a CLI command, and its last segment is allowed for that family. `CommandSurfaceTest` checks every named route in `routes/api.php`.

Some routes have no CLI command. `instance:dependencies:show` reads the stored dependency inventory for the API and SDK. The CLI has `instance:dependencies:scan` and `instance:dependencies:update`, as [Instance dependencies](/reference/instance-dependencies) describes. `tasks:agent-stream` is a server-sent event stream for the web task board. Its JSON read, `tasks:agent:show`, has the [CLI command](/cli/tasks#orbit-tasksagentshow) of the same name. The `conn:` routes serve Conn devices and T3 servers, as [Conn layer](/reference/conn) describes, and have no CLI commands.


[`node:wireguard-key`](/cli/node#orbit-nodewireguard-key) uses a noun-ending command, like `node:settings`, because it sets one specific Node property rather than performing a generic update or managing a child resource. Its endpoint is `PATCH /api/v1/nodes/{node}/wireguard-key`.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Two pairs split by ownership

An agent can derive a command from its family and the ownership of its resource, without a command list. `add` and `remove` for every family is a rejected alternative, because `add` would name the creation of an Instance, a Cluster, or a Route. `create` and `destroy` for every family is also rejected, because `destroy` would name the removal of a Node, which leaves the machine intact. `node:create` and `node:destroy` stay free for a future command that creates and destroys machines at a hosting provider.

### No aliases

The CLI keeps no alias for a renamed command. The consumers of the CLI are this repository's agents, harness, and operator. An alias doubles the surface that the command-surface test must cover.

### Definitions in the process and schedule families

A Project definition has the same fields as the Process or Schedule it produces. So the `process` and `schedule` families serve it with `--project`. `process:update` without `--project` takes an installed Process ID and reconverges it in place; with `--project`, it takes a definition name and changes later copies only. A separate definition command under the `project` family is a rejected alternative, because it would hide four verbs behind flags.
