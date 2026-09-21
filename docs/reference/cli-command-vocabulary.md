# CLI command vocabulary

This page tells an operator or agent which last segment a CLI command uses, how ownership selects `create` and `destroy` or `add` and `remove`, which family-specific actions exist, and which commands keep a noun as their last segment. [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) owns the naming decision. The CLI lives in `apps/cli`. The [CLI design standard](/reference/cli-ux) owns input and output presentation.

Each command is one noun family and one last segment. The last segment is a verb from the pairs below, a family-specific action listed for that family, or one of the two noun-ending commands.

## Verb pairs

The CLI uses one pair for each kind of change.

| Pair | The CLI uses it when |
| --- | --- |
| `create` and `destroy` | The Gateway brings the resource into existence and tears it down. |
| `add` and `remove` | The command attaches or detaches things that exist independently. |
| `install` and `remove` | The command installs or removes a [Tool](/reference/tools). `github:app:install` also uses `install`, because GitHub calls it that. |
| `enable` and `disable` | The command flips a toggle. |
| `set` and `unset` | The command writes or clears a single-valued slot. |
| `update` | The command applies a partial change. |
| `list` and `show` | The command reads a collection or one record. |

## Ownership

An operator selects `create` and `destroy` when the Gateway owns the resource lifecycle, and selects `add` and `remove` when the command records an association between things that already exist.

| Family | Pair | What the command changes |
| --- | --- | --- |
| `app` | `create` and `destroy` | A [Project](/reference/apps) record |
| `cluster` | `create` and `destroy` | A Cluster record |
| `cluster:node` | `add` and `remove` | A [Node](/reference/node-provisioning) in a Cluster |
| `database` | `create` and `destroy` | A [Database connection](/reference/database-connections) record |
| `database:user` | `create` | A MySQL user and database on a Node Docker Process, then a connection record |
| `gateway` | `add` and `remove` | A Gateway profile in the CLI configuration |
| `herdr:session` | `create` and `destroy` | A [Herdr session](/reference/herdr-sessions) |
| `instance` | `create` and `destroy` | An Instance |
| `instance:database` | `add` and `remove` | A Database connection on an Instance |
| `instance:deploy-step` | `create` and `destroy` | A named [deploy step](/reference/deployments) |
| `node` | `add` and `remove` | A Node in the fleet |
| `node:access` | `add` and `remove` | An access grant between Nodes |
| `node:role` | `add` and `remove` | A role on a Node |
| `process` | `create` and `destroy` | A [Process](/reference/app-processes-and-schedules) or a Project Process definition |
| `route` | `create` and `destroy` | A [Route](/reference/routes) |
| `schedule` | `create` and `destroy` | A [Schedule](/reference/schedules) or a Project Schedule definition |
| `tool` | `install` and `remove` | A Tool on a Node |

`cluster:router` and `route:target` use `set` and `unset` because each holds one slot. `extension`, `metrics`, `metrics:exporter`, and `proxycli` use `enable` and `disable`. `schedule:enable` turns a Schedule on. [Gateway trust](/reference/gateway-trust) owns profile registration and removal.

## Family-specific actions

Some families expose actions that are not the pairs above. Those last segments belong only to the families that list them.

| Family | Actions | Result |
| --- | --- | --- |
| `database` | `describe`, `query`, `schema`, `tables` | The CLI inspects a registered [Database connection](/reference/database-connections). |
| `dns` | `resolve` | The CLI writes a caller-local TLD or exact private Route resolver mapping. |
| `doctor` | `doctor` | [Doctor](/concepts#doctor) compares Gateway intent with Node state. |
| `env` | `import`, `sync` | The CLI imports or synchronizes Instance environment values. |
| `firewall` | `allow`, `deny` | The CLI writes an allow or deny firewall rule. |
| `gateway` | `status`, `trust`, `use` | The CLI reports Gateway status, pins the root certificate, or selects a profile. |
| `herdr` | `observe`, `adopt`, `restart` | The CLI observes a session, adopts an existing server, or restarts a session. |
| `instance` | `clone`, `deploy`, `register`, `rollback`, `scan`, `transfer` | The CLI clones, deploys, registers, rolls back, or transfers an Instance, or scans its dependencies. |
| `metrics` | `status` | The CLI reports Metrics role status. |
| `proxycli` | `status` | The CLI reports the fleet CLIProxyAPI quota collector. |
| `node` | `relocate`, `rename` | The CLI moves a relocatable singleton role (`gateway`, `websocket`, or `metrics`) to another Node, or changes a Node's unique registry name. |
| `process` | `logs`, `restart`, `start`, `stop` | The CLI reads Process logs or changes Process runtime state. |
| `profile` | `profile` | The CLI profiles one HTTP request from the operator machine. |
| `realtime` | `tail` | The CLI streams decoded realtime Gateway events as they arrive. |
| `schedule` | `logs`, `run` | The CLI reads Schedule logs or runs a Schedule once. |
| `top` | `top` | The CLI shows the fleet as a live screen. |

`doctor`, `profile`, and `top` are one-segment commands. Each family name is the command.

## Noun-ending commands

Three commands keep a noun as their last segment.

| Command | Result |
| --- | --- |
| `analytics:credentials` | The CLI shows whether a Plausible Stats API key is stored, stores one, or clears it. It never prints the key. |
| `metrics:credentials` | The CLI shows or resets Metrics Grafana credentials. |
| `node:metrics` | The CLI shows one synchronous Node metrics snapshot. |
| `node:settings` | The CLI writes typed [Node settings](/reference/node-settings). |

## Gateway route names

`instance:dependencies:show` is a stored-inventory API and SDK operation without a CLI adapter. The CLI exposes `instance:dependencies:scan` for directory, domain, and `--all` fleet scans, and `instance:dependencies:update` for one development instance via directory or `--app`, as described in [Instance dependencies](/reference/instance-dependencies). Update rejects `--all` and `--latest`.

A named Gateway API route whose prefix matches a CLI command family and whose last segment is a vocabulary verb, a family-specific action, or a noun-ending command carries the same name as the CLI command. The Gateway lives in `apps/gateway` and records that route name as the activity command.
