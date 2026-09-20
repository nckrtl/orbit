---
title: "ADR 0101: Keep one Route publication field and terminate public Ingress with Let's Encrypt"
sidebarTitle: "0101 Keep one Route publication field and public Let's Encrypt"
description: "Proposed. A Route stores only publication=private|public. Public-edge readiness lives on status, failed_step, and Doctor. A healthy public Ingress terminates with Let's Encrypt and converge does not pin Orbit CA over a working public certificate."
---

# ADR 0101: Keep one Route publication field and terminate public Ingress with Let's Encrypt

A Route stores one publication field, `private` or `public`. Public-edge readiness is ordinary Route lifecycle, not a second `*publication*` field. When `publication` is `public` and the Ingress edge is healthy, Ingress terminates TLS with Let's Encrypt. Converge must not replace a working public Let's Encrypt certificate with an Orbit CA leaf. Private Routes keep Orbit CA.

## Status

Proposed.

## Context

[ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) separates Ingress, Router, and workload ownership and requires an active Ingress before a public Route can reach the internet. After that decision, the Route grew a twin field, `public_publication` (`inactive` or `active`), beside `publication` (`private` or `public`). Operators set intent with `publication`. The Gateway set `public_publication` only after it verified the Ingress edge. That second field appeared on the API, CLI, OpenAPI, and analytics tracking hosts.

The twin field duplicated lifecycle that `status`, `failed_step`, and Doctor already describe.

- When a public Route is `status=active` and `public_publication=inactive`, the extra field only means the Ingress edge is not live yet.
- Callers and docs then have to explain two publication words.

Public Ingress also pinned an Orbit CA leaf into Caddy (`tls /etc/caddy/orbit-certificates/route-{id}-ingress/current/cert.pem …`). Browsers and Cloudflare Full (Strict) do not trust that issuer, so a working public host failed with TLS 525s. Converge re-issued and re-pinned that Orbit CA leaf on every public Ingress site, including hosts that already had a Let's Encrypt certificate. Docs described public Ingress as terminating with Orbit CA.

Private hops stay inside the Cluster trust boundary and must keep Orbit CA. Custom proxy Routes stay private. Analytics tracking hosts already mirror the App Route's publication and must follow the same single-field model.

Renaming `public_publication` to `access` would keep a second publication-shaped field. The product needs one field and ordinary readiness signals.

## Decision

- A Route stores one publication field: `publication` = `private` | `public`. The Gateway drops `public_publication` from persistence, responses, and CLI rendering. It does not add `access` or another `*publication*` field.
- Public-edge readiness belongs on the Route's normal lifecycle. Eligibility, activation progress, and recovery use `status`, `replacement_step`, `failed_step`, `error_code`, and Doctor. A public Route may keep `publication=public` when the Cluster cannot yet serve a public edge. Those cases create no public listener, Let's Encrypt site, firewall opening, or partial activation.
- The Gateway treats a public edge as live when `publication` is `public`, the Route is `active` or `activating`, the Cluster is eligible (active Cluster, one active Ingress, one active Router, Cluster scope), and `replacement_step` ranks at `public-activated` or after. A finished public Route keeps that completed step so a never-activated public Route (`replacement_step` null) is not treated as live. Live Caddy and Ingress firewall follow that derived state.
- When `publication` is `public` and the public edge is healthy, Ingress Caddy uses automatic HTTPS so Let's Encrypt is the default issuer. The Gateway does not issue or pin an Orbit CA leaf on the Ingress site. Converge must not write file-based `tls` paths that replace a working public Let's Encrypt certificate with Orbit CA.
- Ingress still forwards Orbit-CA HTTPS to the Router. Router and workload certificates stay Orbit CA. Private Routes, custom proxy Routes, and reserved private hostnames stay on Orbit CA.
- A tracking host continues to mirror the App instance Route's `publication`. Its API shape follows this model: `publication` and Route lifecycle, not `public_publication`.
- Doctor reports `instance.public_tls_mismatch` when a live public Ingress site pins an Orbit CA leaf or otherwise fails public TLS. Doctor stays verify-only.

This decision supersedes ADR 0011 only where that record says Orbit marks a separate public-publication state and where docs described public Ingress as terminating with Orbit CA. Ingress ownership, private-hop Orbit CA, and the Ingress-to-Router-to-workload path remain.

## Rejected alternatives

- Rename `public_publication` to `access`: rejected because it keeps a second publication-shaped field and does not fix the TLS pin.
- Keep `public_publication` as an internal column and hide it from the API: rejected because the twin state would still exist and still drift from `status` and Doctor.
- Fail a public Route that is not yet eligible: rejected because operators already store public intent before Ingress exists, and private traffic can stay healthy.
- Keep Orbit CA on public Ingress and document Cloudflare Full (not Strict): rejected because public browsers do not trust Orbit CA, and converge would keep breaking a working Let's Encrypt certificate.
- Fall back from Let's Encrypt to Orbit CA when ACME fails: rejected because that silent downgrade is the operational failure this decision removes. ACME failure stays on `failed_step` and Doctor.

## Consequences

- Operators set one field and read readiness from the Route they already inspect.
- Public hosts can satisfy Cloudflare Full (Strict) and ordinary browsers.
- Existing public Ingress sites that still pin Orbit CA change to automatic HTTPS on the next public-edge converge. Caddy then obtains Let's Encrypt once HTTP-01 can reach Ingress port 80. The operator still creates public DNS; Orbit calls no DNS-provider API.
- There is a window after cutover where Caddy has published the public site and Let's Encrypt has not yet issued. Doctor reports that as public TLS drift until the certificate exists.
- API, SDK, CLI, OpenAPI, and analytics host payloads lose `public_publication`.
- Private Route and custom-proxy certificate handling does not change.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: supersedes [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) only for the twin public-publication field and public Ingress Orbit CA termination; extends [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances) so tracking hosts follow the single publication field
- Detail: [Routes](/reference/routes#publish-the-public-edge)
- Verify: Gateway Route, public-edge, Doctor, and analytics tests; SDK and CLI Route and analytics contracts; `composer docs-lint`
