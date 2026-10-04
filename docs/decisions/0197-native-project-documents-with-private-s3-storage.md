---
title: "Native Project Documents with private S3 storage"
description: "Keep Project document metadata in the Gateway database and immutable file bodies in a dedicated private UpCloud bucket."
---

# ADR 0197: Native Project Documents with private S3 storage

Orbit owns Project-level folders and versioned files. The Gateway stores their metadata and encrypted storage credentials in its database, and their immutable bodies in a dedicated private UpCloud S3-compatible bucket. All clients use one authorized JSON API.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles) and [One way, one name](/mission#principles). This decision makes a narrow exception to [Where Orbit stops](/mission#where-orbit-stops): Orbit supplies a plain-text editor for Project Documents because native notes need human editing alongside the same agent API. It does not add an IDE, chat, collaborative editor, or planning interface.

## Context

A Project needs notes, specifications, and attachments that are independent of a branch, task workspace, and Instance lifetime. Repository files remain the source for maintained code documentation, but requiring a commit for every note or binary attachment adds work unrelated to code changes. External document links would not give agents a consistent authorization, version, and recovery contract.

Document bodies do not belong in the Gateway database or on disposable Nodes. Orbit already has peer identity, serving-Node grants, Activity, a generated API/MCP catalogue, CLI JSON, and a web client. This feature uses those boundaries rather than inventing public sharing or another login. The complete implementable contract is [Project Documents](/reference/project-documents).

## Decision

Adopt the following model and storage boundary. The maintained reference fixes all client inputs, outputs, errors, and limits.

### One Project tree and immutable history

Use one tree of folders and files per Project, with an implicit root. Numeric entry IDs stay stable across rename, move, and archive. Sibling names are unique and case-sensitive, including archived entries. A file always has a committed current version; each content change creates immutable history. Metadata changes increment an entry revision but do not create versions. Archive preserves the tree and bytes; permanent removal hides metadata immediately and durably queues object deletion. Project removal applies the same cleanup; Instance removal does not.

Require `expected_revision` on every mutation of an existing entry. Compare it atomically at commit, including after an upload. Never automatically overwrite after a conflict. Recursive folder removal serializes against subtree writes. The reference defines [ownership](/reference/project-documents#ownership-and-fields), [archive and removal](/reference/project-documents#folders-archive-and-removal), and [concurrency and retries](/reference/project-documents#optimistic-concurrency-and-retries).

### Database authority, dedicated private bucket

The Gateway database owns hierarchy, names, versions, current-version pointers, revisions, archive state, encrypted credentials, upload intents, and cleanup tombstones. File bodies use the existing private `orbit-project-documents` bucket on `s3-store1`: UpCloud service region `EUROPE-2`, zone `DE-FRA1`, endpoint `https://qho6e.upcloudobjects.com`. Orbit neither provisions another bucket nor changes ACLs. Only the Gateway contacts it. Encrypt both access-key and secret-key values and never return them or record them in Activity, logs, or errors.

The API's `region` is the S3 signing-region input, distinct from UpCloud's service region and zone. Its exact service value remains unverified and must not be guessed from those labels. Confirm it and inject live credentials during post-CLEAN DevOps setup. Live UpCloud verification is not a pre-CLEAN reviewer requirement.

Use immutable object keys derived from IDs, not filenames. Validate a fresh configuration or credential rotation with a private probe. While live versions, active intents, retained abandoned-upload fences, or pending cleanup exist, refuse a bucket, region, or endpoint change. An absent fenced object blocks destination changes even when the pending count is zero. Permanent fences keep the original destination available for late-PUT cleanup with its current credentials. Storage migration and fence retirement are not part of this decision. The [S3 boundary](/reference/project-documents#private-s3-boundary) fixes the configuration, provider protocol, redaction, and status fields.

S3 and database writes do not share a transaction. Record an upload intent, upload first, and publish metadata and intent state atomically after validation and concurrency recheck. Publication and cleanup abandonment compete under one intent lock; abandonment permanently fences publication. Retain abandoned keys so a late PUT cannot leak bytes after an earlier deletion. Process death is recovered by reconciliation, not by publishing abandoned bytes.

Permanent removal leaves durable tombstones until deletion succeeds. Missing committed bytes are an explicit error, not an empty file. Recovery requires database, bucket, and encryption-key backups. Cleanup requires a local permit outside database backups, cleared before workers start on every Gateway service startup. Operator pause/status/reconcile/resume commands gate restored databases before their first deletion. The [publication contract](/reference/project-documents#publish-and-recover) and [restore procedure](/reference/project-documents#restore-time-cleanup-gate) fix the winner, crash recovery, and gate.

### One bounded content transport

Use JSON UTF-8 text or base64 bodies through the Gateway for API, CLI, SDK, MCP, and web. Bound decoded bodies to 10 MiB and inline editing to 1 MiB of admitted UTF-8 text types. Downloads return bounded base64 JSON rather than public/presigned URLs. Search is literal name/path substring search within one Project, not body extraction or semantic retrieval. The reference fixes [limits and versions](/reference/project-documents#content-and-versions), [API and errors](/reference/project-documents#api-contract), [CLI](/reference/project-documents#cli-contract), [SDK/MCP](/reference/project-documents#sdk-and-mcp-contract), and [web behavior](/reference/project-documents#web-contract).

Documents are a core feature, not an extension. Entry operations use the existing Project-owning grant check; storage operations require Gateway access. All clients preserve the same optimistic concurrency and errors. The web editor saves explicitly and retains dirty drafts on conflict. Agents explicitly retrieve Documents; no automatic context injection is added.

## Rejected alternatives

- Store bodies in database columns: grows database backups with binary bodies and makes the database the blob store.
- Store files in Git or on an Instance Node: couples notes to repository commits, branches, checkout paths, and disposable runtime lifetimes.
- Link an external document service: leaves authorization, version history, and agent access outside Orbit's contract.
- Direct or presigned S3 transfers: adds a separate publication and authorization protocol and makes generated MCP parity harder.
- Mutable object keys or S3 versioning as Orbit history: couples file history to provider settings and cannot atomically name the committed version in Orbit metadata.
- Multipart uploads, resumable sessions, and unlimited files: add transport state for a bounded notes-and-attachments feature; larger artifact storage is outside scope.
- Full-text/semantic search and collaborative rich-text editing: add extraction, indexing, merge, and rendering systems not needed for explicit agent read/write and plain-text notes.

## Consequences

- Humans and agents operate the same Project-owned tree with explicit, recoverable concurrency conflicts.
- The dedicated bucket is a new external dependency. Metadata remains usable during an outage, but bodies need storage access.
- Immutable history consumes bucket capacity until permanent file or Project removal. There is no automatic retention purge in this feature.
- Durable reconciliation and paired database/bucket recovery are required; tests must cover upload/commit failure windows, not only successful transfers.
- Base64 adds roughly one-third transfer overhead. The bounded transport avoids an additional upload-session API, but large tool responses cannot pass through the MCP search endpoint.
- The plain-text editor is the stated mission-boundary exception; it must not grow into an IDE or planning interface implicitly.
- The subtask that completes this decision absorbs it into the reference's [Why it works this way](/reference/project-documents#why-it-works-this-way), adds its retired row and redirect, and removes this ADR when its brief names the absorbing page.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web, apps/docs, apps/e2e
- ADRs: none.
- Detail: [Project Documents](/reference/project-documents), especially [Why it works this way](/reference/project-documents#why-it-works-this-way).
- Verify: API tests for authorization, cross-Project IDs, hierarchy, archive inheritance, revisions, limits, redaction, and every error; storage tests for immutable publication, orphan cleanup, missing/corrupt bodies, rotation, and recovery; CLI/SDK/MCP contract tests for byte parity and conflicts; phone/desktop web verification for browse, editing, uploads, history, and destructive consent. Run docs impact, `composer docs-build`, `composer docs-lint`, and each changed project's affected tests and check. Pre-CLEAN implementation and independent review use isolated fake or disposable S3 fixtures, including a writer resumed after cleanup wins, refusal of destination changes with a retained fence and zero pending cleanup before a delayed PUT, and the first job after a database restore. Live credential injection, confirmation of the service's signing region, and private UpCloud read/write/delete verification on the existing bucket are post-CLEAN DevOps work, not pre-CLEAN review requirements. No live credentials or buckets are created or altered by this docs subtask.
