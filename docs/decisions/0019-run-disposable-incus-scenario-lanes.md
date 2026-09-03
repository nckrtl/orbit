# ADR 0019: Run disposable Incus scenario lanes

## Status

Accepted on 2026-09-03.

If accepted, this decision extends the rolling Incus topology in
[ADR 0005](0005-rolling-incus-development-topology.md), the separation of
discovery and proof in
[ADR 0006](0006-topology-led-feature-development.md), and the observed-input
model in
[ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md). Their
feature-proof, promotion, exact-cleanup, and production-separation boundaries
otherwise remain in force.

## Context

Orbit's Incus harness proves issue-specific acceptance criteria against an
exact candidate commit. A successful proof remains immutable through review
and can become the promoted topology snapshot after merge. That lifecycle is
deliberately unsuitable for a reusable regression suite: scenarios are not
owned by one Linear issue, must run repeatedly against later commits, should
continue after another independent scenario fails, and must never authorize
topology-snapshot promotion.

Orbit previously had `bin/e2e-live`, a validation-clone wrapper around live
Pest acceptance. It checked out an exact candidate, exercised cold topology
construction and the public harness commands, recorded phase timings, and
cleaned exact resources. Its final form also owned a second persistent `live`
topology snapshot. That command and namespace were removed when Orbit adopted
one persistent promoted topology snapshot. Restoring the second persistent
snapshot would reintroduce conflicting ownership, but the exact-candidate,
Pest-reporting, timing, and cleanup patterns remain useful.

Regression scenarios need two materially different starting points. Some must
prove that Orbit can construct its complete topology from the generic Ubuntu
26.04 base image. Others need an already established topology so they can
exercise app, Cluster, role, Metrics, failure, recovery, and scale-out flows
without paying the cold-construction cost each time.

The current topology profile also equates three physical VM identities with
the `gateway`, `app-dev`, and `app-prod` role names. That is sufficient for the
feature-development topology but cannot describe a roleless operator becoming
an app-dev Node, an additional Node joining an existing topology, or more than
one Node carrying the same workload role.

## Decision

### Keep regression scenarios separate from feature proof

Orbit introduces a scenario lifecycle beside discovery, proof, and candidate
convergence. A scenario run is disposable regression evidence for one exact
repository commit. It is never issue acceptance evidence, never satisfies an
Incus proof requirement, and never authorizes topology-snapshot promotion or
production release.

A scenario is a committed, stable contract with a unique ID, starting lane,
topology recipe, setup, exercise and assertion actions, bounded deadlines,
declared non-PHP inputs, expected end state, and optional observed-input
collection. Its normalized definition has a fingerprint recorded in every
result.

### Use cold and snapshot lanes

Every scenario declares exactly one starting lane:

- The **cold lane** creates a fresh, isolated topology from the configured
  `orbit-base-ubuntu-26.04-runtime` image alias. It synchronizes the exact
  candidate source and progressively exercises the real construction flow:
  establish the operator environment, bootstrap the Gateway, provision Nodes,
  assign roles, converge services and product projections, and verify the
  declared final topology. It does not read, replace, refresh, or promote the
  persistent topology snapshot.
- The **snapshot lane** creates a fresh, isolated copy of the current promoted
  topology snapshot used by feature development. It synchronizes and converges
  the exact candidate before running the scenario. The promoted generation is
  an immutable input to the run and is never modified by it.

The lane names describe the starting state of the topology. A snapshot-lane
scenario may attach a fresh base-image Node to test scale-out, but it remains a
snapshot scenario because its established Gateway and existing Nodes came from
the promoted generation.

Cold construction and persistent topology-snapshot construction share one
typed construction service. The caller supplies the exact target, recipe,
source identity, ownership metadata, and persistence policy. The persistent
builder retains its fixed identity, manifest, recovery, and promotion rules.
The scenario caller uses attempt-scoped identities, writes no promoted
manifest, and owns unconditional exact cleanup. Orbit does not restore the
removed `live` topology-snapshot namespace.

### Separate physical Nodes from assigned roles

A topology recipe declares a bounded ordered set of physical VM identities.
Each entry declares its stable scenario-local Node key, base source, initial
purpose, and expected role assignments. Role assignment is state of a Node,
not the identity of its VM.

The registered three-Node feature topology remains the default recipe, with
Gateway, operator/app-dev, and app-prod Nodes. A scenario recipe may add
roleless Nodes or multiple Nodes with the same workload role. It may also
declare that an operator Node receives `app-dev` or another role during the
flow. The recipe never permits a scenario to adopt a pre-existing or
unrecorded VM.

Capacity checks count the recipe's actual VM count rather than assuming three
VMs. Network addresses, MAC addresses, Incus names, guest machine identities,
and Orbit Node names derive deterministically from the run, scenario, attempt,
and Node key. Every external identifier remains bounded and validated before
Incus mutation.

### Make one Pest test one independent flow

Pest is the scenario assertion and reporting surface. One independently
meaningful flow is one Pest test. A flow stops after its first failed required
step because later actions on the same broken topology would usually produce
cascade failures. Cleanup still runs, and the suite continues with other
independent tests.

Tests never depend on the mutation left by another test. A flow that requires
a particular established state receives a fresh topology from its declared
lane or from an immutable, run-scoped checkpoint whose complete construction
passed. If a required shared checkpoint cannot be constructed, dependent flows
are reported as `blocked`; they are not misreported as product failures.

The suite records `passed`, `failed`, `blocked`, or `infrastructure-error` for
every selected scenario. It records the exact candidate SHA, run and attempt
identities, lane, recipe and scenario fingerprints, action results, phase
timings, verification result, diagnostics, cleanup result, and applicable
observed inputs. The suite process returns nonzero only after every runnable
scenario has completed and the aggregate result has been written. Machine-
readable JSON and standard test-report output identify each failed flow
without requiring log parsing.

### Run isolated topologies concurrently within host capacity

Every running scenario owns a distinct attempt, network, VM inventory, state
root, and result. Scenario execution for one exact candidate may run in
parallel. Creation briefly uses the existing host creation lock so capacity
and network-slot selection remain atomic; guest convergence and scenario
actions proceed independently after creation.

The runner accepts an explicit bounded worker count and refuses work that
would exceed the configured live Incus VM budget. Cold, snapshot, and
variable-size recipes share that budget. A scheduler may place additional
limits on expensive cold scenarios without defining another semantic lane.

Concurrent scenarios may share immutable repository objects and the promoted
snapshot as sources. They never share a mutable checkout, state directory,
topology, guest filesystem, or application database. One scenario's failure or
cleanup does not cancel an unrelated running scenario.

### Preserve faithful cold behavior and scoped observation

The primary cold scenario represents construction from the unchanged generic
base image. It does not preinstall or enable PCOV because doing so would alter
the machine before Orbit's installation and provisioning paths execute. An
instrumented cold variant, if introduced later, is distinct evidence and
cannot replace the faithful cold result.

Snapshot scenarios may enable the existing fail-closed, file-level PCOV
collector. Observations are recorded per scenario and include the required
role and process surfaces, runtime inventory, completeness checks, and
repository-relative PHP paths. Shell, service-manager, package, networking,
configuration, fixture, and other non-PHP dependencies remain declared static
inputs.

Observed paths can later map changed files to scenarios that exercised them.
That map is initially selection and diagnostic information, not proof that an
unobserved pull-request change is irrelevant. Unknown paths, incomplete
observation, changed bootstrap or configuration boundaries, and changes to
scenario or harness contracts fail closed to broader scenario selection.

### Clean up every scenario by exact recorded inventory

Scheduled and on-demand scenario runs attempt cleanup whether setup, exercise,
assertion, verification, or reporting succeeds or fails. Cleanup revalidates
ownership and removes only the exact instances, devices, networks, and
run-scoped checkpoints recorded for that attempt. It never deletes by prefix,
glob, age, or unresolved environment value.

A cleanup failure is retained beside the primary scenario result and makes the
scenario infrastructure-invalid. It does not authorize broader deletion and
does not hide the original product failure. The aggregate run reports every
remaining exact resource and the supported cleanup action.

### Keep scheduling and pull-request selection as later integrations

The scenario runner is an explicitly invoked, on-demand command for one exact
commit with an optional scenario filter and bounded concurrency. The feature-
development flow does not invoke it. Discovery acquisition, synchronization,
proof, equivalence evaluation, candidate convergence, review, merge, and
topology-snapshot promotion neither start nor require a scenario run. Scenario
results do not become an implicit feature gate.

Nightly scheduling, pull-request triggering, and affected-flow selection are
future integrations requiring their own accepted contract. If introduced,
they invoke the same scenario command and do not create another execution
model. A future pull-request selector may use scenario definitions, declared
inputs, and complete observations to choose likely affected flows, while a
future nightly run may continue to execute the complete suite.

## Consequences

- Orbit gains faithful cold-construction regression evidence without deleting,
  replacing, or duplicating the feature-development topology snapshot.
- Scenario testing is available on demand without extending or delaying the
  feature-development workflow.
- Snapshot scenarios can cover established-topology behavior cheaply while
  remaining isolated from feature discovery and proof.
- Physical Node identity no longer has to equal a role name, enabling
  roleless operators, additional Nodes, scale-out, role movement, and multiple
  Nodes with the same workload role.
- Independent Pest tests and aggregate reporting reveal all runnable failing
  flows in one run instead of stopping the suite at the first failed lifecycle
  assertion.
- Parallel execution shortens the full suite but makes variable-size capacity
  accounting, attempt-scoped state, and exact cleanup mandatory.
- PCOV can build per-scenario PHP input maps for prepared topologies, while the
  faithful cold path remains unmodified and non-PHP inputs remain explicit.
- The E2E harness needs a scenario contract, variable-size topology recipe,
  disposable cold constructor, scenario state and result model, bounded suite
  runner, and focused automated coverage before live acceptance.
- Scenario evidence remains regression evidence only. Issue acceptance,
  review, promotion, and production release keep their existing independent
  authorities.
