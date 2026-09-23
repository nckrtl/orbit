---
title: "ADR 0123: Serve the web app from the Gateway origin"
sidebarTitle: "0123 Serve the web app from the Gateway origin"
description: "Proposed. The Gateway's Caddy site serves the built Orbit web app at https://gateway.orbit from its own release directory. Gateway paths keep reaching Laravel, and /grafana reaches the published Metrics site."
---

# ADR 0123: Serve the web app from the Gateway origin

The Gateway's Caddy site serves the built Orbit web app at `https://gateway.orbit`. Laravel keeps its own paths on that site. The web app is built and released separately from the Gateway checkout.

## Status

Proposed.

This amends the rejected web dashboard alternative in [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client). The web app is a second client of the same API and realtime channel. It does not replace `orbit top`.

## Context

`apps/web` is a static single-page app. It reads the fleet through the Gateway API, follows the realtime channel from [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb), and reads Node metrics from Grafana. Until now it ran only under `vp dev`, whose proxy forwards `/api` and `/grafana` from the developer's machine.

The Gateway identifies a caller by its WireGuard address. It has no login and no session. A browser therefore needs a direct connection to the Gateway. A proxy in front of the API would replace the caller's address with its own.

ADR 0085 rejected a web dashboard because it would need its own hosting, session, and TLS. Serving the app from the Gateway's own site removes those costs. The browser already trusts the Orbit root certificate for `gateway.orbit`, and the Gateway already knows the caller.

The choice of origin depends on these facts:

- The web app calls `/api/...` and `/grafana/...` on its own origin.
- Laravel owns only `/api/*`, `/mcp`, `/mcp/*`, `/up`, and `/.well-known/*`. The web app's pages use none of those paths.
- The Gateway host resolves no `*.orbit` names. Its own Caddy already publishes `metrics.orbit`.
- A macOS app is planned as a thin window that loads `https://gateway.orbit`.

## Decision

- The Gateway site at `https://gateway.orbit` routes `/api/*`, `/mcp`, `/mcp/*`, `/up`, and `/.well-known/*` to Laravel as before.
- `/grafana/*` first runs the same WireGuard authorization as `metrics.orbit`, with the browser's address. It then strips the prefix and proxies to the `metrics.orbit` site on the Gateway's own Caddy. The Metrics publication keeps owning the Grafana upstream, so the web path follows a Metrics move without another change.
- Every other path serves the web app from `current` in the Gateway web directory, `/home/orbit/web` by default. A path without a file falls back to `index.html`, so the app's router handles it.
- Hashed files under `/assets/` are cached as immutable. Every other web response must be revalidated, so a new release reaches the browser on the next load.
- The web directory lives outside the Gateway checkout. Each release is a directory named after its commit under `releases/`, and `current` links to one of them. Deploying the Gateway never changes the web release, and deploying the web app never changes the Gateway checkout.
- `bin/web-deploy` builds `apps/web` from a clean commit on the operator's machine, uploads it as a new release, switches `current`, and keeps the five newest releases. The Gateway host needs no JavaScript toolchain.
- The Gateway publishes the public Orbit root certificate next to its own certificate, so Caddy can verify the `metrics.orbit` hop.

## Rejected alternatives

- Serve the web app from its own hostname, such as `app.orbit`: rejected because every API call becomes cross-origin. The Gateway must then allow that origin. A proxy for the API on that hostname replaces the caller's address with its own.
- Copy the build into the Gateway checkout's `public` directory: rejected because web releases then follow Gateway deploys. Untracked files sit in the checkout, and a Gateway deploy that cleans the checkout removes them.
- Build the web app on the Gateway host: rejected because the Gateway would need Node or Bun only for this step.
- Proxy `/grafana` straight to the Metrics Node address: rejected because that address would be copied into the Gateway site, which the Metrics publication does not rewrite when Metrics moves.
- Refuse cross-site API requests in the same change: deferred because the annotation package's Orbit mode calls the Gateway API from other applications' pages. That policy needs its own decision.

## Consequences

- Operators and the planned macOS app open `https://gateway.orbit` and get the live fleet without running a development server.
- A web release and a Gateway deploy happen independently. Rolling the web app back means pointing `current` at an older release.
- The first web release needs the updated Gateway site. `php artisan orbit:gateway-web` publishes it without a full bootstrap.
- The web app stays as reachable as the Gateway API. It adds no new authority, because every request it makes is an API request from the browser's own address.
- The API still answers cross-origin requests from any page a connected browser visits. That existing exposure is unchanged and needs a separate decision.

## Affects

- Components: apps/gateway
- Browser code: `apps/web`, `bin/web-deploy`
- ADRs: [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client)
- Detail: [Web app](/reference/web-app)
- Verify: `NativeGatewayWebConvergerTest`, `WebDeployTest`, and loading `https://gateway.orbit` after `bin/web-deploy`.
