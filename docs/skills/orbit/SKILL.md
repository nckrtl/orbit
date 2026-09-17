---
name: "orbit"
description: "Operates an Orbit fleet through the orbit CLI, including Nodes, Clusters, Apps, App instances, Routes, environment values, Database connections, Processes, Schedules, deployments, and Doctor checks. Use when the user asks to create, deploy, inspect, move, repair, or remove anything that Orbit manages, or mentions Orbit or the orbit command."
---

# Orbit

Orbit runs applications on machines the user already owns. The `orbit` CLI sends each operational command to the Gateway, which changes the fleet and records the request as Activity. Read the command file before you run a command.

## Rules for every command

- Run `orbit gateway:status` first. A command that needs a Gateway fails when no Gateway profile is selected.
- Pass `--json` and parse the result. A failure returns `error.code`, `error.message`, and `error.request_id`.
- Find numeric IDs with the family's `list` command, such as `orbit app:list --json` and `orbit node:list --json`. Do not guess an ID.
- Ask the user before you pass `--yes` or `--force`. JSON output never gives consent, and `--force` means something different in each family.
- Check the exit status as well as the output. A nonzero status means the command did not succeed.
- After a refusal, read the error code in the command file. Retry only when the file says a retry resumes the work.
- Use `orbit activity:list --request-id=ID` to trace a request through the Gateway.

## Command index

Each family lists its commands. Open the command file before you run the command.

### Fleet

**gateway**: Register Gateway profiles on the operator machine, select the active one, pin the Gateway root certificate, and check Gateway status.

| Task | Command file |
| --- | --- |
| Add a profile, trust the Gateway root CA, and optionally make the profile active. | [gateway:add](references/gateway/gateway-add.md) |
| Select the active profile. | [gateway:use](references/gateway/gateway-use.md) |
| Show the status and version of the active Gateway. | [gateway:status](references/gateway/gateway-status.md) |
| Trust or re-verify the active Gateway root CA. | [gateway:trust](references/gateway/gateway-trust.md) |
| Remove a profile and its pinned certificate. | [gateway:remove](references/gateway/gateway-remove.md) |

**node**: Add machines to the fleet, assign roles, grant Node-to-Node access, set storage settings, and remove Nodes.

| Task | Command file |
| --- | --- |
| Provision a new machine or converge an existing Node. | [node:add](references/node/node-add.md) |
| List Nodes registered with the active Gateway. | [node:list](references/node/node-list.md) |
| Show one Node. | [node:show](references/node/node-show.md) |
| Remove a Node and restore its public SSH recovery rule. | [node:remove](references/node/node-remove.md) |
| Add or converge one role assignment. | [node:role:add](references/node/node-role-add.md) |
| List the role assignments of one Node. | [node:role:list](references/node/node-role-list.md) |
| Remove one role assignment and its dependent state. | [node:role:remove](references/node/node-role-remove.md) |
| Allow one Node to run commands on another Node. | [node:access:add](references/node/node-access-add.md) |
| Remove one access edge. | [node:access:remove](references/node/node-access-remove.md) |
| Update the typed storage settings of one Node. | [node:settings](references/node/node-settings.md) |

**cluster**: Group Nodes into a Cluster, give it a development TLD and one Router, and attach or detach member Nodes.

| Task | Command file |
| --- | --- |
| Create a Cluster, optionally with a TLD. | [cluster:create](references/cluster/cluster-create.md) |
| List Clusters. | [cluster:list](references/cluster/cluster-list.md) |
| Show one Cluster with its members and Router. | [cluster:show](references/cluster/cluster-show.md) |
| Change the name, TLD, or state. | [cluster:update](references/cluster/cluster-update.md) |
| Attach a Node. | [cluster:node:add](references/cluster/cluster-node-add.md) |
| Detach a Node. | [cluster:node:remove](references/cluster/cluster-node-remove.md) |
| Set or replace the Router. | [cluster:router:set](references/cluster/cluster-router-set.md) |
| Clear the Router from an inactive Cluster. | [cluster:router:unset](references/cluster/cluster-router-unset.md) |
| Remove an empty Cluster. | [cluster:destroy](references/cluster/cluster-destroy.md) |

**firewall**: Allow, deny, list, and remove named UFW rules on one Node through the Gateway.

| Task | Command file |
| --- | --- |
| Create or converge one named allow rule. | [firewall:allow](references/firewall/firewall-allow.md) |
| Create or converge one named deny rule. | [firewall:deny](references/firewall/firewall-deny.md) |
| List the named rules of one Node. | [firewall:list](references/firewall/firewall-list.md) |
| Remove one named rule. | [firewall:remove](references/firewall/firewall-remove.md) |

**tool**: Install, update, inspect, and remove packages on a Node through the apt, Vite+, Composer, or Homebrew Tool Managers.

| Task | Command file |
| --- | --- |
| List the managers a Node supports and their status. | [tool:manager:list](references/tool/tool-manager-list.md) |
| Install one package, provisioning its manager first when needed. | [tool:install](references/tool/tool-install.md) |
| List the Tools recorded on a Node. | [tool:list](references/tool/tool-list.md) |
| Show one Tool. | [tool:show](references/tool/tool-show.md) |
| Update one Tool within its constraint. | [tool:update](references/tool/tool-update.md) |
| Remove one Tool and delete its record. | [tool:remove](references/tool/tool-remove.md) |

**metrics**: Enable the Prometheus and Grafana role on one Node, select which Nodes run an exporter, read the Grafana credential, and disable or purge the role.

| Task | Command file |
| --- | --- |
| Enable Metrics on one Node. | [metrics:enable](references/metrics/metrics-enable.md) |
| Show the assignment, container health, and exporter state. | [metrics:status](references/metrics/metrics-status.md) |
| Show or reset the verified Grafana administrator credential. | [metrics:credentials](references/metrics/metrics-credentials.md) |
| Select one Node as an exporter target. | [metrics:exporter:enable](references/metrics/metrics-exporter-enable.md) |
| Exclude one Node from exporter selection. | [metrics:exporter:disable](references/metrics/metrics-exporter-disable.md) |
| Disable Metrics, optionally purging its data. | [metrics:disable](references/metrics/metrics-disable.md) |

**dns**: Point one development TLD at an IP address from a macOS operator machine, or remove that override.

| Task | Command file |
| --- | --- |
| Configure or remove a local resolver override for one TLD. | [dns:resolve](references/dns/dns-resolve.md) |

### Applications

**app**: Create, inspect, and remove App records. An App owns one repository and the source defaults that App instances inherit.

| Task | Command file |
| --- | --- |
| Create an App from a slug and a Git repository URL. | [app:create](references/app/app-create.md) |
| List Apps. | [app:list](references/app/app-list.md) |
| Show one App with its stored repository, default branch, and root. | [app:show](references/app/app-show.md) |
| Change source defaults and reconcile affected instances. | [app:update](references/app/app-update.md) |
| Remove an App. | [app:destroy](references/app/app-destroy.md) |

**instance**: Create, register, clone, deploy, roll back, and remove App instances, and attach Database connections to them.

| Task | Command file |
| --- | --- |
| Create a development App instance on an app-dev Node. | [instance:create](references/instance/instance-create.md) |
| Adopt the current Git checkout or worktree as a managed App instance. | [instance:register](references/instance/instance-register.md) |
| List App instances. | [instance:list](references/instance/instance-list.md) |
| Show one App instance with its Route, source, and deploy steps. | [instance:show](references/instance/instance-show.md) |
| Remove an App instance and its owned Processes, Schedules, and Route. | [instance:destroy](references/instance/instance-destroy.md) |
| Clone a candidate into a prepared production App instance. | [instance:clone](references/instance/instance-clone.md) |
| Change the deployment branch of a production App instance. | [instance:update](references/instance/instance-update.md) |
| Record one named deploy step. | [instance:deploy-step:create](references/instance/instance-deploy-step-create.md) |
| List deploy steps in phase and placement order. | [instance:deploy-step:list](references/instance/instance-deploy-step-list.md) |
| Change one named deploy step. | [instance:deploy-step:update](references/instance/instance-deploy-step-update.md) |
| Remove one named deploy step. | [instance:deploy-step:destroy](references/instance/instance-deploy-step-destroy.md) |
| Deploy the configured branch as a fresh release. | [instance:deploy](references/instance/instance-deploy.md) |
| List retained releases and the current selection. | [instance:release:list](references/instance/instance-release-list.md) |
| Select one retained release. | [instance:rollback](references/instance/instance-rollback.md) |
| Add a Database connection and write prefixed stored environment keys. | [instance:database:add](references/instance/instance-database-add.md) |
| Remove a Database connection and clear those keys. | [instance:database:remove](references/instance/instance-database-remove.md) |
| Move a development App instance to another Node. | [instance:transfer](references/instance/instance-transfer.md) |

**route**: Create explicit App domains and custom proxy hostnames owned by a Node, point App Routes at App instances, change a domain or publication intent, and remove Routes.

| Task | Command file |
| --- | --- |
| Create an explicit Route. | [route:create](references/route/route-create.md) |
| List Routes. | [route:list](references/route/route-list.md) |
| Show one Route. | [route:show](references/route/route-show.md) |
| Change the domain or publication intent of an explicit Route. | [route:update](references/route/route-update.md) |
| Set the configured target or a complete production target set. | [route:target:set](references/route/route-target-set.md) |
| Clear the configured target. | [route:target:unset](references/route/route-target-unset.md) |
| Remove a Route. | [route:destroy](references/route/route-destroy.md) |

**env**: Import, update, and synchronize the Gateway-owned environment configuration of one App instance.

| Task | Command file |
| --- | --- |
| Import the workload `.env` into stored configuration. | [env:import](references/env/env-import.md) |
| Add or replace one stored value. | [env:update](references/env/env-update.md) |
| Replace the workload `.env` from the complete stored configuration. | [env:sync](references/env/env-sync.md) |

**database**: Register, inspect, update, and destroy the mysql, pgsql, and sqlite connections that App instances can attach, and create a MySQL user through a Node Process.

| Task | Command file |
| --- | --- |
| Create one connection record. | [database:create](references/database/database-create.md) |
| List registered connections. | [database:list](references/database/database-list.md) |
| Show one connection. | [database:show](references/database/database-show.md) |
| Replace the supplied fields on one connection. | [database:update](references/database/database-update.md) |
| Create a MySQL user and database through a Node Docker Process, then register or refresh the connection. | [database:user:create](references/database/database-user-create.md) |
| Destroy one connection record. | [database:destroy](references/database/database-destroy.md) |
| Run one SQL statement against a registered connection. | [database:query](references/database/database-query.md) |
| List tables on a registered connection. | [database:tables](references/database/database-tables.md) |
| Show columns for every table on a registered connection. | [database:schema](references/database/database-schema.md) |
| Show columns for one table on a registered connection. | [database:describe](references/database/database-describe.md) |

**process**: Install, start, stop, inspect, and remove systemd services and Docker containers owned by an App instance or a Node, and record App-owned process definitions.

| Task | Command file |
| --- | --- |
| Install one Process or record one App process definition. | [process:create](references/process/process-create.md) |
| List the Processes of one App instance or Node, or the definitions of one App. | [process:list](references/process/process-list.md) |
| Show one App process definition. | [process:show](references/process/process-show.md) |
| Replace one App process definition. | [process:update](references/process/process-update.md) |
| Start one Process and record the running desired state. | [process:start](references/process/process-start.md) |
| Stop one Process and record the stopped desired state. | [process:stop](references/process/process-stop.md) |
| Restart one Process. | [process:restart](references/process/process-restart.md) |
| Return a bounded log tail. | [process:logs](references/process/process-logs.md) |
| Remove one Process and its artifacts, or one definition. | [process:destroy](references/process/process-destroy.md) |

**schedule**: Create, run, inspect, enable, and remove recurring commands that native systemd timers execute on a Node or an App instance, and record App-owned Schedule definitions.

| Task | Command file |
| --- | --- |
| Create one Node or App instance Schedule, or record one App definition. | [schedule:create](references/schedule/schedule-create.md) |
| List authorized Schedules or one App's definitions. | [schedule:list](references/schedule/schedule-list.md) |
| Show one Schedule or one definition. | [schedule:show](references/schedule/schedule-show.md) |
| Replace one App Schedule definition. | [schedule:update](references/schedule/schedule-update.md) |
| Run one Schedule now without changing its timer. | [schedule:run](references/schedule/schedule-run.md) |
| Return a bounded log tail. | [schedule:logs](references/schedule/schedule-logs.md) |
| Enable and start an installed App instance timer. | [schedule:enable](references/schedule/schedule-enable.md) |
| Remove one Schedule or one definition. | [schedule:destroy](references/schedule/schedule-destroy.md) |

### Operations

**doctor**: Compare what the Gateway expects with what is on each Node and report every difference without repairing anything.

| Task | Command file |
| --- | --- |
| Verify registered Node state without making repairs. | [doctor](references/doctor/doctor.md) |

**profile**: Profile one HTTP request from the operator machine and show timings, without using the Gateway.

| Task | Command file |
| --- | --- |
| Profile one HTTP request from this machine. | [profile](references/profile/profile.md) |

**activity**: Read the Gateway's log of every authorized command: who ran it, what it targeted, and how it ended.

| Task | Command file |
| --- | --- |
| List recent Gateway command activity. | [activity:list](references/activity/activity-list.md) |
| Show one Gateway command activity attempt. | [activity:show](references/activity/activity-show.md) |

**extension**: Enable or disable optional command families on the operator machine.

| Task | Command file |
| --- | --- |
| List optional Orbit CLI extensions and their state. | [extension:list](references/extension/extension-list.md) |
| Enable an extension and reveal its commands. | [extension:enable](references/extension/extension-enable.md) |
| Disable an extension and hide its commands. | [extension:disable](references/extension/extension-disable.md) |

**herdr**: Run named Herdr terminal sessions on a managed Node and issue short-lived, receive-only observation grants for one pane.

| Task | Command file |
| --- | --- |
| Create or ensure one named Herdr session on a managed Node. | [herdr:session:create](references/herdr/herdr-session-create.md) |
| List named Herdr sessions on one Node. | [herdr:session:list](references/herdr/herdr-session-list.md) |
| Show one named Herdr session. | [herdr:session:show](references/herdr/herdr-session-show.md) |
| Restart one named Herdr session. | [herdr:session:restart](references/herdr/herdr-session-restart.md) |
| Destroy one session, its Process, and its private observer. | [herdr:session:destroy](references/herdr/herdr-session-destroy.md) |
| Adopt an existing Herdr session for observation without owning its lifecycle. | [herdr:session:adopt](references/herdr/herdr-session-adopt.md) |
| Issue one observation grant that is short-lived and receive-only. | [herdr:observe](references/herdr/herdr-observe.md) |
