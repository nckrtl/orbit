# Routes

This page tells an operator what a Route records, how Orbit creates and changes it, and which related resource mutations the Gateway accepts before traffic convergence.

## Route record

The Gateway stores Route intent independently from Domain Name System (DNS), certificates, Caddy, firewalls, health checks, and request forwarding.

| Value | Contract |
| --- | --- |
| App | The stable owner of the Route and every allowed target. |
| Routing scope | Exactly one direct Node or one active Cluster, independent from the hostname suffix. |
| Hostname | One normalized hostname that no other Route owns. |
| Provenance | The immutable stored value `generated` or `explicit`; Orbit does not infer it from hostname text. |
| Publication intent | Exactly `private` or `public`, retained when the Route has no target. |
| Lifecycle | `pending` while traffic projection is not part of the operation. Route operations do not mark a Route `active`. |
| Failure metadata | `failed_step` and `error_code` are null for every successful pending Route operation. Validation failures return an error without storing failure text. |
| Targets | Ordered one-to-many rows that each reference an AppInstance. The public v1 operations configure and return at most one target. |

The public caller supplies no provenance, lifecycle, failure, backend Node, address, URL, or balancing field. Creating the same Route again with identical App, scope, normalized hostname, publication intent, and target returns the existing Route. A retry with conflicting values fails without changing it.

## Create a Route

Every new Route starts with one active app-dev AppInstance target. `route:new` creates an explicit Route from a required App, one Node-or-Cluster scope, hostname, `private` or `public` publication intent, and scalar AppInstance target.

`instance:new` provides a convenience path for development Routes. The Gateway first completes the AppInstance source lifecycle and marks the AppInstance active. It then creates an explicit Route when `--hostname` was supplied or a generated private Route otherwise. A Route created by either path starts as `pending` with null failure metadata.

The generated hostname uses the Node TLD first and the active Cluster TLD only when the Node has no TLD.

| AppInstance name | Generated hostname with selected TLD `test` |
| --- | --- |
| The App's exact main branch | `<app>.test` |
| Any other name | `<instance>.<app>.test` |

Hostname selection does not choose routing scope. An AppInstance outside an active Cluster gets Node scope. One on a Node in an active Cluster gets Cluster scope even when the hostname uses the Node TLD or an explicit name. A Cluster that receives a Route must already have one active Router, including when it has no TLD.

## Route operations

The API, PHP SDK, and CLI expose the same seven typed operations and return one bounded Route shape.

| CLI command | Result |
| --- | --- |
| `route:new` | Create an explicit pending Route with one scalar active target. |
| `route:list` | Return the Routes visible to the caller in stable order. |
| `route:show` | Return one Route with its App, scope, provenance, intent, lifecycle, failure metadata, and nullable target. |
| `route:update` | Change publication intent or the hostname of an explicit Route without changing its App, scope, provenance, lifecycle, or target. |
| `route:target:set` | Replace the configured target atomically while keeping Route identity. |
| `route:target:clear` | Remove every configured target while keeping the Route and its intent. |
| `route:remove` | Delete the Route and all rows it owns. |

The Gateway owns hostname, scope, target, lifecycle, and relationship validation. The PHP SDK carries typed values without applying Gateway policy. The CLI rejects only command-shape errors it can decide locally, such as a non-numeric required ID, both scope options, neither scope option, or an update with no supplied field. The Gateway rejects malformed hostnames, invalid relationships, unsupported lifecycle input, and stale or conflicting state.

## Target storage and validation

Route persistence keeps several target rows in an explicit order. Public v1 accepts a scalar AppInstance ID, never a target array. Setting a target replaces the current public target; clearing it removes all target rows. Either operation keeps Route identity, hostname, scope, provenance, publication intent, and lifecycle.

The Gateway validates the complete proposed target before it changes the Route.

| Input | Result |
| --- | --- |
| One active AppInstance in the Route's App and direct Node scope | Accepted. |
| One active AppInstance in the Route's App on a member Node of its Cluster scope | Accepted. |
| No target after a clear or AppInstance removal | The pending Route remains stored and reports a null target. |
| An AppInstance from another App | Rejected; every Route row is unchanged. |
| An AppInstance outside the Route's Node or Cluster scope | Rejected; every Route row is unchanged. |
| An inactive AppInstance | Rejected; every Route row is unchanged. |
| A target array, direct Node, backend URL, or balancing value | Rejected; every Route row is unchanged. |

Removing an AppInstance deletes only target rows that reference it after the existing source-removal checks succeed. The zero-target Route remains.

## Reconcile related mutations

The Gateway locks and validates the complete proposed Route set before it makes a related Node or Cluster value authoritative. A duplicate or invalid generated hostname, invalid scope or target, missing effective development TLD, or missing Router refuses the whole mutation and preserves every old Route, Node, Cluster, and membership value. An app-dev Node must retain its Node TLD or an active Cluster TLD fallback. An app-prod Node with explicit Routes needs neither.

| Mutation | Route result |
| --- | --- |
| Change or remove a Node TLD | Recompute generated names on that Node only when the selected TLD changes. |
| Change or remove a Cluster TLD | Recompute generated names only for member Nodes that have no Node TLD. Scope still follows Cluster state. |
| Activate a Cluster or attach a Node to an active Cluster | Move affected Routes to Cluster scope. Keep Node-TLD and explicit hostnames unchanged. Require one active Router when at least one Route moves. |
| Deactivate a Cluster or detach a Node | Move affected Routes to Node scope and recompute generated names whose selected TLD changes. Refuse when any target cannot fit one Node scope. |
| Set or clear a Router | Router replacement keeps Routes unchanged. Clearing is refused while the Cluster owns any Route. |

These operations change stored desired state only. They do not publish or remove a DNS, certificate, Caddy, firewall, health, Router, Ingress, or workload projection.

## Removal guards

The Gateway prevents ordinary, forced, purged, and offline removal paths from orphaning Route intent.

| Removal | Result while a Route depends on the resource |
| --- | --- |
| App | Refused while the App owns a Route. |
| Node | Refused while the Node is a direct scope or hosts any Route target. |
| Cluster | Refused while the Cluster is a Route scope. |
| `app-dev` or `app-prod` role | Refused while the Node hosts a Route target. |
| AppInstance | Existing safe source removal runs first, then only its target rows are cleared. |
| Route | The Route and all owned target rows are deleted; App, AppInstance, Node, Cluster, role, and checkout records remain. |

## Compatibility and limits

Legacy Instance responses keep `hostname` and `certificate_mode`, Workspace responses keep `hostname`, and Route operations change no legacy row. Route operations do not change AppInstance source or checkout identity.

The stored `public` value is intent only. Public traffic still requires Ingress, and no public request reaches a workload Node directly. [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md) defines hostname and scope selection. [ADR 0009](../decisions/0009-clustered-app-instance-routing.md) and [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md) define the private and public projection boundaries.
