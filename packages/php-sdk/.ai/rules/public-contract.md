# Public contract

The SDK models exactly 36 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, provision, remove, and add role.
- Node app-dev setup: fetch script and submit result.
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
- Add a node role only through `POST /api/v1/nodes/{node}/roles`. Fetch and
  submit app-dev setup only through
  `POST /api/v1/node-role-setups/app-dev/script` and
  `POST /api/v1/node-role-setups/app-dev/result`.
  Role removal and generic role-setup routes remain forbidden.
- Send `host_key_fingerprint` in a node provision request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep production-instance `hostname` optional in SDK transport. The Gateway
  validates it for app production and derives it for app development.
- Omit a process runtime only when it is `null`. Preserve every non-null string
  exactly, including empty, unsupported, oversized, and control-bearing direct
  SDK input. The Gateway owns validation and cross-field policy.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, permissions, role removal, generic role setup, Compose,
  image-building, stream, database, proxy, schedule, VPN, tool, or deploy
  surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
