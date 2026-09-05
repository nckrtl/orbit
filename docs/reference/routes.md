# Routes

This page tells an operator what a Route records, how Orbit provisions its initial private traffic path, and which later changes Orbit temporarily refuses. [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md) owns hostname and scope selection, [ADR 0024](../decisions/0024-follow-generated-route-targets.md) owns generated target identity, and [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) requires one Route per active AppInstance.

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
| Target storage | The Route can own several ordered target rows. |
| Configured target | The API, PHP SDK, and CLI accept zero or one AppInstance target. A generated Route also permits at most one target. |

Creating the same explicit Route again with identical App, hostname, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route.

One AppInstance cannot belong to two Routes. An active AppInstance has exactly one Route.

## Select a hostname and scope

During creation of a development AppInstance, optional hostname input selects an explicit Route. The AppInstance does not store a second authoritative hostname. Without hostname input, the Gateway selects a generated Route. The Gateway refuses a request without an explicit hostname when neither the Node nor its active Cluster supplies a generation basis, and it does so before source or runtime mutation.

A generated Route derives its hostname from the target AppInstance name, App name, and effective target Node TLD. The Node TLD has priority; the active Cluster TLD is the fallback when the Node has no TLD.

| AppInstance name | Generated hostname with effective TLD `test` |
| --- | --- |
| The App's exact main branch | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

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
| Target clear | Remove the target only when that does not leave an active AppInstance without a Route. |
| Remove | Delete the Route and only its Route-owned target rows when no active AppInstance depends on it. |

The CLI names these operations `route:new`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:clear`, and `route:remove`.

## Change or clear a target

The Gateway validates the complete proposed Route before it commits a target change.

| Change | Result |
| --- | --- |
| Set the existing AppInstance target again | Return the unchanged Route. |
| Set an active AppInstance from another Route | Return `route.reconciliation_required` and preserve both associations. |
| Replace a generated Route target | Return `route.reconciliation_required` when replacement would detach an active AppInstance before coordinated runtime and URL reconciliation exists. |
| Replace an explicit Route target | Return `route.reconciliation_required` when replacement would detach an active AppInstance before coordinated runtime and URL reconciliation exists. |
| Clear the only target | Return `route.reconciliation_required` for an active AppInstance and preserve the Route, source, configuration, records, and serving path. |
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

### Publication

Publication exposes the Route only after every required private projection is ready.

Route firewall rules admit the required private client and Router paths and do not open a workload listener to unintended public traffic. Orbit publishes private DNS only after runtime, certificates, Caddy, and firewall preparation succeed.

## Guard later reconciliation

Initial projection does not implement later reconciliation. The Gateway returns `route.reconciliation_required` before a mutation that would change an active Route's hostname, target, Node-or-Cluster scope, runtime projection, or Laravel URL. It preserves source, application configuration, Route records, and the serving path.

The temporary guard covers Route hostname changes, target replacement or clearing, Route removal, Node attachment and detachment, Cluster activation and deactivation, Node or Cluster TLD changes, and Router replacement or clearing when an active Route depends on it. An identical request that makes no change remains safe.

Routes without an active AppInstance retain the existing validation for hostname, scope, target, uniqueness, generation basis, and required Router. Full reconciliation of an existing active Route is a separate contract.

## Guard removal

Route ownership prevents deletion from leaving an invalid retained record.

| Removal | Guard |
| --- | --- |
| App | Refused while the App owns a Route. |
| Cluster | Refused while the Cluster owns a Route. |
| Node | Refused while a Route retains the Node as scope, target host, or generation basis. |
| AppInstance | Returns `route.reconciliation_required` before source deletion or target detachment while coordinated removal is unavailable. |
| App role | Refused while the Node hosts a Route target. |
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | Deletes only an eligible Route and its Route-owned target rows. |

## Compatibility and limits

Route operations do not change legacy Instance hostname or certificate fields, Workspace hostnames, AppInstance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract projects the first private development Route only. It does not implement later Route reconciliation or removal, public Ingress, public DNS providers, multi-target balancing, production placement, application setup, or application health tracking. [ADR 0009](../decisions/0009-clustered-app-instance-routing.md), [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md), [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md), [ADR 0024](../decisions/0024-follow-generated-route-targets.md), [ADR 0029](../decisions/0029-manage-laravel-application-urls-through-orbit.md), and [ADR 0030](../decisions/0030-complete-appinstance-provisioning-without-application-health-gates.md) define the remaining boundaries.
