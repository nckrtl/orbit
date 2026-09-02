# ADR 0015: Retain Incus proof by recorded-input equivalence

## Status

Accepted on 2026-09-02.

If accepted, this decision amends the exact-candidate invalidation rule in
[ADR 0006](0006-topology-led-feature-development.md). ADR 0006's separation of
discovery and proof, immutable successful evidence, attempt ownership, and
exact cleanup remain in force. The production-separation principles in
[ADR 0002](0002-candidate-deployment-proof-boundary.md) also remain in force.

## Context

Orbit uses disposable Incus topologies to prove behavior that Pest cannot
establish: operating-system services, networking, certificates, permissions,
deployment, and convergence on real machines. A complete proof takes several
minutes and produces issue-specific acceptance evidence for one exact commit.

ADR 0006 makes any later candidate commit invalidate that proof. This is safe,
but it also requires complete reproof after a documentation or instruction
correction that cannot affect the proved runtime. The same identity-only rule
prevents retention after an equivalent rebase or after unrelated changes from
`main` are incorporated.

Commit ancestry, a clean Git merge, and changed-path non-overlap do not prove
that the inputs to a proof are unchanged. Selective retention requires a
complete, reproducible record of relevant inputs and a fail-closed comparison
between the proved candidate and the later candidate.

Pest must still run against the combined candidate. It does not replace Incus
acceptance evidence, and general topology convergence does not prove an
issue's acceptance criteria.

## Decision

### Relate proof through recorded inputs

Orbit may retain complete Incus acceptance evidence for a later candidate when
it can establish that every input relevant to that evidence remains
equivalent.

The evidence model distinguishes:

- the **proved candidate**, which is the exact commit that ran every Incus
  proof phase and produced the acceptance evidence;
- the **accepted candidate**, which is the exact later pull-request head being
  evaluated and independently reviewed; and
- the **included main**, which is the exact `origin/main` commit contained in a
  candidate.

A changed SHA does not by itself invalidate proof, and shared ancestry does not
by itself retain proof. Orbit compares immutable Git tree inputs, including
file content and Git mode. Additions, deletions, renames, type changes, and
executable-bit changes are material changes.

### Record an immutable proof-input manifest

Every reusable proof records an immutable, normalized proof-input manifest.
The manifest contains:

- schema and policy versions;
- the proved SHA and included-main SHA;
- feature runtime paths relative to the included main;
- static input paths with Git modes and blob identities;
- proof-plan-declared extra inputs with Git modes and blob identities;
- optional observed PHP inputs grouped by proof phase;
- the collector type and version for each observed set;
- completeness checks and their results; and
- a canonical SHA-256 fingerprint of the normalized manifest.

The complete manifest remains beside the retained proof state. The compact
proof result records its fingerprint. Proof consumers verify the proof result,
proof-plan fingerprint, manifest fingerprint, and applicable completeness
checks before considering retention.

Proof plans may declare repository files or directories as extra inputs when
actions read outside the default runtime policy and active fixture directories.
Those declarations are part of the plan fingerprint. A literal checkout path
read by an action must be a default runtime input or a declared extra input.
Indirect reads performed by arbitrary shell programs remain an explicit
proof-contract responsibility because Orbit cannot infer them safely.

### Begin with a static input policy

The first delivery uses repository-owned path classification. Runtime inputs
include the CLI, Gateway, PHP SDK, E2E harness, E2E entrypoints, active proof
plan, active and referenced proof fixtures, and declared extra inputs. Within
those components, bootstrap, configuration, Composer inputs, executable
entrypoints, migrations, routes, and environment templates used by hydration
are proof inputs where applicable.

The policy classifies maintained documentation under `docs`, all of
`apps/docs`, agent instructions, READMEs, tests and test-only fixtures outside
the active proof contract, and tooling configuration that cannot execute in
the proof topology as non-runtime inputs.

The policy uses positive runtime patterns and explicit exclusions. Every
tracked file in a governed area must have one classification. A new or unknown
path inside a runtime area is indeterminate rather than implicitly excluded.

Under this static policy, any changed runtime input requires complete reproof.
Only changes whose proof inputs remain equal, such as documentation or agent
instruction corrections, may retain the earlier proof.

### Produce an explainable equivalence report

Orbit evaluates the exact current candidate against the retained evidence and
writes an immutable equivalence report bound to the proved and accepted SHAs.
The report lists every changed path, its classification, the resulting status,
and the required promotion path.

The evaluator:

1. verifies the retained proof and its plan and manifest fingerprints;
2. verifies that the accepted candidate includes current `origin/main`;
3. compares the proved and accepted Git trees;
4. classifies every content, mode, add, delete, rename, and type change;
5. compares the current static inventory with the recorded inventory;
6. checks `main` drift against feature runtime paths; and
7. when observed-input reuse is enabled, checks `main` drift against observed
   setup and acceptance inputs.

The report has one of four results:

- `exact` means the accepted SHA is the proved SHA or their complete Git trees
  match;
- `equivalent` means the SHAs or trees differ but no proof-relevant input
  differs;
- `stale` means at least one proof-relevant input differs; and
- `indeterminate` means Orbit cannot establish equivalence safely.

`Stale` and `indeterminate` require complete reproof. Every refusal names the
proved SHA, accepted SHA, material paths or completeness failure, and required
next action.

### Add observed-input reuse only as a later delivery

A later delivery may use complete, file-level PHP observations to retain
feature acceptance evidence across unrelated runtime changes. This mode keeps
bootstrap, auto-discovery, Composer inputs, configuration, migrations, routes,
scripts, harness inputs, proof contracts, fixtures, and declared extra inputs
as mandatory static inputs. It may replace the broad ordinary-PHP-source set
with feature runtime paths and complete phase-specific observations.

The collector uses PCOV only inside Incus. It collects from every role and
process that executes Orbit PHP, unions concurrent process results, normalizes
guest paths to repository paths, and treats an observed file as a complete
input. Orbit never uses line-level equivalence.

PCOV stays disabled by default and is disabled again before final topology
verification. The production installer and production convergence executor
never install, enable, or depend on it. A missing extension, process result,
unknown path, failed aggregation, stale output, or failed cleanup makes the
observed graph incomplete and forces complete reproof. A proof contract may
also refuse observed-input reuse when timing or another property makes
instrumentation unsuitable.

### Preserve the complete proof lifecycle

The initial proof flow is:

1. Develop on discovery and run focused checks during implementation.
2. Fetch `origin/main` immediately before proof.
3. If `main` moved, merge or rebase it into the feature branch and rerun
   affected checks. Otherwise, continue.
4. Commit a clean candidate.
5. Create a fresh proof attempt from the current shared topology snapshot.
6. Synchronize the exact candidate commit.
7. Verify clean guest checkout identity.
8. Install the active proof fixtures.
9. Run the complete E2E topology convergence sequence.
10. Run declared proof setup actions.
11. Run every issue-specific acceptance action.
12. Run general topology verification.
13. Record the proof result and proof-input manifest.
14. Freeze the successful proof topology through review and merge.

Full E2E topology convergence is the current fixed `TopologyConverger`
sequence. It aligns identities, prepares and bootstraps the Gateway, authorizes
node access, retargets VPN links, provisions app roles, configures the CLI,
creates or reconciles sample resources, reprojects product state, reconciles
Metrics, hydrates sample applications, and normalizes permissions. It is not a
future per-pull-request release plan.

After a non-runtime correction, the flow is:

1. Commit the documentation, `apps/docs`, or instruction change.
2. Run the applicable documentation and project checks.
3. Evaluate the new exact head against the retained proof.
4. Require an `equivalent` report with no changed proof input.
5. Keep the earlier complete Incus proof.
6. Review and approve the new exact head.
7. Merge that head with the existing match-head gate.
8. Verify that the merge commit has the accepted tree.
9. Promote the retained proof topology under the equivalent-input contract.
10. Record the proved SHA, accepted SHA, merged SHA, and runtime fingerprint in
    the promoted generation.
11. Release proof and discovery resources.

The promoted topology snapshot may retain the proved checkout's non-runtime
files. Its prepared runtime fingerprint matches the merged commit. A later
acquisition synchronizes its own exact candidate before convergence.

The unrelated-runtime-drift flow becomes available only after observed-input
collection and its completeness gates are enabled:

1. Update the feature branch with current `origin/main`.
2. Run Pest and project checks against the combined candidate.
3. Compare `main` drift with feature runtime paths, observed proof inputs, and
   mandatory static inputs.
4. If an input intersects, follow complete reproof. If none intersects, retain
   the earlier feature acceptance evidence.
5. Copy the current shared topology snapshot into a new candidate-convergence
   topology.
6. Synchronize the exact combined candidate.
7. Verify clean guest checkout identity.
8. Run full E2E topology convergence.
9. Run general topology verification.
10. Do not install feature proof fixtures or rerun feature setup or acceptance
    actions. The retained proof supplies that evidence.
11. Freeze the successful candidate-convergence topology.
12. Review the exact combined head against the retained proof, equivalence
    report, convergence report, and topology verification.
13. Merge only the exact approved head and verify the merge tree.
14. Promote the candidate-convergence topology as the next shared snapshot.
15. Release the old proof, discovery, and candidate resources.

This path composes two kinds of evidence. The candidate-convergence topology
supplies convergence evidence for the exact combined candidate. The retained
proof supplies issue acceptance evidence for the unchanged recorded inputs. A
candidate-convergence topology can never be presented as issue acceptance
proof.

The relevant-runtime-drift flow is:

1. Update the feature branch with current `origin/main`.
2. Run Pest and project checks.
3. Record `stale` or `indeterminate` with the exact material paths or failure.
4. Release the old proof attempt.
5. Run the complete initial proof flow for the new combined candidate,
   including convergence, fixtures, setup, acceptance, and verification.
6. Review, merge, promote, and clean up against the new proof.

An exact or equivalent candidate with no runtime drift uses the
`retained-proof` promotion path. An equivalent candidate with unrelated runtime
drift uses `candidate-convergence`, but only after observed-input collection
and its completeness gates are enabled. A relevant runtime change always
requires the complete proof flow.

### Preserve exact review, merge, and evidence gates

Selective proof retention does not transfer review approval between heads.
Independent review remains bound to one exact remote head, and every new head
requires a new review pass. Review verifies the retained proof, proof-input
manifest, equivalence report, and any candidate-convergence evidence.

A later movement of `origin/main` requires another current-main and equivalence
decision. Merge retains the exact match-head gate and verifies that the merge
commit has the accepted tree. Promotion requires the exact accepted tree and
complete zero-exit evidence for its selected promotion path.

Successful proof evidence remains immutable. Promotion lineage records the
proved SHA, accepted SHA, merged SHA, input fingerprint, and promoted runtime
state. A failed convergence or verification attempt is diagnosis evidence,
cannot be promoted, and must be released explicitly before another candidate
attempt.

The promoted shared topology snapshot represents the accepted runtime state.
Promotion and cleanup continue to use exact attempt inventories. Proof,
discovery, convergence, and replaced shared resources are released according
to their recorded ownership and purpose.

### Preserve the production boundary

Disposable Incus proof and convergence remain development evidence. They never
reuse production resources or authorize a production deployment. Production
release uses separate credentials, resources, execution, verification, and
journaling, and it never depends on Incus-only instrumentation.

A future product convergence plan may expose stable product steps to both
Incus and production through different executors. That future reuse does not
make disposable topology state or evidence into production state or release
authority.

## Consequences

- Documentation, `apps/docs`, and agent-instruction corrections can change a
  candidate SHA without forcing complete Incus reproof when all recorded proof
  inputs remain equal.
- Equivalent trees can retain proof across a rebase without treating ancestry
  as evidence.
- Static classification delivers a narrow initial optimization while unknown
  or changed runtime inputs continue to fail closed.
- File-level observation can later retain acceptance evidence across unrelated
  runtime drift, but it adds collector, completeness, and candidate-convergence
  obligations.
- Review, merge, and promotion must reason about proved, accepted, included-main,
  and merged identities rather than one candidate SHA.
- Proof records gain versioned manifests, canonical fingerprints, equivalence
  reports, explicit promotion paths, and complete lineage.
- Pest remains required for the combined candidate, and complete Incus reproof
  remains required whenever relevant inputs differ or equivalence cannot be
  established.
- General convergence evidence and issue acceptance evidence remain distinct.
- Production deployment remains separate and has no PCOV or disposable-Incus
  dependency.
