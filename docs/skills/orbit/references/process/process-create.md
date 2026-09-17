---
title: "process:create"
description: "Install one Process or record one App process definition."
---

# process:create

Install one stopped or initially running systemd service or Docker container, or record an App-owned definition.

```bash
orbit process:create <name> (--instance=ID-or-domain | --node=ID-or-name | --app=APP --for=ENV[,ENV]) [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Process name of at most 63 characters. It is unique within the owner. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--instance=ID-or-domain` | none | Positive App instance ID or exact, unambiguous development Route domain. |
| `--preset=PRESET` | none | Configure a development App instance Process. Supported values are `vp-dev`, `agentation-mcp`, and `antigravity-watch`. |
| `--node=ID-or-name` | none | Node ID or registered Node name. The CLI resolves a name through the Node list before it sends the request. |
| `--app=APP` | none | Numeric App ID for a definition. |
| `--for=ENV[,ENV]` | none | Definition environments, `development` or `production`. Required with `--app`. |
| `--runtime=RUNTIME` | `systemd` | `systemd` or `docker`. |
| `--command=ARG` | none | One argv item. Repeat the option for each argument, at most 64 items of 4,096 bytes each. |
| `--image=IMAGE` | none | Docker image. |
| `--working-directory=PATH` | see below | Runtime working directory. |
| `--environment=NAME=VALUE` | none | Docker environment variable. Repeat as needed. |
| `--port=HOST:CONTAINER[/tcp\|udp]` | none | Docker published port. Repeat as needed. |
| `--volume=SOURCE:TARGET[:ro]` | none | Docker volume. Repeat as needed. |
| `--restart=POLICY` | `never` | `never`, `on-failure`, `always`, or `unless-stopped`. |
| `--keep-alive` | off | Keep the Process running through app-dev idle hibernation. |
| `--start` | off | Start the Process after installing it. Not accepted for a definition. |

To configure VitePlus for a development App instance:

```bash
orbit process:create assets --instance=commander.test --preset=vp-dev --start
```

The preset owns the command, systemd runtime, working directory, and port environment. It refuses custom runtime, command, working-directory, and Docker options. Its default restart policy is `on-failure`. Omit `--start` to install it stopped. An identical create keeps its recorded desired state; use `process:start` to start an existing stopped Process. See [assigned Vite ports](https://orbit.nckrtl.com/docs/reference/assigned-vite-ports.md) for allocation, wake, and application setup.

To publish Agentation on the Route origin and start the hibernate-tied watcher:

```bash
orbit process:create agentation --instance=commander.test --preset=agentation-mcp --start
orbit process:create agentation-watch --instance=commander.test --preset=antigravity-watch --start
```

`agentation-mcp` assigns a Node-scoped loopback port, projects `AGENTATION_URL`, and publishes `/__orbit/agentation`. `antigravity-watch` requires that HTTP Process, refuses `--keep-alive`, and restarts with `always`. See [Agentation](https://orbit.nckrtl.com/docs/reference/agentation.md).

The two generic runtimes accept these values.

| Runtime | Required | Optional | Default working directory |
| --- | --- | --- | --- |
| systemd | Absolute executable with argv | Working directory, restart policy, keep-alive, initial start | The development checkout, the production home's `current` path, or `/home/{user}` on a Node |
| Docker | Image and command argv | Working directory, environment, ports, volumes, restart policy, keep-alive, initial start | `/app` |

Install a Vite development server on an App instance and start it:

```bash
orbit process:create vite \
  --instance=12 \
  --runtime=systemd \
  --command=/usr/local/bin/vp \
  --command=run \
  --command=dev \
  --command=--host=0.0.0.0 \
  --restart=always \
  --start
```

Run a shared Postgres container on a Node:

```bash
orbit process:create postgres \
  --node=beast \
  --runtime=docker \
  --image=postgres:18 \
  --port=5432:5432 \
  --volume=postgres-data:/var/lib/postgresql/data
```

Record a queue worker definition that every production copy inherits:

```bash
orbit process:create queue \
  --app=1 \
  --for=development,production \
  --runtime=systemd \
  --command=/usr/bin/php \
  --command=artisan \
  --command=queue:work \
  --restart=on-failure \
  --keep-alive
```

A development systemd Process runs as the Node's managed runtime user, reads the `.env` file in the recorded checkout, and receives `VITE_DEV_SERVER_CERT`, `VITE_DEV_SERVER_KEY`, and the `ORBIT_DEV_SERVER_*` values for the App instance Route domain. A production Process runs as the dedicated production user from the `current` release. A Node Process receives no App instance environment.

Repeating an identical create returns the same Process and keeps its desired state. A changed specification with the same owner and name returns `process.name_taken` without changing the record or the runtime; destroy the Process and create it again to change it.

> **Note:** On a Node with the active `app-dev` role, the Gateway installs a development systemd Process without host-boot start intent and stops it after the idle window. `--keep-alive` exempts the Process from idle halt; the restart policy does not. A Docker Process with `--restart=always` maps to Docker `unless-stopped` there so an idle stop survives a daemon restart.

A prepared production home without a selected release accepts a stopped installation, but `--start` and a later `process:start` fail until `instance:deploy` selects a release.
