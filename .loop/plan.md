# Feature plan

Plan format: 1
Issue: ORB-200
Flow: discovery
Review verdict: PASS

## Outcome

Doctor reports bounded, read-only drift for production release placement, persistent environment projection, dedicated PHP runtime identity, and workload Caddy projection without treating branch movement or application health as managed intent.

## Code boundaries

In:
- `apps/gateway/app/Domain/Doctor/InstanceInspectionData.php`, `InstanceStateInspector.php`, `InstanceDoctorIssueCode.php`, and production-specific typed observation or expectation objects beside them: retain the existing development checkout fields and add explicit production layout, selected-release root, environment projection, dedicated PHP identity, and workload Caddy results with stable instance-family codes and no raw paths, values, commands, or diagnostics.
- `apps/gateway/app/Actions/Doctor/InstanceDoctorProbe.php`: branch comparison policy by the recorded AppInstance environment, detect missing or cross-AppInstance shared production service, pool, and socket associations from Gateway intent, preserve lifecycle and unreachable-node handling, map every false or unverifiable production result in stable order, and keep one checked resource per AppInstance.
- `apps/gateway/app/Infrastructure/Doctor/NativeInstanceStateInspector.php` and narrow production inspection helpers under `apps/gateway/app/Infrastructure/Doctor/`: derive expected production home, release-layout, environment, runtime, and workload Caddy state from the AppInstance, its conversion checkpoint, sole Route, stored environment snapshot, `ProductionPhpRuntimeIdentity`, `ProductionPhpRuntimeConfigRenderer`, `AppDevSiteRepository`, and `AppDevCaddyConfigRenderer`; execute only bounded read-only commands through the fixed SSH boundary; compare sensitive environment content through protected input; accept a missing `current` before first selection; validate an existing selection beneath `releases/`; validate effective identity without comparing permitted `local.conf` tuning with seeded defaults; and retain the existing development apps-root checkout inspection unchanged.
- `apps/gateway/tests/Feature/Domain/InstanceDoctorProbeTest.php`, `apps/gateway/tests/Feature/Infrastructure/Doctor/ApplicationStateInspectorsTest.php`, and `apps/gateway/tests/Unit/Domain/DoctorReportContractTest.php`: cover stable issue ordering and code registration; healthy prepared and selected release layouts; broken, missing, escaped, and foreign roots; production-home ownership; missing and mismatched secret-safe environment projection; missing, shared, wrong-user, wrong-socket, and invalid-effective-identity PHP associations; allowed local tuning; standalone and Cluster workload Caddy projections; flat, failed-conversion, incomplete-deployment, unreachable, and malformed observations; development containment regression; and absence of raw values and paths from serialized reports.

Out:
- Repair, convergence, adoption, release selection, environment synchronization, service reload or restart, cache reset, Caddy publication, and any other Doctor-owned mutation stay excluded.
- Deployment execution, deployment history, retained-release cleanup, conversion recovery, rollback behavior, and deployment checkpoint ownership stay unchanged; Doctor only observes their current result.
- Remote branch fetches and branch-head freshness comparisons stay excluded; a retained selected release remains valid after branch movement or explicit rollback.
- Application HTTP requests, status interpretation, framework-cache inspection, application database checks, dependency checks, and application health gates stay excluded.
- The `process` and `schedule` Doctor families, their issue catalogs, inspectors, ordering, and findings stay unchanged; production observations remain in the existing `instance` family.
- Production provisioning, cloning, conversion, deployment, environment, runtime, Route, Router, certificate, firewall, and Caddy mutation paths stay unchanged and remain the sources of managed intent.
- The Incus harness under `apps/e2e` and `bin/e2e-*` stays unchanged; discovery uses its existing topology and `shell`, `exec`, `sync`, and `verify` surfaces.

## Documentation

- `docs/reference/deployments.md`: now states the production-home, `current`, selected-release, and effective-root findings; accepts a prepared target with no first selection; and excludes branch-head, rollback age, deployment-history, and HTTP-health comparisons.
- `docs/reference/environment-variables.md`: now states that Doctor compares the persistent `.env` with rendered Gateway intent through a value-redacted, read-only observation and excludes framework cache state and synchronization.
- `docs/reference/php-runtime.md`: now states the dedicated association, user, socket, effective PHP-FPM identity, and workload Caddy findings for standalone and Cluster placements, while accepting local tuning that preserves generated identity.
- `docs/generated/context.json`: regenerated so the three changed references include their new Doctor, Cluster, and Router concept context.
- Documentation audit scope: ORB-200 with component `apps/gateway` and the named AppInstance, Doctor, Cluster, and Effective web root concepts. Fixed drift: none; the pages above were extended from the issue and its accepted ADRs before code exists. Audited without changes: `docs/architecture.md`, `docs/concepts.md`, `docs/domains/applications.md`, `docs/reference/routes.md`, and `docs/solutions/doctor-incus-proof.md` already preserve their authority and do not contradict the current code or ORB-200. Reported findings: none.
- Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check` passed. All documentation changes are committed as `689f7785f38a9f4e531a5befb8cb202d2a5615a5` (`docs: describe production Doctor findings`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Doctor accepts a prepared production target with no selected release, distinguishes a broken `current` pointer, and reports escaped or missing selected roots, invalid home ownership, and a missing persistent environment projection without mutation. | Production layout and environment expectations in the typed instance observation, native inspector, probe mapping, and stable issue catalog. | Reproducible discovery observation `production-doctor-layout`, backed by `InstanceDoctorProbeTest.php`, `ApplicationStateInspectorsTest.php`, and existing release-layout tests through `cd apps/gateway && composer test:affected`. |
| 2. Doctor reports missing or shared dedicated PHP associations, wrong runtime user or socket, invalid effective identity, and workload Caddy drift while accepting valid local tuning differences. | Gateway-side association cardinality in `InstanceDoctorProbe`; `ProductionPhpRuntimeIdentity`, rendered generated identity, effective PHP-FPM inspection, and aggregate workload Caddy comparison in the native inspector. | Reproducible discovery observation `production-doctor-runtime`, backed by the focused instance probe and native inspector cases plus existing production runtime and Route projection tests through Gateway TIA. |
| 3. A retained release behind its branch head, an explicit rollback, or HTTP 500 is not drift, and environment comparison exposes no values or framework-cache requirement. | Release comparison intentionally omits remote Git and HTTP; environment intent is rendered from stored values and Route context, sent only as protected input, and reduced to a boolean observation. | `apps/gateway/tests/Feature/Domain/InstanceDoctorProbeTest.php` through `cd apps/gateway && composer test:affected`, including report serialization assertions that secret values and source details are absent. |
| 4. Valid, failed-conversion, incomplete-deployment, and unreachable production targets yield bounded family findings without reloads, cache resets, repair, or mutation. | Conversion-aware production expectation selection, fixed read-only SSH commands, typed parse failure, existing node-unreachable short circuit, and bounded instance-family report data. | Reproducible discovery observation `production-doctor-read-only`, backed by failure and malformed-result cases in `ApplicationStateInspectorsTest.php`, probe continuation/unreachable cases, and the exact-candidate Builder gate. |
| 5. Maintained documentation describes release-aware findings and local tuning boundaries, with current generated context. | The four documentation artifacts listed above. | `composer docs-build` and `composer docs-lint`. |
| 6. A healthy production home and selected release bypass development apps-root containment, while development checkouts still enforce it and escaped or foreign production paths remain findings. | Environment-specific path policy in `NativeInstanceStateInspector`, retaining the existing `CheckoutRemovalBoundary` development root and adding production-home/release containment. | `apps/gateway/tests/Feature/Domain/InstanceDoctorProbeTest.php` and reproducible discovery observation `production-doctor-layout`, backed by native inspector path fixtures through Gateway TIA. |
| 7. Focused acceptance tests and the exact-candidate quality gate pass. | All changed Gateway boundaries and the exact clean candidate. | `cd apps/gateway && composer test:affected`, `cd apps/gateway && composer check`, and `git diff --check`; after the candidate is committed, the retained Builder runs root `composer check` across all five projects with TIA and records the exact-head receipt. |

## Incus observations

- Incus: required. Flow: discovery. After independent plan approval, acquire the ORB-200 discovery topology. Do not create a proof plan, proof fixture, immutable proof result, observed-input manifest, or separate proof topology.
- Run `production-doctor-layout` against standalone and Cluster-scoped production AppInstances. Record a healthy prepared home with no `current`, then a healthy retained selection. In isolated setup steps, exercise a dangling `current`, a selection outside `releases/`, a missing selected directory, wrong production-home ownership, a missing persistent `.env`, and a production root that escapes its selected release. Restore each fixture and confirm the family returns healthy. Repeat the equivalent escaped-path case for a development AppInstance and confirm its existing apps-root containment still reports drift.
- Run `production-doctor-runtime` with two healthy production PHP AppInstances on one Node and record distinct service, pool, socket, master PID, and Caddy upstream identities. Exercise a missing association, a shared association, wrong service user, wrong socket ownership or path, a `local.conf` directive that overrides generated identity, and a workload Caddy fragment mismatch. Also change an allowed tuning value such as `pm.max_children`, confirm Doctor stays healthy, and restore every fixture without using Doctor as the repair path.
- Run the non-drift part of `production-doctor-layout` after the configured remote branch advances and after explicitly selecting an older retained release. Make the selected application return HTTP 500 and confirm the release and instance findings remain healthy because Doctor fetches no branch and makes no application-health request.
- Run `production-doctor-read-only` across a healthy target, a conversion record stopped at a failed checkpoint, a deployment stopped before and after activation, and an unreachable workload Node. Before and after each Doctor call, compare production-home file identities and hashes, Git state, the conversion checkpoint, AppInstance release selection, service PIDs and restart counters, socket identity, Caddy configuration, environment contents, and relevant table counts. Permit only the normal request-audit Activity row; require no service reload, cache reset, file repair, source change, or persisted Doctor finding.
- Finish discovery with `bin/e2e-topology sync ORB-200` and `bin/e2e-topology verify ORB-200`. Record the exact commands, exit codes, bounded findings, setup, restoration, and state comparisons in `.loop/development.md`, and state `Discovery development only; isolated acceptance proof not run` in the implementation handoff.

## Implementation order

1. Extend the instance-family code catalog and typed observation data, then add failing probe and report-contract tests for every production condition, stable ordering, shared association, secret redaction, unreachable handling, and unchanged development behavior.
2. Add focused native-inspector tests for prepared, selected, escaped, missing, flat, failed-conversion, and incomplete-deployment layouts; stored environment equality and mismatch; dedicated runtime identity and permitted tuning; standalone and Cluster workload Caddy projections; malformed output; and fixed read-only command shape.
3. Build production expectations from the recorded AppInstance, conversion checkpoint, sole Route, stored environment snapshot, dedicated runtime identity and rendered configuration, and aggregate workload Caddy rendering. Fail invalid or unavailable intent into bounded inspection results without exposing the invalid value.
4. Extend `NativeInstanceStateInspector` with an environment-specific production read path that validates home and release containment, compares environment and generated projections through protected input, inspects only effective PHP identity fields, and parses a fixed boolean tuple. Keep the existing development command and apps-root policy intact.
5. Extend `InstanceDoctorProbe` to check dedicated association completeness and uniqueness before native inspection and to emit the new production findings in stable order while preserving checked counts, continuation after per-resource failures, and one node-unreachable finding.
6. Run Gateway TIA and project checks, then acquire discovery after plan approval and execute the three named observations with complete restoration and read-only comparisons. Commit only implementation and test changes, let the retained Builder run root `composer check` on the exact clean candidate, and publish the plan and `.loop/development.md` through the separate candidate-bound artifact for independent code review.

## Must preserve

- ADR 0004: Doctor remains synchronous, verify-only, in-memory, deterministic, and bounded; it uses only database reads and explicitly read-only commands, writes only the normal request-audit Activity row, keeps stable instance-family codes and ordering, exposes no raw output, paths, branch names, environment values, commands, URLs, or exceptions, and turns unknown internal codes into `instance.inspection_failed`.
- ADR 0011 as superseded by ADR 0046: the production user's home remains the workload placement base, while the development apps-root rule stays development-only; standalone and Cluster routing do not move workload release ownership from its app-prod Node.
- ADR 0029 and ADR 0044: the Route remains the canonical Laravel URL authority; Gateway-stored environment configuration remains encrypted and authoritative; Doctor only compares the rendered projection, preserves unrelated settings, reveals no values, and does not synchronize files or refresh framework caches.
- ADR 0031 as superseded by ADRs 0046 and 0047: initial and cloned source evidence remains unchanged; Doctor performs no Git mutation and does not turn branch movement into drift.
- ADR 0045: every production PHP AppInstance owns a separate service, pool, socket, and OPcache identity; installed packages remain shared; local tuning stays operator-owned and accepted when it cannot change generated identity; Doctor never reloads, restarts, rewrites, or resets another runtime.
- ADR 0046: production release selection remains explicit and atomic; persistent `.env` and optional SQLite stay outside releases; the effective root stays beneath the selected release; retained rollback code is valid without branch freshness; Doctor records no deployment history and runs no application command, recovery, rollback, or health check.
- ADR 0047: candidate cloning, independent environment duplication, optional SQLite snapshot, private preview Route, and separate first deployment remain unchanged; Doctor does not modify the candidate, target data, processes, schedules, or application state.
- ADR 0053 under the current ADR 0059 process: focused acceptance tests and independent review remain required, and the retained Builder owns root `composer check` across all five projects on the exact clean candidate; GitHub checks remain disabled and are not a merge gate.
- Existing instance-family lifecycle, source-layout, migration, origin, source-identity, checked-count, stable ordering, failure isolation, and node-unreachable behavior; binary Node authorization; fixed SSH identity and deadline; protected secret transport; AppInstance operation locks; and all non-instance Doctor families remain unchanged.

## Open questions

none

## Deviations

none

## Review findings
