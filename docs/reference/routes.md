# Routes

This page tells an operator what a Route records, how Orbit selects its hostname and routing scope, and which changes Orbit accepts before traffic convergence. [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md) owns hostname and scope selection, and [ADR 0024](../decisions/0024-follow-generated-route-targets.md) owns generated target identity.

## Route record

The Gateway stores Route intent independently from Domain Name System (DNS), certificates, Caddy, firewalls, health checks, and request forwarding.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one Node or one active Cluster. Active Cluster membership selects Cluster scope even when the Cluster has no TLD. |
| Hostname | One normalized hostname that no other Route owns. |
| Provenance | The immutable stored value `generated` or `explicit`; Orbit does not infer provenance from hostname text. |
| Generation basis | The current target Node for a generated Route, or its last target Node after target clearing. An explicit Route stores no generation basis. |
| Publication intent | The requested publication state, retained even when the Route has no target. |
| Lifecycle | Stored intent has status `pending`, `failed_step = null`, and `error_code = null`. These operations do not advance the lifecycle. |
| Target storage | The Route can own several ordered target rows. |
| Configured target | The API, PHP SDK, and CLI accept zero or one AppInstance target. A generated Route also permits at most one target. |

Creating the same explicit Route again with identical App, hostname, publication intent, scope, and target returns the existing Route. A retry that changes one of those values fails without changing the Route.

## Select a hostname and scope

Optional hostname input during app-dev AppInstance creation is a convenience that creates an explicit Route after the AppInstance becomes active. The AppInstance does not store a second authoritative hostname. Without hostname input, the Gateway creates a generated Route after activation.

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
| Target set | Add or replace the one AppInstance target and reconcile the Route atomically. |
| Target clear | Remove the target while retaining the Route and its last routing state. |
| Remove | Delete the Route and only its Route-owned target rows. |

The CLI names these operations `route:new`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:clear`, and `route:remove`.

## Change or clear a target

The Gateway validates the complete proposed Route before it commits a target change.

| Change | Result |
| --- | --- |
| Set an active same-App AppInstance | Accepted when the resulting scope, hostname, Router requirement, and uniqueness rules are valid. |
| Replace a generated Route target | Atomically replace its target, generation basis, hostname, and routing scope from the new AppInstance and Node. |
| Replace an explicit Route target | Atomically replace its target and routing scope while preserving its hostname. |
| Clear the only target | Retain the Route, hostname, scope, provenance, publication intent, and last generation basis, and report no active backend. |
| Set an AppInstance from another App or an inactive AppInstance | Reject the change and retain the complete current Route. |
| Set a generated target without an effective TLD | Reject the change and retain the complete current Route. |
| Set a direct Node, backend URL, second generated target, or balancing value | Reject the change and retain the complete current Route. |

A successful generated target replacement releases the old generation basis only after the replacement commits. Clearing a target does not release that basis.

## Reconcile infrastructure changes

The Gateway reconciles every affected Route before a Node or Cluster mutation becomes authoritative. It commits the infrastructure mutation and all resulting target, generation-basis, hostname, and scope changes atomically, or it retains the complete old state.

The affected mutations include Node attachment and detachment, Cluster activation and deactivation, and Node or Cluster TLD changes. Active Cluster membership changes every affected Route to Cluster scope; leaving active membership changes it to Node scope. When a mutation changes the effective TLD, the Gateway recomputes only the generated hostnames based on that TLD. Explicit hostnames never change during this reconciliation.

The Gateway rejects a mutation when any resulting Route has no valid hostname, scope, target, unique hostname, or required Router. Removing the last Node TLD used by an app-dev Route therefore requires an active Cluster TLD fallback. An app-prod Node and its explicit Routes need no Node or Cluster TLD.

## Guard removal

Route ownership prevents deletion from leaving an invalid retained record.

| Removal | Guard |
| --- | --- |
| App | Refused while the App owns a Route. |
| Cluster | Refused while the Cluster owns a Route. |
| Node | Refused while a Route retains the Node as scope, target host, or generation basis. |
| AppInstance | Clears its Route target while retaining the Route state. |
| App role | Refused while the Node hosts a Route target. |
| Cluster Router | Clearing the Router assignment is refused while the Cluster owns a Route. |
| Route | Deletes only the Route and its Route-owned target rows. |

## Compatibility and limits

Route operations do not change legacy Instance hostname or certificate fields, Workspace hostnames, AppInstance source, Nodes, Clusters, or checkouts. Route and route target are typed inputs to the existing `instance` Doctor family; Doctor adds no family and remains verify-only.

This contract does not render Router or workload Caddy configuration, forward requests, publish DNS records, issue certificates, change firewall state, or perform health checks. Those projections derive from the same Route record. Private traffic for a Route with Cluster scope normally enters through the Router. An allowed client can address the same hostname directly on a target workload Node. Public traffic always enters through Ingress before the Router and workload, including when roles share one Node. [ADR 0009](../decisions/0009-clustered-app-instance-routing.md), [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md), [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md), and [ADR 0024](../decisions/0024-follow-generated-route-targets.md) define these boundaries.
