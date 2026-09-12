# Public contract

The SDK models exactly 97 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, provision, settings update, remove, access add, access remove, role list, role add, and role remove.
- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.
- App: list, show, create, and remove.
- App runtime definition: process and Schedule list, create, show, replace, and remove.
- AppInstance: list, show, create, register, clone, remove, deployment-layout preparation, deployment configuration read and replace, deploy, rollback, retained-release list, environment import, environment update, and environment synchronization through the concise Instance routes.
- Route: list, show, create, update, target set, target clear, and remove.
- Workspace: list, show, create, remove, and update PHP.
- Process: list, add, start, stop, restart, logs, and remove.
- Schedule: list, add, show, run, logs, complete, remove, and activate.
- Firewall: list, allow, deny, and remove.
- Tool: manager list, tool list, show, install, update, and remove.
- Doctor: run the complete typed Gateway report.
- Metrics: enable, disable, status, credentials, credential reset, exporter enable, and exporter disable.

The four abstract request bases are implementation details, not extra Gateway
operations. Keep the public API typed and small.

- Use numeric resource IDs in routes where the Gateway contract does. Keep a
  firewall rule name as the delete route key. Do not substitute display names
  for identifiers.
- Send `host_key_fingerprint` in a node provision request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep AppInstance lifecycle transport limited to App, Node, name, optional
  root, optional Route hostname, optional creation branch, explicit
  source-profile recovery, and explicit force intent.
  The Gateway owns placement, source, and Route policy.
- Keep candidate clone transport limited to the numeric candidate AppInstance
  ID, destination Node ID, target name, preview name, optional branch, and
  optional SQLite source path. Preserve omission separately from every supplied
  string. The Gateway owns candidate eligibility, placement, cloning, and Route
  policy.
- Keep AppInstance deployment-layout transport limited to the numeric
  AppInstance ID and an optional explicit SQLite source path. Preserve omission
  separately from every supplied string. The Gateway owns eligibility,
  placement, conversion, and recovery policy.
- Keep AppInstance deployment transport limited to configuration read and
  replacement, explicit deploy and rollback streams, and retained-release
  inspection. Preserve optional step-timeout omission, require an explicit empty
  deployment body, and send only the selected release for rollback. Decode
  bounded correlated events incrementally, close a cancelled response, and
  never retry or replay a deployment stream. The Gateway owns deployment
  validation, execution, cancellation, and recovery policy.
- Treat configured deployment commands and decoded application output as
  sensitive transport values. Keep them out of generic debug and serialization
  representations while preserving their intended request or event value.
- Keep AppInstance environment transport limited to an ID-or-hostname selector,
  optional import replacement, one key and string value for update, an empty
  synchronization body, and the bounded value-free operation result. The
  Gateway owns lookup, validation, references, storage, and synchronization.
- Keep Route transport limited to App, hostname, publication intent, exclusive
  Node-or-Cluster scope, and at most one scalar AppInstance target. The Gateway
  owns hostname, scope, basis, relationship, and lifecycle policy.
- Preserve explicitly supplied process fields for every runtime. The Gateway
  owns cross-field policy.
- Keep App runtime definition transport limited to a numeric App ID, a
  definition UUID for item operations, and the caller's exact JSON document for
  create and full replacement. The Gateway owns definition validation and
  persistence. Collection responses omit commands.
- Keep Schedule transport limited to typed Node and AppInstance targets and the
  eight shipped operations. Preserve caller-supplied optional values, bounded
  and redacted command and log responses, the Gateway's JSON envelopes for
  seven operations, and completion's validated response-header request ID with
  no response body. The Gateway owns target resolution, validation, execution,
  and lifecycle policy.
- Accept only the current Doctor family tokens: node, role, app, instance,
  workspace, schedule, tool, process, and firewall. Keep Doctor verify-only and
  policy-free.
- Model binary node access add/remove and node-show access lists. Do not model
  granular permissions, presets, wildcards, permission editing, or legacy
  grant/revoke compatibility.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, Compose, image-building, generic stream, database,
  or proxy surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
- Preserve manager, package, nullable constraint, outcomes, structured errors,
  and request IDs without applying policy. The SDK transports typed values; the
  Gateway owns validation and execution policy.
