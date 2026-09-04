# ADR 0024: Make generated Route identity follow its target

In the context of Route-owned hostnames that can outlive their single target, facing target replacement and clearing across Nodes, we decided for a stored target-derived generation basis that moves atomically with the target and remains after clearing, and against permanent original-Node binding or fixed generated hostnames, to keep generated names predictable, accepting that Orbit must retain the last basis while a Route has no target.

## Status

Accepted on 2026-09-04. Extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md).

## Context

[ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) makes a Route authoritative for its hostname and requires affected generated hostnames to reconcile with Node and Cluster mutations. A Route can keep its hostname after its target is cleared, but the decision does not identify which Node supplies the effective TLD after a target changes or disappears. The same missing identity leaves target replacement, basis-Node removal, and generated multi-target Routes ambiguous.

## Decision

- Orbit must derive a generated Route hostname from its current target AppInstance name, App name, and target Node effective TLD.
- Orbit must atomically replace a generated Route target, generation basis, hostname, and routing scope.
- Orbit must atomically replace an explicit Route target and routing scope without changing its hostname.
- A generated Route owns its last generation basis Node while it has no target.
- Orbit must retain a Route hostname, routing scope, and generation basis when its target is cleared.
- Orbit must reconcile a generated Route before a mutation changes the effective TLD of its current or retained generation basis Node.
- Orbit must refuse removal of a Node while a generated Route retains that Node as its generation basis.
- Orbit must not assign more than one target to a generated Route without another accepted decision that defines one generation basis for the target set.

## Rejected alternatives

- Bind a generated Route permanently to its original Node: rejected because a target move would retain an obsolete Node namespace and keep the old Node as a permanent dependency.
- Infer the generation basis only from a present target: rejected because a zero-target Route retains its hostname and still needs deterministic mutation and removal behavior.
- Freeze every generated hostname after creation: rejected because a target move or effective-TLD change would no longer follow the selected generated-name contract.

## Consequences

- Replacing the target of a generated Route can change both its hostname and routing scope.
- Replacing the target of an explicit Route can change its routing scope but not its hostname.
- Clearing a target preserves one deterministic unavailable Route until another target replaces its basis.
- A retained generation basis can temporarily prevent Node removal.
- Generated Routes remain single-target until another accepted decision defines multi-target naming.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md)
- Detail: docs/reference/routes.md
- Verify: `bin/test`
