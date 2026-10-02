# Public contract

The SDK models exactly 185 concrete public Gateway API operations:

- Gateway: status and root trust.
- Activity: list and show.
- Node: list, show, add, rename, settings update, remove, access add, access remove, role list, role add, role relocate, role remove, and metrics.
- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.
- Project: list, show, create, update, and remove; development deploy step list, create, update, and remove. Development steps carry a name, command, timeout, and required boolean. Omit null optional fields and preserve explicit false.
- Development node exclusion: add, list, and remove from either the Project or the Node.
- Project runtime definition: process and Schedule list, create, show, update, and destroy.
- Instance: list, show, create, register, clone, transfer, remove, update, deploy-step create, list, update, and destroy, deploy, rollback, retained-release list, deployment-history list and show, environment import, environment update, environment synchronization, dependency inventory read, dependency scan, dependency update, and full-domain and directory instance resolution through the concise Instance routes.
- Route: list, show, create, update, target set, target clear, and remove.
- Process: list, add, start, stop, restart, logs, and remove.
- Logs: Instance log read, and live log stream create, renew, and destroy for an Instance or a Process.
- Schedule: list, add, show, run, logs, complete, remove, and activate.
- Firewall: list, allow, deny, and remove.
- Tool: manager list, inventory scan, tool list, show, install, adopt, update, and remove.
- Doctor: run the complete typed Gateway report.
- Database connection: list, show, add, update, remove, attach, detach, query, tables, schema, describe, user create, and user list.
- Metrics: enable, disable, status, credentials, credential reset, exporter enable, and exporter disable.
- Analytics: pin the Plausible version, and show, set, and unset the Stats API key. The key is never returned.
- GitHub App: install, show, and destroy.
- proxycli: enable, disable, status, provider list, provider show, account update, and model list.
- Tasks: enable, disable, status, group list, show, create, update, cancel, and complete, subtask create, update, destroy, and cancel, comment create and list, question list, agent thread list, and task definition list, show, create, update, and destroy.

The four abstract request bases are implementation details, not extra Gateway
operations. Keep the public API typed and small.

- Use numeric resource IDs in routes where the Gateway contract does. Keep a
  firewall rule name as the delete route key. Do not substitute display names
  for identifiers.
- Send `host_key_fingerprint` in a node add request. Parse
  `ssh_host_fingerprint` from a node response.
- Keep Instance lifecycle transport limited to Project, Node, name, optional
  root, optional Route domain, optional creation branch, explicit
  source-profile recovery, and explicit force intent.
  The Gateway owns placement, source, and Route policy.
- Keep candidate clone transport limited to the numeric candidate Instance
  ID, destination Node ID, target name, preview name, optional branch, and
  optional SQLite source path. Preserve omission separately from every supplied
  string. The Gateway owns candidate eligibility, placement, cloning, and Route
  policy.
- Keep Instance transfer transport limited to the numeric Instance ID,
  destination Node ID, optional rename, and optional SQLite source path.
  Preserve omission separately from every supplied string. The Gateway owns
  eligibility, destination reservation, downtime, Route cutover, and cleanup.
- Keep Instance deployment transport limited to named deploy-step create,
  list, update, and destroy, Instance branch update, explicit deploy and
  rollback streams, and retained-release inspection. Preserve optional
  step-timeout omission, require an explicit empty deployment body, and send
  only the selected release for rollback. Decode bounded correlated events
  incrementally, close a cancelled response, and never retry or replay a
  deployment stream. The Gateway owns deployment validation, execution,
  cancellation, and recovery policy.
- Treat configured deployment commands and decoded application output as
  sensitive transport values. Keep them out of generic debug and serialization
  representations while preserving their intended request or event value.
- Keep Instance environment transport limited to an ID-or-domain selector,
  optional import replacement, one key and string value for update, an empty
  synchronization body, and the bounded value-free operation result. The
  Gateway owns lookup, validation, references, storage, and synchronization.
- Keep Instance dependency update transport limited to a numeric instance ID
  and an explicit empty JSON object. Preserve typed step statuses, possible
  mutation flags, nullable inventory, and request correlation. The Gateway owns
  target authorization, preflight, package execution, and inventory refresh.
- Keep Route transport limited to Project, domain, publication intent, exclusive
  Node-or-Cluster scope, a single Instance target, an ordered production
  target set with explicit Instance and Route identities plus removal
  authorization, or a custom proxy Node, optional Process, and loopback
  upstream with omitted nulls. The Gateway owns domain, kind, scope, basis,
  relationship, pool policy, and lifecycle policy.
- Keep log transport limited to a numeric Instance or Process ID and a line
  count for a one-shot read, the caller's Reverb socket ID and a line count to
  open a live log stream, and the stream ID to renew or close it. Keep the
  stream's subscription signature out of arrays, debug output, and
  serialization. The Gateway owns access, stream limits, and the relay; the
  caller owns renewal, the realtime subscription, and the fallback to one-shot
  reads.
- Preserve explicitly supplied process fields for every runtime. The Gateway
  owns cross-field policy.
- Keep Project runtime definition transport limited to a numeric Project ID, a
  definition name for item operations, and the caller's exact JSON document for
  create and full update. The Gateway owns definition validation and
  persistence. Collection responses omit commands.
- Keep Schedule transport limited to typed Node and Instance targets and the
  eight shipped operations. Preserve caller-supplied optional values, bounded
  and redacted command and log responses, the Gateway's JSON envelopes for
  seven operations, and completion's validated response-header request ID with
  no response body. The Gateway owns target resolution, validation, execution,
  and lifecycle policy.
- Keep Database connection transport limited to slug identity, driver, optional
  Node ID, host, port, database name, sqlite path, username, and password.
  Preserve omitted optional fields as absence. Item and collection responses
  omit the password and expose `has_password`. Attach and detach send an
  Instance ID-or-domain selector, the connection slug, and an optional
  prefix. User create sends a numeric Process ID, slug, database, username, and
  password. Attachment responses omit environment values and passwords. Query
  sends the registered-connection slug, SQL, and an optional write flag.
  Tables, schema, and describe are bodyless reads against that slug. Inspection
  responses omit passwords and redact credential-shaped values. The Gateway
  owns validation, encryption, persistence, Process execution, stored-environment writes, and
  inspection execution.
- Keep proxycli transport limited to Node ID, Redis connection slug, CLIProxyAPI
  URL, and management key on enable; a provider slug on show; and an account
  identity plus disabled flag on update. Status, disable, provider list, and model list are
  bodyless. Item and collection responses omit tokens and the management key.
  The Gateway owns Valkey placement, collection, publication, and pooling.
- Keep Tasks transport limited to numeric task group and subtask IDs, the
  optional Project ID and status list filters, the question list filters
  (Project ID, cause, status, and since), the group title, brief, status,
  Coder notification flag, and ordered subtask titles and briefs on create,
  partial title, brief, status, and position updates, and the comment type,
  body, author, and optional agent thread ID. Toggle, status, cancel, complete,
  destroy, and question list requests are bodyless. Task and subtask responses
  keep `assistance_kind`, `assistance_question`, `questions`, and `escalations`.
  The Gateway owns the lifecycle, scheduling, and every status rule.
  Task definition requests use a numeric Project ID, an optional project_id list filter, a definition name for show, update, and destroy, and the caller's exact JSON document for create and full update. List, show, and destroy are bodyless. The Gateway owns definition validation. The agent conversation stream stays outside the SDK.
- Accept only the current Doctor family tokens: node, role, app, instance,
  schedule, tool, process, firewall, database_connection, and route.
  Keep Doctor verify-only and policy-free.
- Model binary node access add/remove and node-show access lists. Do not model
  granular permissions, presets, wildcards, permission editing, or legacy
  grant/revoke compatibility.
- Do not restore the retired Agent, generic executor, direct SSH execution,
  Docker Swarm, Compose, image-building, generic stream, unregistered database query,
  proxy, legacy Instance, or Workspace surfaces.
- Coordinate contract changes with Gateway and CLI owners. Do not implement
  Gateway policy or CLI presentation in this repository.
- Keep Tool inventory scan transport limited to a numeric Node ID. Preserve
  scan state, package kind, version, dependency, registration, adoption block,
  and inspection time. Do not adopt a package, infer ownership, or apply manager
  policy.
- Keep Tool adoption transport limited to a numeric Node ID, manager, package,
  and optional version constraint. Preserve an explicit empty constraint and
  omit only a null constraint. Do not decide ownership, scope, package support,
  or manager policy.
- Preserve manager, package, nullable constraint, outcomes, structured errors,
  and request IDs without applying policy. The SDK transports typed values; the
  Gateway owns validation and execution policy.
