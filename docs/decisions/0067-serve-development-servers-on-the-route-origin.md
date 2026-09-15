---
title: "ADR 0067: Serve development servers on the Route origin"
sidebarTitle: "0067 Serve development servers on the Route origin"
description: "Accepted on 2026-09-13. Extends ADR 0009, ADR 0023, ADR 0028, and ADR 0033."
---

# ADR 0067: Serve development servers on the Route origin

In the context of Cluster-routed development AppInstances that run a frontend toolchain on the workload Node, facing browser requests that follow Cluster DNS to the Router on a toolchain port, we decided for a reserved path on the existing Route hostname over HTTPS 443 and against a shared Cluster port, a second hostname, or direct Node access, to keep development assets and HMR inside Cluster routing and Orbit CA trust, accepting that applications must publish asset and HMR URLs on that path.

## Status

Accepted on 2026-09-13. Extends [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance), and [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic).

## Context

A development toolchain such as Vite binds a local HTTP server on the workload Node and emits asset and hot-module-replacement URLs that include that listen port. Cluster DNS resolves the Route hostname to the Router, so a browser that follows those URLs connects to the Router on the toolchain port instead of the owning AppInstance. The Router Caddy service already forwards the Route hostname on 443 to the owning Node. A second Cluster listen port, a second published hostname, or a Node address in the browser would split that path.

## Decision

- Orbit must publish the development-server endpoint as the reserved path `/__orbit/vite` on the AppInstance's existing Route hostname.
- Workload Caddy must reverse-proxy that path to `127.0.0.1:5173` on the owning Node.
- Router Caddy must forward that path with the Route hostname as the HTTP Host value and TLS server name through the existing Cluster HTTPS proxy.
- Orbit must not listen on a Cluster-wide development-server port.
- Orbit must not publish a second hostname or wildcard DNS record for the development server.
- A browser must not need a Node address or toolchain port to load development assets or open the HMR WebSocket.
- HTTPS and WSS for the development-server endpoint must terminate with the Route's Orbit CA certificates.
- When the owning Node has no process on `127.0.0.1:5173`, Caddy must fail that hostname's reserved path and must not select another AppInstance.
- A development systemd Process may receive the Route hostname as the published development-server origin.

## Rejected alternatives

- Shared Cluster port on the Router: rejected because DNS selects one address, a second listener would leave ADR 0009's single 443 listener, and two AppInstances that share a listen port would collide at the Router.
- Extra hostname or wildcard DNS: rejected because ADR 0028 requires one Route hostname per active AppInstance and extra DNS would replace Cluster application DNS.
- Direct workload access: rejected because it replaces Cluster DNS with a Node address and splits TLS from the Route certificate.

## Consequences

- Applications must configure the toolchain to publish asset and HMR URLs on the reserved path of the Route hostname.
- A missing process on `127.0.0.1:5173` returns a proxy error for that hostname's reserved path only.
- Two AppInstances on one Node cannot both bind `127.0.0.1:5173`.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance), and [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic)
- Detail: docs/reference/routes.md
- Verify: Gateway Caddy renderer and process-environment tests, `composer docs-lint`
