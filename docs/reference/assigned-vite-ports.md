---
title: "Assigned Vite ports"
description: "Preferred Vite ports and the VitePlus Process preset."
---

# Assigned Vite ports

Orbit assigns each development App instance its own preferred Vite port. Use the `vp-dev` Process preset to connect that port to systemd, Caddy, and wake readiness. [ADR 0078](/decisions/0078-assign-vite-ports-to-development-appinstances) records the design.

## Assignment and startup

Each development App instance has a stored preferred `vite_port`. Orbit assigns it automatically during creation or registration. Production instances have no Vite port assignment. Assigning a port does not create or start a Process.

Before starting Vite, the Gateway checks the preferred port on the owning Node. An already running owned Vite Process keeps its port. An unrelated listener triggers selection of the next candidate before startup. Initial allocation starts at `5173`; there is no fixed-size product range. Search uses valid unprivileged TCP ports and terminates at the TCP port limit with an exhaustion error.

Allocation excludes other instance assignments, service ports on an exclusion list, and current TCP bindings on the destination Node, including IPv4 and IPv6. Separate Nodes may use the same port. Concurrent allocation and startup preparation share the Node operation owner, and the database enforces uniqueness for `(node_id, vite_port)`. The exclusion list does not replace actual bind checks.

Instance list and show output, the API, and the PHP SDK expose `vite_port`. Hibernation keeps the preferred assignment as stored configuration, not an open socket. Start, restart, and wake use the same preparation operation. A bind conflict after the check may trigger bounded recovery; an unrelated startup error must not cause repeated port changes.

## Process preset

The positional argument to `process:create` is a name. Generic processes use repeated `--command` values for their executable and arguments. The `--app` option selects a reusable App definition.

This command selects a preset explicitly:

```bash
orbit process:create vite --instance=commander.test --preset=vp-dev --start
```

`process:create --instance` accepts a positive ID or an exact Route domain that resolves to one authorized development App instance. It preserves the meaning of `--app`. The supported preset is `vp-dev` for an instance-owned systemd Process. Naming an ordinary Process `vp-dev` has no special effect.

Preset creation checks the VitePlus executable, project manifest, and installed dependencies, prepares the assigned port, writes service configuration, and installs the Process. `--start` records the running desired state and starts it; omission keeps the current stopped-by-default contract. An identical create reuses the Process and preserves its desired state. One instance has at most one Vite preset Process. A same-name generic Process or unrelated listener is not adopted automatically.

The preset supplies the runtime, executable arguments, instance working directory, and restart-on-failure default. Conflicting custom command, runtime, or Docker options are refused. Generic Process creation remains available. Stored preset identity connects start, restart, wake, and transfer to port preparation without guessing from names or command strings.

## Apply the assignment

Orbit projects one selected port into each consumer before startup.

| Consumer | Configuration |
| --- | --- |
| Development Process | Write `ORBIT_DEV_SERVER_PORT` to an Orbit-owned environment file. |
| VitePlus preset | Run `vp dev` on loopback with that explicit port and strict binding. |
| Workload Caddy | Proxy the Route's reserved development path to that loopback port. |
| Wake readiness | Check the owned Vite Process and selected endpoint before writing the awake marker. |

Systemd can substitute the environment value into a stable command. The preset reads an Orbit-owned file at each start.

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-64.env
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Systemd reads the environment file at service startup. Updating its contents does not require rewriting the command or reloading the unit definition. The generated port takes precedence over application environment inputs. The preset removes `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY` from its service environment to prevent implicit internal HTTPS. Generic processes retain their certificate environment. Generic Process arguments must retain their existing literal treatment; preset expansion must not enable arbitrary shell interpolation.

The preset sets the Vite base to `/__orbit/vite/`; Caddy preserves that prefix for assigned endpoints. Applications set `server.origin` to the URL origin of `ORBIT_DEV_SERVER_ORIGIN` and `server.hmr.path` to `hmr` (Vite prepends its base). Applications also configure assets and HMR for `ORBIT_DEV_SERVER_ORIGIN`, `ORBIT_DEV_SERVER_PATH`, and `ORBIT_DEV_SERVER_HOST`. Caddy terminates TLS on port 443. Laravel's plugin writes the public URL into `public/hot`; Orbit does not use that file for discovery. Plain Vite applications use the same proxy contract. Process registration alone does not establish that the application's asset and HMR configuration is correct.

Agent setup guidance:

> To configure VitePlus for an App instance, use the `vp-dev` Process preset. Let Orbit assign the port, apply strict binding, and prepare the service, proxy, and readiness check. Do not select a port manually or implement a separate allocator. Configure and verify the project's assets and HMR for the Orbit Route origin. Use `--start` when the Process should run on wake; do not add keep-alive merely to enable wake.

## Lifecycle and failure

Orbit keeps or replaces the preferred assignment at these boundaries.

| Event | Result |
| --- | --- |
| Create or register an instance | Assign an initial preferred port without automatically installing a Vite Process. |
| Create the preset | Register the Process and connect it to port preparation. |
| Start an already healthy owned Process | Keep its current port and runtime. |
| Start, restart, or wake | Recheck the preferred port, replace it if needed, project configuration, and start with strict binding. |
| Hibernate or reboot | Keep the preferred assignment for the next startup check. |
| Replace a Process | Keep the preferred assignment and prepare it for the replacement. |
| Remove an instance | Release its assignment after owned runtime cleanup. |
| Transfer | Prepare a destination assignment and consumers before cutover; release the source assignment after source cleanup. |
| Transfer fails before cutover | Preserve source recovery and release destination allocation with destination cleanup. |
| Candidate is claimed before Vite binds | Retry confirmed bind conflicts within the startup deadline, without stopping the unrelated listener. |
| Projection or another startup step fails | Leave wake incomplete and report the failed boundary. |

Database, environment-file, and Caddy updates are separate writes. Their operation records must support retry without admitting traffic through a partially updated endpoint. A retry resumes recorded work and rechecks ownership and availability. It must not report rollback unless that rollback completed.

Existing development instances need coordinated migration of Process identity, port assignment, proxying, and readiness. Preserve desired Process states and unrelated listeners. Do not silently convert a manually configured Process into a preset based on its name.

## Initial preset limits

The initial exclusion list covers common database, cache, messaging, HTTP administration, and development service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`. Actual socket checks remain authoritative. Startup permits at most three attempts within the wake deadline, and retries only when a fresh socket check confirms that another process claimed the selected port.

The preset requires executable `/usr/local/bin/vp`, a readable project `package.json`, and installed project dependencies. It does not install dependencies or edit application code. App definition inheritance is deferred; this version accepts an explicit preset request for an existing development instance. Port assignment alone never installs a process.

Existing instances retain their legacy endpoint until explicitly configured with the preset or reprovisioned. Orbit does not infer preset identity from existing commands. Allocation records retain both placements during transfer, and release the source only after confirmed source cleanup.

## Verification

Verify initial selection, preferred-port reuse, IPv4 and IPv6 conflicts, Node-scoped uniqueness, concurrent creation and wake, exclusions, exhaustion, and idempotent retry. Verify release on removal and recovery after failed transfer. An already running owned listener must not trigger reassignment, and an unrelated listener must not establish readiness after Vite fails to bind.

Verify preset selector resolution, stored preset identity, generic-command compatibility, conflicting options, literal argument handling, retries, and truthful human and JSON results. Confirm that effective Vite arguments, service environment, proxy upstream, and readiness target agree after reassignment and transfer.

Run two Vite instances concurrently on one Incus Node and verify their assets and HMR through their Route origins. Hibernate them, occupy one preferred port with an unrelated service, and wake both. Verify reassignment, preserved unrelated service state, and unchanged browser URLs. Transfer one instance between Nodes. Include Laravel and plain Vite fixtures so the checks do not depend on Laravel's hot file.
