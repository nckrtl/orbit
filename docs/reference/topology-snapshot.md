# Topology snapshot

This page is for the operator who maintains Orbit's one persistent topology snapshot. Every proof topology and discovery topology starts from it. It answers which `bin/e2e-topology-snapshot` command from the `apps/e2e` harness to run, what each one changes, and how to recover when the manifest and the host disagree. The disposable topologies that clone it and any issue-local extension are on the [Incus topology registry](incus-topologies.md).

## Identity

A topology snapshot is a coordinated set of three Incus snapshots in one promoted generation. It never includes an issue's temporary `app-prod-2` Node. The primary checkout owns it, keeps its VMs stopped, and records the generation under `<primary>/.e2e/topology-snapshot/`: `promoted.json`, `generations/<id>.json`, `promotions/<id>.json` for lineage, `corrupt.json` after a failed rollback, and the recovery journal.

| Resource | Name |
| --- | --- |
| Network | `oe-topo-snap`, slot 1, `10.232.1.0/24` |
| Instances | `orbit-e2e-topology-snapshot-gateway`, `-app-dev`, and `-app-prod` |
| Base image | `orbit-base-ubuntu-26.04-runtime` |
| Generation ID | 12 characters of the main SHA, a hyphen, and 12 characters of the prepared fingerprint |
| Snapshot | `main-<generation-id>` on every instance |
| Promotion copy | `<instance>-next`, present only while `promote` runs |

## Commands

Every command accepts `--json`, and `--main-sha=SHA` must be the full SHA of the clean commit checked out on `main` in the primary checkout.

| Command | What it does |
| --- | --- |
| `status` | Prints the promoted generation, `missing`, or `stale` with a `recovery` command; fails when the VMs are not stopped |
| `fingerprint [--main-sha=SHA]` | Computes the prepared-state fingerprint of that commit, default `HEAD` |
| `promote ISSUE [--plan=PATH]` | Reads the reviewed plan that defaults to `.loop/proof/ISSUE.json`, makes the issue's proved or candidate topology the new generation, then releases the issue's topologies |
| `refresh --main-sha=SHA [--allow-cold]` | Refreshes the generation in place when the fingerprint changed; `--allow-cold` permits only initial construction |
| `restore` | Restores the promoted snapshots, leaves the VMs stopped, and clears `corrupt.json` |
| `rebuild --main-sha=SHA` | Forgets stale manifests and builds from the base image when every exact resource is absent |
| `recover-legacy --main-sha=SHA` | Proves ownership of present resources, retains evidence, and builds again |

## Promote

Feature closeout uses `refresh` from merged main while captured successful proof resources remain retained, under [ADR 0056](../decisions/0056-retain-proof-topologies-for-interactive-review.md). The explicit `promote` command remains available only for an unchanged retained live topology that has not received interactive reviewer access, and it verifies its plan selected from `.loop/proof/`. [ADR 0049](../decisions/0049-keep-delivery-artifacts-off-the-merge-head.md) governs the artifact workspace that supplies that plan. The harness refuses, without touching Incus, in each of these cases.

| Refusal | Condition |
| --- | --- |
| Evidence | No `proved` attempt, or the plan fingerprint, zero-exit action list, or manifest does not match the recorded proof |
| Mutation | The plan declares an extension, declares `mutates: true`, or its `ends_with` leaves a Node out; each condition sets `mutates` |
| Review state | The retained proof topology has any interactive reviewer action, so its live state is not promotion input |
| Tree | Primary `main` does not hold the accepted tree, or a different accepted tree has no `equivalent` report bound to that head |
| Fingerprint | The proved, accepted, and merged runtime fingerprints differ, or the cold epoch or base image alias changed |
| Leftover | A `-next` copy from an earlier promotion exists |

Under the refresh, generation, and issue locks the harness captures the ordered purpose and attempt identity of every topology that promotion may release. It then removes `/var/lib/orbit-e2e/proof` from the promoted VMs and stops them. It copies each one to `<instance>-next` on `oe-topo-snap` with the fixed address and MAC, strips the attempt metadata, and snapshots the copies as `main-<generation-id>`. A failure up to here deletes the copies and leaves the proved topology stopped. It then deletes each old instance and renames its copy into place. A failure during the swap names the rename to finish by hand, followed by `refresh`.

The harness records the generation with the merged SHA as `main_sha` and the old generation as `previous_generation_id`. It promotes the generation, forgets every other generation manifest, and records the lineage in `promotions/<id>.json`: `promotion_path`, `issue`, `generation_id`, `proved_sha`, `accepted_sha`, `merged_sha`, `runtime_fingerprint`, `manifest_sha256`, and `equivalence_sha256`. It then releases each captured attempt once, in promotion topology, discovery, and retained proof order. Repeated purposes are removed from this cleanup list.

Cleanup compares every captured identity with the current lease while it holds the issue lock. An absent identity or a replacement identity stops cleanup before any Incus deletion, Git unpinning, or lease deletion. A discovery or other attempt created after capture is outside the cleanup list and remains untouched.

The failure reports that the new snapshot generation is already installed, the captured identity that did not match, and any earlier cleanup that completed. The operator keeps the replacement attempt, checks `bin/e2e-topology-snapshot status`, and releases that current attempt separately when its own lifecycle ends. A missing captured lease does not prove that its old resources are absent.

## Refresh

`refresh` is the maintenance path when no proved topology exists and the closeout path for a retained successful proof after interactive review. A plan that normalizes to `mutates: true` also uses refresh under [ADR 0035](../decisions/0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md). An extended plan always takes this closeout path. Merge closeout never substitutes a refresh for missing or invalid proof or review evidence.

It requires the primary checkout at the requested SHA with a clean tree. When the fingerprints of that commit equal the promoted ones, it proves the snapshots exist and the VMs are stopped, then reports `unchanged`. Otherwise it restores the promoted snapshots, starts the VMs, synchronizes `main`, converges, verifies, stops the VMs, snapshots `main-<generation-id>`, and promotes the generation.

After a successful closeout refresh, `bin/e2e-topology closeout` records the proved attempt, artifact SHA, accepted head, merge commit, and promoted generation. It then releases the complete retained proof topology. A failed refresh retains every proof Node, the captured evidence, and the review record, and does not report closeout complete. The refresh result is `unchanged`, `promoted`, or `failed`.

### Convergence

Convergence prepares the sample resources and every product projection before verification.

Every convergence runs, in order, `converge-sample-app.sh reproject` on `app-dev`, `metrics-publication`, a wait until `instance:list --json` answers on `app-dev`, `hydrate` on the sample checkouts, and `prepare-node.sh permissions` on every role. Reproject runs `node:role:add --converge` for every app role. On the legacy `instances` envelope it then runs `instance:php` for every Instance, development last.

On the typed `app_instances` envelope, sample convergence uses the Orbit CLI to keep `e2e-dev` associated with one explicit private Route named `e2e-dev.orbit`. It creates the Route when the association is absent, reuses only the exact sample App, target, scope, hostname, provenance, and publication, and refuses conflicting or multiple associations without editing the Gateway database directly. The topology verifier applies the active-AppInstance Route association rule from [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) to every active AppInstance.

The rendered pools, Caddy fragments, firewall rules, and DNS records then match the checkout. When `create-resources` returns no typed checkout path, `internal-tls` on `app-prod` runs before reproject and places the `local_certs` global block as `fragments/00-orbit-e2e-global.caddy` inside the managed Caddy version behind `/etc/caddy/Caddyfile`; the product publisher carries unmanaged fragments forward, so Doctor reports no Caddy drift.

### Sample compatibility

The sample adapter selects its production creation contract before it changes sample state. When the complete candidate-clone and explicit-deployment command set is available, it creates production from the development candidate and deploys it explicitly. Otherwise it uses direct production creation. A failure after selection stops convergence and never switches to the older contract.

Hydration derives each checkout, persistent environment file, optional SQLite database, production user, and active release from the AppInstance's recorded placement. It imports and synchronizes environment configuration only when the complete public command set is available. A repeated convergence keeps stored and local environment keys, database contents, sample identities, and the selected release.

Verification accepts two exact production layouts during this compatibility period. A flat placement uses the recorded home and web root with the shared PHP-FPM service and socket. A release placement uses the recorded persistent paths and `current` release with the recorded dedicated service and socket. A missing or mismatched home, web root, environment file, database, service, socket, owner, or current target fails verification; the verifier does not substitute the flat expectation for a malformed release placement.

The compatibility period ends when the supported sample contract always includes candidate cloning, explicit deployment, release placement, environment synchronization, and a dedicated production PHP service. The later sample convergence change removes direct production creation, flat placement, and shared production PHP verification together.

`--allow-cold` permits construction only when no promoted generation, `corrupt.json`, topology snapshot network, or topology snapshot VM exists. It never replaces a promoted generation.

## Rebuild

A manifest that names snapshots or VMs the host does not hold is stale, not corrupt. `status` reports `state: stale` with the `recovery` command, and `refresh` and `restore` refuse before they mutate anything. `rebuild --main-sha=SHA` holds the refresh lock and refuses while any of the three instances, their `-next` copies, or the network exists. The refusal lists each present name and directs the operator to `recover-legacy`. When all are absent it deletes every manifest and `corrupt.json`, runs a cold build at `SHA`, and reports `instances_deleted`, `networks_deleted`, and the refresh result.

## Retire legacy resources

The `legacy:inventory`, `legacy:quarantine`, `legacy:delete`, and `legacy:verify` commands keep the topology snapshot pool, base image, current namespace, and evidence outside legacy deletion. [ADR 0005](../decisions/0005-rolling-incus-development-topology.md) governs this exact and reversible retirement boundary.

Every current retirement artifact uses schema 3. This version applies to the inventory, quarantine manifest, retirement result, and the quarantine and deletion journals that embed them. A quarantine or deletion retry accepts only a matching schema 3 journal with its exact inventory or quarantine digest, freeze evidence, targets, preserved references, acknowledgement, retention time, pending entry, and completed entries.

The inventory records one scoped selector and one display field for each preserved Incus storage pool and base image.

| Kind | Required scope | Exact API selector | Display field |
| --- | --- | --- | --- |
| Storage pool | `remote` and `project` | `name`, validated as the pool API name | `identity` |
| Base image | `remote` and `project` | `fingerprint`, exactly 64 lowercase hexadecimal characters | `name` |

The kind, remote, project, and selector form one reference. The harness uses that reference for ordering, uniqueness, selection from the reviewed observation, the native query, and live comparison. A pool display identity never selects the pool API path, and an image display name never replaces its fingerprint.

Native revalidation runs `incus query --raw` through the same classifier for single and batch reads. A launched command with exit code zero and a `type: sync` envelope is present. A launched command with exit code zero and a `type: error` envelope whose integer `error_code` is `404` is absent. Launch failure, nonzero exit, plain standard error, empty or malformed output, any other error envelope, and disagreement between the exit code and envelope stop the operation before candidate mutation.

Schema 2 files remain byte-for-byte audit evidence. The current commands can read their protected JSON bytes, but inventory, quarantine, result, and journal decoders reject them as mutation or resume authority. The harness never fills missing scope from host defaults, rewrites the file, replaces its digest, transfers its acknowledgement, or reuses its quarantine time.

After a partial schema 2 retirement, the operator keeps every old file and runs `legacy:inventory` to a new path. The operator reviews and acknowledges the schema 3 inventory, uses its derived new quarantine, retirement, and journal paths, and waits a new full seven days before deletion. A remaining instance that is already stopped enters the new quarantine as `unchanged` and is not stopped again. When no candidate remains, the acknowledged inventory is the terminal audit; the operator does not create an empty quarantine or run deletion.

The retained proof runs in the three standard Nodes and checks serialization and refusal without access to the host Incus socket. An issue-local host rehearsal complements it by using the production batch reader for the configured pool and exact base-image fingerprint. That rehearsal may mutate only its own temporary file candidates; every native Incus operation is read-only, and its receipt binds the candidate, plan, fixtures, discovery identity, resource identities, commands, file hashes, assertions, and exits.

## Locks and journal

Two host locks under `<primary>/.e2e/locks/` serialize every topology snapshot mutation. Each lock file records the owning process, the operation ID, and the acquisition time.

| Lock file | Held by |
| --- | --- |
| `standby-refresh.lock` | `promote`, `refresh`, `restore`, `rebuild`, and `recover-legacy`, which wait up to 3600 seconds for it |
| `standby-generation.lock` | Exclusive while snapshots or the promoted manifest change; shared by `acquire`, `prove`, and `candidate` while they copy the promoted snapshots |

`recover-legacy` journals to `recovery.json` before it mutates anything. The journal holds the inventory with its SHA-256 digest, the requested SHA, and a phase history drawn from `authorized`, `instances_pending`, `instances_verified`, `network_pending`, `network_verified`, `manifests_pending`, `manifests_verified`, `construction_pending`, `construction_cleanup_pending`, `construction_cleanup_verified`, `construction_verified`, and `failed`. A new recovery archives a completed journal to `recoveries/<operation-id>.json` first.

## Recover

The operator runs `recover-legacy --main-sha=SHA` when `rebuild` reports present resources. Recovery accepts only a readable promoted manifest of schema 4 or 5. It authorizes only the configured base VMs and their exact `-next` copies. Each must carry owner and operation metadata, the topology snapshot network and role MAC, and no extra disk. Each base VM must hold the promoted snapshot, and `oe-topo-snap` must carry the exact bridge configuration with only inventoried VMs as users. Any other evidence fails closed, and a name, prefix, glob, or age never authorizes deletion.

Recovery also handles the alternate identity `oe-standby` with `orbit-e2e-standby-<role>` instances and state under `.e2e/standby/`. It refuses when both identities are present or when that journal is incomplete.

Under the refresh lock, recovery deletes the instances, the network, and the manifests, verifying each boundary in the journal. It then runs a cold build at `SHA` and verifies the generation, the stopped VMs, and the absence of every `-next` copy. A retry with the same SHA resumes from the retained inventory when its digest and the host agree. The harness cleans up an interrupted build by removing only resources stamped with that construction operation.

Every result includes `error`, `recovery_evidence`, `recovery_phase`, and `next_action`: `bin/e2e-topology-snapshot status` on success, the same recovery command on failure. The operator never runs `incus delete`, removes a manifest, or edits retained evidence by hand. Those actions discard the authority and retry evidence that recovery depends on.
