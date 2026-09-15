---
title: "Routes"
description: "What a Route records, how Orbit projects its private traffic path through a Node or Router, and which later changes it coordinates or refuses."
---

# Routes

A Route gives an App instance a domain and directs private traffic to it. This page explains domain selection, traffic setup, and supported changes. Each active App instance has exactly one authoritative Route, as [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) requires. A domain change creates a replacement Route under [ADR 0065](/decisions/0065-replace-routes-when-domains-change).

## Route record

The Gateway stores each Route's settings and tracks setup of its certificates, web server, firewall, and DNS records.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one Node or one active Cluster. Active Cluster membership selects Cluster scope even when the Cluster has no TLD. |
| Domain | One immutable normalized domain that no other Route owns. |
| Domain source | Always `generated` or `explicit`. This value never changes, and Orbit does not infer it from the domain. |
| Replacement | Optional `replaces_route_id`, `replaced_by_route_id`, and `replacement_step` that expose a reserved, activating, retiring, or failed replacement pair. |
| Generation basis | The current target Node for a generated Route, or its last target Node after target clearing. An explicit Route stores no generation basis. |
| Publication intent | The requested publication state, retained even when the Route has no target. |
| Public publication | `inactive` until a public Route has a verified Ingress edge, then `active`. A public Route can keep intent while public publication stays inactive. |
| Status | `pending`, `active`, `activating`, `retiring`, or `failed`. Failure details identify the step to retry. |
| Target storage | The Route can own several ordered target rows. An active multi-target set belongs to one explicit production Route and uses distinct active app-prod Nodes in the same Cluster. |
| Configured target | The API, PHP SDK, and CLI accept zero or one App instance target. Generated and development Routes permit at most one target. |

Creating the same explicit Route again with identical App, domain, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route.

## Select a domain and scope

Supply a domain when creating an App instance to request an explicit Route. Otherwise, Orbit generates one from the Node or Cluster top-level domain (TLD). If neither can supply a name, creation stops before source or runtime changes. App instance responses derive the domain and URL from the authoritative Route.

A generated domain combines the instance name, App name, and Node TLD. If the Node has no TLD, Orbit uses its active Cluster's TLD. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines the `default` name.

| App instance name | Generated domain with effective TLD `test` |
| --- | --- |
| `default` | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

An explicit source branch changes neither placement nor generated Route identity. For example, `instance:create <app> <node> default --branch=release` still generates `<app>.test`.

## Generated domains after an App slug update

An App slug update recomputes every generated development Route domain from the new slug, the existing App instance name, and the current effective TLD. The Gateway creates a replacement Route for each domain that must change. The replacement keeps the App, routing scope, provenance, publication intent, and target. The previous generated Route is removed after the replacement is authoritative. Explicit domains never change because of an App slug update.

A default-branch update does not replace a Route or change its domain. The [Apps reference](/reference/apps#update-an-app) owns the App update lifecycle that drives this replacement.

An app-dev Node must have a Node TLD or belong to an active Cluster with a TLD. A standalone app-prod Node can remain valid without a TLD when production creation supplies an explicit Route domain.

Cluster membership determines routing, independently of the domain. A Node in an active Cluster uses Cluster routing, even if the name uses a Node TLD or the Cluster has no TLD. Other Nodes route directly. A Cluster with Routes needs exactly one active Router.

## Route operations

The API, PHP software development kit (SDK), and command-line interface (CLI) expose seven operations and return the Route's relationships.

| Operation | Result |
| --- | --- |
| Create | Store an explicit Route with its App, domain, publication intent, optional single target, and either the target-derived scope or one supplied scope when no target is present. |
| List | Return the Routes visible to the caller in stable order. |
| Show | Return one Route with its stored scope, provenance, generation basis, intent, lifecycle, failure metadata, and target. |
| Update | Reserve a replacement Route for an explicit domain change, or change publication intent on the same Route ID. |
| Target set | Add or replace the one App instance target when the change does not detach an active App instance from its sole Route. |
| Target unset | Remove the target only when that does not leave an active App instance without a Route, unless the same operation removes that App instance. |
| Destroy | The Gateway deletes an eligible Route after untargeted private projection cleanup. It refuses a targeted Route before cleanup. |

The CLI names these operations `route:create`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:unset`, and `route:destroy`.

## Change or clear a target

The Gateway validates the complete proposed Route before it commits a target change.

| Change | Result |
| --- | --- |
| Set the existing App instance target again | Return the unchanged Route. |
| Clear an already empty Route | Return the unchanged Route. |
| Set an App instance from another Route | Return `route.target_conflict`, identify the requested and existing Routes, and preserve both associations. |
| Replace a generated or explicit Route target | Return `route.target_conflict` when replacement would detach an active App instance from its Route, and preserve the association. |
| Clear the only target | Return `route.target_conflict` when clearing would detach an active App instance from its Route, and preserve the association. |
| Remove a targeted Route | Return `route.target_conflict` when removal would detach an active App instance from its Route, and preserve the Route, App instance, and association. |
| Set an App instance from another App or an inactive App instance | Reject the change and retain the complete current Route. |
| Set a generated target without an effective TLD | Reject the change and retain the complete current Route. |
| Set a direct Node, backend URL, second generated target, or balancing value | Reject the change and retain the complete current Route. |

A permitted generated target replacement releases the old generation basis only after the replacement commits. Clearing a target from a non-active App instance does not release that basis.

## Set up private traffic

The Gateway prepares the initial private Route before it marks the Route and App instance active. It does not require the application to return a successful response.

### Node scope

For Node routing, Gateway Domain Name System (DNS) records point the domain at the workload Node. Its Caddy service terminates HTTPS with an Orbit certificate authority (CA) certificate and serves the App instance's web root through its runtime.

Before publishing a development Route, the Gateway gives Caddy read access to each local development Web root and traversal access to its parent directories. Caddy cannot read the other source files. The Web root must exist inside its checkout. Symlinks in the Web root are refused except Laravel's `public/storage` link to that checkout's `storage/app/public`. Nested Git worktrees keep access to their own Web roots. If file-access preparation fails, the Gateway restores the preceding permissions and reports `app-dev.source_access_failed` at `source-access` before publishing the Route. If permission recovery also fails, it retains a protected permission snapshot on the Node for recovery.

### Cluster scope

For Cluster routing, Gateway DNS points the Route domain and Cluster TLD at the Router. [ADR 0062](/decisions/0062-select-cluster-router-dns-addresses-from-lan-intent) defines which Router address each requester receives.

An active LAN-configured WireGuard member of that active Cluster receives the Router's configured LAN address for the Cluster TLD and for each exact Cluster-scoped Route, including a Route domain outside the Cluster TLD. Other permitted requesters receive the Router's WireGuard address.

The Gateway identifies the requester from the registered WireGuard source that delivered the query. A shared LAN subnet or an identity in the DNS message does not change the selected address.

[Private DNS](/reference/private-dns#cluster-router-addresses) owns how an operator inspects that selection, removes incorrect LAN intent, and recognizes an unreachable configured LAN path.

Router Caddy preserves the domain as the HTTP `Host` value and Transport Layer Security (TLS) server name when it forwards Orbit-CA HTTPS to the workload Node. Orbit issues separate private keys to the Router and workload Node. When both roles share one Node, the composed Caddy service sends the request to the local runtime without proxying to its own HTTPS listener.

The Router uses the workload Node's configured LAN address. It uses WireGuard only when that LAN address is absent. A configured but unreachable LAN path fails publication and never falls back to WireGuard.

### Development-server endpoint

The reserved path `/__orbit/vite` serves live frontend assets and hot module replacement (HMR) over the Route's HTTPS domain on port 443. Cluster DNS points that domain at the Router. The application serves its web root on the same domain. See [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin).

Workload Caddy reverse-proxies that path to `127.0.0.1:5173` on the owning Node. Router Caddy forwards the path with the Route domain as the HTTP `Host` value and TLS server name. HTTPS and WSS terminate with the Route's Orbit certificate-authority certificates. The toolchain process speaks HTTP on loopback and does not present a certificate to the browser.

Two App instances that use port 5173 on different Nodes stay isolated because each Caddy site proxies only to its own Node loopback. When the process on that loopback is stopped, Caddy returns a proxy error for that domain's reserved path and does not select another App instance.

An operator configures the frontend toolchain to publish asset and HMR URLs on the reserved path. A development systemd Process receives these environment values from the Route domain.

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | `5173` |

A Vite development server that follows the contract binds loopback port 5173 and publishes the Cluster origin:

```js
import { defineConfig } from 'vite'

const origin = process.env.ORBIT_DEV_SERVER_ORIGIN

export default defineConfig({
    server: {
        host: '127.0.0.1',
        port: Number(process.env.ORBIT_DEV_SERVER_PORT || 5173),
        strictPort: true,
        origin,
        hmr: origin
            ? {
                protocol: 'wss',
                host: process.env.ORBIT_DEV_SERVER_HOST,
                clientPort: 443,
                path: process.env.ORBIT_DEV_SERVER_PATH,
            }
            : undefined,
    },
})
```

Laravel's `@vite` directive reads the `public/hot` file. The file must contain `ORBIT_DEV_SERVER_ORIGIN` so the browser requests `/__orbit/vite/@vite/client` on the Route domain. The [process reference](/reference/app-processes-and-schedules#add-a-process) describes the injected certificate and origin environment.

### Private network and publication

Orbit exposes the Route only after its runtime, certificates, Caddy configuration, and firewall rules are ready. Private DNS is published last.

Active WireGuard membership trusts a Node to reach every other active WireGuard member over all protocols and ports. Node grants do not limit ordinary private Node traffic; they authorize only Orbit commands and Gateway API actions. Grafana is the exception: [`metrics.orbit`](/reference/metrics#private-access-and-credentials) requires Gateway authority, and the Metrics node refuses direct Grafana traffic from other peers. A configured LAN path can carry Router-to-workload traffic only when it preserves the same registered-Node trust boundary.

Public traffic enters through Ingress on HTTP or HTTPS. The firewall does not expose a Router or workload Node as a direct public endpoint. A standalone production Route is private and terminates Orbit-CA TLS on its workload Node. Orbit publishes private DNS only after runtime, certificates, Caddy, and firewall preparation succeed.

When the Gateway converges the app-prod role, it retires the Orbit-owned public HTTP and HTTPS workload rules and does not republish them. Ingress keeps public HTTP and HTTPS publication. Unrelated firewall rules stay in place. A retry after a failed cleanup uses the same owned-rule set.

### Inspect private projections with Doctor

Doctor instance checks compare each active private Route and its target with the expected routing scope, Router and workload Caddy, Route-scoped certificate, private DNS, role-owned firewall, and detected Laravel URL. They stay in the existing `instance` family, remain verify-only, and change no Route, Node, service, certificate, DNS, firewall, Laravel file, lifecycle state, or lock.

Missing, stale, malformed, and unreachable observations become bounded drift or unverifiable findings. Reports, Activity, errors, and diagnostics expose no command, path, configuration contents, address, credential, or exception text.

A valid serving configuration stays healthy when the application returns HTTP 500. Doctor does not use application health to classify Orbit provisioning.

Related-node checks use only caller-authorized selected nodes. An unavailable Router observation reports `instance.related_node_unverifiable` without contacting an unselected Node.

| Doctor issue code | Difference |
| --- | --- |
| `instance.private_routing_scope_mismatch` | The Route Node or Cluster scope differs from the target's expected placement. |
| `instance.router_caddy_mismatch` | Router Caddy does not match the private Route. |
| `instance.workload_caddy_mismatch` | Workload Caddy does not match the private Route. |
| `instance.private_certificate_mismatch` | A Route-scoped certificate is missing or stale. |
| `instance.private_dns_mismatch` | Private DNS does not answer the Route domain with the expected address. |
| `instance.private_firewall_mismatch` | Role-owned firewall policy differs from the Route's expected rules. |
| `instance.laravel_url_mismatch` | A detected Laravel `APP_URL` differs from the Route domain. |
| `instance.related_node_unverifiable` | A required related Node is outside the selected inspection set. |
| `instance.inspection_failed` | A required observation is missing, malformed, or unreachable. |

## Publish a public Route

A public Route terminates HTTPS on the Cluster Ingress, forwards privately through the Cluster Router, and reaches the app-prod workload without exposing placement or workload listeners. Role ownership follows [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement). Only Ingress may be the public boundary; [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing) records that rule.

The Gateway activates public publication only when the Route is Cluster-scoped, the Cluster is active, and that Cluster has exactly one active Ingress and one active Router. A Node-scoped Route, an inactive Cluster, or a Cluster that lacks an active Ingress or Router keeps publication intent and reports public publication as inactive. Those cases create no public certificate, listener, firewall rule, or partial activation.

The Ingress artifact names the public domain and the Router upstream. It does not name an App instance, workload Node, or backend pool. Router Caddy keeps backend selection. Workload Caddy stays private.

Public TLS terminates on the Ingress Node with an Orbit certificate-authority certificate for the Route domain. Ingress forwards Orbit-CA HTTPS to the Router over the configured LAN address and uses WireGuard only when no LAN address is set. A configured but unreachable LAN path fails and does not fall back to WireGuard. Ingress preserves the original `Host` value, HTTPS scheme, and client address.

When Ingress shares a Node with the Router, with app-prod, or with both, one composed Caddy service serves the public Route. The composed site does not proxy to its own public listener.

Firewall policy admits public HTTP and HTTPS only on the Ingress Node, and only while that Cluster has at least one active public Route. Router and workload listeners stay private. Direct public workload traffic is denied.

An exact client-local override can send the Route domain to the Router address and then to the workload address without changing public Ingress or DNS state. [Local resolver overrides](/reference/local-resolver-overrides) owns installing that caller-local resolver.

Creating or showing a public Route adds no Node public-IP field and calls no DNS-provider API.

A stale application HTTP error does not block a valid public edge. The trusted response remains observable and the App instance remains active.

### Activate public publication

A publication-only update on the same domain keeps the Route ID. The Gateway prepares the Ingress certificate, stages the Ingress Caddy site outside the live import, and verifies the private hops before it marks public publication active and installs the handler. Firewall rules open after at least one public Route is active. The candidate handler stays unreachable until those checks succeed, including when another Route already keeps Ingress ports open.

A combined domain and publication change reserves a replacement Route with public publication intent. The current Route stays authoritative until cutover. Environment synchronization and private infrastructure complete before public exposure. Cutover makes the replacement authoritative in one database transition. Successful cleanup deletes the preview Route and releases its domain.

The PHP software development kit (SDK) and `route:update` CLI send the combined domain and publication request and return the replacement Route identity. They do not bypass Cluster Ingress.

Failure before cutover leaves the preview Route authoritative, restores its environment projection, and rolls back the candidate public edge. Incomplete cleanup remains inspectable and recovers only through the identical request. Failure after cutover keeps the replacement authoritative and recovers forward. A conflicting domain or publication request changes no recorded intent.

The Gateway refuses Ingress replacement or removal while any public Route in that Cluster depends on the Ingress, unless a later operation preserves that public edge atomically.

Doctor instance checks report public Ingress, private forwarding, TLS, and firewall drift with bounded codes. They expose no placement and change no Ingress, Router, workload, TLS, or firewall state. Related-node checks use only caller-authorized selected nodes. An unavailable observation reports `instance.related_node_unverifiable` without contacting an unselected Node.

### Publication ownership

Only one operation can publish private Caddy, DNS, or Metrics configuration at a time. The Gateway takes a shared lock before reading current Route, target, Cluster, and Router state. It holds the lock through Caddy updates, DNS publication, and activation. Nested calls in the same request share the lock. Failure releases it so a retry can read fresh state.

A competing publisher waits up to 30 seconds, or the remaining command time if shorter. If still blocked, it returns HTTP 409 `app-dev.projection_busy` before generating configuration, publishing it, or activating records. A retry reads fresh state after taking the lock.

### Gateway worker rollout

Deploy the shared publication owner by stopping admission of new Gateway mutations, draining requests that run the old code, restarting every Gateway worker, and resuming mutations. Keep `$ORBIT_HOME/.dnsmasq-projections.lock` in place throughout the rollout. The deployment deletes no lock file and runs no stored-data migration.

## Change an existing Route

### Change a Node TLD

The Gateway reconciles every private Route whose current target Node or retained generation basis uses the Node before the Node TLD becomes authoritative. It inventories those Routes, validates the complete proposed domains, and refuses an invalid or occupied result before it changes the Node, a Route record, environment configuration, or traffic.

A generated Route receives a replacement domain from its target name and the new effective TLD. A targetless generated Route follows the same retained generation basis. An explicit Route keeps its domain.

The Gateway prepares and verifies workload and Router Caddy, Route-scoped certificates, firewall policy, and the detected Laravel URL against the candidate replacement before it publishes the new domain. It commits the Node TLD only after that publication. Development Laravel sources receive `APP_URL` in the environment file and cached configuration without Composer, Artisan, or application bootstrap. Production sources render stored configuration against the candidate Route. Making a stale application cache effective remains a separate application setup or deployment step.

Failure before publication restores the previous Node TLD, Route records, infrastructure intent, and Laravel URL. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step. A conflicting Node or Route mutation is refused. After cutover, retry continues forward so two authoritative domains are never exposed for the same Route.

Old projections are removed only after the replacement domain is authoritative.

### Change an explicit private domain

The Gateway can change a development or production Route domain when the Route is active, explicit, and private. A shared production Route keeps its complete ordered target pool on one replacement. This is the operation that replaces a production clone's preview domain with its intended private domain.

The Gateway reserves a unique pending replacement Route for the same App and complete target set while the existing Route stays the sole authoritative `active` Route. It refuses an invalid, occupied, or conflicting domain before it changes Route records, environment configuration, runtime projections, or traffic.

When an eligible target has no recorded source profile, the Gateway returns HTTP 409 `instance.source_profile_missing` and names recovery through the same creation request with `recover_source_profile`. The [applications domain](/domains/applications#provision-the-application-endpoint) owns that recovery.

The Gateway prepares the replacement workload certificate and Caddy site before it prepares the Router certificate, workload firewall policy, and Router Caddy site. For a detected development Laravel source, it aligns `APP_URL` in the environment file and cached configuration without running Composer, Artisan, or application bootstrap. A non-Laravel development source receives no application configuration change.

For production, the Gateway checks the saved environment location and renders stored settings for the candidate Route. It resolves `{{app_instance.domain}}`, preserves other values and literal application keys, and replaces only the home's `.env`. It runs no Composer, Artisan, framework, cache, deployment, or restart commands. Add required cache or process commands to a separate deployment step. A stale cached URL or HTTP error does not block a valid infrastructure change. See [App instance environment variables](/reference/environment-variables#synchronize-during-a-domain-change).

Cutover is one database transition. The Gateway publishes the replacement domain in private DNS only after it verifies every required projection, then marks the replacement `activating` and the old Route `retiring`. App instance output derives only the replacement domain. Route inspection exposes both records and their relationship. Cleanup then removes old projections, deletes the retiring Route, and marks the replacement `active`.

### Resume or refuse a change

During a change, Route inspection reports both records, `replacement_step`, `failed_step`, and `error_code`. Retry with the same domain to verify completed work and resume the first incomplete step. A different domain returns `route.domain_change_conflict` and changes neither record.

A failure before cutover leaves the old Route authoritative. Successful cleanup of replacement projections deletes the replacement. Incomplete cleanup retains an inspectable `failed` replacement. Only the identical request recovers that replacement.

A failure after cutover keeps the replacement authoritative. Retry continues forward until the replacement is `active`, every old projection is removed, the retiring Route is deleted, and its domain becomes available.

The reconciliation guard still returns `route.reconciliation_required` for operator-requested generated Route domain changes and for active Route target changes. A publication-only change on an active Route publishes or withdraws the public Ingress edge on that Route ID. The guard also refuses Node WireGuard, LAN, or Cluster-membership changes when an active Route depends on the change. The same rule covers Cluster activation, deactivation, or TLD changes and Router replacement or clearing. A Node TLD set, change, or clear is not this refusal; it reconciles the generated private Routes that depend on that Node.

Deployment, code rollback, clone finalization, App instance removal, environment import, stored environment updates, environment synchronization, and a domain replacement share the target App instance's bounded operation owner. A competitor waits or returns `env.operation_busy` before mutation. The domain replacement also holds the shared private projection owner through its Caddy and DNS work. It does not change source, the selected production release, SQLite data, or local PHP tuning.

Route and App instance removal keep their coordinated removal contract. Setting the existing target or clearing an already empty Route succeeds without creating, deleting, or reassigning a Route association. A Node grant change does not alter private network reachability and retains its command-authorization behavior.

App instance removal is the coordinated target-clear exception. After complete source and Route preflight, the Gateway marks each accepted App instance `removing`. Development removal publishes an unavailable response before deleting each final-target Route in worktree-first order. Production removal republishes every ordered survivor when a shared Route remains. Final-target removal clears managed Route projections, deletes the Route, and releases its domain before source finalization. A projection failure keeps the unfinished Route checkpoint available for retry. The [App instance removal reference](/reference/appinstance-removal) owns content retention, the transient response, cascade order, and retry behavior.

During a Node or Cluster placement mutation, the Gateway validates only Routes whose direct scope, target Nodes, retained generation basis, or provisioning baseline depends on the affected Nodes or Clusters. It compares proposed domains with one operation-local index of all Route domain owners, so an unaffected Route still blocks a collision. Routes outside this workset stay unchanged. A Node TLD change fully reconciles those generated private Routes. Other active Route, Cluster, and Router mutations keep the separate reconciliation refusal.

### Remove an untargeted private Route

`route:destroy` removes an already untargeted private Route. The Gateway refuses a targeted Route before it changes projections. It then removes Route-owned DNS records, certificates, workload and Router Caddy fragments, and firewall entries, and deletes the Route record last.

A failure at a projection step or at final record deletion keeps the Route inspectable with bounded `failed_step` and `error_code`. Retry uses the same destroy request, revalidates completed work, and resumes at the earliest unverified step. It does not restore removed projections, delete unrelated Routes or workloads, or accept a conflicting target mutation.

Successful removal releases the domain immediately. An identical retry finds no Route. The Node's shared runtime and composed Caddy service stay. App instance removal remains the owner of final-target deletion; see [App instance removal](/reference/appinstance-removal).

### Router transition ownership

Each Cluster has a Router operation lock. Setting or clearing its Router holds the lock through validation, setup, activation, and cleanup. Cluster status and TLD changes also use it while updating dependent state. Different Clusters use separate locks.

A same-Cluster contender waits for at most 30 seconds or the shorter remaining command deadline. If the current transition still owns the Cluster, the Gateway returns `cluster.router_busy` with HTTP 409 before validation or mutation. A retry enters from current Cluster, Node, Router assignment, and Route state after the previous owner releases.

The owner has no expiry during remote work and releases after success or failure. Router baseline work can reenter the same request owner. Replacement keeps the current Router active until its candidate is ready, promotes the candidate before obsolete cleanup, and keeps failure evidence for an identical retry. Clear removes non-active assignments first and the active assignment last, then resumes retained cleanup on retry.

Operations acquire owners in this order when they need more than one: node lifecycle or role, app-dev source, Cluster Router, Metrics lifecycle or credential, development projection, then remote host. Projection and remote host callbacks do not acquire an earlier owner, and no database transaction remains open during remote work.

## Guard removal

Route ownership prevents deletion from leaving an invalid retained record.

| Removal | Guard |
| --- | --- |
| App | Refused while the App owns a Route. |
| Cluster | Refused while the Cluster owns a Route. |
| Node | Refused while a Route retains the Node as scope, target host, or generation basis. |
| App instance | Clears its target only inside an accepted removal, retains a non-empty shared production Route, and deletes a final-target Route before source finalization. |
| App role | Refused while the Node hosts a Route target. |
| Cluster Ingress | Refused while any public Route in the Cluster depends on the Ingress. |
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | The Gateway deletes an untargeted private Route after projection cleanup, or a pending targeted Route whose App instance is not active. It refuses an active targeted Route before cleanup. |

## Compatibility and limits

Route operations do not change App instance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract projects private Routes and publishes public Routes through Cluster Ingress. It changes a development or production Route domain by reserving a replacement Route when the current Route is active and explicit, and it removes an already untargeted private Route with its Route-owned projections. It also coordinates target clearing during development checkout, worktree, fixed-set cascade, and production App instance removal.

It does not implement generated domain changes outside replacement reservation, other later Route reconciliation, public DNS providers, public production pool creation, production placement, application setup, or application health tracking. [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), [ADR 0024](/decisions/0024-follow-generated-route-targets), [ADR 0029](/decisions/0029-manage-laravel-application-urls-through-orbit), [ADR 0030](/decisions/0030-complete-appinstance-provisioning-without-application-health-gates), [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic), and [ADR 0041](/decisions/0041-delete-an-empty-route-during-appinstance-removal) define the remaining boundaries.
