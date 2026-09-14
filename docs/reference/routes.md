---
title: "Routes"
description: "What a Route records, how Orbit projects its private traffic path through a Node or Router, and which later changes it coordinates or refuses."
---

# Routes

A Route gives an App instance a hostname and directs private traffic to it. This page explains hostname selection, traffic setup, and supported changes. Each active App instance has exactly one Route, as [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) requires.

## Route record

The Gateway stores each Route's settings and tracks setup of its certificates, web server, firewall, and DNS records.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one Node or one active Cluster. Active Cluster membership selects Cluster scope even when the Cluster has no TLD. |
| Hostname | One normalized hostname that no other Route owns. |
| Hostname source | Always `generated` or `explicit`. This value never changes, and Orbit does not infer it from the hostname. |
| Generation basis | The current target Node for a generated Route, or its last target Node after target clearing. An explicit Route stores no generation basis. |
| Publication intent | The requested publication state, retained even when the Route has no target. |
| Status | Pending during setup, then active once the private traffic path is ready. Failure details identify the step to retry. |
| Target storage | The Route can own several ordered target rows. An active multi-target set belongs to one explicit production Route and uses distinct active app-prod Nodes in the same Cluster. |
| Configured target | The API, PHP SDK, and CLI accept zero or one App instance target. Generated and development Routes permit at most one target. |

Creating the same explicit Route again with identical App, hostname, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route.

## Select a hostname and scope

Supply a hostname when creating an App instance to request an explicit Route. Otherwise, Orbit generates one from the Node or Cluster top-level domain (TLD). If neither can supply a name, creation stops before source or runtime changes. App instance responses derive the hostname and URL from the Route.

A generated hostname combines the instance name, App name, and Node TLD. If the Node has no TLD, Orbit uses its active Cluster's TLD. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines the `default` name.

| App instance name | Generated hostname with effective TLD `test` |
| --- | --- |
| `default` | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

An explicit source branch changes neither placement nor generated Route identity. For example, `instance:create <app> <node> default --branch=release` still generates `<app>.test`.

An app-dev Node must have a Node TLD or belong to an active Cluster with a TLD. A standalone app-prod Node can remain valid without a TLD when production creation supplies an explicit Route hostname.

Cluster membership determines routing, independently of the hostname. A Node in an active Cluster uses Cluster routing, even if the name uses a Node TLD or the Cluster has no TLD. Other Nodes route directly. A Cluster with Routes needs exactly one active Router.

## Route operations

The API, PHP software development kit (SDK), and command-line interface (CLI) expose seven operations and return the Route's relationships.

| Operation | Result |
| --- | --- |
| Create | Store an explicit Route with its App, hostname, publication intent, optional single target, and either the target-derived scope or one supplied scope when no target is present. |
| List | Return the Routes visible to the caller in stable order. |
| Show | Return one Route with its stored scope, provenance, generation basis, intent, lifecycle, failure metadata, and target. |
| Update | Change an explicit Route hostname or mutable publication intent without changing its App, provenance, generation basis, or scope. |
| Target set | Add or replace the one App instance target when the change does not detach an active App instance from its sole Route. |
| Target unset | Remove the target only when that does not leave an active App instance without a Route, unless the same operation removes that App instance. |
| Destroy | Delete the Route and only its Route-owned target rows when no active App instance depends on it. |

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

For Node routing, Gateway Domain Name System (DNS) records point the hostname at the workload Node. Its Caddy service terminates HTTPS with an Orbit certificate authority (CA) certificate and serves the App instance's web root through its runtime.

Before publishing a development Route, the Gateway gives Caddy read access to each local development Web root and traversal access to its parent directories. Caddy cannot read the other source files. The Web root must exist inside its checkout. Symlinks in the Web root are refused except Laravel's `public/storage` link to that checkout's `storage/app/public`. Nested Git worktrees keep access to their own Web roots. If file-access preparation fails, the Gateway restores the preceding permissions and reports `app-dev.source_access_failed` at `source-access` before publishing the Route. If permission recovery also fails, it retains a protected permission snapshot on the Node for recovery.

### Cluster scope

For Cluster routing, Gateway DNS points the Route hostname and Cluster TLD at the Router. [ADR 0062](/decisions/0062-select-cluster-router-dns-addresses-from-lan-intent) defines which Router address each requester receives.

An active LAN-configured WireGuard member of that active Cluster receives the Router's configured LAN address for the Cluster TLD and for each exact Cluster-scoped Route, including a Route hostname outside the Cluster TLD. Other permitted requesters receive the Router's WireGuard address.

The Gateway identifies the requester from the registered WireGuard source that delivered the query. A shared LAN subnet or an identity in the DNS message does not change the selected address.

[Private DNS](/reference/private-dns#cluster-router-addresses) owns how an operator inspects that selection, removes incorrect LAN intent, and recognizes an unreachable configured LAN path.

Router Caddy preserves the hostname as the HTTP `Host` value and Transport Layer Security (TLS) server name when it forwards Orbit-CA HTTPS to the workload Node. Orbit issues separate private keys to the Router and workload Node. When both roles share one Node, the composed Caddy service sends the request to the local runtime without proxying to its own HTTPS listener.

The Router uses the workload Node's configured LAN address. It uses WireGuard only when that LAN address is absent. A configured but unreachable LAN path fails publication and never falls back to WireGuard.

### Development-server endpoint

The reserved path `/__orbit/vite` serves live frontend assets and hot module replacement (HMR) over the Route's HTTPS hostname on port 443. Cluster DNS points that hostname at the Router. The application serves its web root on the same hostname. See [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin).

Workload Caddy reverse-proxies that path to `127.0.0.1:5173` on the owning Node. Router Caddy forwards the path with the Route hostname as the HTTP `Host` value and TLS server name. HTTPS and WSS terminate with the Route's Orbit certificate-authority certificates. The toolchain process speaks HTTP on loopback and does not present a certificate to the browser.

Two App instances that use port 5173 on different Nodes stay isolated because each Caddy site proxies only to its own Node loopback. When the process on that loopback is stopped, Caddy returns a proxy error for that hostname's reserved path and does not select another App instance.

An operator configures the frontend toolchain to publish asset and HMR URLs on the reserved path. A development systemd Process receives these environment values from the Route hostname.

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-hostname>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route hostname |
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

Laravel's `@vite` directive reads the `public/hot` file. The file must contain `ORBIT_DEV_SERVER_ORIGIN` so the browser requests `/__orbit/vite/@vite/client` on the Route hostname. The [process reference](/reference/app-processes-and-schedules#add-a-process) describes the injected certificate and origin environment.

### Private network and publication

Orbit exposes the Route only after its runtime, certificates, Caddy configuration, and firewall rules are ready. Private DNS is published last.

Active WireGuard membership trusts a Node to reach every other active WireGuard member over all protocols and ports. Node grants do not limit ordinary private Node traffic; they authorize only Orbit commands and Gateway API actions. Grafana is the exception: [`metrics.orbit`](/reference/metrics#private-access-and-credentials) requires Gateway authority, and the Metrics node refuses direct Grafana traffic from other peers. A configured LAN path can carry Router-to-workload traffic only when it preserves the same registered-Node trust boundary.

Public traffic enters through Ingress on HTTP or HTTPS. The firewall does not expose a Router or workload Node as a direct public endpoint. A standalone production Route is private and terminates Orbit-CA TLS on its workload Node. Orbit publishes private DNS only after runtime, certificates, Caddy, and firewall preparation succeed.

When the Gateway converges the app-prod role, it retires the Orbit-owned public HTTP and HTTPS workload rules and does not republish them. Ingress keeps public HTTP and HTTPS publication. Unrelated firewall rules stay in place. A retry after a failed cleanup uses the same owned-rule set.

### Publication ownership

Only one operation can publish private Caddy, DNS, or Metrics configuration at a time. The Gateway takes a shared lock before reading current Route, target, Cluster, and Router state. It holds the lock through Caddy updates, DNS publication, and activation. Nested calls in the same request share the lock. Failure releases it so a retry can read fresh state.

A competing publisher waits up to 30 seconds, or the remaining command time if shorter. If still blocked, it returns HTTP 409 `app-dev.projection_busy` before generating configuration, publishing it, or activating records. A retry reads fresh state after taking the lock.

### Gateway worker rollout

Deploy the shared publication owner by stopping admission of new Gateway mutations, draining requests that run the old code, restarting every Gateway worker, and resuming mutations. Keep `$ORBIT_HOME/.dnsmasq-projections.lock` in place throughout the rollout. The deployment deletes no lock file and runs no stored-data migration.

## Change an existing Route

### Change an explicit private hostname

The Gateway can change a development or production Route hostname when the Route is active, explicit, private, and has one target. This is the operation that replaces a production clone's preview hostname with its intended private hostname. The App instance keeps the same Route record and sole association throughout the transition. The Gateway refuses an invalid or occupied hostname before it changes Route records, environment configuration, runtime projections, or traffic. When that eligible target has no recorded source profile, the Gateway returns HTTP 409 `instance.source_profile_missing` and names recovery through the same creation request with `recover_source_profile`. The [applications domain](/domains/applications#provision-the-application-endpoint) owns that recovery.

The Gateway prepares the new workload certificate and Caddy site before it prepares the Router certificate, workload firewall policy, and Router Caddy site. For a detected development Laravel source, it aligns `APP_URL` in the environment file and cached configuration without running Composer, Artisan, or application bootstrap. A non-Laravel development source receives no application configuration change.

For production, the Gateway checks the saved environment location and renders stored settings for the proposed hostname. It resolves `{{app_instance.hostname}}`, preserves other values and literal application keys, and replaces only the home's `.env`. It runs no Composer, Artisan, framework, cache, deployment, or restart commands. Add required cache or process commands to a separate deployment step. A stale cached URL or HTTP error does not block a valid infrastructure change. See [App instance environment variables](/reference/environment-variables#synchronize-during-a-hostname-change).

Private DNS publication is the traffic cutover. The Gateway publishes the new exact owner only after it verifies every required projection. It then records the new Route hostname, normalizes the serving projections, and removes the old Caddy, certificate, and DNS state. The old hostname remains authoritative until the new hostname is ready, and cleanup starts only after the new hostname is authoritative.

### Resume or refuse a change

During a change, the Route reports the requested hostname, direction, saved checkpoint, `failed_step`, and `error_code`. Retry with the same hostname to verify completed work and resume the first incomplete step. A different hostname returns a conflict and leaves the operation intact.

A failure before database cutover rolls back to the previous Route and application configuration. Rollback first restores authoritative DNS, then serving configuration and certificates, and finally the prior development Laravel URL or production environment file. A rollback interruption keeps its checkpoint and failure visible so the same request can resume restoration. A cleanup failure leaves the new hostname and matching production environment authoritative and resumes cleanup without reverting the completed cutover.

The reconciliation guard still returns `route.reconciliation_required` for generated Route hostname changes, multi-target Route hostname changes, and active Route publication or target changes. It also refuses Node WireGuard, LAN, TLD, or Cluster-membership changes when an active Route depends on the change. The same rule covers Cluster activation, deactivation, or TLD changes and Router replacement or clearing.

Deployment, code rollback, clone finalization, App instance removal, environment import, stored environment updates, environment synchronization, and a hostname transition share the target App instance's bounded operation owner. A competitor waits or returns `env.operation_busy` before mutation. The hostname transition also holds the shared private projection owner through its Caddy and DNS work. It does not change source, the selected production release, SQLite data, or local PHP tuning.

Route and App instance removal keep their coordinated removal contract. Setting the existing target or clearing an already empty Route succeeds without creating, deleting, or reassigning a Route association. A Node grant change does not alter private network reachability and retains its command-authorization behavior.

App instance removal is the coordinated target-clear exception. After complete source and Route preflight, the Gateway marks each accepted App instance `removing`. Development removal publishes an unavailable response before deleting each final-target Route in worktree-first order. Production removal republishes every ordered survivor when a shared Route remains. Final-target removal clears managed Route projections, deletes the Route, and releases its hostname before source finalization. A projection failure keeps the unfinished Route checkpoint available for retry. The [App instance removal reference](/reference/appinstance-removal) owns content retention, the transient response, cascade order, and retry behavior.

During a Node or Cluster placement mutation, the Gateway validates only Routes whose direct scope, target Nodes, retained generation basis, or provisioning baseline depends on the affected Nodes or Clusters. It compares proposed hostnames with one operation-local index of all Route hostname owners, so an unaffected Route still blocks a collision. Routes outside this workset stay unchanged. Full reconciliation of an existing active Route is a separate contract.

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
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | Deletes only an eligible Route and its Route-owned target rows. |

## Compatibility and limits

Route operations do not change leftover Legacy Instance hostname or certificate fields, Workspace hostnames, App instance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract projects private Routes and changes a development or production Route hostname when the Route is active, explicit, private, and has one target. It also coordinates target clearing during development checkout, worktree, fixed-set cascade, and production App instance removal. It does not implement generated or multi-target hostname changes, other later Route reconciliation or removal, public Ingress, public DNS providers, public production pool creation, production placement, application setup, or application health tracking. [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), [ADR 0024](/decisions/0024-follow-generated-route-targets), [ADR 0029](/decisions/0029-manage-laravel-application-urls-through-orbit), [ADR 0030](/decisions/0030-complete-appinstance-provisioning-without-application-health-gates), [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic), and [ADR 0041](/decisions/0041-delete-an-empty-route-during-appinstance-removal) define the remaining boundaries.
