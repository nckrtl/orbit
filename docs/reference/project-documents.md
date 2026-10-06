---
title: "Project Documents"
description: "Native Project folders, versioned files, editing, private S3 storage, and the shared API, CLI, SDK, MCP, and web contract."
covers:
  - apps/gateway/app/{Actions,Jobs}/ProjectDocuments/**
  - apps/gateway/app/{Data/ProjectDocuments/**,Infrastructure/{ProjectDocuments/**,Gateway/{GatewayCleanupStartupRenderer,NativeGatewayFpmConverger}.php},Providers/DocumentsServiceProvider.php}
  - apps/gateway/app/Http/Requests/ProjectDocuments/**
  - apps/gateway/artisan
  - apps/gateway/app/Models/ProjectDocument*.php
  - apps/gateway/app/Console/Commands/*Document{Probe,Cleanup}*.php
  - apps/gateway/database/migrations/*_create_project_document*_table*.php
  - apps/web/src/{api/documents.ts,pages/ProjectDocuments.tsx}
---

# Project Documents

Project Documents are folders and versioned files owned by one [Project](/reference/projects). They hold notes, specifications, and attachments outside Git. They belong to the Project, not an Instance, branch, task, checkout, or Node. Orbit owns their metadata, history, authorization, and client operations; External document services are not backends. The [storage and editing rationale](/reference/project-documents#why-it-works-this-way) explains this boundary.

Every supported action is available to agents. The web app is another client of the same API, not a separate source of truth. Documents do not replace maintained repository documentation or automatically enter an agent's context. An agent explicitly lists, searches, and reads them. This feature has no collaboration cursor, rich-text editor, public sharing, external URL import, OCR, embeddings, or document-to-task automation.

## Ownership and fields

Each Project has an implicit root folder, represented by `parent_id: null`. A stored entry has `id`, `project_id`, `kind` (`folder` or `file`), `parent_id`, `name`, `revision`, `archived_at`, `created_at`, and `updated_at`. IDs are positive database integers. Timestamps are UTC ISO 8601 strings; absent timestamps are null. IDs remain stable across rename, move, archive, and restore. `revision` starts at 1 and increases on each successful entry mutation. Responses also include `path`, the computed slash-separated display path from the root; paths are never identifiers or S3 keys.

Every entry response includes `is_archived`, its effective archive state.

A file also exposes `current_version` with `id`, `number`, `media_type`, `size_bytes`, `sha256`, `created_at`, and `created_by_node_id`. A folder has `current_version: null`. The number starts at 1 and increases by one per committed content change. A version is immutable, belongs to exactly one file, and records the exact bytes stored. The SHA-256 value is lowercase hexadecimal. Metadata and version responses contain no body, storage key, credentials, or provider URL. Removing an author Node does not remove its versions; its author field becomes null.

Names are UTF-8, NFC-normalized, 1–255 bytes after normalization, with no leading or trailing whitespace, slash, backslash, control character, `.` or `..`. They are case-sensitive and unique among all siblings, including archived entries, regardless of kind. Empty folders are valid. Parents must be folders in the same Project. Moving an entry into itself or a descendant is refused. A tree is at most 32 stored entries deep; moving a subtree checks its deepest descendant. Root cannot be renamed, archived, moved, or removed. There are no symlinks or filesystem paths to traverse.

## Folders, archive, and removal

Create a folder with a name and optional `parent_id` (default null). Rename or move with a metadata update; omitted fields remain unchanged, and explicit null moves to root. Updating a folder does not create file versions or change descendant revisions. A failed mutation changes nothing. Sibling uniqueness is enforced atomically, including concurrent creates and moves.

### Archive

Archive sets `archived_at` without deleting bytes or versions. Restore clears it. An entry is effectively archived if it or any ancestor is archived. Archiving or restoring a folder changes only that folder's stored archive flag and revision. Restoring the folder preserves the independent archive flags of its children. Normal lists and searches exclude effectively archived entries. `state=archived` selects effectively archived entries; `state=all` includes both states. Direct show, version history, read, and download allow archived entries.

An archived entry or an entry under an archived parent cannot be edited, moved, or used as a create destination. Restore is allowed only when the parent is active; repeated archive or restore with the current revision is a no-op and does not increment it.

### Permanent removal

Removal is permanent and distinct from archive. Removing a file hides its metadata and all versions immediately and schedules deletion of every body. Removing an empty folder succeeds.

Removing a nonempty folder requires `recursive=true`; its complete subtree, including archived children, is removed together. Recursive removal compares the target folder revision and serializes against every subtree mutation so that a concurrent upload or move cannot escape the deletion. The caller must inspect the subtree before confirming; the folder revision is not a snapshot of all descendant content. There is no undelete or per-version deletion API. Restoring old file content creates a new version instead of rewriting history.

Project removal schedules the same cleanup for all its Documents in the Project removal transaction. It must not leave accessible orphan Documents or make S3 availability a prerequisite for removing a Project.

An ordinary Instance removal has no effect on Documents.

## Content and versions

A file is created with its first body. There are no bodyless files or published pending versions. Content writes accept exactly one of `content_text` (a JSON UTF-8 string) or `content_base64` (strict canonical RFC 4648 base64). The server computes the digest and decoded size, validates limits, and stores the bytes without newline conversion. Uploads use the same write operation as edits. Binary files are attachments, not editable text.

### Limits

The decoded body limit is 10 MiB (10,485,760 bytes), including text, for every client. Empty content is allowed. The HTTP JSON request limit is 15 MiB (15,728,640 bytes); oversized requests use the same JSON error contract as other API failures, not an HTML proxy response. Document routes use this limit instead of PHP's smaller form-POST limit, including numeric IDs with leading zeroes.

MCP admits envelopes up to the same 15 MiB limit, checked after active-peer authorization and before JSON-RPC parsing; each dispatched document request still enforces its own limit after Node access authorization. Document and MCP routes skip automatic trimming and empty-string conversion so authorization precedes JSON parsing and exact body bytes stay unchanged.

Inline text editing and reading are limited to 1 MiB (1,048,576 bytes), valid UTF-8 without NUL, and the media types `text/plain`, `text/markdown`, `application/json`, `application/yaml`, and `text/csv`. JSON and YAML bodies are not parsed or reformatted. Larger text can be uploaded and downloaded, but not read or edited inline. A supplied media type is a lowercase type/subtype without parameters, at most 127 bytes; it defaults to `application/octet-stream` for base64 and `text/plain` for text. The `content_text` field requires an admitted editable media type and the 1 MiB inline limit; `content_base64` uploads can carry any valid media type up to 10 MiB. Changing a file's media type is a content write and creates a version.

### Version history and search

Each successful content write creates one immutable version and advances the entry revision and current version together. A write of identical bytes and media type is a no-op with no new version or revision. A restore-version operation copies the selected version's exact bytes and media type into a new version, with the same no-op rule if it already matches current content. Rename, move, and archive do not create versions. History is ordered by version number descending. Downloads specify a version or default to the current one captured at request start; they never switch versions mid-request.

Search matches entry names and computed paths only, using a case-insensitive literal substring, not SQL wildcards. It does not index file bodies or S3 objects. `q` is a trimmed UTF-8 string of 1–200 characters. Search is scoped to one Project and supports the same archive and kind filters as list. This boundary keeps private body content out of database search indexes and avoids promising full-text extraction for attachments.

## Optimistic concurrency and retries

Every mutation of an existing entry requires `expected_revision`, including metadata update, content write, restore-version, archive, restore, and removal. The Gateway atomically compares it with the stored revision under the same lock as the mutation. A stale revision returns HTTP 409 `project_documents.revision_conflict`, with `entry_id` and `current_revision` in error details, and commits no change.

Missing or invalid revisions return validation errors. Versions cannot be edited directly. Clients never force overwrite or automatically retry a conflict with a fresh revision.

On conflict, read current metadata and content, preserve the caller's draft, then merge or deliberately submit a replacement with the newly read revision. The web app offers reload or copy of the unsaved draft; it does not silently discard it. A lost success response followed by a retry with the old revision is a conflict, not another version.

After an ambiguous create, look up the parent and exact name and compare digest and media type; create is not an upsert and a duplicate name is always a conflict. After an ambiguous removal, a 404 on show confirms that the entry is absent. No operation promises exactly-once replay or an idempotency-key store.

## Private S3 boundary

The Gateway database owns entry hierarchy, names, revisions, version records, archive flags, storage configuration, and durable cleanup work. File bodies live only in a dedicated private UpCloud S3-compatible bucket for this Gateway. They are not stored in database text columns, Git, Node checkouts, or public web assets. Temporary Gateway files used for streaming are private and removed on success and failure. The bucket is not shared with application backups or unrelated resources.

Configure an existing dedicated private bucket, for example `project-documents-example` at `https://s3.example.test`. These are placeholders, not a live destination. There is one configuration per Gateway, not a bucket or credential per Project. Orbit does not provision buckets, change ACLs, or create credentials.

The Gateway uses HTTPS, Signature Version 4, and path-style bucket addressing. The configuration field `region` is the S3 signing-region input to the client. Use the signing region from the provider's S3 connection details, not its service region or zone.

The credential must allow GetObject, PutObject, DeleteObject, and listing only this dedicated bucket. Object keys include generated Project/entry IDs and a fresh random token per upload intent, never user names or input paths. Restoring an older database must not reuse an existing object's key even if numeric IDs repeat. Bodies are immutable objects; renaming an entry does not move them. Provider bucket versioning is not the Orbit version history.

Configuration accepts `endpoint`, `region`, `bucket`, `access_key_id`, and `secret_access_key`. Endpoint, region, and bucket are required on the initial update and may be omitted on later updates. The endpoint is an HTTPS origin without userinfo, query, fragment, or path; region is a nonempty S3 signing-region string of at most 63 ASCII characters; bucket is a valid S3 bucket name of 3–63 characters. Credential fields are nonempty strings of at most 1,024 bytes. The Gateway encrypts both credential fields with its application encryption key before database storage.

Credentials are write-only: no API, SDK, CLI, MCP result, web view, Activity payload, exception, or log returns either value. Configuration responses expose only `configured`, `endpoint`, `region`, `bucket`, `credentials_configured`, `updated_at`, and the cleanup status fields below. Before configuration, `configured` and `credentials_configured` are false and endpoint, region, bucket, and updated time are null. Supplying credentials requires both fields; omitting both preserves them.

Configuration validates a read/write/delete probe under the reserved `orbit-document-probes/` prefix with a random key before saving. The probe writes 32 private bytes, reads at most 33 bytes to detect a mismatch, and deletes only its own key.

The Gateway uses cURL for each provider request, allowing at most two seconds to connect and five seconds to complete a request, with no retries or HTTP redirects. Probe responses use a size-limited memory sink, not PHP's streaming HTTP transport: 33 bytes for GET and 64 KiB for PUT and DELETE responses, including reconciliation. The read loop handles short reads, rejects trailing bytes and stalled reads, and shares the GET request's five-second deadline.

A provider operation succeeds only with a 2xx HTTP response. The cURL handler retains Guzzle's HTTP-error middleware; redirects are failures. The Gateway also rejects all other responses outside the 2xx range. A denied or failed DELETE prevents configuration approval and records a reconciliation failure with backoff, even when its response body is small.

Invalid credentials, an unavailable endpoint, a missing bucket, and a failed probe return `project_documents.storage_unavailable`, without provider diagnostics. Probe failures leave the previous configuration intact and trigger cleanup of any probe object created.

#### Recover reserved probes

Before the first PUT, the Gateway commits each fresh probe ID, its exact candidate endpoint, region and bucket, and encrypted candidate credentials to a private journal at `$ORBIT_HOME/project-document-probes/journal.sqlite`. This is a separate SQLite database with full synchronous commits, not a nested transaction on the configuration connection. A journal failure prevents the PUT. Configuration rollback leaves this journal intact. Journal credentials remain write-only in debug output, errors, logs, Activity and responses.

Cleanup records are permanent, including after a successful DELETE or an absent-key observation. Request death or a late PUT cannot erase the record. Immediate cleanup and later reconciliation delete only the exact generated `orbit-document-probes/{UUID}` key named by a tracked record. They never list provider objects, accept arbitrary keys, retarget an old record to a new configuration, or use document-body deletion authorization.

The scheduler attempts `project-documents:probes:reconcile` once per minute. Operators can also run this local command to attempt one batch without waiting for the scheduler. Each run claims at most 20 due records and makes one bounded DELETE per record. Records are ordered by their next attempt time and ID for fair progress. Successful attempts become due after one minute; failures back off exponentially from one minute to at most one hour. Claims expire after ten minutes if the process dies. Records and retry state survive restart; neither success nor missing bytes retires a record.

The cadence is an attempt schedule, not a wall-clock lifetime promise. Eventual deletion requires a running scheduler, a reachable provider, and usable authorized credentials. Revoked credentials or a lost encryption key require operator repair and never justify dropping a record.

The local `project-documents:probes:repair RECORD --access-key-id-file=PATH --secret-access-key-file=PATH` command replaces a tracked record's encrypted credentials from paired mode-0600 UTF-8 files, stripping one optional final newline; it preserves its ID and destination. Inspect only the journal's nonsecret ID, destination, attempt times and error code locally to select a repair record; never print its credential columns. Back up the journal and the Gateway encryption key, and restore both when recovering the Gateway.

API cleanup counts report only work on document bodies. The guard against changing the destination also checks only state for document bodies, not these retained probe records. Probe records keep their own destination and credentials when the configured destination changes.

Reserved probes contain only synthetic random bytes. They are separate from document bodies and their restore-time cleanup gate: probe reconciliation remains authorized to delete its tracked generated keys while document-body cleanup is paused. Restoring the journal must preserve all retained records, including keys currently absent from the bucket. No bucket lifecycle policy, bucket provisioning, or live resource change is required.

Secrets and bodies are redacted before Activity recording; Activity can record entry IDs, version IDs, size, digest, and operation outcome.

While live versions, active upload intents, retained abandoned-upload fences, or pending cleanup exist, endpoint, region, and bucket cannot change; return `project_documents.storage_in_use`. This guard is atomic with upload-intent creation. An absent fenced object still reserves the destination even when `pending_cleanup_count` is zero. Because abandoned fences are permanent, any retained fence prevents destination changes permanently; storage migration and fence retirement are out of scope.

Credentials can rotate after the probe succeeds. Supplying both replacement credentials does not decrypt the previous pair, including when the old Gateway encryption key has been lost; a failed probe preserves the previous ciphertext unchanged. There is no disable or delete-configuration operation. Metadata listing, search, show, and archive remain available if S3 is unavailable. Body operations require configuration and reachable storage; permanent removal can enqueue cleanup during an outage.

### Publish and recover

A body write records a durable upload intent before contacting S3. The intent owns a fresh, never-reused object key and has `active`, `published`, or `abandoned` state. Upload bytes first; then publish the version, current-version pointer, entry revision, and intent's `published` state in one database transaction. This transaction locks the intent and entry, requires an `active` intent, and rechecks revision, parent, and archive state. Readers see only committed versions.

Do not hold a database transaction open across a provider request. Body operations start outside any database transaction. The Gateway rejects an enclosing transaction before provider access so an outer rollback cannot erase an upload's recovery record. Bound each provider request to a 30-second timeout; do not automatically retry a content mutation inside the HTTP request.

Publication and abandonment compete for the same intent lock. Cleanup atomically changes an unreferenced `active` intent older than one hour to `abandoned` and records its deletion tombstone before any DeleteObject call. If publication commits first, cleanup sees `published` and cannot claim that key. If abandonment commits first, publication is permanently refused with HTTP 409 `project_documents.upload_abandoned`; the caller must retry with a new intent and key. No check-then-delete sequence outside this transaction substitutes for the claim.

A writer paused beyond the cutoff must recheck intent state before PUT and again in the publication transaction after PUT. It cannot revive an abandoned intent. A late PUT may finish after cleanup deleted or found no object; therefore abandoned intent fences and their keys are retained permanently, and reconciliation rechecks those keys even after a successful deletion. A late writer schedules deletion, never publication. A crash after late PUT but before that scheduling is covered by the retained fence.

An absent abandoned object is not counted as pending cleanup, but its fence is not discarded and still blocks destination changes. Reconciliation continues to use the unchanged destination and its current credentials, including after credential rotation.

An abandoned upload whose object has been deleted and whose pending count is zero still reserves its destination: changing endpoint, region, or bucket returns `project_documents.storage_in_use`. A delayed PUT to that original destination cannot publish and is deleted by the worker's retained-fence reconciliation.

A crash before PUT leaves an active intent that expires; a crash after PUT but before publication leaves an uncommitted object that expires. A crash after publication preserves the committed version; a crash after abandonment but before deletion resumes deletion from the tombstone. A failed upload or failed publication, including a late revision conflict, abandons the intent atomically and schedules the same cleanup. Reconciliation never resumes uploads or publishes intent bytes on a writer's behalf.

#### Transfer published keys on removal

File, recursive subtree, and Project removal record each version's exact key in a durable removal tombstone before removing versions and entries in the same metadata transaction. Removal uses the shared publication/abandonment lock and leaves matching `published` upload rows for the cleanup worker's transactional handoff. The tombstone alone does not authorize deletion while a published row remains.

Do not turn a published upload into an abandoned upload or discard an abandoned intent or fence.

The transaction commits the metadata removal and tombstones together. A rollback preserves the entries, versions, and published rows and rolls back newly created tombstones. Process death before commit cannot leave a partial removal; death after commit leaves durable tombstones and published rows for the worker. No provider access occurs in this transaction, and no queued payload substitutes for committed work. Removing a Project uses its enclosing removal transaction. Active uploads become permanently abandoned with retained fences in that same transaction.

Published rows retained by removal remain recoverable, including those from earlier Gateway versions. A `published` row with an exact-key removal tombstone and no committed version or active intent referencing that key is a pending handoff, not deletion authority. Before DELETE, the worker holds the execution lock with a valid permit and completes that handoff in a database transaction under the publication/removal locks: reload the row and tombstone, recheck references, and delete only the matching published row. It preserves the tombstone and any retained fence. Then commit and recheck the full exact-key authorization before contacting the provider outside the transaction.

A rollback or crash before that handoff transaction commits leaves both records for retry and sends no DELETE. A crash after commit leaves the tombstone for retry. Neither an absent bucket key, a missing Project/entry identity, nor a bucket listing justifies retiring a published row. A published row without a committed version or matching tombstone is an unresolved consistency difference. Conflicting committed or active references refuse handoff and deletion. The deletion worker performs this handoff before applying its normal exact-key authorization.

The scheduler runs `project-documents:cleanup:work` at least every five minutes. This deletion command is separate from the operator's read-only `project-documents:cleanup:reconcile`. While the cleanup gate below is running, the worker retries pending deletions with bounded backoff. It never deletes a committed version because it is old or because a bucket listing briefly omitted it. Removal tombstones retain the exact keys until deletion succeeds; DeleteObject of an absent key counts as success. Abandoned-upload fences remain after success to prevent late PUT leaks. Unknown bucket objects without an intent or tombstone are reported for operator reconciliation, not automatically deleted.

Each worker run claims at most 100 due records, prioritizing pending deletions over idle retained-fence rechecks, then ordering each group by next attempt time and ID. Idle fences cannot displace pending tombstones from a batch. Claims expire after ten minutes for process death. Each claim persists a random ownership token and expiry on the cleanup record. A worker reloads that token before deletion and records results only for its own claim. Process death after DELETE but before recording success leaves the record recoverable; retrying the exact key is safe. Pause leaves outstanding claims to expire without consuming attempts.

The worker expires active intents in batches of at most 100 using the publication/abandonment lock above. Each claimed key receives at most one DELETE per run, outside a database transaction, with a two-second connect timeout, a 30-second request timeout, no redirects, and no provider retries.

Only a 2xx DELETE counts as success, including an absent key; a provider failure or redirect leaves durable work pending. Failures back off exponentially from five minutes to at most one hour. Retained fences become due again after five minutes even when absent and not pending.

A paused run performs no provider deletion and does not consume attempts or retire work. Scheduling bounds work, not deletion time; eventual cleanup needs a running scheduler, reachable storage, and usable credentials.

Before each DELETE, the worker holds the cleanup-execution lock, checks the current-generation permit, and reloads the durable authorization for that exact key at the configured destination. Authorization requires either a removal tombstone or an abandoned intent with its retained fence, and no committed version or active/published intent referencing the key. A queued payload, report, prefix, or bucket listing is never deletion authority. Inconsistent references refuse deletion and surface a sanitized cleanup error.

All scheduled, queued, manual, and late-writer cleanup uses this same path; request handlers only enqueue durable work. A successful removal deletion may retire its tombstone; a retained fence and abandoned intent never retire. Probe reconciliation uses only its separate journal and cannot authorize document-body keys.

There is no automatic purge of archived files or old versions. Cleanup failures remain visible in storage status as `pending_cleanup_count`, `oldest_pending_cleanup_at`, and `last_cleanup_error_code`, without keys or provider secrets. The same job handles Project-removal cleanup.

If a committed body is missing or fails its stored digest check, return `project_documents.body_unavailable` and preserve the version metadata. Never replace it with an empty body or silently fall back to another version. Operators restore the exact object from a bucket backup or restore another available version explicitly. Database backups, bucket backups, and the Gateway encryption key are all required for disaster recovery. Restoring only the database cannot recover bytes, and losing the encryption key requires supplying fresh credentials.

### Restore-time cleanup gate

Cleanup is fail-closed. A local permit under `/run/orbit/project-documents/`, outside the database and backups, controls every scheduled or queued document-body deletion, including removal and abandoned-intent cleanup. A synchronous configuration probe may delete only its own fresh random probe key; it cannot delete document keys. Missing or invalid permit means paused. Gateway service startup clears the permit before API, scheduler, or queue workers can run. The permit is bound to that service-start generation; queued jobs check it immediately before each deletion. Restoring a database must use the procedure below, not copy a snapshot underneath running workers.

Run the local commands as the Gateway service account, from the Gateway install directory, using `php artisan COMMAND`. They are operator recovery commands, not public API/MCP operations. They never prompt or print keys, bodies, credentials, provider diagnostics, or recovery-backup paths. Each emits one JSON object on stdout; sanitized diagnostics go to stderr. Success exits 0, invalid arguments exit 2, and state, inventory, provider, or authorization failures exit 1. Failure includes `error_code` and never reports running without a valid permit.

| Command | Success output beyond the shared status fields | Boundary |
| --- | --- | --- |
| `project-documents:cleanup:pause` | None. | Remove the permit, invalidate the report, and rotate the generation under the execution lock. |
| `project-documents:cleanup:status` | None. | Inspect local state and database counts only; no provider access. |
| `project-documents:cleanup:reconcile` | `report_id`, `report_path`, `report_state` (`complete` or `incomplete`), `difference_count`. | Require paused state; inventory without mutation or deletion. A complete scan with differences succeeds but cannot authorize resume. |
| `project-documents:cleanup:resume --report=ID` | None. | Require an unchanged, complete, difference-free report from the current generation; atomically grant its permit. |
| `project-documents:cleanup:work` | `claimed_count`, `deleted_count`, `failed_count`. | Run one bounded durable batch; paused is a successful no-op. |

The shared status fields are `cleanup_state`, `cleanup_generation`, `reconciliation_report_id`, `pending_cleanup_count`, `oldest_pending_cleanup_at`, and `last_cleanup_error_code`. IDs and generations are opaque strings; times are UTC ISO 8601 or null. When no valid generation can be read, status reports paused with null generation and exits 1. A report ID is an identifier, not a path; resume rejects path traversal and arbitrary file input.

Pause removes the permit under an exclusive cleanup-execution lock and waits for in-flight deletions to finish; each deletion holds that lock from its gate check through the provider result. Once pause returns, no deletion can start. Pause is idempotent in effect: every successful call leaves cleanup paused and invalidates prior reports. Commands fail closed if their lock or state files cannot be read or written.

Use one stable lock file; replacing or unlinking that file cannot be used to create independent locks. The generation binds the lock's device and inode. An existing runtime directory with a missing or replaced lock needs repair with services stopped; startup does not recreate that lock. A failed pause is not permission to restore: stop the services and repair local state before proceeding.

Status reports `cleanup_state` (`paused` or `running`), `cleanup_generation`, `reconciliation_report_id` (nullable), and pending-cleanup counts, without keys or credentials. The storage-show API adds these same gate fields. A pause or service restart invalidates any prior reconciliation report. Resume refuses without a completed report from the current generation, with unchanged database and bucket inventory since reconciliation. An invalid report returns a local nonzero exit status and leaves the gate paused.

### Local state and startup ordering

Keep the generation, permit, and stable execution lock under `/run/orbit/project-documents/`, in a mode-0700 directory with mode-0600 files owned by the Gateway service account. Never include them in backups. The permit names the generation and authorized report ID. Missing, malformed, stale, incorrectly owned, symlinked, or permissively readable state is invalid. Every worker reads the current files inside the execution lock rather than caching authorization. A missing runtime directory is paused, not an instruction to recreate an old permit.

Actual Gateway service startup runs the invalidation hook under that same lock before accepting API traffic or starting scheduler and queue consumers. It removes the permit and report association and creates a fresh unpredictable generation atomically. Wire this ordering into the installed service lifecycle for the Gateway PHP-FPM pool and every scheduler/queue entry point, including independently restarted consumers.

The installed PHP-FPM service drop-in runs `project-documents:cleanup:invalidate` as the Gateway account in `ExecStartPre`. Gateway web convergence also runs that hook before activating the pool with a reload, because a reload does not run `ExecStartPre`. Hook failure prevents activation. The hook does not query the database or contact S3.

Artisan invalidates authorization when `schedule:work`, `queue:work`, or `queue:listen` starts. A standalone `schedule:run` also invalidates authorization. Start the Gateway scheduler with `schedule:work`, then reconcile and resume in that startup generation. Laravel launches a fresh `schedule:run` child every minute; these recurring children preserve the generation and permit, rather than treating every tick as a restart.

The scheduler daemon holds an exclusive process-lifetime lock on the private `scheduler.session` file beside the cleanup gate. It passes a random session token to its children in `ORBIT_DOCUMENT_SCHEDULER_SESSION`. A tick preserves authorization only when the local gate is valid, the private session file matches that token, and another process still holds the session lock. This recognizes the running consumer; it never grants deletion authority. Every DELETE still checks the current gate permit and durable exact-key references. A stale token after process death cannot bypass startup invalidation. Session state is outside backups and contains no credentials.

Bare cron or manual `schedule:run` invocations have no live scheduler session and remain fail-closed startup operations; they cannot sustain resumed document cleanup. Use the supervised `schedule:work` daemon for recurring Gateway cleanup. Do not copy or manually set the session token. A daemon restart pauses cleanup and requires a fresh reconcile and resume. Pause still invalidates authorization immediately; ticks in a live session preserve that paused state until the operator resumes.

Do not rely on a first scheduled job, an operator remembering pause, or a boot-only `/run` cleanup. Per-request application boot must not rotate a healthy generation. Hook failure prevents that consumer from starting; a manual consumer without established startup state remains paused. Tests exercise the installed startup wiring and Laravel's recurring `schedule:work` → `schedule:run` path.

### Private reconciliation reports

Store reports at `$ORBIT_HOME/project-document-recovery/reports/{ID}.json`, outside the restored metadata database and outside web-served directories. The report directory is mode 0700 and reports are mode 0600, owned by the Gateway service account. Reject symlinks, wrong ownership, and broader permissions on reads as well as writes. Publish completed reports atomically; a partial file, modified report, unknown schema, or untrusted report cannot authorize resume. Maintain the completed report's integrity binding in the current local generation state. Only the latest completed report in that generation is eligible; pause and startup invalidate eligibility without needing to erase earlier reports.

A report contains `schema_version`, `report_id`, `cleanup_generation`, start/completion times, `report_state`, destination, database and bucket fingerprint algorithms/values, inventory counts, size and digest results for each version, and all missing, corrupt, inconsistent, or unreferenced keys. These detailed keys and object markers stay in private reports, never storage-show, Activity, general logs, or command output. Reports contain no credentials, body bytes, Authorization headers, or provider response bodies. Their nonsecret path and ID may appear in local command output. Treat reports and any operator resolution records as private recovery material, not attachments to shared agent transcripts.

The operator follows this sequence for installation, restart, or restore:

#### 1. Pause before replacement

Run pause and confirm status is paused. Before restoring, stop API writes, scheduler, and queue workers. Wait for in-flight uploads to finish. Preserve database, bucket, encryption key, and cleanup records. Do not restore `/run` permits.

#### 2. Restore with workers stopped

Restore the database and required bucket objects with workers stopped. Start the Gateway; startup keeps cleanup paused, so its first scheduled job cannot delete anything. Keep document mutations disabled through reconciliation.

#### 3. Inventory without deletion

Run reconcile while paused and with document mutations and cleanup-state mutations stopped. It reads a consistent database inventory and all pages of the dedicated bucket listing, including unknown keys and reserved probes. Tracked reserved probes are classified separately and never become document deletion candidates. It inventories committed versions, active/published/abandoned intents, retained fences, and removal tombstones.

Reconcile classifies a published row with its committed version as live publication authority. A published row with an exact-key removal tombstone but no committed or active reference is recorded as a pending handoff to removal cleanup. It is accounted-for work, not an unknown object or a difference requiring operator disposal, but remains ineligible for DELETE until the worker commits the handoff above. Reconcile records both rows without changing them. A published row with neither version nor tombstone, or a tombstone conflicting with committed or active references, is an unresolved consistency difference regardless of whether the object exists.

It verifies every committed body's size and SHA-256 using bounded streaming, without retaining body bytes. Missing or corrupt committed bytes and inconsistent database references are differences. An absent active intent, absent fence, or absent removal key is recorded but is not itself a difference; the absence never retires durable recovery state. A present fenced or removal key is accounted-for cleanup work, not an unknown object. Unknown objects are differences, not deletion candidates. Reconcile never deletes, publishes, or automatically adopts objects.

Each provider call has a two-second connect timeout and 30-second request timeout, no redirects or automatic retries.

Listings request `EncodingType=url` so keys containing XML control characters or carriage returns remain representable. Require the response's `EncodingType` to confirm `url` on every page, then percent-decode each key exactly once without treating plus signs as spaces or normalizing paths. Preserve literal percent sequences and Unicode bytes. Continuation tokens are opaque and are not decoded. Missing or unsupported encoding markers and malformed percent escapes make the scan incomplete.

Pagination must reach the end without repeated or missing continuation progress. A list/read failure, interrupted scan, invalid response, unverifiable committed body, or observed inventory change produces an incomplete report and exit 1, with no eligible report association. A confirmed missing or digest-mismatched body is a completed verification with a difference, not a provider success assumption. Reports with differences remain ineligible even if the scan completed. Repeat inventories at scan completion to detect changes; a listing is not a transactional provider snapshot.

#### 4. Resolve differences

Resolve every difference before resume: restore missing committed bytes; recover newer metadata from a matching database backup; or explicitly preserve unmatched objects in a separate operator-owned recovery backup before removing them explicitly. A newer bucket is not proof that unreferenced bytes are disposable.

Rerun reconcile after each repair. Record the operator resolution of each earlier difference outside the restored database in a JSON file encoded as UTF-8 with mode 0600 and supply it with `project-documents:cleanup:reconcile --resolution-file=PATH`. The file contains the prior report ID and, for each resolved difference, its key, action (`restore_bytes`, `restore_metadata`, or `preserve_then_remove`), and evidence reference. For preserve-then-remove, record the private recovery-backup location and verified size/SHA-256. Reconcile copies these records into its private report; it does not execute them. Reject invalid resolution input with exit 2.

The resolution file has `prior_report_id` and a nonempty `resolutions` array. Each record has `key`, `action`, and a nonempty `evidence_reference`. A `preserve_then_remove` record also requires `recovery_backup` (the private backup location), `size_bytes` (a nonnegative integer), and `sha256` (lowercase SHA-256). Unknown fields, duplicate keys, and keys absent from the prior report's differences are invalid. Keep this file private; the command copies it into the report and does not print its contents.

Resolution records document operator actions, not waivers: the new full scan must still verify every committed body and show zero differences. A supplied claim cannot make missing, corrupt, inconsistent, or unmatched objects eligible for resume. Never edit a completed report to make it clean.

#### 5. Authorize cleanup

Run resume with that report ID. Under the same execution lock, it rechecks the fingerprints and generation, writes the permit atomically, and reports running. Only then enable document mutations and normal scheduled cleanup. If any comparison or provider check fails, it remains paused; reconcile again rather than bypassing the gate.

### Fingerprints and refusal outcomes

Use versioned canonical inventories and lowercase SHA-256 fingerprints. Serialize fixed-order fields as UTF-8 JSON arrays with explicit nulls, decimal integers, UTC timestamps, and no insignificant whitespace; sort database rows by table then numeric ID and bucket rows by exact key bytes. Preserve key bytes without path normalization. Record the serialization schema and algorithm so an unsupported schema refuses resume rather than comparing unlike inventories.

| Inventory | Required fields |
| --- | --- |
| Destination | Exact endpoint, signing region, and bucket; no credential values or ciphertext. Credential rotation still requires a fresh provider check at resume. |
| Entries | ID, Project ID, parent ID, kind, name, sibling scope, revision, current-version ID, archive time, creation/update times. |
| Versions | ID, entry ID, upload ID, version number, media type, size, SHA-256, exact storage key, author Node ID, creation time. |
| Upload intents | ID, Project/entry IDs, exact storage key, state, creation/update times; include live published rows, published rows awaiting handoff, and permanently retained abandoned rows. |
| Tombstones and fences | ID, exact storage key, retained-fence flag, pending flag, attempt count, next attempt time, last error code, creation/update times, and any added claim/retry fields affecting worker eligibility. |
| Bucket objects | Every key, size, last-modified marker, ETag and provider version marker when supplied; encode unavailable markers as null. ETag is an inventory marker, never proof of SHA-256. |

The current serialization schema is `orbit-document-inventory-v1`, with fingerprint algorithm `sha256`. It encodes named fields as ordered `[name, value]` pairs inside JSON arrays. Database tables are ordered by table name, rows by numeric ID, and fields by name; bucket rows are ordered by exact key bytes. Every cleanup column is included, including any added claim or retry fields. Credentials are excluded. Reports also record whether each classified key was present in the bucket.

The report also records each published key's classification and the exact tombstone paired with a pending handoff. Both records remain in the database fingerprint until handoff commits; the deleted published row is absent from subsequent inventories. Published-row retirement before resume changes that fingerprint and requires a fresh reconcile. After resume, the authorized worker may perform the transactional handoff as normal durable cleanup, but it must still pass the unchanged no committed/active/published-reference guard before DELETE. Permanent abandoned rows and fences remain in every inventory even after successful deletion.

The report records streamed body-digest verification separately from the bucket fingerprint. Resume holds the execution lock throughout its checks and permit write, requires paused state, validates report integrity/schema/generation and zero differences, recomputes both complete fingerprints, and verifies committed sizes/digests again using current credentials. It does not send PUT or DELETE as a reachability probe. Inventory equality alone cannot stand in for digest verification. Any mismatch or provider failure refuses resume and leaves the permit absent. Keep writes and external bucket changes stopped until resume completes; no fingerprint protocol makes concurrent out-of-band provider writes transactional.

| Failure | Local `error_code` | Outcome and operator action |
| --- | --- | --- |
| Invalid arguments or resolution input | `project_documents.cleanup_input_invalid` | Exit 2; correct input. No permit is granted. |
| Lock, state, permissions, or atomic-write failure | `project_documents.cleanup_state_unavailable` | Exit 1; treat cleanup as paused, repair local access, and rerun pause/status. Do not replace a database after a failed pause. |
| Reconcile or resume requires paused state | `project_documents.cleanup_not_paused` | Exit 1; run pause before recovery. No new authorization is granted. |
| Unknown, incomplete, changed, invalidated, or tampered report | `project_documents.cleanup_report_invalid` | Exit 1; remain paused and reconcile again. |
| Complete report still has differences | `project_documents.cleanup_unresolved` | Resume exits 1; remain paused, repair, and rerun reconcile. |
| Database or bucket fingerprint/digest changed | `project_documents.cleanup_inventory_changed` | Exit 1; remain paused and reconcile again. |
| Provider cannot complete list/read/delete | `project_documents.storage_unavailable` | Exit 1 for recovery commands; remain paused. Worker failures retain pending work and back off without invalidating otherwise valid running authorization. |
| Key has conflicting durable references | `project_documents.cleanup_reference_conflict` | Refuse DELETE and retain work; pause and investigate metadata consistency. |

Startup or pause invalidation always wins over stale queued work. A worker's gate failure is a no-op for provider access, not a successful deletion. Status and API responses expose only sanitized counts and error codes; they never return report contents. This gate does not promise safety for an unsupported database replacement while services remain running. The supported restore procedure pauses before replacement and the startup hook always resets authorization before any job.

## API contract

All routes are core operations under `/api/v1`, not an optional extension. `project`, `entry`, and `version` path parameters are numeric IDs. Every entry and version lookup is constrained to its enclosing Project and file; a cross-Project or cross-file ID is 404. Authorization runs before body parsing, S3 access, or existence disclosure. Document operations use the existing `ServingNode::ProjectOwning` grant check, including reads; storage configuration and status use `ServingNode::Gateway`. Being the author of a version conveys no extra authority. Browser calls use the web app's existing trusted peer proxy; there are no public or signed download links.

Paths below are relative to `/api/v1/projects/{project}/documents`, except the storage paths. Operation IDs are also MCP tool names. All successes use `data` plus `meta.request_id`; create returns 201, every other success returns 200, including removal (`data: {id, removed: true, cleanup_pending: boolean}`). Errors use the normal `error.code`, `message`, `details`, and request correlation envelope. No success uses 204.

| Method and suffix | Operation ID | Inputs and result |
| --- | --- | --- |
| GET (base) | `project-document-list` | `parent_id` (default root), `state` (default `active`), optional `kind`, `cursor`, `limit`; entry page. |
| GET `/search` | `project-document-search` | Required `q`, `state`, optional `kind`, `cursor`, `limit`; entry page across the tree. |
| POST (base) | `project-document-create` | `kind`, `name`, optional `parent_id`; a file also requires one body field and optional `media_type`; entry. |
| GET `/{entry}` | `project-document-show` | Entry, including effective archive state as `is_archived`. |
| PATCH `/{entry}` | `project-document-update` | `expected_revision`, at least one of `name`, `parent_id`; entry. |
| PUT `/{entry}/content` | `project-document-write` | `expected_revision`, one body field, optional `media_type` (default current type); entry with current version. |
| GET `/{entry}/content` | `project-document-read` | Optional `version`; `{entry_id, revision, version, content_text}` within inline limits. |
| GET `/{entry}/download` | `project-document-download` | Optional `version`; `{entry_id, revision, version, content_base64}`. |
| GET `/{entry}/versions` | `project-document-version-list` | `cursor`, `limit`; version page. |
| POST `/{entry}/restore-version` | `project-document-restore-version` | `expected_revision`, required `version_id`; entry. |
| POST `/{entry}/archive` | `project-document-archive` | `expected_revision`; entry. |
| POST `/{entry}/restore` | `project-document-restore` | `expected_revision`; entry. |
| DELETE `/{entry}` | `project-document-destroy` | JSON `expected_revision`, `recursive` (default false); no mutation query fields; removal receipt. |
| GET `/api/v1/project-document-storage` | `project-document-storage-show` | Redacted configuration and cleanup status. No provider probe on reads. |
| PUT `/api/v1/project-document-storage` | `project-document-storage-update` | Configuration fields described above; redacted configuration and cleanup status. |

List accepts an explicit null or omitted parent for root, never a recursive flag. Browsing a folder shows only direct children; search spans the tree. Pages default to 50 rows, permit 1–100, and return `meta.next_cursor` (null at end). Entries are ordered by kind (folders first), case-sensitive name, then ID; search uses the same order. Version pages use descending version number. Cursors are opaque and tied to filters; invalid cursors fail validation. Pagination is not a snapshot; clients refetch after tree mutations.

Unknown fields, invalid kind/state, a body on folder creation, and creating a file without content fail validation. Content and history operations on a folder return `project_documents.not_file`.

Download is base64 JSON, not a redirect or raw HTTP file, so generated MCP tools and every client share one transport contract. Clients decode locally. The 10 MiB decoded limit applies to a download response as well. Read returns the file revision captured with the selected version, even for an older version; an old version number is not a write precondition. No request uses an ETag or If-Match instead of `expected_revision`.

### Errors and recovery

Use these stable codes to decide whether to correct input, retry, or request storage recovery.

| HTTP | Code | Meaning and caller action |
| --- | --- | --- |
| 403 | `peer.identity_unknown`, `node_access.required` | Existing identity or grant failure; use an authorized peer. |
| 404 | `project_documents.not_found` | Project-scoped entry or file-scoped version is absent or removed; relist. A missing Project uses the existing Project not-found response. |
| 409 | `project_documents.revision_conflict` | Reload and reconcile the draft; never force overwrite. |
| 409 | `project_documents.upload_abandoned` | Cleanup fenced an expired upload; reread metadata and retry with a fresh upload intent and key. |
| 409 | `project_documents.name_conflict` | A sibling already reserves that name; choose another name. |
| 409 | `project_documents.archived` | Entry or destination is effectively archived; restore ancestors first. |
| 409 | `project_documents.folder_not_empty` | Inspect descendants and explicitly request recursive removal. |
| 409 | `project_documents.storage_in_use` | Destination cannot change while live versions, active intents, retained abandoned fences, or pending cleanup exist, even when pending count is zero. |
| 409 | `project_documents.storage_not_configured` | Configure the dedicated bucket before a body operation. |
| 413 | `project_documents.content_too_large` | Decoded or HTTP body limit exceeded; reduce content. |
| 422 | `validation.failed` | Invalid fields, names, base64, depth, parent, cycle, media type, query, or cursor; correct the input. |
| 422 | `project_documents.not_file` | A file-only operation targeted a folder. |
| 422 | `project_documents.not_editable` | Inline read/write cannot represent this content; download/upload instead. |
| 502 | `project_documents.body_unavailable` | A committed object is absent or corrupt; operator recovery is required. |
| 503 | `project_documents.storage_unavailable` | Provider timeout, credentials, probe, or network failure; retry after storage recovers. |

Provider diagnostics are mapped to these stable codes, sanitized, and correlated through request IDs. Do not expose credential values, Authorization headers, provider response bodies, bucket keys, or file content in errors. Validation and concurrency failures are safe to correct; ambiguous network failures require the retry checks above. Storage cleanup does not resurrect removed entries or block metadata reads.

## CLI contract

The family is `orbit project:document:<verb>`. It follows [CLI UX](/reference/cli-ux): JSON is noninteractive, machine failures preserve the API error, human output is a property or data list, and destructive actions require explicit consent. Each command accepts the Project selector as its first argument (ID or slug). Entry and version selectors are numeric IDs, never paths. Interactive Project and entry selection uses finite lists; entry selection includes archived entries for show, history, download, restore, and removal. Cancellation performs no write.

| Verb | Arguments and options beyond Project | Behavior |
| --- | --- | --- |
| `list` | `--parent=ID`, `--state=active\|archived\|all`, `--kind=folder\|file`, `--cursor`, `--limit` | Show direct children; columns ID, KIND, NAME, REVISION, SIZE, ARCHIVED. |
| `search` | Query argument; `--state`, `--kind`, `--cursor`, `--limit` | Show ID, KIND, PATH, REVISION, SIZE, ARCHIVED. |
| `show` | Entry argument | Metadata property list, never body content. |
| `create` | Name argument, required `--kind`, optional `--parent`, file `--from=PATH` or `--content=TEXT`, optional `--media-type` | Create folder or file; `--from=-` reads stdin. |
| `update` | Entry argument, `--name`, `--parent=ID\|root`, `--expected-revision` | Rename or move. |
| `read` | Entry argument, optional `--version` | Exact text on stdout with no added newline; diagnostics on stderr. `--json` returns the API envelope. |
| `upload` | Entry argument, `--from=PATH`, optional `--media-type`, `--expected-revision` | Write local bytes as a new version; `--from=-` reads stdin. |
| `write` | Entry argument, `--content=TEXT` or `--from=PATH`, optional `--media-type`, `--expected-revision` | UTF-8 inline content write; `--from=-` reads stdin. |
| `download` | Entry argument, optional `--version`, required `--output=PATH\|-` | Decode body to file or raw stdout. `--json` instead returns base64 envelope and forbids `--output`. |
| `versions` | Entry argument, `--cursor`, `--limit` | Show NUMBER, ID, TYPE, SIZE, SHA256, CREATED. |
| `restore-version` | Entry argument, required `--version`, `--expected-revision` | Create a version from history; no extra destructive confirmation. |
| `archive`, `restore` | Entry argument, `--expected-revision` | Toggle archive state, not permanent deletion. |
| `remove` | Entry argument, `--expected-revision`, optional `--recursive`, `--yes` | Permanent removal; consent names the Project, entry, and recursive scope. |

All commands support `--json`; API entry and page envelopes are returned unchanged. In noninteractive mode all required inputs, including revision and removal `--yes`, must be supplied before a mutation.

In a terminal missing required inputs are prompted: Project and entry from authorized lists, kind from folder/file, name as validated text, destination from active folders plus root, body source as a local path, version from history. A terminal may read the current revision when omitted and show it before submission, but it never substitutes a newer revision after conflict. Storage credentials use masked prompts.

Invalid prompted names and local file inputs show validation feedback and allow correction without a retry cap; cancellation sends no mutation. Explicit invalid inputs fail without prompting. Input/body flags that are mutually exclusive fail before any write. Missing inputs, invalid local input, and refused confirmation exit 2; API or storage failures exit 1; success exits 0. Human diagnostics go to stderr for raw content commands.

Local filesystem or integrity failures use `project_documents.local_io_failed`, exit 1, and leave any existing destination unchanged. No file bytes or local secret paths appear in the error message.

Download never overwrites an existing file. Use a new path; there is no implicit overwrite or force option. It verifies the decoded size and digest and atomically publishes a private temporary file without replacing an existing destination, including a racing creator. Raw stdout cannot be recalled, so clients validate the entire bounded body before emitting it. CLI refuses binary stdout to a terminal; a pipe or `--output` file is required. JSON mode creates no local file. Upload verifies local size before sending and the server verifies again.

Storage uses `orbit project:document-storage:show` and `orbit project:document-storage:update`, without a Project selector. Update takes `--endpoint`, `--region`, `--bucket`, and credential input files `--access-key-id-file` and `--secret-access-key-file` (or masked terminal prompts); secret values are not command-line arguments or human output. Both credential files must be supplied together in noninteractive mode when setting or rotating credentials. Existing credentials may be omitted. Secret files are UTF-8 with one optional final newline stripped. Storage commands support `--json` and expose only the redacted configuration/status.

## SDK and MCP contract

The PHP SDK follows its existing `GatewayRequest`/`GatewayConnector::send` pattern. It provides `ListProjectDocumentsRequest`, `SearchProjectDocumentsRequest`, `CreateProjectDocumentRequest`, `ShowProjectDocumentRequest`, `UpdateProjectDocumentRequest`, `WriteProjectDocumentRequest`, `ReadProjectDocumentRequest`, `DownloadProjectDocumentRequest`, `ListProjectDocumentVersionsRequest`, `RestoreProjectDocumentVersionRequest`, `ArchiveProjectDocumentRequest`, `RestoreProjectDocumentRequest`, and `DestroyProjectDocumentRequest`. Storage uses `ShowProjectDocumentStorageRequest` and `UpdateProjectDocumentStorageRequest`. Constructor inputs match the API table and use numeric IDs. Each request maps its envelope to a typed response through `createDtoFromResponse`, preserving request correlation and the existing `GatewayApiException` contract.

Read responses contain text; download responses contain base64, with an explicit helper to verify and decode bytes. Entry, entry-page, version-page, content, removal, and redacted storage responses preserve the table's fields and cursor metadata. The SDK has no automatic revision retry, filesystem side effect, or credential getter. Upload helpers encode a supplied bounded byte string; they do not fetch a URL. Credential inputs use the SDK's existing sensitive-parameter and debug-redaction conventions.

Every operation in the API table generates one [MCP tool](/reference/mcp) with exactly the listed operation ID. There is no separate browser-only or CLI-only upload endpoint. MCP bodies are JSON text or base64; there is no multipart transport or local path read by the Gateway. Removal tools are destructive and clients explicitly pass revisions and recursive scope. Storage secrets are redacted on dispatch, Activity, and results; agents must not put them in shared transcripts.

The `/mcp/search` 65,536-byte result cap still applies: large content downloads use `/mcp` directly, the CLI, or SDK, not `execute_tools`. The inline limit is not a promise that every inline response fits the search endpoint. Generate OpenAPI, MCP manifests, and web types when implementing the routes; do not hand-maintain competing schemas.

## Web contract

### Browsing and actions

Open a Project and choose Documents from its section menu. The workspace URL is `/projects/{id}/documents`; its query stores the current folder, search, and archive state so browser Back returns to that view. Search / filters opens one sheet on a phone. New opens the folder, text-file, and upload choices. Row menus hold secondary actions, and move uses a navigable folder picker rather than a flat list of paths. A safe preview shows escaped text, including Markdown and JSON; it does not render user markup.

The Project detail page has a Documents section with root browsing, folder navigation, breadcrumbs, name/path search, active/archive filters, create-folder, new text file, and upload. Rows show kind, name, version, size, and archive state, with reachable rename, move, archive/restore, history, download, and remove actions.

Infinite pagination retains the current folder and filters. Automatic page loading pauses while any list, destination-picker, or history request is fetching, including a refresh, so it cannot cancel refreshed data. On a phone, content starts near the top, filters and secondary actions use a sheet/menu, and there is no wide fixed table or stacked filter wall. A desktop may use a table and side detail panel.

### Large-tree browsing limitation

Pagination bounds response rows, not database work. The Gateway currently loads all entries in the Project before filtering and sorting a list or search page, and resolves ancestors and current versions with per-entry queries. Version-history paging loads the file's complete history before slicing it. Large Projects and long histories can therefore increase memory use, query count, and latency even for a small page. There is no tested Project-size or latency guarantee. Database-bounded pagination and batched ancestor/version resolution are deferred; infinite scrolling does not remove this server-side limitation.

### Editing and pending requests

Editable files open a plain-text editor with explicit Save. There is no autosave.

The editor is read-only while a save, reload, or replacement is pending, including the content reload after a write. Create and metadata forms disable their inputs until the operation and folder refresh finish. This prevents changes made after a submitted snapshot from being silently replaced or discarded.

The client captures the entry revision when loading content and sends it on Save. Dirty drafts survive recoverable API failures and conflicts until the user reloads or leaves with confirmation. Attachments and oversized text show metadata, history, and download/upload, not an unsafe inline preview. HTML, SVG, Markdown, and other user content are never executed or injected as trusted markup. History supports downloading a selected version and restoring it as a new one. Removal confirmation distinguishes a file from a recursive folder deletion and names the irreversible scope.

### Storage status and verification

The Gateway settings page configures storage with write-only credential fields and sanitized status. Revisiting it never fills credentials from the server. Document metadata remains usable during outages; body actions show the stable error and recovery guidance. Clients refresh the open folder, search results, and affected entry metadata after successful mutations; realtime collaboration is not part of this feature. Phone and desktop verification must include a nested folder, a long name, an archived child, an attachment, a dirty conflicting edit, and recursive-removal confirmation.

## Why it works this way

### Metadata in Orbit, bodies in private object storage

The database provides atomic hierarchy and revision checks, while a dedicated private bucket holds bounded immutable bytes without expanding database backups into a blob store. Database body columns would grow backups with attachments. Git commits would couple drafts to repository history; local Node files would tie Project data to an Instance's lifetime. An external document service would leave authorization, history, and agent access outside Orbit's contract.

The Gateway proxies every body operation so authorization, redaction, limits, and errors stay the same across API, CLI, SDK, MCP, and web. Documents are core operations using existing peer identity and serving-Node grants, not an extension or a separate login. Presigned URLs and direct browser uploads would create a second authorization and publication protocol. Only the Gateway needs bucket credentials, and the dedicated destination keeps document cleanup separate from application backups.

Orbit versions name exact immutable bytes in committed metadata. Mutable object keys or provider bucket versioning cannot atomically identify that history alongside the entry revision. Rename and move therefore change metadata, not object keys. History consumes bucket capacity until permanent file or Project removal; automatic age-based purging would weaken the promised recovery boundary.

### Small native editor, not a planning environment

Project Documents serve [Agents operate, humans steer](/mission#principles) and [One way, one name](/mission#principles): humans and agents use the same Project tree and explicit revision checks. The plain-text editor is a narrow exception to [Where Orbit stops](/mission#where-orbit-stops), because native notes need human editing alongside the agent API. It is not an IDE or chat/planning interface. Documents do not replace maintained repository documentation or automatically enter agent context.

Explicit save and optimistic concurrency expose conflicts instead of silently losing another writer's changes. Name/path search avoids body extraction, OCR, embeddings, and private-content indexes. Collaborative rich-text editing and semantic search would add merge, rendering, and indexing systems beyond this notes-and-attachments boundary.

Base64 JSON adds roughly one-third transfer overhead, but keeps one generated API/MCP contract for bounded files. Multipart uploads, resumable sessions, and unlimited files would add transport state for larger artifacts outside this feature. The direct MCP endpoint supports bounded downloads; its search endpoint's smaller result cap is not bypassed.

### Durable recovery, not a shared S3 transaction

The database and S3 cannot commit together. A durable intent before PUT and atomic publication after PUT make the failure windows visible without holding database locks through provider requests. Publication and abandonment compete for the same lock; permanently retained abandoned fences catch late PUTs even after a successful DELETE. Treating absence as permission to discard a fence would leak delayed bytes and make a destination change unsafe. The cost is permanent recovery state and a destination that cannot migrate while any fence remains.

Removal records an exact-key tombstone in the metadata transaction and leaves the published upload row in place. Only the authorized worker can retire that row in a transactional handoff and delete the unreferenced key. A queued payload, age cutoff, bucket prefix, or listing never authorizes deletion. Unknown objects remain operator recovery work, not automatic garbage collection; a newer bucket may contain data missing from a restored database.

The cleanup permit lives outside database backups and is invalidated before services start. Otherwise an older restored database could authorize deletion of newer bucket objects on its first scheduled tick. Private, integrity-bound reports verify complete database and bucket inventories plus every committed size and digest. Resume repeats those checks rather than trusting a listing, ETag, or an operator resolution claim. Pause waits for in-flight deletion under the stable execution lock; startup and stale jobs cannot reuse earlier authorization.

Synthetic probes use their own durable encrypted journal because a failed configuration transaction must not erase cleanup authority. Their generated keys contain no user bodies, so exact tracked probe cleanup remains separate from the document restore gate. Neither probe nor document reconciliation promises deletion by a fixed deadline: both require scheduling, reachable storage, and usable credentials. Recovery needs paired database and bucket backups, the probe journal, and the encryption key; metadata alone cannot recreate body bytes.
