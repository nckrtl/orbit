---
title: "route"
description: "Create explicit App domains and custom proxy hostnames owned by a Node, point App Routes at App instances, change a domain or publication intent, and remove Routes."
commands:
  - route:create
  - route:list
  - route:show
  - route:update
  - route:target:set
  - route:target:unset
  - route:destroy
---

A Route is a domain the Gateway publishes on the private network. An App Route has one routing scope: a Node or an active Cluster. Development App Routes have one target. Production App Routes can have an ordered target pool. A custom proxy Route gives a Node-local service an exact hostname. Orbit generates an App Route for every App instance it creates. Generated domains use an explicit hostname, an active Cluster TLD, then the Node TLD. The `route` family manages explicit App Routes, custom proxy Routes, and App Route targets and domains.

The [Routes reference](/reference/routes) owns the Route record, domain generation, private projection, and the guards that refuse a change while an active App instance depends on it.

## Commands

| Command | Result |
| --- | --- |
| [`route:create`](#orbit-routecreate) | Create an explicit Route. |
| [`route:list`](#orbit-routelist) | List Routes. |
| [`route:show`](#orbit-routeshow) | Show one Route. |
| [`route:update`](#orbit-routeupdate) | Change the domain or publication intent of an explicit Route. |
| [`route:target:set`](#orbit-routetargetset) | Set the configured target or a complete production target set. |
| [`route:target:unset`](#orbit-routetargetunset) | Clear the configured target. |
| [`route:destroy`](#orbit-routedestroy) | Remove a Route. |

Every command accepts `--json`. Route, App, App instance, Node, and Cluster arguments are numeric IDs. Human lists use uppercase table headers and include every target in an ordered pool. One Route uses a detail tree with identity, scope, targets, publication, lifecycle and replacement metadata. Requests show progress while waiting and retain the Gateway request ID.

{/* commands */}

## Related

- [`instance`](/cli/instance) creates the App instances that App Routes target and accepts `--domain` for an explicit Route at creation.
- [`process`](/cli/process) installs the Node-owned Process a custom proxy Route can target.
- [`cluster`](/cli/cluster) owns the Router that serves Cluster-scoped App Routes.
- [`dns`](/cli/dns) points a development TLD at a Node from the operator machine.
- [Custom proxy Routes](/reference/routes#custom-proxy-routes) owns uniqueness, DNS, Caddy, and the Executor example.
