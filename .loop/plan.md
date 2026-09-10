# Feature plan

Plan format: 1
Issue: ORB-232
Flow: discovery
Review verdict: PASS

## Outcome

Metrics enables exporters only for active Nodes on the supported managed-node platform with Gateway-owned WireGuard and SSH identity, while eligible managed Nodes without roles can still opt in.

## Code boundaries

In:
- Exporter eligibility, enable-request validation, stored preference interpretation, fleet projection and convergence, and Doctor expectations change only in `apps/gateway/app/Domain/Nodes/`, `apps/gateway/app/Domain/Metrics/`, `apps/gateway/app/Infrastructure/Metrics/`, `apps/gateway/app/Actions/Doctor/`, and the matching files under `apps/gateway/tests/`. Introduce one read-only managed-Node eligibility policy based on the existing stored Linux platform, managed WireGuard address, and pinned SSH host identity; make exporter selection and projection exclude ineligible records before any exporter runtime operation; refuse enablement before preference mutation or fleet reconciliation; and let Doctor suppress exporter and SSH expectations only for ineligible records while keeping ordinary managed-Node observation failures.

Out:
- Client enrollment and platform support do not change; no client API, registration path, platform allow-list, migration, or documentation promise is added.
- Exporter preference never converts an ineligible record into a managed Node; Node identity, role assignment, and provisioning flows stay unchanged.
- Grafana routing, authorization, publication, and access behavior stay unchanged.
- Active and pending Grafana credential storage, reset, verification, and redaction stay unchanged.
- `apps/e2e/` and `bin/e2e-*` stay unchanged; the existing topology and native discovery commands provide the real-machine observation.

## Documentation

- `docs/reference/metrics.md`: now states the managed-Node eligibility boundary, preference behavior for eligible and ineligible records, refusal before preference or remote work, and Doctor expectations.
- `docs/architecture.md`: now distinguishes disposable discovery observations from a separate fresh proof topology according to the selected delivery flow.
- `docs/tech-stack.md`: now states the focused local checks, independent root gate with test impact analysis, and disabled GitHub continuous integration.
- `docs/generated/context.json`: rebuilt after the Metrics ADR link and documentation-context concepts changed.
- Documentation audit fixed the three drift findings above. No findings remain to report to another owner.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Eligible active managed Nodes keep role defaults and explicit roleless opt-in; inactive, unsupported, and unmanaged fixtures stay excluded for every preference. | `apps/gateway/app/Domain/Nodes/`, `apps/gateway/app/Domain/Metrics/ExporterSelector.php`, `apps/gateway/app/Infrastructure/Metrics/NativeMetricsExporterProjection.php` | `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Domain/Metrics/ExporterSelectorTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterProjectionTest.php` |
| 2. Ineligible enablement fails before preference mutation or remote work, and stored enabled intent cannot bypass selection. | `apps/gateway/app/Infrastructure/Metrics/NativeMetricsRoleManager.php`, managed-Node eligibility and Metrics projection boundaries, `apps/gateway/tests/Feature/Api/MetricsRoutesTest.php`, `apps/gateway/tests/Feature/Infrastructure/Metrics/NativeMetricsRoleManagerTest.php` | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/MetricsRoutesTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsRoleManagerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterProjectionTest.php`; the refusal cases assert the Setting row is unchanged and the fleet/runtime spies receive no call. |
| 3. Reconciliation never inspects or mutates an ineligible exporter and its failure cannot block eligible Nodes. | Metrics projection and `apps/gateway/app/Infrastructure/Metrics/NativeMetricsExporterLifecycle.php` | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Infrastructure/Metrics/NativeMetricsExporterLifecycleTest.php`; transport spies assert no snapshot, install, configuration, service, firewall, or removal call for the ineligible fixture and successful convergence for eligible fixtures. |
| 4. Doctor omits exporter and SSH findings for an ineligible record, preserves eligible exporter findings, stays verify-only, and keeps a managed Node's temporary SSH failure unverifiable. | `apps/gateway/app/Actions/Doctor/`, Metrics projection consumed by `apps/gateway/app/Infrastructure/Metrics/NativeMetricsFirewallExpectationProvider.php`, and matching Doctor tests | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Domain/NodeDoctorProbeTest.php tests/Feature/Domain/RunDoctorActionTest.php tests/Feature/Domain/FirewallDoctorProbeTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFirewallExpectationProviderTest.php tests/Feature/Api/DoctorTest.php`; assertions cover no mutating collaborator calls or persisted state changes. |
| 5. A supported managed roleless Node can enable its exporter, expose host metrics, enter the scrape projection, and leave it on disable. | The same Gateway eligibility, Metrics manager, projection, lifecycle, and status boundaries | After plan review, acquire the required topology and run the `managed-roleless-exporter-lifecycle` discovery observation described below, ending with `bin/e2e-topology verify ORB-232`. |
| 6. Existing role defaults, Metrics-Node default, explicit disable, and retry behavior remain valid inside the eligible fleet. | `apps/gateway/app/Domain/Metrics/`, `apps/gateway/app/Infrastructure/Metrics/`, and their existing selector, lifecycle, fleet reconciler, status, and role-manager tests | `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Domain/Metrics/ExporterSelectorTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterLifecycleTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFleetReconcilerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsRoleManagerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsStatusReaderTest.php` |
| 7. Metrics guidance states only the supported managed-Node contract and generated context is current. | `docs/reference/metrics.md`, `docs/architecture.md`, `docs/tech-stack.md`, `docs/generated/context.json` | From the repository root: `composer docs-build && git diff --exit-code -- docs/generated/context.json && composer docs-lint`; documentation is committed in `e5d79193`. |
| 8. Gateway quality checks pass. | All changed files under `apps/gateway/` | `cd apps/gateway && composer check`; the independent reviewer later runs root `composer check` as the local review gate. |

## Incus observations

Flow: `discovery`. Incus is required because acceptance depends on Ubuntu 26.04, SSH, systemd, firewall state, and live Prometheus scraping. Proof instrumentation is not required, and no `.loop/proof/ORB-232.json`, fixture, observed-input manifest, exact-commit proof, or separate proof topology is planned.

After independent plan review, the implementer acquires and synchronizes the existing `gateway_app-dev_app-prod` topology with `bin/e2e-topology acquire ORB-232 /fast/worktrees/orbit/orb-232` and `bin/e2e-topology sync ORB-232`. For the reproducible `managed-roleless-exporter-lifecycle` observation, the implementer uses the CLI on the `app-dev` checkout Node to resolve the `app-prod` Node ID, removes its `app-prod` role with `node:role:remove --force --json`, and confirms it remains active with managed WireGuard and pinned SSH identity but has no roles. The implementer then:

1. runs `metrics:exporter:enable app-prod --json` and confirms the preference and Metrics status select the roleless Node;
2. uses `bin/e2e-topology exec` on `app-prod` to confirm `prometheus-node-exporter` is active, bound to its WireGuard address, returns `node_exporter_build_info`, and has the Metrics-owned firewall rule;
3. queries Prometheus on the Metrics Node until the `app-prod` target is healthy and host metrics are present;
4. runs `metrics:exporter:disable app-prod --json` and confirms status and Prometheus no longer select it and its exporter-owned service configuration and firewall rule are absent; and
5. runs `orbit doctor --node=<app-prod-id> --json` to confirm the now-roleless but eligible managed Node remains observable without exporter drift, then runs `bin/e2e-topology verify ORB-232`.

This is discovery development only; isolated acceptance proof is not run. The implementer reports every command and observed result in the development handoff and leaves topology release to the assigned delivery flow.

## Implementation order

1. Add the read-only managed-Node eligibility policy and focused fixtures for active supported managed, inactive, unsupported-platform, and unmanaged records.
2. Apply the policy to exporter selection and projection so every stored preference on an ineligible record yields no runtime candidate, target, or Metrics firewall expectation while eligible defaults remain unchanged.
3. Guard exporter enablement before the preference write and reconciliation call, with API and manager tests that prove the bounded refusal and zero side effects.
4. Update fleet lifecycle and status behavior only where needed to ensure an ineligible record is never inspected or mutated and cannot block eligible convergence; retain existing degradation and retry semantics for eligible Nodes.
5. Apply the same eligibility result to Node Doctor reporting and Metrics-owned Doctor expectations, preserving verify-only behavior and managed-Node SSH failure reporting.
6. Run the focused acceptance tests, `cd apps/gateway && composer check`, and the documentation checks.
7. After independent plan review authorizes development resources, acquire discovery and run the `managed-roleless-exporter-lifecycle` observation.

## Must preserve

- ADR 0057: select exporters only for active Nodes on the supported managed platform with Gateway management over SSH; permit explicit opt-in for an eligible roleless managed Node; exclude operator-client or otherwise unmanaged records for every preference; refuse ineligible enablement before preference or SSH; never converge an exporter on an ineligible record; and give Doctor no exporter or exporter-SSH expectation for that record.
- ADR 0003, as narrowed by ADR 0057: keep one tri-state Metrics-owned preference per Node without creating it during registration; keep role default, roleless default exclusion, explicit disable, and the Metrics Node's mandatory exporter within the eligible fleet; preserve preferences across role and Metrics lifecycle changes; use prospective synchronous projection; and publish no Prometheus target before exporter convergence.
- ADR 0003 runtime boundary: keep Metrics ownership limited to node-exporter configuration, its systemd lifecycle, WireGuard binding, and Metrics-owned firewall rules; do not create public Process or Tool rows, adopt unrelated services, or change shared packages.
- ADR 0004: Doctor remains synchronous, in-memory, bounded, deterministic, and verify-only; it uses only database reads and read-only local or remote commands, creates no Doctor persistence, exposes no raw command or exception data, and keeps stable managed-Node observation failures unverifiable rather than changing eligibility after a transient failure.
- ADR 0012 status and ADR 0057 extension: Ubuntu 24.04 support and client enrollment remain withdrawn; no exporter preference grants platform support, managed roles, Gateway SSH responsibility, or service convergence to a client record.
- Existing authorization against the active Gateway, Metrics singleton behavior, Grafana publication and credentials, exporter degradation handling, rollback, retry, removal, and status ordering remain unchanged.

## Open questions

- none; the issue, accepted ADRs, existing managed-Node policy fields, and current discovery topology settle the implementation choices needed for this plan.

## Deviations

- The issue's Incus `Proof:` venue maps to a reproducible discovery observation because this worktree selects `discovery`. No acceptance outcome is weakened. Discovery development only; isolated acceptance proof is not run.
- none otherwise.

## Review findings
