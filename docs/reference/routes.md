# Routes

This page tells an operator what a Route records, how generated development hostnames are formed, and which target changes Orbit accepts before traffic convergence.

## Route record

The Gateway stores Route intent independently from DNS, certificates, Caddy, firewalls, and request forwarding.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one direct Node or one active TLD-bearing Cluster. |
| Hostname | One normalized hostname that no other Route owns. |
| Provenance | The immutable stored value `generated` or `explicit`; Orbit does not infer it from hostname text. |
| Publication intent | The requested publication state, retained even when the Route has no target. |
| Lifecycle | A bounded state with nullable failure step and error code. |
| Target | Zero or one active AppInstance in the Route's App and routing scope. |

Creating the same Route again with identical App, scope, hostname, publication intent, provenance, and target returns the existing Route. A retry that conflicts with the stored App or scope fails without changing the Route.

## Generated development hostnames

Development AppInstance creation records a generated Route only when the selected Node has an effective TLD. The active Cluster TLD takes precedence over the Node TLD.

| AppInstance name | Generated hostname with effective TLD `test` |
| --- | --- |
| The App's exact main branch | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

When the Node has no effective TLD, AppInstance creation records no generated Route. Publication then requires an explicit hostname. A later App slug, main-branch, or routing-scope change does not rename a generated Route as part of this contract.

## Route operations

The API, PHP SDK, and CLI expose the same seven typed operations and return the complete Route relationships.

| Operation | Result |
| --- | --- |
| Create | Store an explicit Route with its App, exclusive scope, hostname, publication intent, and optional single target. |
| List | Return the Routes visible to the caller in stable order. |
| Show | Return one Route with its stored scope, provenance, intent, lifecycle, failure metadata, and target. |
| Update | Change the mutable Route intent without changing its identity, App, scope, or hostname provenance. |
| Target set | Add or replace the one AppInstance target while keeping the Route identity. |
| Target clear | Remove the target while keeping the Route, scope, hostname, and publication intent. |
| Remove | Delete the Route and only its Route-owned target row. |

The CLI names these operations `route:new`, `route:list`, `route:show`, `route:update`, `route:target:set`, `route:target:clear`, and `route:remove`.

## Target validation

The Gateway validates the complete proposed target before it changes the Route.

| Input | Result |
| --- | --- |
| One active AppInstance in the Route's App and direct Node scope | Accepted. |
| One active AppInstance in the Route's App on a member Node of its Cluster scope | Accepted. |
| No target | The Route remains stored and reports no active backend. |
| An AppInstance from another App | Rejected; the current target is unchanged. |
| An AppInstance outside the Route's Node or Cluster scope | Rejected; the current target is unchanged. |
| An inactive AppInstance | Rejected; the current target is unchanged. |
| A direct Node, backend URL, second target, or balancing value | Rejected; the current target is unchanged. |

## Compatibility and limits

Route operations do not change legacy Instance hostname or certificate fields, Workspace hostnames, AppInstance source, Nodes, Clusters, or checkouts. Removing a Route deletes no resource outside its own target row.

This contract records publication intent but does not render Router or workload Caddy configuration, forward requests, publish Gateway Domain Name System records, issue certificates, or change firewall state. [ADR 0009](../decisions/0009-clustered-app-instance-routing.md), [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md), and [ADR 0017](../decisions/0017-optional-cluster-placement-and-tld-precedence.md) define those later projection boundaries.
