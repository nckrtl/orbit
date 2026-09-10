# ADR 0042: Provision Tool Managers on demand

In the context of Tool operations on Gateway-managed Nodes, facing a requirement to use supported managers without tying them to application roles or installing every manager everywhere, we decided for retained on-demand Node capabilities and against role-owned or universally preinstalled managers, to make Tools available wherever the Gateway owns SSH management, accepting setup latency on first use.

## Status

Accepted on 2026-09-08. Extends [ADR 0001](0001-tool-management.md). Supersedes [ADR 0001](0001-tool-management.md) for Tool Manager availability, application-role ownership, and final application-role removal.

## Context

Ubuntu 24.04 is no longer supported. The references to ADR 0012 preserve its management boundary only; they do not authorize client support or enrollment.

[ADR 0001](0001-tool-management.md) makes APT available on managed Linux Nodes but makes VP and Composer available only through an application role. That prevents an operator from using a supported manager on another SSH-managed Node and makes role removal responsible for unrelated Tool intent. Installing every supported manager during Node provisioning would avoid that restriction at the cost of unnecessary software and mutations on every Node.

## Decision

- The Gateway must treat each Tool Manager as a protected Node capability independent of Node roles.
- The Gateway must allow Tool mutations only on an active Linux Node whose supported managed-node platform and SSH transport are controlled by the Gateway.
- The Gateway must not manage Tools on a roleless operator client governed by [ADR 0012 (platform support withdrawn)](0012-ubuntu-24-04-roleless-operator-clients.md).
- The Gateway must materialize a missing Tool Manager when the first Tool operation needs it.
- The Gateway must retain a failed materialization as retryable manager state.
- The Gateway must not install every registered Tool Manager during Node provisioning.
- A role may require a Tool Manager when it converges, but the role must not own that manager or its Tools.
- The Gateway must retain a materialized Tool Manager after its final Tool is removed.
- Public Tool operations must not remove a Tool Manager.
- The Gateway must not block final application-role removal solely because Tool intent remains on an SSH-managed Node.

## Rejected alternatives

- Keep VP, Composer, and each added manager owned by application roles: rejected because Tool availability would continue to depend on workload placement rather than Node manageability.
- Install every registered manager during Node provisioning: rejected because most Nodes do not use every manager and would receive unnecessary software and supply-chain exposure.
- Manage roleless operator clients when SSH happens to be reachable: rejected because those clients remain outside Gateway-owned convergence under ADR 0012.

## Consequences

- Every supported Tool Manager can serve any compatible SSH-managed Node.
- The first operation through a missing manager includes its materialization time and can fail before the Tool changes.
- Manager software and its protected state remain after the final managed package is removed.
- Application-role removal no longer disables unrelated Tool intent.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0001](0001-tool-management.md); supersedes [ADR 0001](0001-tool-management.md) for Tool Manager availability, application-role ownership, and final application-role removal; preserves [ADR 0012 (platform support withdrawn)](0012-ubuntu-24-04-roleless-operator-clients.md)
- Detail: [Tools](../reference/tools.md)
- Verify: `bin/test`
