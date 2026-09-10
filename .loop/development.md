# Implementation record

Issue: ORB-232
Flow: discovery
Candidate: `212088fb1afa5418e29d8342ea70da3b05f2d432`
Approved plan candidate: `e5d791934354f5bfe5d5031c2d4c3780ec210882`
Approved plan artifact: `624168009313c690ca76a3e1279e0b14db51b6d4`

## Acceptance evidence

1. `ManagedNodeEligibilityTest.php`, `ExporterSelectorTest.php`, and `NativeMetricsExporterProjectionTest.php` prove that an active Linux Node with a non-empty managed WireGuard address is eligible when it has either a non-empty stored fingerprint or an active/provisioning managed role. They cover a legacy null-fingerprint Gateway receiving `role_default`, null-fingerprint Metrics/Gateway co-location remaining mandatory, roleless fingerprint opt-in, roleless no-fingerprint exclusion for absent/enabled/disabled preferences, and inactive or unsupported exclusion. `BootstrapGatewayActionTest.php` and `NativeGatewaySelfAccessConvergerTest.php` retain fresh and repeated bootstrap fingerprint persistence.
2. `MetricsRoutesTest.php` and `NativeMetricsRoleManagerTest.php` assert the stable 409 refusal `metrics.exporter_node_ineligible`, an unchanged exporter preference, and no fleet reconciliation call. `NativeMetricsExporterProjectionTest.php` proves stored enabled intent cannot restore an ineligible record to the projection.
3. `NativeMetricsExporterLifecycleTest.php` records every runtime fake event. The roleless no-fingerprint Node receives no snapshot, converge, remove, or restore call while eligible Nodes converge. The same file restores the eligible non-empty invalid-address case and asserts bounded `metrics.exporter_address_invalid`, HTTP 409, and no raw sentinel exposure. Existing lifecycle and fleet reconciler tests retain degradation, rollback, and retry behavior.
4. `NodeDoctorProbeTest.php` and `RunDoctorActionTest.php` omit the SSH finding for an ineligible record while retaining unreachable and inspection-failure findings for eligible Nodes, including a legacy null-fingerprint managed-role Node. `NodeDoctorProbeTest.php` retains eligible unsupported-architecture redaction and the original ineligible-record redaction coverage. `NativeMetricsFirewallExpectationProviderTest.php` proves legacy null-fingerprint Gateway and Metrics roles retain their expected firewall checks while a roleless ineligible record remains omitted. The focused Doctor tests retain verify-only behavior and no persisted Doctor state.
5. The `managed-roleless-exporter-lifecycle` discovery observation used Node 3 (`app-prod`) and also exercised legacy Node 1 (`gateway`). The Gateway remained active with `ssh_host_fingerprint:null` and active `gateway`/`vpn` roles throughout; no bootstrap replay or row rewrite occurred. It returned to Metrics as `role_default`, ran its exporter, was a healthy Prometheus target, exposed `node_exporter_build_info`, and returned a healthy Doctor report. After the `app-prod` role was removed, `node:list` showed active Linux, WireGuard `10.44.0.3`, a pinned SSH fingerprint, and no roles. Enable returned `enabled`; status showed all three eligible defaults (`gateway=role_default`, `app-dev=metrics_node`, `app-prod=explicit_enabled`) active; the physical roleless Node served metrics from `10.44.0.3:9100`, had the Orbit drop-in and Metrics-owned UFW rule; Prometheus reported all three targets `up` and returned `node_exporter_build_info` for each. Disable returned `disabled`; status showed `explicit_disabled` and `actual=inactive`; Prometheus removed only `app-prod`; its service became inactive and its drop-in and rule were absent. The `app-prod` role was restored and topology verification passed.
6. The 192-test focused command retains Metrics-node default, Gateway and managed-role defaults, roleless default exclusion, explicit disable, lifecycle rollback and degradation, fleet retries, Node removal reconciliation, deterministic status behavior, and fresh/repeated Gateway bootstrap behavior.
7. The planning commit `e5d791934354f5bfe5d5031c2d4c3780ec210882` changes `docs/reference/metrics.md`, `docs/architecture.md`, `docs/tech-stack.md`, and `docs/generated/context.json`. This candidate corrects `docs/reference/metrics.md` to state the stored role-or-fingerprint evidence for Gateway-owned SSH management and retains the documented cleanup limit for pre-existing state after a Node becomes ineligible. The documentation build regenerated identical context and lint returned zero issues, errors, or warnings.
8. `cd apps/gateway && composer check` passed: 12 guidance tests with 267 assertions, Rector, Pint, and PHPStan level 6.

## Focused checks

- `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Domain/BootstrapGatewayActionTest.php tests/Feature/Console/BootstrapGatewayCommandTest.php tests/Feature/Infrastructure/Gateway/NativeGatewaySelfAccessConvergerTest.php tests/Feature/Domain/NodeDoctorProbeTest.php`: passed, 44 tests and 238 assertions.
- `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/RemoveNodeTest.php tests/Unit/Domain/Nodes/ManagedNodeEligibilityTest.php tests/Unit/Domain/Metrics/ExporterSelectorTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterProjectionTest.php tests/Feature/Api/MetricsRoutesTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsRoleManagerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterLifecycleTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFleetReconcilerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsStatusReaderTest.php tests/Feature/Domain/NodeDoctorProbeTest.php tests/Feature/Domain/RunDoctorActionTest.php tests/Feature/Domain/FirewallDoctorProbeTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFirewallExpectationProviderTest.php tests/Feature/Api/DoctorTest.php tests/Feature/Domain/BootstrapGatewayActionTest.php tests/Feature/Console/BootstrapGatewayCommandTest.php tests/Feature/Infrastructure/Gateway/NativeGatewaySelfAccessConvergerTest.php`: passed, 192 tests and 765 assertions on candidate `212088fb1afa5418e29d8342ea70da3b05f2d432`.
- `cd apps/gateway && composer check`: passed; guidance 12 tests and 267 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `cd apps/gateway && vendor/bin/pint --dirty --format agent`: passed without changing the candidate.
- `composer docs-build && git diff --exit-code -- docs/generated/context.json && composer docs-lint`: passed; generated context stayed current and lint reported 0 issues, errors, or warnings.
- `git diff --check`: passed.
- Independent root `composer check`: pending reviewer.

## Review correction

- Reviewed candidate: `7849e8ef856e6dfe3f3c0c94ad1357efb46370cd`.
- Finding: the root gate exposed two `RemoveNodeTest.php` failures because `remove_node_record()` modeled managed Linux Nodes without the pinned SSH fingerprint required by `ManagedNodeEligibility`.
- Correction: `apps/gateway/tests/Feature/Api/RemoveNodeTest.php` now supplies `ssh_host_fingerprint` in that shared managed-node fixture. No production behavior changed.
- `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/RemoveNodeTest.php`: passed, 29 tests and 179 assertions.
- The combined focused suite and Gateway `composer check` passed after the correction.

## Formal review correction

- Reviewed candidate: `7cffb078b137b18833535d2431a1fb68e1453a29`.
- F1 finding: Gateway role default and Metrics/Gateway co-location lacked explicit managed-node eligibility enforcement and coverage.
- Correction: `ExporterSelector::select()` now requires eligibility instead of defaulting it to true. Unit and projection tests cover eligible and ineligible Gateway defaults, plus an eligible Metrics/Gateway node with explicit disable and an ineligible Metrics/Gateway node with explicit enable.
- Documentation finding: `docs/reference/metrics.md` overstated cleanup for exporter state converged before a Node became ineligible.
- Correction: the page now limits managed cleanup to eligible Nodes and states that the Gateway does not inspect or change the pre-existing exporter state of an ineligible Node.
- `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Domain/Metrics/ExporterSelectorTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsExporterProjectionTest.php`: passed, 9 tests and 42 assertions.
- The combined 146-test suite, Gateway `composer check`, documentation build/context check, and documentation lint passed after the correction.

## Second formal review correction

- Reviewed candidate: `867304beb969545ce59a2a1c95994789922503c1` on PR 252.
- F1 finding: a freshly bootstrapped Gateway stored no `ssh_host_fingerprint`, so managed-Node eligibility excluded it from the Gateway role default, Metrics/Gateway co-location, and managed Doctor SSH and WireGuard findings.
- F1 correction: `GatewaySelfAccessConverger` now returns the host fingerprint that native self-access already validates and writes to `known_hosts`, and `BootstrapGatewayAction` persists that value on the Gateway Node. No migration or eligibility exception was added.
- F2 finding: the unsupported stored-architecture redaction test used an ineligible fixture and returned before the bounded managed-identity branch.
- F2 correction: `NodeDoctorProbeTest.php` now uses an eligible Linux Node with WireGuard and pinned SSH identity, asserts one bounded `node.inspection_failed` issue with `expected=supported` and `observed=unsupported`, and asserts the serialized report omits the sentinel.
- The correction-focused command passed with 44 tests and 238 assertions. The combined focused command passed with 185 tests and 754 assertions. Gateway `composer check` passed after the correction.

## Resolver-aligned formal review correction

- Reviewed candidate: `a9d33d7348ccea74650a78ebb419e41908a4ac62` on PR 252.
- Resolver: `/home/nckrtl/shared-knowledge/projects/orbit/loop-improvement/sessions/2026-09-10-orb-232-resolution.md`, published verbatim under `ORBIT-RESOLUTION:ORB-232:gateway-eligibility-a9d33d73`.
- F1 finding: fingerprint-only eligibility excluded legacy Gateways that had already completed Gateway self-access and retained active managed roles but had no fingerprint copied into the Node row.
- F1 correction: `ManagedNodeEligibility` now requires active status, Linux, and a non-empty WireGuard address, then accepts either a non-empty stored fingerprint or an active/provisioning managed role as stored Gateway SSH-management intent. Roleless no-fingerprint records remain excluded for every preference. Fresh-bootstrap persistence remains unchanged. No migration, backfill, bootstrap replay, Node-row rewrite, or Gateway-name exception was added.
- F2 finding: eligible non-empty invalid WireGuard-address coverage had been replaced by a Doctor test, while unsupported-architecture Doctor redaction was already fixed.
- F2 correction: `NativeMetricsExporterLifecycleTest.php` restores an otherwise eligible managed-role Node with a non-empty invalid sentinel address and asserts bounded `metrics.exporter_address_invalid`, status 409, and no sentinel exposure. Eligible unsupported-architecture and ineligible-record Doctor redaction coverage both remain.
- The recommendation-aligned narrow set passed with 92 tests and 362 assertions. The complete focused command passed with 192 tests and 765 assertions. Gateway `composer check`, explicit Pint, documentation build/context comparison, documentation lint, and `git diff --check` passed on candidate `212088fb1afa5418e29d8342ea70da3b05f2d432`.

## Discovery observations

- `bin/e2e-topology acquire ORB-232 /fast/worktrees/orbit/orb-232` and `bin/e2e-topology sync ORB-232`: ready as discovery attempt `73f66ea055d0af3be396a7de530efe63`.
- `orbit node:role:remove 3 app-prod --force --json`: removed the role; the next `node:list --json` showed Node 3 active, managed, and roleless.
- `orbit metrics:exporter:enable app-prod --json`: returned `{"node_id":3,"status":"enabled"}`. `metrics:status --json` showed `app-prod` desired, active, and selected by `explicit_enabled`.
- On `app-prod`, `systemctl is-active prometheus-node-exporter` returned `active`; `ss` showed `10.44.0.3:9100`; `/metrics` contained `node_exporter_build_info`; `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf` contained the Orbit marker; UFW contained `orbit:metrics-node-exporter` from `10.44.0.2`.
- On `app-dev`, Prometheus `/api/v1/targets` returned `app-prod`, `health=up`, no last error, and scrape URL `http://10.44.0.3:9100/metrics`. A `node_exporter_build_info{node="app-prod"}` query returned one result.
- `orbit metrics:exporter:disable app-prod --json`: returned `{"node_id":3,"status":"disabled"}`. Status showed `explicit_disabled` and `actual=inactive`; the Prometheus active-target count became zero; the physical service was inactive and the Orbit drop-in and Metrics UFW rule were absent.
- `orbit doctor --node=3 --json`: returned healthy, eight healthy families, one Node check, and no issues.
- The first general topology verification correctly detected the observation's removed baseline role. The role was restored with `orbit node:role:add 3 app-prod --json`, which converged active. `bin/e2e-topology verify ORB-232` then returned `verified 73f66ea055d0af3be396a7de530efe63`.
- After candidate `a9d33d7348ccea74650a78ebb419e41908a4ac62` was committed, `bin/e2e-topology sync ORB-232` returned `ready 73f66ea055d0af3be396a7de530efe63` and `bin/e2e-topology verify ORB-232` returned `verified 73f66ea055d0af3be396a7de530efe63`.
- Candidate `212088fb1afa5418e29d8342ea70da3b05f2d432` was synchronized without rerunning bootstrap or changing the Gateway row. Before and after convergence, `node:list --json` showed Node 1 active Linux at `10.44.0.1`, roles `gateway` and `vpn`, and `ssh_host_fingerprint:null`.
- On the corrected candidate, `metrics:status --json` restored Node 1 as `role_default` and `actual=active`. The Gateway exporter service was active and served `node_exporter_build_info`. After fleet reconciliation, Prometheus reported its `10.44.0.1:9100` target `up` with no last error and returned its `node_exporter_build_info` sample. `orbit doctor --node=1 --json` returned healthy with one Node check, two role checks, and no drift or unverifiable findings.
- The corrected `managed-roleless-exporter-lifecycle` removed Node 3's `app-prod` role and confirmed the Node stayed active, Linux, roleless, and fingerprint-managed. Enable produced an active `explicit_enabled` exporter while Gateway remained active as `role_default`; the service bind, metric response, Orbit drop-in, UFW rule, Prometheus target, and host metric were present. Doctor returned healthy for the roleless Node. Disable produced `explicit_disabled` and inactive, removed only the `app-prod` target, and removed its service state, drop-in, and UFW rule. The `app-prod` role was then restored active.
- `bin/e2e-topology verify ORB-232` returned `verified 73f66ea055d0af3be396a7de530efe63`. `bin/e2e-topology status ORB-232 --json` records `host_sha` and `guest_sha` as `212088fb1afa5418e29d8342ea70da3b05f2d432`, `dirty:false`, and passing `source.gateway`, `source.app-dev`, and `source.manifest` probes.
- Discovery remains active with the baseline role restored and the roleless exporter preference explicitly disabled for reviewer inspection.
- Discovery development only; isolated acceptance proof not run.

## Documentation and deviations

- Documentation changed: `docs/reference/metrics.md` states the stored role-or-fingerprint evidence for Gateway-owned SSH management, managed exporter boundary, Doctor behavior, and the cleanup limit for pre-existing state on an ineligible Node; `docs/architecture.md` and `docs/tech-stack.md` correct delivery-flow and local-check drift; `docs/generated/context.json` is rebuilt.
- Documentation audit findings: all fixed in the approved planning commit; no reported findings or owners remain.
- Deviations: the issue's Incus proof venue maps to this discovery observation under the selected flow, as recorded in the approved plan. No implementation deviation changed an acceptance outcome.
- Limitations: isolated acceptance proof was not run because the selected flow is discovery. The independent root gate and code review are pending reviewer work.
