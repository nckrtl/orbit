---
title: "Migrate an unmanaged Executor hostname"
description: "Replace a hand-placed executor.test Caddy site with a Gateway-owned custom proxy Route at executor.orbit before the first Node Caddy build removes it."
---

# Migrate an unmanaged Executor hostname

## Problem

Beast can already run a self-hosted Executor as a Node-owned Docker Process. The private hostname was published outside Gateway intent: an unmanaged Caddy site such as the `executor.caddy` fragment plus an Orbit CA leaf placed by hand under `/etc/caddy/orbit-certificates/executor/`. That name, often `executor.test`, does not appear in `route:list`. Doctor cannot own it. The first [Node Caddy build](/reference/caddy-configuration#replaced-configuration) on Beast backs up that fragment and stops serving it.

## Cause

Project Routes only target Instances. There was no first-class way to give a Node Process an arbitrary hostname. Operators copied the Herdr-style loopback proxy by hand instead of waiting for a Route kind that is not a Project.

## Solution

Create a custom proxy Route for the exact name you want. Point it at Beast and the existing Executor Process or its loopback publish.

```bash
orbit route:create executor.orbit --node=beast --process=executor
```

Use `--upstream=http://127.0.0.1:4788` when the Process listener is a known loopback publish and you do not want Process resolution.

The Gateway then publishes exact private DNS for `executor.orbit`, issues an Orbit CA leaf on Beast, and writes the Caddy reverse-proxy site. List and show include the Route. Destroy removes only that Route.

When no Node Caddy build has run on Beast yet, creating the Route runs the first one. That build starts serving `executor.orbit` and stops serving `executor.test` in the same reload, so switch clients to `executor.orbit` at that time. The build copies the old Caddy configuration to `/etc/caddy/orbit-backups/<UTC timestamp>/` and leaves the hand-placed certificate files in place. Remove them by hand after clients stop using `executor.test`.

[Custom proxy Routes](/reference/routes#custom-proxy-routes) owns uniqueness, DNS, Caddy, and removal. [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes) records the kind.

## Limits

This note does not migrate DNS clients. A name under a Cluster TLD still needs the exact custom proxy record; the TLD wildcard alone would send unmatched names to the Router.

## Verification

Create the Route, then confirm `orbit route:show` reports kind `custom_proxy`, `getent ahostsv4 executor.orbit` returns the Beast address, and `https://executor.orbit` reaches Executor through Orbit-CA TLS. Doctor family `route` on Beast should be healthy. After the first Node Caddy build, confirm the old fragment is in `/etc/caddy/orbit-backups/` and `executor.orbit` still works.
