# Implementation record

Issue: ORB-232
Flow: discovery
Candidate: `7849e8ef856e6dfe3f3c0c94ad1357efb46370cd`
Approved plan candidate: `e5d791934354f5bfe5d5031c2d4c3780ec210882`
Approved plan artifact: `624168009313c690ca76a3e1279e0b14db51b6d4`

## Acceptance evidence

1. `ManagedNodeEligibilityTest.php`, `ExporterSelectorTest.php`, and `NativeMetricsExporterProjectionTest.php` cover the supported platform and managed identities, every preference outcome, active managed defaults, roleless opt-in, and exclusion of inactive, unsupported, and unmanaged records.
2. `MetricsRoutesTest.php` and `NativeMetricsRoleManagerTest.php` assert the stable 409 refusal `metrics.exporter_node_ineligible`, an unchanged exporter preference, and no fleet reconciliation call. `NativeMetricsExporterProjectionTest.php` proves stored enabled intent cannot restore an ineligible record to the projection.
3. `NativeMetricsExporterLifecycleTest.php` records every runtime fake event. The ineligible node receives no snapshot, converge, remove, or restore call while eligible nodes converge. Existing lifecycle and fleet reconciler tests retain degradation, rollback, and retry behavior.
4. `NodeDoctorProbeTest.php` and `RunDoctorActionTest.php` omit the SSH finding for an ineligible record while retaining managed-node unreachable and inspection-failure findings. `NativeMetricsFirewallExpectationProviderTest.php` omits the exporter firewall expectation despite stored enabled intent. The focused Doctor tests retain verify-only behavior and no persisted Doctor state.
5. The `managed-roleless-exporter-lifecycle` discovery observation used Node 3 (`app-prod`). After its `app-prod` role was removed, `node:list` showed active Linux, WireGuard `10.44.0.3`, a pinned SSH fingerprint, and no roles. Enable returned `enabled`; status showed `explicit_enabled` and `actual=active`; the physical node served `node_exporter_build_info` from `10.44.0.3:9100`, had the Orbit drop-in and Metrics-owned UFW rule; Prometheus reported the target `up` and returned one host-metric result. Disable returned `disabled`; status showed `explicit_disabled` and `actual=inactive`; Prometheus removed the target; the service became inactive and the drop-in and rule were absent. Doctor then returned healthy with no issues.
6. The 116-test focused command retains Metrics-node default, role defaults, roleless default exclusion, explicit disable, lifecycle rollback and degradation, fleet retries, and deterministic status behavior.
7. The planning commit `e5d791934354f5bfe5d5031c2d4c3780ec210882` changes `docs/reference/metrics.md`, `docs/architecture.md`, `docs/tech-stack.md`, and `docs/generated/context.json`. The documentation build regenerated identical context and lint returned zero issues, errors, or warnings.
8. `cd apps/gateway && composer check` passed: 12 guidance tests with 267 assertions, Rector, Pint, and PHPStan level 6.

## Focused checks

- `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Domain/Nodes/ManagedNodeEligibilityTest.php tests/Unit/Domain/Metrics/ExporterSelectorTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterProjectionTest.php tests/Feature/Api/MetricsRoutesTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsRoleManagerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterLifecycleTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFleetReconcilerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsStatusReaderTest.php tests/Feature/Domain/NodeDoctorProbeTest.php tests/Feature/Domain/RunDoctorActionTest.php tests/Feature/Domain/FirewallDoctorProbeTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFirewallExpectationProviderTest.php tests/Feature/Api/DoctorTest.php`: passed, 116 tests and 363 assertions.
- `cd apps/gateway && composer check`: passed; guidance 12 tests and 267 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `composer docs-build && git diff --exit-code -- docs/generated/context.json && composer docs-lint`: passed; generated context stayed current and lint reported 0 issues, errors, or warnings.
- `git diff --check`: passed.
- Independent root `composer check`: pending reviewer.

## Discovery observations

- `bin/e2e-topology acquire ORB-232 /fast/worktrees/orbit/orb-232` and `bin/e2e-topology sync ORB-232`: ready as discovery attempt `73f66ea055d0af3be396a7de530efe63`.
- `orbit node:role:remove 3 app-prod --force --json`: removed the role; the next `node:list --json` showed Node 3 active, managed, and roleless.
- `orbit metrics:exporter:enable app-prod --json`: returned `{"node_id":3,"status":"enabled"}`. `metrics:status --json` showed `app-prod` desired, active, and selected by `explicit_enabled`.
- On `app-prod`, `systemctl is-active prometheus-node-exporter` returned `active`; `ss` showed `10.44.0.3:9100`; `/metrics` contained `node_exporter_build_info`; `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf` contained the Orbit marker; UFW contained `orbit:metrics-node-exporter` from `10.44.0.2`.
- On `app-dev`, Prometheus `/api/v1/targets` returned `app-prod`, `health=up`, no last error, and scrape URL `http://10.44.0.3:9100/metrics`. A `node_exporter_build_info{node="app-prod"}` query returned one result.
- `orbit metrics:exporter:disable app-prod --json`: returned `{"node_id":3,"status":"disabled"}`. Status showed `explicit_disabled` and `actual=inactive`; the Prometheus active-target count became zero; the physical service was inactive and the Orbit drop-in and Metrics UFW rule were absent.
- `orbit doctor --node=3 --json`: returned healthy, eight healthy families, one Node check, and no issues.
- The first general topology verification correctly detected the observation's removed baseline role. The role was restored with `orbit node:role:add 3 app-prod --json`, which converged active. `bin/e2e-topology verify ORB-232` then returned `verified 73f66ea055d0af3be396a7de530efe63`.
- Discovery remains active with the baseline role restored and the exporter preference explicitly disabled for reviewer inspection.
- Discovery development only; isolated acceptance proof not run.

## Documentation and deviations

- Documentation changed in planning: `docs/reference/metrics.md` states the managed exporter boundary and Doctor behavior; `docs/architecture.md` and `docs/tech-stack.md` correct delivery-flow and local-check drift; `docs/generated/context.json` is rebuilt.
- Documentation audit findings: all fixed in the approved planning commit; no reported findings or owners remain.
- Deviations: the issue's Incus proof venue maps to this discovery observation under the selected flow, as recorded in the approved plan. No implementation deviation changed an acceptance outcome.
- Limitations: isolated acceptance proof was not run because the selected flow is discovery. The independent root gate and code review are pending reviewer work.
