---
title: "Browser access to the Gateway"
description: "Which web pages may call the Gateway API and MCP endpoints from a browser, and how the Gateway refuses the rest."
covers:
  - apps/gateway/app/Http/Middleware/GuardBrowserOrigins.php
  - apps/gateway/app/Domain/Gateway/BrowserOriginPolicy.php
  - apps/gateway/config/cors.php
  - apps/web/vite.config.ts
---

# Browser access to the Gateway

This page tells an operator which web pages may call the Gateway API from a browser. The Gateway identifies a caller by its WireGuard address. So without this check, any page open in a browser on a WireGuard peer would act with that peer's authority.

## Allowed callers

The check covers `/api/*`, `/mcp`, and `/mcp/*`. It runs before CORS, routing, and the WireGuard identity check.

| Caller | Result |
| --- | --- |
| The CLI, the SDK, MCP clients, and other programs that send no `Origin` and no cross-site `Sec-Fetch-Site` | Allowed. |
| A page on the Gateway's own origin, such as the [web app](/reference/web-app) | Allowed. |
| A page on `https://<gateway node>.<private domain>` | Allowed, with a CORS response for that exact origin. |
| A page on `https://<domain>` of an active `app` Route with private publication | Allowed, with a CORS response for that exact origin. |
| Any other page | Refused with HTTP 403 and `api.origin_refused`. |

A request without `Origin` passes only when its `Sec-Fetch-Site` is absent, `same-origin`, or `none`. So a cross-site request without an `Origin`, such as a link opened from another site, is refused. A request whose origin is `null` is refused. A CORS preflight from a refused page is refused too. The Gateway never answers CORS with `*` and never allows credentials.

Public Routes, custom proxy Routes, analytics tracking hosts, and Routes that are not `active` grant no browser access.

## Annotation package

The annotation package's Orbit mode calls the Gateway from the page it annotates. It works on an Instance with an active private Route. On any other page, the refusal carries no CORS headers, so the browser hides it. The availability check then reports `Cannot reach Orbit. Check the connection.`

## Web app development server

`vp dev` in `apps/web` proxies `/api` to the Gateway and `/grafana` to Grafana. For both, the proxy removes the browser's `Origin` header, because it serves the page on its own origin. The browser's `Sec-Fetch-Site` header still reaches the Gateway, so a cross-site request through the development server is refused too.

For disposable UI work, [run the web app against a topology](/reference/web-app#run-against-a-topology) with `bin/e2e-topology web ISSUE`. Its proxy runs inside the topology's operator container as that topology's WireGuard peer, with a Gateway access grant. It pins the selected Gateway URL and CA for Gateway, realtime, and metrics traffic. It does not fall back to the caller's live profile. Loopback publication and SSH forwarding expose the development page, not a new Gateway origin or an exception to the browser-origin policy.

## Refusal response

A refused request receives this JSON body with HTTP 403.

```json
{"error": {"code": "api.origin_refused", "message": "Browser requests from this origin cannot reach the Gateway API."}}
```

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Route records as the trust list

The Gateway has no login, so a browser on a peer carries the peer's authority. CORS alone does not help, because a cross-site form `POST` reaches the server without a preflight. The annotation package must keep working on development applications, so the Gateway origin alone is too narrow. A fixed list of TLDs such as `*.test` is rejected, because an unrelated site can use those names. The Route list names exactly the applications that Orbit serves. A page on one of them keeps API access, because those applications belong to the operator.

### No browser login

A login or token for browsers is rejected, because it would change the identity model of every client. The origin check closes the exposure without it.
