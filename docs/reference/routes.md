---
title: "Routes"
description: "What a Route records, how Orbit projects its private traffic path through a Node or Router, and which later changes it coordinates or refuses."
---

# Routes

A Route is a domain the Gateway publishes on the private network. A Project Route gives an Instance a domain and directs private traffic to it. A custom proxy Route gives a Node-local service a hostname. This page explains both kinds, domain selection, traffic setup, and supported changes. Each active Instance has exactly one authoritative Project Route, as [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) requires. A domain change creates a replacement Project Route under [ADR 0065](/decisions/0065-replace-routes-when-domains-change). Custom proxy Routes follow [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes).

The CLI renders Route lists as tables and individual Routes as detail trees, including lifecycle and replacement fields. Human requests show progress while waiting. Route removal and target clearing require default-No interactive confirmation or `--yes`; JSON and piped calls never imply consent.

## Route record

The Gateway stores each Route's settings and tracks setup of its certificates, web server, firewall, and DNS records.

| Value | Contract |
| --- | --- |
| Kind | `app` or `custom_proxy`. The kind never changes. |
| Project | The stable owner of a Project Route and every allowed Instance target. A custom proxy Route stores no Project. |
| Routing scope | A Project Route has exactly one Node or one active Cluster. A custom proxy Route stays Node-direct on its serving Node. |
| Domain | One immutable normalized domain that no other Route owns. |
| Domain source | Always `generated` or `explicit`. This value never changes, and Orbit does not infer it from the domain. |
| Replacement | Optional `replaces_route_id`, `replaced_by_route_id`, and `replacement_step` that expose a reserved, activating, retiring, or failed replacement pair. |
| Generation basis | The current target Node for a generated Route, or its last target Node after target clearing. An explicit Route stores no generation basis. |
| Publication | The only publication field: `private` or `public`. The Route keeps this value even when it has no target. Public-edge readiness is `status`, `replacement_step`, `failed_step`, and Doctor, not a second publication field. |
| Status | `pending`, `active`, `activating`, `retiring`, or `failed`. Failure details identify the step to retry. |
| Target storage | The Route can own several ordered target rows. An active multi-target set belongs to one explicit production Route and uses distinct active app-prod Nodes in the same Cluster. |
| Configured target | A Project Route accepts a single Instance target or an ordered production target set. A custom proxy Route stores a loopback upstream or a Node Process. |

Active Cluster membership still selects Cluster scope for Project Routes, even when the Cluster has no TLD. Generated and development Project Routes still permit at most one Instance target. A custom proxy Route stores no Instance targets.

Creating the same explicit Project Route again with identical Project, domain, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route. Creating the same custom proxy Route again with identical domain, Node, and upstream or Process returns the existing Route.

## Select a domain and scope

Supply a domain when creating an Instance to request an explicit Route. Otherwise, Orbit generates one from the effective top-level domain (TLD). Generation uses an active Cluster TLD first, then the Node TLD. If neither authority supplies a TLD, creation stops before source or runtime changes. An explicit domain stays as supplied. Instance responses derive the domain and URL from the authoritative Route.

A generated domain combines the instance name, Project name, and that effective TLD. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines the `default` name. [ADR 0063](/decisions/0063-prefer-active-cluster-tlds-for-generated-routes) owns the Cluster-first precedence.

| Instance name | Generated domain with effective TLD `test` |
| --- | --- |
| `default` | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

An explicit source branch changes neither placement nor generated Route identity. For example, `instance:create <app> <node> default --branch=release` still generates `<app>.test`.

## Generated domains after a Project slug update

A Project slug update recomputes every generated development Route domain from the new slug, the existing Instance name, and the current effective TLD. The Gateway creates a replacement Route for each domain that must change. The replacement keeps the Project, routing scope, provenance, publication intent, and target. The previous generated Route is removed after the replacement is authoritative. Explicit domains never change because of a Project slug update.

A default-branch update does not replace a Route or change its domain. The [Projects reference](/reference/apps#update-an-app) owns the Project update lifecycle that drives this replacement.

An app-dev Node must have a Node TLD or belong to an active Cluster with a TLD. A standalone app-prod Node can remain valid without a TLD when production creation supplies an explicit Route domain.

Cluster membership determines routing, independently of the domain. A Node in an active Cluster uses Cluster routing, even when the generated name uses a Node TLD because the Cluster has no TLD. Other Nodes route directly. A Cluster with Routes needs exactly one active Router.

## Route operations

The API, PHP software development kit (SDK), and command-line interface (CLI) expose seven operations and return the Route's relationships.

| Operation | Result |
| --- | --- |
| Create | Store an explicit Project Route, or persist a custom proxy Route and converge its private projection. |
| List | Return the Routes visible to the caller in stable order, including both kinds. |
| Show | Return one Route with its kind, stored scope, provenance, generation basis, intent, lifecycle, failure metadata, Instance targets or custom proxy upstream, and ordered target set. |
| Update | Reserve a replacement Route for an explicit domain change, or change publication intent on the same Route ID. |
| Target set | Add or replace one Instance target, or replace the complete ordered production target set with explicit dispositions for every detached active Instance. |
| Target unset | Remove the target only when that does not leave an active Instance without a Route, unless the same operation removes that Instance. |
| Destroy | The Gateway deletes an eligible Route after untargeted private projection cleanup. It refuses a targeted Route before cleanup. |

The CLI names these operations `route:create`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:unset`, and `route:destroy`.

## Change or clear a target

The Gateway validates the complete proposed Route before it commits a target change.

| Change | Result |
| --- | --- |
| Set the existing Instance target again | Return the unchanged Route. |
| Clear an already empty Route | Return the unchanged Route. |
| Set an Instance from another Route | Return `route.target_conflict`, identify the requested and existing Routes, and preserve both associations. |
| Replace a generated or explicit Route target | Return `route.target_conflict` when replacement would detach an active Instance from its Route, and preserve the association. |
| Clear the only target | Return `route.target_conflict` when clearing would detach an active Instance from its Route, and preserve the association. |
| Remove a targeted Route | Return `route.target_conflict` when removal would detach an active Instance from its Route, and preserve the Route, Instance, and association. |
| Set an Instance from another Project or an inactive Instance | Reject the change and retain the complete current Route. |
| Set a generated target without an effective TLD | Reject the change and retain the complete current Route. |
| Set a direct Node, backend URL, second generated target, or balancing value on a Project Route | Reject the change and retain the complete current Route. |

A permitted generated target replacement releases the old generation basis only after the replacement commits. Clearing a target from a non-active Instance does not release that basis.

## Change a production target set

An operator sends the complete ordered Instance ID set for one explicit Cluster-scoped production Route. The Gateway accepts that set when every target is an active production Instance of the Route Project on a distinct active app-prod Node in the same Cluster. A Cluster TLD is not required. The request may transfer an Instance that already belongs to another Route in that Cluster.

The Gateway refuses a generated Route, an app-dev target, a duplicate target, two targets on one Node, a foreign Project or Cluster, an inactive Node or Instance, and a competing in-progress target-set change. Those refusals leave the stored set unchanged.

A change that detaches an active Instance must name that instance in `dispositions` and either reassign it to a compatible explicit Route or authorize Instance removal. A missing disposition, an invalid destination, or a request that both reassigns and removes the same Instance is refused before mutation. No target-set request deletes an Instance unless `remove` is true for that instance.

Transport accepts Instance and Route identities plus explicit removal authorization. The Gateway rejects caller-supplied backend URLs, Node addresses, Caddy directives, and balancing policy fields. Route responses expose target IDs and positions and do not expose infrastructure addresses.

The Gateway prepares each added target's runtime, workload projection, certificate, and required URL configuration before it publishes that target in the Router pool. It then commits the complete association set, synchronizes Laravel URL configuration for every retained and reassigned Instance from `{{app_instance.domain}}`, publishes the destination Router pool, republishes each vacated Route, and only then runs authorized Instance removals. Success returns the complete requested set. An identical completed request changes no records, runtime artifacts, URL configuration, or removal state.

A failure before the association commit restores the original associations and rolls back the prepared projections. A failure after that commit keeps the recorded intent, `target_set_step`, and `failed_step` so the same request resumes. A different request returns `route.target_set_conflict` and does not replace that intent. After authorized removal begins, retry completes the recorded removal and does not recreate a removed Instance.

| Error code | Meaning |
| --- | --- |
| `route.pool_unsupported` | The Route or target cannot own or join a production pool. |
| `route.target_conflict` | The set contains a duplicate Instance or two targets on one Node. |
| `route.target_app_conflict` | A target or reassignment destination belongs to another Project. |
| `route.target_scope_conflict` | A target or destination is outside the Route Cluster or is not an active app-prod Node. |
| `route.target_inactive` | A target Instance is missing or not active. |
| `route.target_disposition_required` | A detached active Instance has no reassignment or authorized removal. |
| `route.target_disposition_invalid` | A disposition names an invalid destination or combines reassignment with removal. |
| `route.target_set_conflict` | Another target-set change is already recorded on the Route. |

### Serve a production pool

Router Caddy publishes one site for the Route domain and distributes requests with round-robin. It does not replay a failed request to another target. A connection or TLS failure excludes that target for 10 seconds and then admits it again without an operator update or an active probe. A later request can reach another eligible target. A failed LAN connection does not select WireGuard for that target.

Targets with a configured `lan_ip` use that address. Targets without one use `wireguard_ip`. Remote hops validate Orbit certificate-authority TLS for the Route domain. An invalid certificate never enables an insecure fallback. An application HTTP 500 does not remove the target from the pool or change Instance state.

When one target shares the Router Node and another is remote, one Caddy service serves both. The local workload uses an internal Unix listener. The composed site does not proxy to its own HTTPS listener.

An empty pool or a pool whose every target is excluded returns HTTP 503 with `Orbit Route unavailable` and exposes no backend address. Restoring an eligible target resumes traffic on the same Route domain and certificate. A vacated Route keeps its domain and serves that unavailable response until it receives a new eligible target. When authorized Instance removal removes the final target, the Gateway deletes the Route and releases its domain before source finalization, as [ADR 0041](/decisions/0041-delete-an-empty-route-during-appinstance-removal) requires.

Once the replacement pool is published, new requests are not assigned to removed targets. An in-flight request does not delay target or Instance removal. Explicit Instance removal keeps the established cascade, retains production application content, and leaves unrelated Instances and Node-owned schedules unchanged.

Orbit does not change session, cookie, or encryption environment keys while it creates or replaces a pool. Shared sessions across targets require the application to already use one shared session store and compatible cookie settings. Round-robin is not backend affinity and does not pin a client to one target.

[ADR 0039](/decisions/0039-use-round-robin-for-production-route-pools) owns the balancing decision.

## Custom proxy Routes

A custom proxy Route publishes an exact hostname for a service that already runs on one managed Node. It is not an Instance Route. The Gateway does not create a Project, a PHP handle, or a document root.

```bash
orbit route:create executor.orbit --node=beast --upstream=http://127.0.0.1:4788
orbit route:create executor.orbit --node=beast --process=executor
```

The first form stores the loopback URL. The second form stores the Node-owned Process and resolves its listener to a loopback or Node-local bind. Both forms require an active serving Node. The CLI accepts a Node ID or registered Node name. The Process value is the Process name on that Node or its numeric ID.

| Rule | Result |
| --- | --- |
| Domain | Any unique DNS domain. `executor.orbit`, `grafana.internal`, `foo.bar`, and `something.test` are valid. Orbit does not require a Cluster TLD, a Node TLD, or `.orbit`. |
| Uniqueness | Fleet-global across Project Routes, custom proxy Routes, `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, `analytics.orbit`, and `collector.proxycli.orbit`. A conflict leaves the existing name in place. Apex `proxycli.orbit` is not reserved. |
| Owner | The serving Node. Cluster membership does not move the Route to Cluster scope. |
| Publication | Private only. The Gateway refuses public intent. |
| Upstream | HTTP on loopback (`127.0.0.1`, `localhost`, or `::1`) or the resolved listener of a Node-owned Process on that Node. A remote URL is refused. |
| Caddy | Callers cannot supply a Caddyfile. The serving Node site terminates Orbit-CA TLS and reverse-proxies HTTP to the local upstream. It preserves `Host` and admits streaming and WebSocket upgrades. |
| DNS | An exact private `host-record` answers with the serving Node under Node-scoped private Route rules. The Cluster Router is not a hop. |
| Create | Persist, issue the Orbit CA leaf, publish Caddy, then publish DNS. Success returns an active Route. An identical retry returns the existing Route. |
| Destroy | Run untargeted private cleanup for that Route only. Instance Routes stay unchanged. |
| Node removal | Refuse while the Node still owns a custom proxy Route (`node.has_routes` or `route.reconciliation_required`). |
| Process removal | Refuse while a custom proxy Route still targets that Process (`process.has_routes`). Destroy the Route first. |

A Cluster TLD suffix still answers names that have no exact record. An exact custom proxy record wins for its domain, including a name under that TLD.

The Executor example is a Node-owned Docker Process on Beast with a loopback publish such as `127.0.0.1:4788:4788`. Creating `executor.orbit` against that Node and Process is the supported replacement for an unmanaged `executor.test` Caddy fragment. [Migrate an unmanaged Executor hostname](/solutions/migrate-unmanaged-executor-hostname) owns that cutover. Orbit does not delete live unmanaged fragments from automation.

Doctor inspects each custom proxy Route on its serving Node.

| Doctor issue code | Difference |
| --- | --- |
| `route.dns_mismatch` | Private DNS does not answer the exact domain with the serving Node address. |
| `route.certificate_mismatch` | The Route-scoped Orbit CA leaf is missing on the serving Node. |
| `route.caddy_mismatch` | Serving Node Caddy does not contain the custom proxy site. |
| `route.upstream_unreachable` | The resolved loopback or Process listener does not accept a connection. |
| `route.inspection_failed` | A required observation is missing, malformed, or unreachable. |
| `route.node_unreachable` | The serving Node could not be observed. |

Those checks stay verify-only. They change no Route, certificate, Caddy, DNS, Process, or firewall state.

## Set up private traffic

The Gateway prepares the initial private Route before it marks the Route and Instance active. It does not require the application to return a successful response.

### Node scope

For Node routing, Gateway Domain Name System (DNS) records point the domain at the workload Node. Its Caddy service terminates HTTPS with an Orbit certificate authority (CA) certificate and serves the Instance's web root through its runtime.

Before publishing a development Route, the Gateway gives Caddy read access to each local development Web root and traversal access to its parent directories. Caddy cannot read the other source files. The Web root must exist inside its checkout. Symlinks in the Web root are refused except Laravel's `public/storage` link to that checkout's `storage/app/public`. Nested Git worktrees keep access to their own Web roots. If file-access preparation fails, the Gateway restores the preceding permissions and reports `app-dev.source_access_failed` at `source-access` before publishing the Route. If permission recovery also fails, it retains a protected permission snapshot on the Node for recovery.

### Cluster scope

For Cluster routing, Gateway DNS points the Route domain and Cluster TLD at the Router. [ADR 0062](/decisions/0062-select-cluster-router-dns-addresses-from-lan-intent) defines which Router address each requester receives.

An active LAN-configured WireGuard member of that active Cluster receives the Router's configured LAN address for the Cluster TLD and for each exact Cluster-scoped Route, including a Route domain outside the Cluster TLD. Other permitted requesters receive the Router's WireGuard address.

The Gateway identifies the requester from the registered WireGuard source that delivered the query. A shared LAN subnet or an identity in the DNS message does not change the selected address.

[Private DNS](/reference/private-dns#cluster-router-addresses) owns how an operator inspects that selection, removes incorrect LAN intent, and recognizes an unreachable configured LAN path.

Router Caddy preserves the domain as the HTTP `Host` value and Transport Layer Security (TLS) server name when it forwards Orbit-CA HTTPS to the workload Node. Orbit issues separate private keys to the Router and workload Node. When both roles share one Node, the composed Caddy service sends the request to the local runtime without proxying to its own HTTPS listener. A pool that mixes a Router-local workload with a remote workload uses that same composed service plus an internal Unix listener for the local target.

The Router uses the workload Node's configured LAN address. It uses WireGuard only when that LAN address is absent. A configured but unreachable LAN path fails publication and never falls back to WireGuard. A production pool applies the same address rule per target and does not fail over from LAN to WireGuard after a connection failure.

### Development-server endpoint

The reserved path `/__orbit/vite` serves live frontend assets and hot module replacement (HMR) over the Route's HTTPS domain on port 443. Cluster DNS points that domain at the Router. The application serves its web root on the same domain. See [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin).

Workload Caddy reverse-proxies that path to the Instance's assigned `vite_port` on Node loopback. Existing instances without an assignment retain port `5173`. Use the [VitePlus preset](/reference/assigned-vite-ports) to configure the assigned endpoint. Router Caddy forwards the path with the Route domain as the HTTP `Host` value and TLS server name. HTTPS and WSS terminate with the Route's Orbit certificate-authority certificates. The toolchain process speaks HTTP on loopback and does not present a certificate to the browser.

Instances on one Node have distinct assignments. Separate Nodes can reuse the same port. When the process on that loopback is stopped, Caddy returns a proxy error for that domain's reserved path and does not select another Instance.

An operator configures the frontend toolchain to publish asset and HMR URLs on the reserved path. A development systemd Process receives these environment values from the Route domain.

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<route-domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The instance assignment, or `5173` for a legacy instance. |

A Vite development server that follows the contract binds its assigned loopback port and publishes the Cluster origin:

```js
import { defineConfig } from 'vite'

const origin = process.env.ORBIT_DEV_SERVER_ORIGIN
const path = process.env.ORBIT_DEV_SERVER_PATH

export default defineConfig({
    base: path ? `${path}/` : '/',
    server: {
        host: '127.0.0.1',
        port: Number(process.env.ORBIT_DEV_SERVER_PORT || 5173),
        strictPort: true,
        origin: origin ? new URL(origin).origin : undefined,
        hmr: origin
            ? {
                protocol: 'wss',
                host: process.env.ORBIT_DEV_SERVER_HOST,
                clientPort: 443,
                path: 'hmr',
            }
            : undefined,
    },
})
```

The preset sets `--base=/__orbit/vite/`. Workload Caddy preserves the prefix for assigned endpoints, so Vite can generate module imports under that base. Legacy unassigned endpoints retain prefix stripping. Vite prepends its base to the HMR path, so configure `hmr.path` as `hmr`.

Laravel's `@vite` directive reads the `public/hot` file. The file must contain `ORBIT_DEV_SERVER_ORIGIN` so the browser requests `/__orbit/vite/@vite/client` on the Route domain. The [process reference](/reference/app-processes-and-schedules#add-a-process) describes the injected certificate and origin environment.

### Agentation endpoint

The reserved path `/__orbit/agentation` publishes the Instance Agentation HTTP Process over the Route's HTTPS domain on port 443. Workload Caddy reverse-proxies that path to the assigned `agentation_port` on Node loopback and strips the reserved prefix so the upstream Agentation API keeps `/health` and `/sessions` at its root. Sites without an assignment have no Agentation handle. See [ADR 0082](/decisions/0082-wire-agentation-watch-mode-through-appinstance-processes) and [Agentation](/reference/agentation).

| Variable | Value |
| --- | --- |
| `AGENTATION_URL` | `https://<route-domain>/__orbit/agentation` |
| `ORBIT_AGENTATION_PORT` | The instance assignment, starting at `4747`. |

### Private network and publication

Orbit exposes the Route only after its runtime, certificates, Caddy configuration, and firewall rules are ready. Private DNS is published last.

Active WireGuard membership trusts a Node to reach every other active WireGuard member over all protocols and ports. Node grants do not limit ordinary private Node traffic; they authorize only Orbit commands and Gateway API actions. Grafana is the exception: [`metrics.orbit`](/reference/metrics#private-access-and-credentials) requires Gateway authority, and the Metrics node refuses direct Grafana traffic from other peers. A configured LAN path can carry Router-to-workload traffic only when it preserves the same registered-Node trust boundary.

Public traffic enters through Ingress on HTTP or HTTPS. The firewall does not expose a Router or workload Node as a direct public endpoint. A standalone production Route is private and terminates Orbit-CA TLS on its workload Node. Orbit publishes private DNS only after runtime, certificates, Caddy, and firewall preparation succeed.

When the Gateway converges the app-prod role, it retires the Orbit-owned public HTTP and HTTPS workload rules and does not republish them. Ingress keeps public HTTP and HTTPS publication. Unrelated firewall rules stay in place. A retry after a failed cleanup uses the same owned-rule set.

### Inspect private projections with Doctor

Doctor instance checks compare each active private Route and its target with the expected routing scope, Router and workload Caddy, Route-scoped certificate, private DNS, role-owned firewall, detected Laravel URL, target set, and Route association. They stay in the existing `instance` family, remain verify-only, and change no Route, Node, service, certificate, DNS, firewall, Laravel file, lifecycle state, or lock.

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
| `instance.target_set_mismatch` | Router Caddy does not publish the Route's ordered production target set. |
| `instance.route_association_mismatch` | An Instance is missing its sole Route association or has more than one. |
| `instance.related_node_unverifiable` | A required related Node is outside the selected inspection set. |
| `instance.inspection_failed` | A required observation is missing, malformed, or unreachable. |

## Publish a public Route

A public Route terminates HTTPS on the Cluster Ingress, forwards privately through the Cluster Router, and reaches the app-prod workload without exposing placement or workload listeners. Role ownership follows [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement). Only Ingress may be the public boundary; [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing) records that rule.

The Gateway publishes the public edge only when the Route is Cluster-scoped, the Cluster is active, and that Cluster has exactly one active Ingress and one active Router. A Node-scoped Route, an inactive Cluster, or a Cluster that lacks an active Ingress or Router keeps `publication=public` and creates no public listener, Let's Encrypt site, firewall rule, or partial activation. Those cases report readiness on `status`, `replacement_step`, `failed_step`, and Doctor.

A public edge is live when `publication` is `public`, the Route is `active` or `activating`, the Cluster is eligible, and `replacement_step` ranks at `public-activated` or after. A finished public Route keeps that completed step. A public Route with an empty replacement step has not finished public activation and has no live Ingress listener. When the Gateway drops `public_publication`, it writes `replacement_step=ingress-firewall` on public Routes that were `public_publication=active` and `active` or `activating`, so those hosts stay on the public edge.

Environment import, update, and synchronization use the same terminal public-edge ranking. A finished public Route that keeps `public-activated` or `ingress-firewall` remains an available environment owner. A Route that is still activating or replacing returns `env.owner_unavailable`.

The Ingress artifact names the public domain and the Router upstream. It does not name an Instance, workload Node, or backend pool. Router Caddy keeps backend selection. Workload Caddy stays private.

Public TLS terminates on the Ingress Node with Let's Encrypt when the public edge is healthy. Ingress Caddy uses automatic HTTPS for that hostname and does not pin an Orbit CA leaf. Converge must not replace a working public Let's Encrypt certificate with Orbit CA. Ingress forwards Orbit-CA HTTPS to the Router over the configured LAN address and uses WireGuard only when no LAN address is set. A configured but unreachable LAN path fails and does not fall back to WireGuard. Ingress preserves the original `Host` value, HTTPS scheme, and client address. [ADR 0101](/decisions/0101-simplify-route-publication-and-use-lets-encrypt-on-public-ingress) records this decision.

When Ingress shares a Node with the Router, with app-prod, or with both, one composed Caddy service serves the public Route. The composed site does not proxy to its own public listener.

Firewall policy admits public HTTP and HTTPS only on the Ingress Node, and only while that Cluster has at least one active public Route. Router and workload listeners stay private. Direct public workload traffic is denied.

An exact client-local override can send the Route domain to the Router address and then to the workload address without changing public Ingress or DNS state. [Local resolver overrides](/reference/local-resolver-overrides) owns installing that caller-local resolver.

Creating or showing a public Route adds no Node public-IP field and calls no DNS-provider API.

A stale application HTTP error does not block a valid public edge. The trusted response remains observable and the Instance remains active.

### Publish the public edge

A publication-only update on the same domain keeps the Route ID. The Gateway stages the Ingress Caddy site outside the live import, verifies the private hops, then installs the handler so Caddy can obtain Let's Encrypt for the public hostname. Firewall rules open after at least one public Route is live. The candidate handler stays unreachable until those checks succeed, including when another Route already keeps Ingress ports open. A Let's Encrypt failure stays on `failed_step` and Doctor; converge does not fall back to Orbit CA.

A combined domain and publication change reserves a replacement Route with `publication=public`. The current Route stays authoritative until cutover. Environment synchronization and private infrastructure complete before public exposure. Cutover makes the replacement authoritative in one database transition. Successful cleanup deletes the preview Route and releases its domain.

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

A generated Route receives a replacement domain from its target name and the new effective TLD. An active Cluster TLD still owns that effective TLD, so a Node TLD change does not rename a generated Route that already uses the Cluster namespace. A targetless generated Route follows the same retained generation basis. An explicit Route keeps its domain.

The Gateway prepares and verifies workload and Router Caddy, Route-scoped certificates, firewall policy, and the detected Laravel URL against the candidate replacement before it publishes the new domain. It commits the Node TLD only after that publication. Development Laravel sources receive `APP_URL` in the environment file and cached configuration without Composer, Artisan, or application bootstrap. Production sources render stored configuration against the candidate Route. Making a stale application cache effective remains a separate application setup or deployment step.

Failure before publication restores the previous Node TLD, Route records, infrastructure intent, and Laravel URL. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step. A conflicting Node or Route mutation is refused. After cutover, retry continues forward so two authoritative domains are never exposed for the same Route.

Old projections are removed only after the replacement domain is authoritative.

### Change a Cluster TLD

The Gateway reconciles every private Route whose current Cluster scope or retained generation basis uses the Cluster before the Cluster TLD becomes authoritative. It inventories those Routes, validates the complete proposed domains, and refuses an invalid or occupied result before it changes the Cluster, a Route record, environment configuration, or traffic.

A generated Route receives a replacement domain from its target name and the new effective TLD. A targetless generated Route follows the same retained generation basis. An explicit Route keeps its domain. Cluster state, membership, Router assignment, and Instance placement stay unchanged.

Removing a Cluster TLD keeps Cluster Router routing for owned Routes while active membership remains. Generated domains fall back to the Node TLD instead of changing routing scope. The Gateway refuses the complete change when that fallback would leave a generated Route without an effective TLD.

The Gateway prepares and verifies workload and Router Caddy, Route-scoped certificates, firewall policy, and the detected Laravel URL against the candidate replacement before it publishes the new domain. It commits the Cluster TLD only after that publication. Development Laravel sources receive `APP_URL` in the environment file and cached configuration without Composer, Artisan, or application bootstrap. Production sources render stored configuration against the candidate Route. Making a stale application cache effective remains a separate application setup or deployment step.

Failure before publication restores the previous Cluster TLD, Route records, infrastructure intent, and Laravel URL. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step. A conflicting Cluster or Route mutation is refused. After cutover, retry continues forward so two authoritative domains are never exposed for the same Route.

Old projections are removed only after the replacement domain is authoritative.

### Change Cluster activation

The Gateway reconciles every private Route whose current target Node, Cluster scope, or retained generation basis depends on the Cluster before activation or deactivation becomes authoritative. It inventories those Routes, validates every resulting domain, routing scope, target, and required Router, and refuses the complete change when any result is invalid. Cluster TLD, membership, and Instance placement stay unchanged.

Activation prepares and verifies the Cluster Router serving path before publication. Deactivation prepares usable direct Node scope before it removes authoritative Cluster routing. Workload and Router Caddy, Route-scoped certificates, firewall policy, DNS, and detected Laravel URLs agree with the published scope, including a TLD-less Cluster that already owns Routes.

A generated Route follows its current target name and effective TLD, or the retained generation basis when it has no target. When a Cluster that has a TLD becomes active, generated domains move into that Cluster namespace. Deactivation falls back to the Node TLD. An explicit Route keeps its domain. A domain change reserves a replacement Route. A scope-only change keeps the same Route ID. Old projections are removed after publication. When the Router and workload share one Node, one composed Caddy service uses a local next hop and does not proxy to its own HTTPS listener.

Failure before publication restores the previous Cluster state, Route records, infrastructure intent, and Laravel URL. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step. A conflicting Cluster or Route mutation is refused. After cutover, retry continues forward so two authoritative scopes are never exposed for the same Route.

Development Laravel sources receive `APP_URL` in the environment file and cached configuration without Composer, Artisan, or application bootstrap. Production sources render stored configuration against the candidate Route. Making a stale application cache effective remains a separate application setup or deployment step. See [Instance environment variables](/reference/environment-variables#synchronize-during-a-domain-change).

### Replace a Cluster Router

The Gateway inventories every Cluster-owned Route and prepares the new Router's composed Caddy sites, separate Route keys, role-owned firewall policy, and exact DNS projections before the Router assignment becomes authoritative. Publication then moves every Router site and exact DNS record to the new Router.

Route identity stays on the same Route. Domains, targets, scopes, workload projections, Laravel URLs, and Instance placement stay unchanged. The replacement does not create a replacement Route.

When Router and workload roles share the old or new Node, the composed Caddy service uses the local next hop and never proxies back into its own HTTPS listener.

Failure before publication restores the previous Router and infrastructure intent. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` on the Cluster Router candidate with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step under the Cluster Router owner. The Gateway refuses a conflicting transition and does not delete another transition's live candidate or activate against stale Router state. After publication, retry continues forward.

A valid serving configuration succeeds even when the application returns HTTP 500. Application health does not change Instance or Route lifecycle.

Clearing a Router that would leave Cluster-owned Routes without a serving path still returns `route.reconciliation_required`.

### Change Cluster membership

The Gateway reconciles every private Route whose current target Node or retained generation basis uses the Node before attach or detach becomes authoritative. It inventories those Routes, validates the complete proposed domains, routing scopes, targets, and required Router, and refuses an invalid or occupied result before it changes membership, a Route record, environment configuration, or traffic. Cluster TLD, Cluster state, and Instance placement stay unchanged.

Attach to an active Cluster prepares and verifies the Cluster serving path before publication, including a TLD-less active Cluster that still uses Cluster scope and a Router. Detach prepares usable direct Node scope before it removes authoritative Cluster routing. Workload and Router Caddy, Route-scoped certificates, firewall policy, private DNS, and detected Laravel URLs agree with the published scope.

A generated Route follows its current or retained generation basis. When a Node joins an active Cluster that has a TLD, generated domains move into that Cluster namespace. Detach falls back to the Node TLD. An explicit Route keeps its domain. When the resulting domain changes, the Gateway uses the replacement Route lifecycle. When only the routing scope changes, the Route keeps its ID. Old projections are removed only after publication. When Router and workload roles share one Node, the composed Caddy service uses a local next hop and does not proxy to its own HTTPS listener.

The Gateway prepares and verifies those projections and the detected Laravel URL before it publishes the resulting scope. Development Laravel sources receive `APP_URL` in the environment file and cached configuration without Composer, Artisan, or application bootstrap. Production sources render stored configuration against the candidate Route. Making a stale application cache effective remains a separate application setup or deployment step.

Failure before publication restores the previous membership, Route records, infrastructure intent, and Laravel URL. Each preparation, publication, database, cleanup, or rollback failure records `failed_step` and `error_code` with durable completed-step evidence. Retry revalidates that evidence and resumes from the earliest unverified step. A conflicting Node or Route mutation is refused. After publication, retry continues forward so two authoritative scopes are never exposed for the same Route.

### Change an explicit private domain

The Gateway can change a development or production Route domain when the Route is active, explicit, and private. A shared production Route keeps its complete ordered target pool on one replacement. This is the operation that replaces a production clone's preview domain with its intended private domain.

The Gateway reserves a unique pending replacement Route for the same Project and complete target set while the existing Route stays the sole authoritative `active` Route. It refuses an invalid, occupied, or conflicting domain before it changes Route records, environment configuration, runtime projections, or traffic.

When an eligible target has no recorded source profile, the Gateway returns HTTP 409 `instance.source_profile_missing` and names recovery through the same creation request with `recover_source_profile`. The [applications domain](/domains/applications#provision-the-application-endpoint) owns that recovery.

The Gateway prepares the replacement workload certificate and Caddy site before it prepares the Router certificate, workload firewall policy, and Router Caddy site. For a detected development Laravel source, it aligns `APP_URL` in the environment file and cached configuration without running Composer, Artisan, or application bootstrap. A non-Laravel development source receives no application configuration change.

For production, the Gateway checks the saved environment location and renders stored settings for the candidate Route. It resolves `{{app_instance.domain}}`, preserves other values and literal application keys, and replaces only the home's `.env`. It runs no Composer, Artisan, framework, cache, deployment, or restart commands. Add required cache or process commands to a separate deployment step. A stale cached URL or HTTP error does not block a valid infrastructure change. See [Instance environment variables](/reference/environment-variables#synchronize-during-a-domain-change).

Cutover is one database transition. The Gateway publishes the replacement domain in private DNS only after it verifies every required projection, then marks the replacement `activating` and the old Route `retiring`. Instance output derives only the replacement domain. Route inspection exposes both records and their relationship. Cleanup then removes old projections, deletes the retiring Route, and marks the replacement `active`.

A retiring Route stops being served at cutover. Both domains share one certificate scope per Instance, and cleanup issues that certificate for the Route the Node now serves. A Node that kept answering under the previous domain would present a certificate naming the replacement, and Caddy would then treat that host as unmanaged and try to obtain a public certificate for a private Orbit domain. Until cutover, both domains stay served, so an interrupted change never leaves the Route unreachable.

### Resume or refuse a change

During a change, Route inspection reports both records, `replacement_step`, `failed_step`, and `error_code`. Retry with the same domain to verify completed work and resume the first incomplete step. A different domain returns `route.domain_change_conflict` and changes neither record.

A failure before cutover leaves the old Route authoritative. Successful cleanup of replacement projections deletes the replacement. Incomplete cleanup retains an inspectable `failed` replacement. Only the identical request recovers that replacement.

A failure after cutover keeps the replacement authoritative. Retry continues forward until the replacement is `active`, every old projection is removed, the retiring Route is deleted, and its domain becomes available.

The reconciliation guard still returns `route.reconciliation_required` for operator-requested generated Route domain changes and for single-target replacement of an active Route. An explicit production target-set change is a separate operation and does not use that refusal. A publication-only change on an active Route publishes or withdraws the public Ingress edge on that Route ID. The guard also refuses Node WireGuard or LAN changes when an active Route depends on the change. The same rule covers Router clearing.

A Node or Cluster TLD set, change, or clear, a Cluster activation or deactivation, and fully reconciled Node attach and detach are not this refusal; they reconcile the private Routes that depend on that Node or Cluster. Router replacement is not this refusal; it moves private Router sites and exact DNS to the new Router.

Deployment, code rollback, clone finalization, Instance removal, environment import, stored environment updates, environment synchronization, and a domain replacement share the target Instance's bounded operation owner. A competitor waits or returns `env.operation_busy` before mutation. The domain replacement also holds the shared private projection owner through its Caddy and DNS work. It does not change source, the selected production release, SQLite data, or local PHP tuning.

Route and Instance removal keep their coordinated removal contract. Setting the existing target or clearing an already empty Route succeeds without creating, deleting, or reassigning a Route association. A Node grant change does not alter private network reachability and retains its command-authorization behavior.

Instance removal is the coordinated target-clear exception. After complete source and Route preflight, the Gateway marks each accepted Instance `removing`. Development removal publishes an unavailable response before deleting each final-target Route in worktree-first order. Production removal republishes every ordered survivor when a shared Route remains. Final-target removal clears managed Route projections, deletes the Route, and releases its domain before source finalization. A projection failure keeps the unfinished Route checkpoint available for retry. The [Instance removal reference](/reference/appinstance-removal) owns content retention, the transient response, cascade order, and retry behavior.

During a Node or Cluster placement mutation, the Gateway validates only Routes whose direct scope, target Nodes, retained generation basis, or provisioning baseline depends on the affected Nodes or Clusters. It compares proposed domains with one operation-local index of all Route domain owners, so an unaffected Route still blocks a collision. Routes outside this workset stay unchanged.

A Node or Cluster TLD change fully reconciles those generated private Routes. A Cluster activation or deactivation fully reconciles the private Routes whose scope or generated domain depends on that Cluster. Node attach and detach fully reconcile the private Routes whose scope or generated domain follows that membership change. Router replacement moves private Router projections without changing Route identity. Other active Route and Router-clearing mutations keep the separate reconciliation refusal.

### Remove an untargeted private Route

`route:destroy` removes an already untargeted private Route. The Gateway refuses a targeted Route before it changes projections. It then removes Route-owned DNS records, withdraws workload and Router Caddy fragments while their certificates remain, removes those certificates, removes firewall entries, and deletes the Route record last. MCP `route-destroy` sends the Route id as a tool argument; the server places it on the path. A DELETE body may repeat that path id, and the Gateway treats it as path identity rather than an unsupported field.

A failure at a projection step or at final record deletion keeps the Route inspectable with bounded `failed_step` and `error_code`. Retry uses the same destroy request, revalidates completed work, and resumes at the earliest unverified step. It does not restore removed projections, delete unrelated Routes or workloads, or accept a conflicting target mutation.

Successful removal releases the domain immediately. An identical retry finds no Route. The Node's shared runtime and composed Caddy service stay. Instance removal remains the owner of final-target deletion; see [Instance removal](/reference/appinstance-removal).

### Router transition ownership

Each Cluster has a Router operation lock. Setting or clearing its Router holds the lock through validation, setup, activation, and cleanup. Cluster status and TLD changes also use it while updating dependent state. Different Clusters use separate locks.

A same-Cluster contender waits for at most 30 seconds or the shorter remaining command deadline. If the current transition still owns the Cluster, the Gateway returns `cluster.router_busy` with HTTP 409 before validation or mutation. A retry enters from current Cluster, Node, Router assignment, and Route state after the previous owner releases.

The owner has no expiry during remote work and releases after success or failure. Router baseline work can reenter the same request owner. Replacement keeps the current Router active until its candidate is ready, promotes the candidate before obsolete cleanup, and keeps failure evidence for an identical retry. Clear removes non-active assignments first and the active assignment last, then resumes retained cleanup on retry.

Operations acquire owners in this order when they need more than one: node lifecycle or role, app-dev source, Cluster Router, Metrics lifecycle or credential, development projection, then remote host. Projection and remote host callbacks do not acquire an earlier owner, and no database transaction remains open during remote work.

## Guard removal

Route ownership prevents deletion from leaving an invalid retained record.

| Removal | Guard |
| --- | --- |
| Project | Refused while the Project owns a Route. |
| Cluster | Refused while the Cluster owns a Route. |
| Node | Refused while a Route retains the Node as scope, target host, or generation basis. |
| Instance | Clears its target only inside an accepted removal, retains a non-empty shared production Route, and deletes a final-target Route before source finalization. |
| Project role | Refused while the Node hosts a Route target. |
| Cluster Ingress | Refused while any public Route in the Cluster depends on the Ingress. |
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | The Gateway deletes an untargeted private Route after projection cleanup, or a pending targeted Route whose Instance is not active. It refuses an active targeted Route before cleanup. |

## Compatibility and limits

Route operations do not change Instance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract projects private Routes and publishes public Routes through Cluster Ingress. It changes an active explicit development or production Route domain by reserving a replacement Route. It replaces an explicit Cluster-scoped production Route target set with recorded retry. It reconciles generated private Routes when a Node TLD, Cluster TLD, Cluster activation, or Cluster membership change alters the effective TLD or routing scope. Generated domains follow [ADR 0063](/decisions/0063-prefer-active-cluster-tlds-for-generated-routes): explicit domain, active Cluster TLD, then Node TLD.

It also removes an already untargeted private Route with its Route-owned projections, moves private Router sites and exact DNS when a Cluster Router is replaced, and coordinates target clearing during development checkout, worktree, fixed-set cascade, and production Instance removal.

It does not implement public DNS providers, automatic placement, application setup, or application health tracking. [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), [ADR 0024](/decisions/0024-follow-generated-route-targets), [ADR 0029](/decisions/0029-manage-laravel-application-urls-through-orbit), [ADR 0030](/decisions/0030-complete-appinstance-provisioning-without-application-health-gates), [ADR 0033](/decisions/0033-trust-wireguard-members-for-private-node-traffic), [ADR 0041](/decisions/0041-delete-an-empty-route-during-appinstance-removal), and [ADR 0063](/decisions/0063-prefer-active-cluster-tlds-for-generated-routes) define the remaining boundaries.
