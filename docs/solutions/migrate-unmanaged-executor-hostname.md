---
title: "Migrate an unmanaged Executor hostname"
description: "Replace a hand-placed Caddy fragment for a Node Process, such as executor.test, with a Gateway-owned custom proxy Route."
covers:
  - apps/cli/app/Commands/Routes/CreateRouteCommand.php
  - apps/gateway/app/Infrastructure/Caddy/Build/NodeCaddyPushScript.php
---

# Migrate an unmanaged Executor hostname

## Problem

A Node runs a service, such as a self-hosted Executor, as a Node-owned Docker Process. Its private hostname is published by hand: a Caddy fragment such as `executor.caddy` and an Orbit CA certificate under `/etc/caddy/orbit-certificates/executor/`. The name, often `executor.test`, does not appear in `route:list`. Doctor does not check it, and the Node Caddy build does not render it.

## Cause

An app Route is created from an Instance, not from a Project or an empty Node or Cluster scope. A hostname for a Node Process needs a custom proxy Route. Without one, operators copy a loopback proxy into Caddy by hand.

## Solution

Create a custom proxy Route for the name you want. Point it at the Node and the Process, or at its loopback port.

```bash
orbit route:create executor.orbit --node=beast --process=executor
```

Use `--upstream=http://127.0.0.1:4788` instead of `--process` when the Process listens on a known loopback port.

The Gateway publishes private DNS for `executor.orbit`, issues an Orbit CA certificate on the Node, and writes the Caddy site. `route:list` and `route:show` include the Route. `route:destroy` removes only that Route.

Move clients before the first [Node Caddy build](/reference/caddy-configuration#node-caddy-build) on that Node. The build moves the hand-placed fragment to `/etc/caddy/orbit-backups/` and stops serving `executor.test`. Orbit never deletes the hand-placed certificate files. When `https://executor.orbit` works, remove them by hand and drop `executor.test` from client configuration. [Custom proxy Routes](/reference/routes#custom-proxy-routes) describes uniqueness, DNS, Caddy, and removal.

## Limits

Orbit does not delete the hand-placed files and does not move DNS clients. A name under a Cluster TLD still needs its own custom proxy Route, because the TLD wildcard sends unmatched names to the Router.

## Verification

After you create the Route, `orbit route:show` reports kind `custom_proxy`, and `getent ahostsv4 executor.orbit` returns the Node's address. `https://executor.orbit` reaches the service with Orbit CA TLS, and `orbit doctor --family=route` is healthy for the Node. After you delete the hand-placed fragment, `executor.orbit` still works.
