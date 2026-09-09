# Routes

This page tells an operator what a Route records, how Orbit provisions its initial private traffic path, and which later changes Orbit refuses. [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md) owns hostname and scope selection, [ADR 0024](../decisions/0024-follow-generated-route-targets.md) owns generated target identity, [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) requires one Route per active AppInstance, [ADR 0041](../decisions/0041-delete-an-empty-route-during-appinstance-removal.md) owns final-target deletion during AppInstance removal, and [ADR 0033](../decisions/0033-trust-wireguard-members-for-private-node-traffic.md) owns private Node trust.

## Route record

The Gateway stores Route intent and records the lifecycle of its derived private traffic projections.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one Node or one active Cluster. Active Cluster membership selects Cluster scope even when the Cluster has no TLD. |
| Hostname | One normalized hostname that no other Route owns. |
| Provenance | The immutable stored value `generated` or `explicit`; Orbit does not infer provenance from hostname text. |
| Generation basis | The current target Node for a generated Route, or its last target Node after target clearing. An explicit Route stores no generation basis. |
| Publication intent | The requested publication state, retained even when the Route has no target. |
| Lifecycle | A new Route is pending during provisioning. It becomes active after Orbit prepares its required private projections. Failure metadata identifies an incomplete boundary for retry. |
| Target storage | The Route can own several ordered target rows. An active multi-target set belongs to one explicit production Route and uses distinct active app-prod Nodes in the same Cluster. |
| Configured target | The API, PHP SDK, and CLI accept zero or one AppInstance target. Generated and development Routes permit at most one target. |

Creating the same explicit Route again with identical App, hostname, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route.

One AppInstance cannot belong to two Routes. An active AppInstance has exactly one Route.

## Select a hostname and scope

During creation of a development AppInstance, optional hostname input selects an explicit Route. The AppInstance does not store a second authoritative hostname; API, PHP SDK, and CLI AppInstance output derives the hostname and URL from its sole Route. Without hostname input, the Gateway selects a generated Route. The Gateway refuses a request without an explicit hostname when neither the Node nor its active Cluster supplies a generation basis, and it does so before source or runtime mutation.

A generated Route derives its hostname from the target AppInstance identity, App name, and effective target Node TLD. The Node TLD has priority; the active Cluster TLD is the fallback when the Node has no TLD. [ADR 0025](../decisions/0025-stabilize-the-default-appinstance-identity.md) defines the reserved default identity and hostname shape.

| AppInstance name | Generated hostname with effective TLD `test` |
| --- | --- |
| `default` | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

An explicit source branch changes neither placement nor generated Route identity. For example, `instance:new <app> <node> default --branch=release` still generates `<app>.test`.

An app-dev Node must have a Node TLD or belong to an active Cluster with a TLD. An app-prod Node can remain valid without either TLD because an explicit Route supplies its hostname.

Hostname selection and routing scope are independent. An AppInstance on a Node outside an active Cluster produces Node scope. An AppInstance on a Node in an active Cluster produces Cluster scope, including when the hostname uses the Node TLD or the Cluster has no TLD. A Cluster that owns a Route needs exactly one active Router.

## Route operations

The API, PHP SDK, and CLI expose the same seven typed operations and return the complete Route relationships.

| Operation | Result |
| --- | --- |
| Create | Store an explicit Route with its App, hostname, publication intent, optional single target, and either the target-derived scope or one supplied scope when no target is present. |
| List | Return the Routes visible to the caller in stable order. |
| Show | Return one Route with its stored scope, provenance, generation basis, intent, lifecycle, failure metadata, and target. |
| Update | Change an explicit Route hostname or mutable publication intent without changing its App, provenance, generation basis, or scope. |
| Target set | Add or replace the one AppInstance target when the change does not detach an active AppInstance from its sole Route. |
| Target clear | Remove the target only when that does not leave an active AppInstance without a Route, unless the same operation removes that AppInstance. |
| Remove | Delete the Route and only its Route-owned target rows when no active AppInstance depends on it. |

The CLI names these operations `route:new`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:clear`, and `route:remove`.

## Change or clear a target

The Gateway validates the complete proposed Route before it commits a target change.

| Change | Result |
| --- | --- |
| Set the existing AppInstance target again | Return the unchanged Route. |
| Clear an already empty Route | Return the unchanged Route. |
| Set an AppInstance from another Route | Return `route.target_conflict`, identify the requested and existing Routes, and preserve both associations. |
| Replace a generated or explicit Route target | Return `route.target_conflict` when replacement would detach an active AppInstance from its Route, and preserve the association. |
| Clear the only target | Return `route.target_conflict` when clearing would detach an active AppInstance from its Route, and preserve the association. |
| Remove a targeted Route | Return `route.target_conflict` when removal would detach an active AppInstance from its Route, and preserve the Route, AppInstance, and association. |
| Set an AppInstance from another App or an inactive AppInstance | Reject the change and retain the complete current Route. |
| Set a generated target without an effective TLD | Reject the change and retain the complete current Route. |
| Set a direct Node, backend URL, second generated target, or balancing value | Reject the change and retain the complete current Route. |

A permitted generated target replacement releases the old generation basis only after the replacement commits. Clearing a target from a non-active AppInstance does not release that basis.

## Initial private projection

The Gateway prepares the initial private Route before it marks the Route and AppInstance active. It does not require the application to return a successful response.

### Node scope

Node scope sends private traffic directly to the workload Node.

For Node scope, Gateway DNS resolves the Route hostname to the workload Node. That Node terminates Orbit certificate authority (CA) HTTPS in its composed Caddy service and serves the AppInstance's configured document root through its application runtime.

### Cluster scope

Cluster scope sends private traffic through the Router.

For Cluster scope, Gateway DNS resolves the same hostname to the Router. Router Caddy preserves the hostname as the HTTP `Host` value and Transport Layer Security (TLS) server name when it forwards Orbit-CA HTTPS to the workload Node. Orbit issues separate private keys to the Router and workload Node. When both roles share one Node, the composed Caddy service sends the request to the local runtime without proxying to its own HTTPS listener.

The Router uses the workload Node's configured LAN address. It uses WireGuard only when that LAN address is absent. A configured but unreachable LAN path fails publication and never falls back to WireGuard.

### Private network and publication

Publication exposes the Route only after every required private projection is ready.

Active WireGuard membership trusts a Node to reach every other active WireGuard member over all protocols and ports. Node grants do not limit ordinary private Node traffic; they authorize only Orbit commands and Gateway API actions. A configured LAN path can carry Router-to-workload traffic only when it preserves the same registered-Node trust boundary.

Public traffic enters through Ingress on HTTP or HTTPS. The firewall does not expose a Router or workload Node as a direct public endpoint. Orbit publishes private DNS only after runtime, certificates, Caddy, and firewall preparation succeed.

### Publication ownership

The Gateway serializes app-dev Caddy and private Domain Name System (DNS) publication with Metrics publication. It acquires one owner before it refreshes Route, target, Cluster, and Router facts or renders an aggregate. The owner remains held through Caddy publication, DNS-last publication, and the transaction that marks the Route and AppInstance active. Nested publication calls in the same request share that owner, and a failed operation releases it for a fresh retry.

A concurrent publisher waits for at most 30 seconds or the shorter remaining command deadline. If the current operation still owns publication, the Gateway returns `app-dev.projection_busy` with HTTP 409 before rendering, remote publication, or activation. A retry reads current state after it acquires the owner.

### Gateway worker rollout

Deploy the shared publication owner by stopping admission of new Gateway mutations, draining requests that run the old code, restarting every Gateway worker, and resuming mutations. Keep `$ORBIT_HOME/.dnsmasq-projections.lock` in place throughout the rollout. The deployment deletes no lock file and runs no stored-data migration.

## Guard later reconciliation

### Change a development hostname

The Gateway can change the hostname of an active explicit private development Route with one target. It refuses an invalid or occupied hostname before it changes Route records, Laravel configuration, runtime projections, or traffic.

The Gateway prepares the new workload certificate and Caddy site before it prepares the Router certificate, workload firewall policy, and Router Caddy site. For a detected Laravel source, it then aligns `APP_URL` in the environment file and cached configuration without running Composer, Artisan, or application bootstrap. A non-Laravel source receives no application configuration change. The Gateway accepts valid serving configuration even when the application returns an HTTP error.

Private DNS publication is the traffic cutover. The Gateway publishes the new exact owner only after it verifies every required projection. It then records the new Route hostname, normalizes the serving projections, and removes the old Caddy, certificate, and DNS state. The old hostname remains authoritative until the new hostname is ready, and cleanup starts only after the new hostname is authoritative.

### Resume or refuse a change

The Route exposes the requested hostname, direction, durable checkpoint, `failed_step`, and `error_code` while a change is unfinished. Each failure identifies one bounded preparation, publication, database, cleanup, or rollback step. A retry must request the same hostname, revalidates completed evidence, and resumes at the first unverified step. A conflicting hostname request returns a conflict without changing the in-progress operation.

A failure before database cutover rolls back to the previous Route and Laravel URL. Rollback first restores authoritative DNS, then serving configuration and certificates, and finally the Laravel URL. A rollback interruption keeps its checkpoint and failure visible so the same request can resume restoration. A cleanup failure leaves the new hostname authoritative and resumes cleanup without reverting the completed cutover.

The reconciliation guard still returns `route.reconciliation_required` for production or generated Route hostname changes and active Route publication or target changes. It also refuses Node WireGuard, LAN, TLD, or Cluster-membership changes when an active Route depends on the change. The same rule covers Cluster activation, deactivation, or TLD changes and Router replacement or clearing.

Route and AppInstance removal keep their coordinated removal contract. Setting the existing target or clearing an already empty Route succeeds without creating, deleting, or reassigning a Route association. A Node grant change does not alter private network reachability and retains its command-authorization behavior.

AppInstance removal is the coordinated target-clear exception. After complete source and Route preflight, the Gateway marks each accepted AppInstance `removing`. Development removal publishes an unavailable response before deleting each final-target Route in worktree-first order. Production removal republishes every ordered survivor when a shared Route remains. Final-target removal clears managed Route projections, deletes the Route, and releases its hostname before source finalization. A projection failure keeps the unfinished Route checkpoint available for retry. The [AppInstance removal reference](appinstance-removal.md) owns content retention, the transient response, cascade order, and retry behavior.

During a Node or Cluster placement mutation, the Gateway validates only Routes whose direct scope, target Nodes, retained generation basis, or provisioning baseline depends on the affected Nodes or Clusters. It compares proposed hostnames with one operation-local index of all Route hostname owners, so an unaffected Route still blocks a collision. Routes outside this workset stay unchanged. Full reconciliation of an existing active Route is a separate contract.

### Router transition ownership

The Gateway gives each Cluster one Router operation owner. Router set and clear hold that owner from fresh validation through baseline work, promotion, and ordered cleanup. A Cluster state or TLD update also holds it while validating and reconciling Router-dependent state. Different Clusters use different owners.

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
| AppInstance | Clears its target only inside an accepted removal, retains a non-empty shared production Route, and deletes a final-target Route before source finalization. |
| App role | Refused while the Node hosts a Route target. |
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | Deletes only an eligible Route and its Route-owned target rows. |

## Compatibility and limits

Route operations do not change legacy Instance hostname or certificate fields, Workspace hostnames, AppInstance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract projects private development Routes and coordinates target clearing during development checkout, worktree, fixed-set cascade, and production AppInstance removal. It does not implement other later Route reconciliation or removal, public Ingress, public DNS providers, public production pool creation, production placement, application setup, or application health tracking. [ADR 0009](../decisions/0009-clustered-app-instance-routing.md), [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md), [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md), [ADR 0024](../decisions/0024-follow-generated-route-targets.md), [ADR 0029](../decisions/0029-manage-laravel-application-urls-through-orbit.md), [ADR 0030](../decisions/0030-complete-appinstance-provisioning-without-application-health-gates.md), [ADR 0033](../decisions/0033-trust-wireguard-members-for-private-node-traffic.md), and [ADR 0041](../decisions/0041-delete-an-empty-route-during-appinstance-removal.md) define the remaining boundaries.
