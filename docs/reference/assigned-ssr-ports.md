---
title: "Assigned SSR ports"
description: "How Orbit gives each development Instance its own Inertia SSR port, and the environment contract that applications follow so two task workspaces on one Node never share an SSR server."
covers:
  - apps/gateway/app/Domain/AppDev/{SsrPortAllocator,SsrEndpoint}.php
  - apps/gateway/database/migrations/2026_10_20_000000_add_ssr_port_assignments.php
---

# Assigned SSR ports

Several development Instances can run an Inertia server-side rendering (SSR) server on one Node. Inertia's default port is `13714`, and many applications pin one fixed port. Two task workspaces on one Node then share one SSR server, and one workspace renders the other's HTML. Orbit gives each development Instance its own SSR port on its Node and hands it to the application through the environment.

## Assignment

Orbit assigns `ssr_port` when it creates or registers a development Instance. Production Instances get none. An assignment creates or starts no Process.

When this Instance already has a recorded port on the Node, Orbit keeps it. Otherwise the search starts at `13714` and moves up to `65535`. It skips:

- SSR ports that other Instances on the Node hold,
- [Vite ports](/reference/assigned-vite-ports), annotator ports, and Agentation ports assigned on the Node,
- ports that any TCP socket on the Node uses, over IPv4 or IPv6, except in `TIME_WAIT`,
- the common service ports that the Vite search also skips.

Orbit never stops or kills a listener that holds a port. It picks another port. Two Nodes can use the same port. The database keeps each port unique per Node. The search runs under the Node's operation lock and fails when no port is left.

`instance:show`, the API, and the SDK return `ssr_port`. The assignment is a stored preference, not an open socket. It survives hibernation, Process replacement, and reboots. Removal releases it with the Instance. A [transfer](/reference/instance-transfer) assigns a port on the destination and releases the source port after cleanup. Instances created before Orbit assigned SSR ports have none until they are created again.

## Application setup

Every Process of a development Instance with an `ssr_port` gets these variables. The [Project check](/reference/tasks#project-check), its setup steps, and command deliverables get them too. Instances without an `ssr_port` get none, and Orbit then keeps any value that a Process stores itself.

| Variable | Value |
| --- | --- |
| `ORBIT_SSR_PORT` | The assigned port. |
| `INERTIA_SSR_URL` | `http://127.0.0.1:<ssr_port>` |

An application that runs in Orbit task workspaces must read these variables and must not pin a port:

- **SSR build.** Do not bake a fixed port into the SSR bundle at build time.
- **SSR server.** Read `process.env.ORBIT_SSR_PORT` at startup and listen on that port.
- **Laravel.** Set `ssr.url` in `config/inertia.php` from `env('INERTIA_SSR_URL', 'http://127.0.0.1:13714')`.
- **Browser and SSR tests.** Test runners, such as a `BrowserTestRunner::ssrPort()` helper, start and reach SSR at `ORBIT_SSR_PORT` and `INERTIA_SSR_URL`.

Keep `13714` only as the fallback when the variables are absent, such as on a laptop outside Orbit. An application that hard-pins a port still collides with another workspace on a shared Node. Its browser and SSR checks can then hydrate against another workspace's server.

## Why it works this way

### Mirror the Vite port

The Vite port already solves the same problem: a stored port, unique per Node, chosen after a live socket check. The SSR port uses the same rules and the same Node socket probe, so operators learn one model.

### Environment, not discovery

Orbit cannot see which port an SSR bundle bakes in. The application owns its SSR server, so Orbit hands it a port and documents the contract. Orbit does not add an SSR Process preset, and it does not rewrite application configuration.

### Never take a port back

Another workspace or a process outside Orbit may hold `13714` or a later port. Stopping it could break that work. Orbit skips the port instead.
