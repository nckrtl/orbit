---
title: "ADR 0138: Opt public Ingress sites into Caddy certificate automation"
sidebarTitle: "0138 Opt public Ingress sites into certificate automation"
description: "Proposed. Orbit keeps auto_https disable_certs as the Node-wide Caddy default and marks each public Ingress site with tls force_automate, so Let's Encrypt still issues and renews public certificates. The Caddy release floor rises to 2.9.0."
---

# ADR 0138: Opt public Ingress sites into Caddy certificate automation

Orbit keeps `auto_https disable_certs` in the Caddy global options block on every Node. Each public Ingress site block opts back into certificate automation with `tls force_automate`. Caddy then obtains and renews a Let's Encrypt certificate for public hostnames only, and never asks a public CA for a private Orbit hostname. The Caddy release floor rises from 2.8.0 to 2.9.0, the first release with `force_automate`.

## Status

Proposed.

## Context

[ADR 0101](/decisions/0101-simplify-route-publication-and-use-lets-encrypt-on-public-ingress) requires public Ingress to terminate TLS with a Let's Encrypt certificate that Caddy's automatic HTTPS obtains. The public Ingress site block names no `tls` directive and relies on that automation.

Commit `7fe26990` made every Orbit Caddy publisher start the Caddyfile with `auto_https disable_certs`. The goal was to stop Caddy from asking a public CA for a private `.orbit`, `.test`, or `.prod` hostname whenever a pinned Orbit CA certificate did not match its site. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) made that block the only global options block on a Node.

`disable_certs` turns off certificate management for every site on the HTTP server, and public Ingress sites share that server with private sites. On Caddy 2.11.4 the HTTP app logs "skipping automated certificate management for server because it is disabled", never contacts the CA for the public hostname, and loads no stored certificate for it. A TLS handshake to the public hostname then fails with an internal error alert. Doctor did not report it, because its public TLS check looked only for a pinned Orbit CA leaf.

Caddy 2.9.0 added `tls force_automate`. The Caddyfile adapter adds a site with that option to the TLS app's `automate` certificate loader. The loader manages the certificate with the default issuers whatever the `auto_https` global setting is. HTTP-to-HTTPS redirects stay on under `disable_certs`, so the port 80 listener still answers HTTP-01 challenges.

## Decision

- `CaddyGlobalOptions` keeps `auto_https disable_certs` as the Node-wide default. ADR 0137's ownership of the global block does not change.
- The App development renderer writes `tls force_automate` in every public listener site block. That covers public App Routes, composed public sites, and public analytics tracking hosts on Ingress. It uses Caddy's default issuers, so Let's Encrypt is the first issuer.
- Private sites keep their pinned Orbit CA files and get no automation. A mismatch on a private site still fails TLS and does not reach a public CA.
- Orbit adds no ACME issuer, email, or CA endpoint to the global block. Certificate automation is a per-site property.
- The Caddy floor in `CaddyRelease` rises to 2.9.0. A Node below it fails role convergence and Doctor reports `role.caddy_version_unsupported`, as [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source) describes.
- Doctor reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf or when Caddy does not automate its certificate. Caddy automates it when the site block has `tls force_automate` or when the live Caddyfile does not disable certificate management.

## Rejected alternatives

- Remove `disable_certs` and rely on automatic HTTPS again: rejected because any mismatch on a private site sends Caddy to a public CA for a private hostname again.
- Name an explicit ACME issuer in the public site block: rejected because `disable_certs` still skips management for the whole server. An issuer only chooses who issues a managed certificate. It does not make Caddy manage the name.
- Use a global `skip_certificates` or `certificates automate` list with the public hostnames: rejected because it puts per-Route data in the Node-wide block that ADR 0137 keeps static. Every publisher would then need the Ingress Route list.
- Have Orbit obtain the public certificate itself and pin it as a file: rejected because Orbit would take over ACME accounts, challenges, and renewal, which Caddy already does.

## Consequences

- Public hostnames get and renew Let's Encrypt certificates again. Private hostnames never reach a public CA.
- A public Ingress site that Orbit published before this change has no `tls force_automate`. If a later publication writes `disable_certs` before the site gets the option, the site loses its certificate on reload. The renderer writes both in the same publication, so a Node that converges on this release gets both at once.
- Nodes need Caddy 2.9.0 or newer. The pinned Caddy source already installs 2.11.
- The Doctor check reads the Caddyfile and does not perform a TLS handshake. A public hostname whose DNS or port 80 does not reach Ingress still fails ACME. Caddy retries and logs the failure.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0101](/decisions/0101-simplify-route-publication-and-use-lets-encrypt-on-public-ingress) with the mechanism for public certificates, extends [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) with a per-site exception to the global certificate default, and raises the floor from [ADR 0100](/decisions/0100-install-caddy-from-the-pinned-caddy-apt-source)
- Detail: [Caddy configuration](/reference/caddy-configuration#public-ingress-certificates), [Routes](/reference/routes#publish-a-public-route)
- Verify: `apps/gateway` Pest tests for `AppDevCaddyConfigRenderer`, `NativePublicRouteEdgeInspector`, and `CaddyRelease`; a Caddy 2.11.4 run against an ACME test server that obtains a certificate for a `force_automate` site under `auto_https disable_certs`
