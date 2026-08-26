# Public contract

The SDK models exactly 33 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, provision, and remove.
- App: list, show, create, and remove.
- Instance: list, show, create, remove, and update PHP.
- Workspace: list, show, create, remove, and update PHP.
- Process: list, add, start, stop, restart, logs, and remove.
- Firewall: list, allow, deny, and remove.

The two abstract request bases are implementation details, not extra Gateway
operations. Keep the public API typed and small.

- Use numeric resource IDs in routes where the Gateway contract does. Keep a
  firewall rule name as the delete route key. Do not substitute display names
  for identifiers.
- Send `host_key_fingerprint` in a node provision request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep production-instance `hostname` optional in SDK transport. The Gateway
  validates it for app production and derives it for app development.
- Preserve explicitly supplied process fields for every runtime. The Gateway
  owns cross-field policy.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, permissions, role add/remove, Compose, image-building, stream,
  database, proxy, schedule, VPN, tool, or deploy surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
