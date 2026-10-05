---
title: "Project Documents"
description: "Native Project folders, versioned files, editing, private S3 storage, and the shared API, CLI, SDK, MCP, and web contract."
covers:
  - apps/gateway/app/Actions/ProjectDocuments/**
  - apps/gateway/app/Infrastructure/ProjectDocuments/**
  - apps/gateway/app/Models/ProjectDocument*.php
  - apps/gateway/app/Console/Commands/*DocumentProbe*.php
  - apps/gateway/database/migrations/*_create_project_document_storages_table.php
  - apps/gateway/database/migrations/*_create_project_documents_tables.php
---

# Project Documents

Project Documents are folders and versioned files owned by one [Project](/reference/projects). They hold notes, specifications, and attachments outside Git. They belong to the Project, not an Instance, branch, task, checkout, or Node. The [in-progress decision](/decisions/0197-native-project-documents-with-private-s3-storage) records this feature's storage and editing boundary.

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

The decoded body limit is 10 MiB (10,485,760 bytes), including text, for every client. Empty content is allowed. The HTTP JSON request limit is 15 MiB (15,728,640 bytes); oversized requests use the same JSON error contract as other API failures, not an HTML proxy response.

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

The allocated live destination is the existing private `orbit-project-documents` bucket on UpCloud object-storage service `s3-store1`, in service region `EUROPE-2` and zone `DE-FRA1`, at `https://qho6e.upcloudobjects.com`. Orbit uses this bucket, not a newly provisioned bucket. There is one configuration per Gateway, not a bucket or credential per Project. Orbit does not provision buckets, change ACLs, or create credentials.

The Gateway uses HTTPS, Signature Version 4, and path-style bucket addressing. The configuration field `region` is the S3 signing-region input to the client, not the UpCloud service region or zone. Its exact value for `s3-store1` remains unverified; do not infer it from `EUROPE-2` or `DE-FRA1`. Confirm it against the service's S3 connection details during post-CLEAN DevOps setup, then inject it with the existing endpoint, bucket, and credentials.

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

Live credential injection and verification of private UpCloud read/write/delete are post-CLEAN DevOps work on the existing service. Before CLEAN, implementers and reviewers use isolated fake or disposable S3 fixtures to verify this contract. Neither live credentials nor a live UpCloud probe is required for pre-CLEAN review. Post-CLEAN setup uses the validated configuration operation and records the verified signing region without publishing credentials.

### Publish and recover

A body write records a durable upload intent before contacting S3. The intent owns a fresh, never-reused object key and has `active`, `published`, or `abandoned` state. Upload bytes first; then publish the version, current-version pointer, entry revision, and intent's `published` state in one database transaction. This transaction locks the intent and entry, requires an `active` intent, and rechecks revision, parent, and archive state. Readers see only committed versions.

Do not hold a database transaction open across a provider request. Body operations start outside any database transaction. The Gateway rejects an enclosing transaction before provider access so an outer rollback cannot erase an upload's recovery record. Bound each provider request to a 30-second timeout; do not automatically retry a content mutation inside the HTTP request.

Publication and abandonment compete for the same intent lock. Cleanup atomically changes an unreferenced `active` intent older than one hour to `abandoned` and records its deletion tombstone before any DeleteObject call. If publication commits first, cleanup sees `published` and cannot claim that key. If abandonment commits first, publication is permanently refused with HTTP 409 `project_documents.upload_abandoned`; the caller must retry with a new intent and key. No check-then-delete sequence outside this transaction substitutes for the claim.

A writer paused beyond the cutoff must recheck intent state before PUT and again in the publication transaction after PUT. It cannot revive an abandoned intent. A late PUT may finish after cleanup deleted or found no object; therefore abandoned intent fences and their keys are retained permanently, and reconciliation rechecks those keys even after a successful deletion. A late writer schedules deletion, never publication. A crash after late PUT but before that scheduling is covered by the retained fence.

An absent abandoned object is not counted as pending cleanup, but its fence is not discarded and still blocks destination changes. Reconciliation continues to use the unchanged destination and its current credentials, including after credential rotation.

Storage tests must cover an abandoned upload whose object has been deleted and whose pending count is zero: changing endpoint, region, or bucket still returns `project_documents.storage_in_use`; a delayed PUT to that original destination cannot publish and is deleted by reconciliation.

A crash before PUT leaves an active intent that expires; a crash after PUT but before publication leaves an uncommitted object that expires. A crash after publication preserves the committed version; a crash after abandonment but before deletion resumes deletion from the tombstone. A failed upload or failed publication, including a late revision conflict, abandons the intent atomically and schedules the same cleanup. Reconciliation never resumes uploads or publishes intent bytes on a writer's behalf.

While the cleanup gate below is running, reconciliation runs at least every five minutes and retries pending deletions with bounded backoff. It never deletes a committed version because it is old or because a bucket listing briefly omitted it. Removal tombstones retain the exact keys until deletion succeeds; DeleteObject of an absent key counts as success. Abandoned-upload fences remain after success to prevent late PUT leaks. Unknown bucket objects without an intent or tombstone are reported for operator reconciliation, not automatically deleted.

There is no automatic purge of archived files or old versions. Cleanup failures remain visible in storage status as `pending_cleanup_count`, `oldest_pending_cleanup_at`, and `last_cleanup_error_code`, without keys or provider secrets. The same job handles Project-removal cleanup.

If a committed body is missing or fails its stored digest check, return `project_documents.body_unavailable` and preserve the version metadata. Never replace it with an empty body or silently fall back to another version. Operators restore the exact object from a bucket backup or restore another available version explicitly. Database backups, bucket backups, and the Gateway encryption key are all required for disaster recovery. Restoring only the database cannot recover bytes, and losing the encryption key requires supplying fresh credentials.

### Restore-time cleanup gate

Cleanup is fail-closed. A local permit under `/run/orbit/project-documents/`, outside the database and backups, controls every scheduled or queued document-body deletion, including removal and abandoned-intent cleanup. A synchronous configuration probe may delete only its own fresh random probe key; it cannot delete document keys. Missing or invalid permit means paused. Gateway service startup clears the permit before API, scheduler, or queue workers can run. The permit is bound to that service-start generation; queued jobs check it immediately before each deletion. Restoring a database must use the procedure below, not copy a snapshot underneath running workers.

Local Gateway Artisan commands are `project-documents:cleanup:pause`, `project-documents:cleanup:status`, `project-documents:cleanup:reconcile`, and `project-documents:cleanup:resume --report=ID`. These are operator recovery commands, not public API/MCP operations. Pause removes the permit under an exclusive cleanup-execution lock and waits for in-flight deletions to finish; each deletion holds that lock from its gate check through the provider result. Once pause returns, no deletion can start. Pause is idempotent; commands fail closed if their lock or state files cannot be read or written.

Status reports `cleanup_state` (`paused` or `running`), `cleanup_generation`, `reconciliation_report_id` (nullable), and pending-cleanup counts, without keys or credentials. The storage-show API adds these same gate fields. A pause or service restart invalidates any prior reconciliation report. Resume refuses without a completed report from the current generation, with unchanged database and bucket inventory since reconciliation. An invalid report returns a local nonzero exit status and leaves the gate paused.

The operator follows this sequence for installation, restart, or restore:

#### 1. Pause before replacement

Run pause and confirm status is paused. Before restoring, stop API writes, scheduler, and queue workers. Wait for in-flight uploads to finish. Preserve database, bucket, encryption key, and cleanup records. Do not restore `/run` permits.

#### 2. Restore with workers stopped

Restore the database and required bucket objects with workers stopped. Start the Gateway; startup keeps cleanup paused, so its first scheduled job cannot delete anything. Keep document mutations disabled through reconciliation.

#### 3. Inventory without deletion

Run reconcile while paused. It inventories committed versions, active/abandoned intents, removal tombstones, and bucket objects. It verifies referenced sizes/digests and reports missing, corrupt, and unreferenced objects. Reconcile never deletes, publishes, or automatically adopts objects. Detailed key reports are local files readable only by the Gateway operator.

#### 4. Resolve differences

Resolve every difference before resume: restore missing committed bytes; recover newer metadata from a matching database backup; or explicitly preserve unmatched objects in a separate operator-owned recovery backup before removing them explicitly. A newer bucket is not proof that unreferenced bytes are disposable.

Rerun reconcile after each repair. Its completed report records the database fingerprint, bucket inventory fingerprint, and operator resolution of all differences outside the restored database.

#### 5. Authorize cleanup

Run resume with that report ID. Under the same execution lock, it rechecks the fingerprints and generation, writes the permit atomically, and reports running. Only then enable document mutations and normal scheduled cleanup. If any comparison or provider check fails, it remains paused; reconcile again rather than bypassing the gate.

A database fingerprint covers document entries, versions, intents, tombstones, and storage destination; a bucket fingerprint covers object keys, sizes, and provider modification/version markers. The report records verification of body digests separately. This gate does not promise safety for an unsupported database replacement while services remain running. The supported restore procedure pauses before replacement and the startup hook always resets authorization before any job.

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
| DELETE `/{entry}` | `project-document-destroy` | JSON `expected_revision`, `recursive` (default false); removal receipt. |
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

In a terminal missing required inputs are prompted: Project and entry from authorized lists, kind from folder/file, name as validated text, destination from active folders plus root, body source as a local path, version from history. A terminal may read the current revision when omitted and show it before submission, but it never substitutes a newer revision after conflict. Storage credentials use masked prompts. Input/body flags that are mutually exclusive fail before any write. Missing inputs, invalid local input, and refused confirmation exit 2; API or storage failures exit 1; success exits 0. Human diagnostics go to stderr for raw content commands.

Download never overwrites an existing file. Use a new path; there is no implicit overwrite or force option. It verifies the decoded size and digest and atomically renames a temporary file into place. Raw stdout cannot be recalled, so clients validate the entire bounded body before emitting it. CLI refuses binary stdout to a terminal; a pipe or `--output` file is required. JSON mode creates no local file. Upload verifies local size before sending and the server verifies again.

Storage uses `orbit project:document-storage:show` and `orbit project:document-storage:update`, without a Project selector. Update takes `--endpoint`, `--region`, `--bucket`, and credential input files `--access-key-id-file` and `--secret-access-key-file` (or masked terminal prompts); secret values are not command-line arguments or human output. Both credential files must be supplied together in noninteractive mode when setting or rotating credentials. Existing credentials may be omitted. Secret files are UTF-8 with one optional final newline stripped. Storage commands support `--json` and expose only the redacted configuration/status.

## SDK and MCP contract

The PHP SDK follows its existing `GatewayRequest`/`GatewayConnector::send` pattern. It provides `ListProjectDocumentsRequest`, `SearchProjectDocumentsRequest`, `CreateProjectDocumentRequest`, `ShowProjectDocumentRequest`, `UpdateProjectDocumentRequest`, `WriteProjectDocumentRequest`, `ReadProjectDocumentRequest`, `DownloadProjectDocumentRequest`, `ListProjectDocumentVersionsRequest`, `RestoreProjectDocumentVersionRequest`, `ArchiveProjectDocumentRequest`, `RestoreProjectDocumentRequest`, and `DestroyProjectDocumentRequest`. Storage uses `ShowProjectDocumentStorageRequest` and `UpdateProjectDocumentStorageRequest`. Constructor inputs match the API table and use numeric IDs. Each request maps its envelope to a typed response through `createDtoFromResponse`, preserving request correlation and the existing `GatewayApiException` contract.

Read responses contain text; download responses contain base64, with an explicit helper to verify and decode bytes. Entry, entry-page, version-page, content, removal, and redacted storage responses preserve the table's fields and cursor metadata. The SDK has no automatic revision retry, filesystem side effect, or credential getter. Upload helpers encode a supplied bounded byte string; they do not fetch a URL. Credential inputs use the SDK's existing sensitive-parameter and debug-redaction conventions.

Every operation in the API table generates one [MCP tool](/reference/mcp) with exactly the listed operation ID. There is no separate browser-only or CLI-only upload endpoint. MCP bodies are JSON text or base64; there is no multipart transport or local path read by the Gateway. Removal tools are destructive and clients explicitly pass revisions and recursive scope. Storage secrets are redacted on dispatch, Activity, and results; agents must not put them in shared transcripts.

The `/mcp/search` 65,536-byte result cap still applies: large content downloads use `/mcp` directly, the CLI, or SDK, not `execute_tools`. The inline limit is not a promise that every inline response fits the search endpoint. Generate OpenAPI, MCP manifests, and web types when implementing the routes; do not hand-maintain competing schemas.

## Web contract

The Project detail page has a Documents section with root browsing, folder navigation, breadcrumbs, name/path search, active/archive filters, create-folder, new text file, and upload. Rows show kind, name, version, size, and archive state, with reachable rename, move, archive/restore, history, download, and remove actions. Infinite pagination retains the current folder and filters. On a phone, content starts near the top, filters and secondary actions use a sheet/menu, and there is no wide fixed table or stacked filter wall. A desktop may use a table and side detail panel.

Editable files open a plain-text editor with explicit Save. There is no autosave. The client captures the entry revision when loading content and sends it on Save. Dirty drafts survive recoverable API failures and conflicts until the user reloads or leaves with confirmation. Attachments and oversized text show metadata, history, and download/upload, not an unsafe inline preview. HTML, SVG, Markdown, and other user content are never executed or injected as trusted markup. History supports downloading a selected version and restoring it as a new one. Removal confirmation distinguishes a file from a recursive folder deletion and names the irreversible scope.

The Gateway settings page configures storage with write-only credential fields and sanitized status. Revisiting it never fills credentials from the server. Document metadata remains usable during outages; body actions show the stable error and recovery guidance. Clients refresh the open folder, search results, and affected entry metadata after successful mutations; realtime collaboration is not part of this feature. Phone and desktop verification must include a nested folder, a long name, an archived child, an attachment, a dirty conflicting edit, and recursive-removal confirmation.

## Why it works this way

### Metadata in Orbit, bodies in private object storage

The database provides atomic hierarchy and revision checks, while a dedicated private bucket holds bounded immutable bytes without expanding database backups into a blob store. The Gateway proxies every body operation so authorization, redaction, limits, and errors stay the same across clients. Presigned URLs and direct browser uploads would create a second authorization and publication protocol; local Node files would tie Project data to an Instance's lifetime. Version immutability plus durable cleanup makes the database/S3 failure boundary explicit rather than pretending they share a transaction.

### Small native editor, not a planning environment

Project Documents make notes and attachments available beside the Project without requiring a Git commit for each draft. The plain-text editor is a narrow exception to Orbit's no-editor boundary, not an IDE or chat/planning interface. Explicit save, optimistic concurrency, name/path search, and byte limits provide a complete agent contract without collaborative editing, indexing services, or unbounded tool output. Base64 JSON costs bandwidth, but keeps one generated API/MCP contract and avoids a separate upload-session protocol for small bounded files.
