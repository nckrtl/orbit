---
title: "Browser access to the Gateway"
description: "Which web pages may call the Gateway API and MCP endpoints from a browser, and how the Gateway refuses the rest."
---

# Browser access to the Gateway

This page tells an operator which web pages may call the Gateway API from a browser. The Gateway identifies a caller by its WireGuard address, so any page open on a WireGuard peer would otherwise act with that peer's authority. [ADR 0125](/decisions/0125-limit-browser-api-calls-to-orbit-origins) records the decision.

## Allowed callers

The check covers `/api/*`, `/mcp`, and `/mcp/*`. It runs before CORS, routing, and the WireGuard identity check.

| Caller | Result |
| --- | --- |
| CLI, SDK, MCP clients, and other programs that send no `Origin` or `Sec-Fetch-Site` | Allowed, as before. |
| A page on the Gateway origin, such as the [web app](/reference/web-app) | Allowed. |
| A page on `https://<domain>` of an active App Route with private publication | Allowed, with a CORS response for that exact origin. |
| Any other page | Refused with HTTP 403 and `api.origin_refused`. |

A refused request includes a CORS preflight, a request whose origin is `null`, and a cross-site request without an `Origin` header, such as a link opened from another site. The Gateway never answers CORS with `*` and never allows credentials.

Public Routes, custom proxy Routes, analytics tracking hosts, and Routes that are not `active` grant no browser access. The Gateway origin is `https://<gateway node>.<private domain>`, which is `https://gateway.orbit` by default.

## Annotation package

The annotation package's Orbit mode calls the Gateway from the page it annotates. It works on an Instance with an active private Route. On any other page, its availability check reports the refusal.

## Web app development server

`vp dev` in `apps/web` proxies `/api` and `/grafana` to the Gateway. The proxy removes the browser's `Origin` header, because it serves the page on its own origin. The browser's `Sec-Fetch-Site` header still reaches the Gateway, so a cross-site request through the development server is refused too.

## Refusal response

A refused request receives this JSON body with HTTP 403.

```json
{"error": {"code": "api.origin_refused", "message": "Browser requests from this origin cannot reach the Gateway API."}}
```
