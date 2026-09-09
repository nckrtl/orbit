# Feature plan

Issue: ORB-171
Review verdict: IMPLEMENTATION AUTHORIZED BY ROOT

## Outcome

Legacy retirement writes a coherent schema 3 artifact family and uses one exact scoped selector for each preserved Incus pool and base image before any candidate mutation. Existing schema 2 artifacts remain immutable audit evidence; remaining work starts from a fresh schema 3 inventory, review, paths, acknowledgements, and seven-day retention period.

## Code boundaries

In:
- `RetirementInventory`, `QuarantineManifest`, `RetirementResult`, and embedded quarantine/deletion recovery journals move atomically to schema 3.
- Preserved pool/image validation, ordering, uniqueness, observation selection, batch labels, raw query construction, and live comparison share one exact `kind + remote + project + selector` reference.
- Production single and batch Incus reads share one raw-envelope classifier.
- Legacy retirement and command tests cover schema 2 audit-only refusal, fresh partial restart, already-stopped and empty remaining sets, and exact schema 3 retry.
- `docs/reference/topology-snapshot.md` documents the current contract.
- `.loop/proof/ORB-171.json` and issue-local fixtures provide the retained guest action and supplemental host rehearsal.

Out:
- Unrelated resource identity or hierarchy changes.
- Incus mutation command changes.
- New proof action APIs, Node roles, proof VMs, or guest access to the host Incus socket.
- Native Incus mutations in the host rehearsal.

## Documentation

Audit scope: ORB-171; `docs/reference/topology-snapshot.md`, `docs/reference/incus-topologies.md`, and `docs/reference/proof-plans.md`.

Fixed:
- `docs/reference/topology-snapshot.md`: the maintained operator reference omitted the current legacy-retirement artifact schema, exact preserved references, raw envelope policy, schema 2 compatibility, restart cases, retry boundary, and proof venues -> it states each current behavior.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint` pass; the lint reports zero findings.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Schema 3 exact pool/image fields and shared reference | Value objects, retirement ordering, host selection, revalidator | `LegacyRetirementTest`, `LegacyIncusRevalidatorTest`, `LegacyCommandsTest` |
| One raw Incus envelope policy | `LegacyIncusRevalidator` single and batch paths | `LegacyIncusRevalidatorTest`, `LegacyCommandsTest` |
| Scope and selector drift refuse before mutation | Host requested observation and retirement preservation barrier | Three focused test files and host rehearsal |
| Schema 2 is audit-only; partial work restarts fresh | Schema readers, command paths, retirement lifecycle | `LegacyRetirementTest`, `LegacyCommandsTest` |
| Recovery retries only an exact schema 3 journal | Journal writers and resume validators | `LegacyRetirementTest` |
| Two proof venues bind the real boundary | `.loop/proof/ORB-171.json`, guest fixture, host rehearsal and receipt | Incus action and root-run host command |
| Maintained reference matches behavior | `docs/reference/topology-snapshot.md` | `composer docs-build`, `composer docs-lint` |
| Owning and repository suites pass | `apps/e2e`, root | `composer check`, `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and update the maintained topology snapshot reference.
2. Introduce the exact preserved Incus reference and schema 3 value validation.
3. Apply the reference to ordering, selection, query, comparison, and preservation checks.
4. Unify raw single/batch result classification.
5. Cut embedded manifests, results, and journals to schema 3 and cover fresh restart cases.
6. Add the guest proof action and immutable host rehearsal receipt fixture.
7. Run focused tests, documentation checks, `apps/e2e` checks, and hand off for root-run final integration, suite, proof, and host rehearsal.

## Must preserve

- Schema 2 artifact bytes and their original paths.
- Candidate, filesystem, other Incus-kind, result-entry, and resource-hierarchy semantics outside the exact scope comparison needed for selection.
- Existing deletion commands, dependency order, seven-day retention, freeze evidence, and recovery journal barriers.
- Standard three-Node topology with no extension.
- Production separation and task-owned mutation boundaries.

## Open questions

- None. Root adopted the complete repaired contract.

## Deviations

- None.

## Verification status

- Focused retirement suite: 116 tests passed with 337 assertions.
- `apps/e2e` `composer check`: 1,169 tests passed with 5,980 assertions; Rector, formatting, lint, and analysis passed.
- Guest proof fixture: local execution passed schema 3 serialization, malformed-reference refusal, five schema 2 immutable-file refusals, and exact temporary-file deletion.
- Host fixture scenarios: local native execution passed the matching deletion and changed-project, changed-pool, and changed-fingerprint byte-identical refusals against `local:/default`.
- Standard three-node discovery `437d3fc242ef81d18d5e02b5cd9f7bfa` passed acquisition and source synchronization on `local:/default`.
- The discovery guest action exited zero. The diagnostic host rehearsal also passed with 18 zero-exit commands, 16 zero-exit assertions, exact production raw-query logs, and unchanged discovery and Incus resource captures.
- Repository `bin/test`, current-main integration, immutable proof, and the authoritative host receipt remain assigned to the final root-coordinated window.

## Review findings

- Pending independent review after candidate handoff.
