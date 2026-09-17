---
title: "process:update"
description: "Replace one App process definition."
---

# process:update

Replace one App process definition with a complete specification. The command requires `--app` and `--for`, and it accepts the runtime options of [`process:create`](process-create.md) with the same meanings.

```bash
orbit process:update <name> --app=APP --for=ENV[,ENV] [runtime options] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Process definition name. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--app=APP` | none | Numeric App ID. |
| `--for=ENV[,ENV]` | none | Definition environments, `development` or `production`. |
| `--runtime=RUNTIME` | `systemd` | `systemd` or `docker`. |
| `--command=ARG` | none | One argv item. Repeat the option for each argument. |
| `--image=IMAGE` | none | Docker image. |
| `--working-directory=PATH` | see `process:create` | Runtime working directory. |
| `--environment=NAME=VALUE` | none | Docker environment variable. Repeat as needed. |
| `--port=HOST:CONTAINER[/tcp\|udp]` | none | Docker published port. Repeat as needed. |
| `--volume=SOURCE:TARGET[:ro]` | none | Docker volume. Repeat as needed. |
| `--restart=POLICY` | `never` | `never`, `on-failure`, `always`, or `unless-stopped`. |
| `--keep-alive` | off | Keep the Process running through app-dev idle hibernation. |

Changing a definition changes only App-owned configuration. It makes no remote call and does not change an existing App instance Process copy or its runtime state.
