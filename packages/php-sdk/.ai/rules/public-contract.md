# Public contract

The SDK models exactly 169 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, add, rename, settings update, remove, access add, access remove, role list, role add, role relocate, role remove, and metrics.
- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.
- App: list, show, create, update, and remove.
- Development node exclusion: add, list, and remove from either the Project or the Node.
- App runtime definition: process and Schedule list, create, show, update, and destroy.
- AppInstance: list, show, create, register, clone, transfer, remove, update, deploy-step create, list, update, and destroy, deploy, rollback, retained-release list, deployment-history list and show, environment import, environment update, environment synchronization, dependency inventory read, dependency scan, dependency update, and full-domain and directory instance resolution through the concise Instance routes.
- Route: list, show, create, update, target set, target clear, and remove.
- Process: list, add, start, stop, restart, logs, and remove.
- Schedule: list, add, show, run, logs, complete, remove, and activate.
- Firewall: list, allow, deny, and remove.
- Tool: manager list, tool list, show, install, update, and remove.
- Doctor: run the complete typed Gateway report.
- Herdr: session list, add, adopt, show, restart, remove, and observation-grant.
- Database connection: list, show, add, update, remove, attach, detach, query, tables, schema, describe, user create, and user list.
- Metrics: enable, disable, status, credentials, credential reset, exporter enable, and exporter disable.
- Analytics: pin the Plausible version, and show, set, and unset the Stats API key. The key is never returned.
- GitHub App: install, show, and destroy.
- proxycli: enable, disable, status, provider list, provider show, and account update.
- Tasks: enable, disable, status, group list, show, create, update, cancel, and complete, subtask create, update, and destroy, comment create and list, and agent thread list.

The four abstract request bases are implementation details, not extra Gateway
operations. Keep the public API typed and small.

- Use numeric resource IDs in routes where the Gateway contract does. Keep a
  firewall rule name as the delete route key. Do not substitute display names
  for identifiers.
- Send `host_key_fingerprint` in a node add request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep AppInstance lifecycle transport limited to App, Node, name, optional
  root, optional Route domain, optional creation branch, explicit
  source-profile recovery, and explicit force intent.
  The Gateway owns placement, source, and Route policy.
- Keep candidate clone transport limited to the numeric candidate AppInstance
  ID, destination Node ID, target name, preview name, optional branch, and
  optional SQLite source path. Preserve omission separately from every supplied
  string. The Gateway owns candidate eligibility, placement, cloning, and Route
  policy.
- Keep AppInstance transfer transport limited to the numeric AppInstance ID,
  destination Node ID, optional rename, and optional SQLite source path.
  Preserve omission separately from every supplied string. The Gateway owns
  eligibility, destination reservation, downtime, Route cutover, and cleanup.
- Keep AppInstance deployment transport limited to named deploy-step create,
  list, update, and destroy, AppInstance branch update, explicit deploy and
  rollback streams, and retained-release inspection. Preserve optional
  step-timeout omission, require an explicit empty deployment body, and send
  only the selected release for rollback. Decode bounded correlated events
  incrementally, close a cancelled response, and never retry or replay a
  deployment stream. The Gateway owns deployment validation, execution,
  cancellation, and recovery policy.
- Treat configured deployment commands and decoded application output as
  sensitive transport values. Keep them out of generic debug and serialization
  representations while preserving their intended request or event value.
- Keep AppInstance environment transport limited to an ID-or-domain selector,
  optional import replacement, one key and string value for update, an empty
  synchronization body, and the bounded value-free operation result. The
  Gateway owns lookup, validation, references, storage, and synchronization.
- Keep AppInstance dependency update transport limited to a numeric instance ID
  and an explicit empty JSON object. Preserve typed step statuses, possible
  mutation flags, nullable inventory, and request correlation. The Gateway owns
  target authorization, preflight, package execution, and inventory refresh.
- Keep Route transport limited to App, domain, publication intent, exclusive
  Node-or-Cluster scope, a single AppInstance target, an ordered production
  target set with explicit AppInstance and Route identities plus removal
  authorization, or a custom proxy Node, optional Process, and loopback
  upstream with omitted nulls. The Gateway owns domain, kind, scope, basis,
  relationship, pool policy, and lifecycle policy.
- Preserve explicitly supplied process fields for every runtime. The Gateway
  owns cross-field policy.
- Keep App runtime definition transport limited to a numeric App ID, a
  definition name for item operations, and the caller's exact JSON document for
  create and full update. The Gateway owns definition validation and
  persistence. Collection responses omit commands.
- Keep Schedule transport limited to typed Node and AppInstance targets and the
  eight shipped operations. Preserve caller-supplied optional values, bounded
  and redacted command and log responses, the Gateway's JSON envelopes for
  seven operations, and completion's validated response-header request ID with
  no response body. The Gateway owns target resolution, validation, execution,
  and lifecycle policy.
- Keep Herdr transport limited to a numeric Node ID, a numeric session ID for
  item operations, explicit session name and Unix user on add or adopt, optional
  observer publication and restart handoff flags, optional removal termination
  acceptance, and pane, terminal, columns, rows, and an HTTPS browser origin
  for observation grants.
  Preserve omitted optional flags as explicit `false` and the returned managed or external lifecycle mode. Observation grant URLs
  stay out of generic diagnostics. The Gateway owns session lifecycle,
  publication, trust, and grant policy.
- Keep Database connection transport limited to slug identity, driver, optional
  Node ID, host, port, database name, sqlite path, username, and password.
  Preserve omitted optional fields as absence. Item and collection responses
  omit the password and expose `has_password`. Attach and detach send an
  AppInstance ID-or-domain selector, the connection slug, and an optional
  prefix. User create sends a numeric Process ID, slug, database, username, and
  password. Attachment responses omit environment values and passwords. Query
  sends the registered-connection slug, SQL, and an optional write flag.
  Tables, schema, and describe are bodyless reads against that slug. Inspection
  responses omit passwords and redact credential-shaped values. The Gateway
  owns validation, encryption, persistence, Process execution, stored-environment writes, and
  inspection execution.
- Keep proxycli transport limited to Node ID, Redis connection slug, CLIProxyAPI
  URL, and management key on enable; a provider slug on show; and an account
  identity plus disabled flag on update. Status, disable, and provider list are
  bodyless. Item and collection responses omit tokens and the management key.
  The Gateway owns Valkey placement, collection, publication, and pooling.
- Keep Tasks transport limited to numeric task group and subtask IDs, the
  optional Project ID and status list filters, the group title, brief, status,
  Coder notification flag, and ordered subtask titles and briefs on create,
  partial title, brief, status, and position updates, and the comment type,
  body, author, and optional agent thread ID. Toggle, status, cancel, complete,
  and destroy requests are bodyless. The Gateway owns the lifecycle, scheduling,
  and every status rule. The agent conversation stream stays outside the SDK.
- Accept only the current Doctor family tokens: node, role, app, instance,
  schedule, tool, process, firewall, herdr, database_connection, and route.
  Keep Doctor verify-only and policy-free.
- Model binary node access add/remove and node-show access lists. Do not model
  granular permissions, presets, wildcards, permission editing, or legacy
  grant/revoke compatibility.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, Compose, image-building, generic stream, unregistered database query,
  proxy, legacy Instance, or Workspace surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
- Preserve manager, package, nullable constraint, outcomes, structured errors,
  and request IDs without applying policy. The SDK transports typed values; the
  Gateway owns validation and execution policy.
