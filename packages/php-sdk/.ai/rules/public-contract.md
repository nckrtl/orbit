# Public contract

The SDK models exactly 68 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, provision, settings update, remove, access add, access remove, role list, role add, and role remove.
- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.
- App: list, show, create, and remove.
- AppInstance: list, show, create, and remove through the concise Instance routes.
- Route: list, show, create, update, target set, target clear, and remove.
- Workspace: list, show, create, remove, and update PHP.
- Process: list, add, start, stop, restart, logs, and remove.
- Firewall: list, allow, deny, and remove.
- Tool: manager list, tool list, show, install, update, and remove.
- Doctor: run the complete typed Gateway report.
- Metrics: enable, disable, status, credentials, credential reset, exporter enable, and exporter disable.

The two abstract request bases are implementation details, not extra Gateway
operations. Keep the public API typed and small.

- Use numeric resource IDs in routes where the Gateway contract does. Keep a
  firewall rule name as the delete route key. Do not substitute display names
  for identifiers.
- Send `host_key_fingerprint` in a node provision request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep AppInstance transport limited to App, Node, name, optional root, optional
  Route hostname, and explicit source-discard intent. The Gateway owns placement,
  source, and Route policy.
- Keep Route transport limited to App, hostname, publication intent, exclusive
  Node-or-Cluster scope, and at most one scalar AppInstance target. The Gateway
  owns hostname, scope, basis, relationship, and lifecycle policy.
- Preserve explicitly supplied process fields for every runtime. The Gateway
  owns cross-field policy.
- Model binary node access add/remove and node-show access lists. Do not model
  granular permissions, presets, wildcards, permission editing, or legacy
  grant/revoke compatibility.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, Compose, image-building, stream, database,
  proxy, schedule, or deploy surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
- Preserve manager, package, nullable constraint, outcomes, structured errors,
  and request IDs without applying policy. The SDK transports typed values; the
  Gateway owns validation and execution policy.
