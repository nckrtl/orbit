---
title: "ADR 0126: Limit browser API calls to Orbit origins"
sidebarTitle: "0126 Limit browser API calls to Orbit origins"
description: "Proposed. The Gateway refuses cross-origin browser requests to its API and MCP paths unless the page comes from the Gateway itself or an active private App Route."
---

# ADR 0126: Limit browser API calls to Orbit origins

The Gateway accepts a browser request to `/api/*`, `/mcp`, or `/mcp/*` only from its own origin or from the origin of an active private App Route. It refuses every other cross-origin or cross-site browser request before any Gateway code runs. Requests from the CLI, the SDK, and other non-browser clients are unchanged.

## Status

Proposed.

This closes the exposure that [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin) recorded and deferred.

## Context

The Gateway identifies a caller by its WireGuard address and has no login. A browser on a WireGuard peer therefore carries that peer's full authority. Any page in that browser can send requests to `https://gateway.orbit`.

The Gateway has no CORS configuration, so Laravel's default policy applies to `api/*`. That policy allows every origin, every method, and every header. A page on any website can read API responses, including `GET /api/v1/metrics/credentials`, and send requests that change the fleet. CORS alone also never blocks a cross-site form-style `POST`, because the browser sends it without a preflight request.

Two browser clients call the API legitimately:

- The Orbit web app, served from the Gateway origin.
- The annotation package's Orbit mode, which runs inside development applications and calls the Gateway from their Route domains.

Browsers mark each request with `Origin` and `Sec-Fetch-Site`. Non-browser clients send neither header.

## Decision

- A request to `/api/*`, `/mcp`, or `/mcp/*` passes the origin check when it has no `Origin` header and its `Sec-Fetch-Site` is absent, `same-origin`, or `none`.
- It also passes when its `Origin` equals the requested origin, equals `https://<gateway node>.<private domain>`, or equals `https://<domain>` of an active App Route with private publication.
- Every other request to those paths fails with HTTP 403 and `api.origin_refused` before CORS, routing, or WireGuard identity checks run. This includes CORS preflight requests, requests with an opaque `null` origin, and cross-site requests without an `Origin`.
- For an allowed cross-origin request, the Gateway answers CORS with that exact origin. It never answers with `*`, and it never allows credentials.
- Public Routes, custom proxy Routes, analytics tracking hosts, and Routes that are not active grant no browser access.
- The web app's development proxy removes the browser's `Origin` header before it forwards `/api` and `/grafana`. The proxy is a same-origin client of the page it serves, and the browser's `Sec-Fetch-Site` still reaches the Gateway.

## Rejected alternatives

- Keep allowing every origin: rejected because any website open on a WireGuard peer acts with that peer's authority.
- Allow only the Gateway origin: rejected because the annotation package's Orbit mode must keep working on development applications.
- Allow a fixed list of top-level domains such as `*.test`: rejected because a public site or an unrelated local service can use those names. Route records name exactly the applications Orbit serves.
- Rely on CORS alone: rejected because a cross-site form-style `POST` reaches the Gateway without a preflight.
- Add a login or a token for browsers: rejected because it changes the identity model of every client. The origin check closes this exposure without it.

## Consequences

- A website outside Orbit can no longer read from or change the fleet through a connected browser.
- The annotation package keeps working on active private App Routes. It stops working on a public Route or an address without a Route.
- A compromised page on an active private App Route keeps API access. Those applications belong to the operator, and the Route list is the trust boundary.
- Browser requests to the guarded paths read Route and Gateway records once per request. Requests without browser markers skip that lookup.

## Affects

- Components: apps/gateway
- Browser code: `apps/web` development proxy
- ADRs: [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin)
- Detail: [Browser access to the Gateway](/reference/browser-access)
- Verify: `BrowserOriginGuardTest`, and a cross-origin `curl` against `https://gateway.orbit/api/v1/nodes` after deployment.
