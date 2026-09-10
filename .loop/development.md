# Implementation record

Issue: ORB-229
Flow: discovery
Candidate: `3de2c75dcadab38ea4a6bb32cff08aa66a074ccc`
Approved plan candidate: `c7169ed87c1e94328a996fad51a27f54d0029837`
Approved plan artifact: `80c2ef25f7da87043b94de6cbc2b92e1a987b949`
Approved plan SHA-256: `2132c77a40270768ba0ef5102a897286ca9abe5498e1fa95381472c9302e2aa5`

## Acceptance evidence

1. `GrafanaAccessAuthorizationTest.php` and `MetricsRoutesTest.php` cover the narrow authorization route, active Gateway authority, a directed Gateway grant, Metrics-only, ungranted, inactive, and unknown callers. Discovery observation `grafana-gateway-authority` returned Grafana login HTTP 200 for the Gateway and granted app-dev peer, HTTP 403 for app-prod when ungranted and when granted only to the Metrics node, HTTP 403 with `peer.identity_unknown` while app-prod was inactive, and no public connection on the Gateway Incus address.
2. `GrafanaAccessAuthorizationTest.php` and `MetricsInfrastructureTest.php` cover connection-address identity, spoof rejection, missing authority, and one authorization-first route for every request. Discovery observation `grafana-request-identity` returned 200 for an authorized browser path and Grafana API path, reached Grafana's 401 for an authorized unauthenticated WebSocket handshake, returned Gateway HTTP 403 for all three paths from app-prod despite `Forwarded`, `X-Forwarded-For`, and Orbit identity headers, and returned HTTP 403 while the Gateway role was unavailable.
3. `MetricsInfrastructureTest.php`, `MetricsPublicationSshExecutorTest.php`, `NativeMetricsFirewallExpectationProviderTest.php`, and `RoleCatalogContractsTest.php` cover host-only publication and the owned allow/deny boundary. Discovery observation `grafana-upstream-isolation` denied the raw Gateway address, alternate Host, Metrics address, and peer access to port 3000; admitted Gateway access to Grafana upstream health; and observed ordered allow, deny, and general WireGuard trust rules on the separate-role Metrics node. With Metrics co-located on the Gateway, the authorized proxy returned 200, app-prod could not reach port 3000, and the Gateway had ordered allow then deny rules. The mutated topology was released afterward.
4. `NodeAccessTest.php`, `RemoveNodeTest.php`, `NativeMetricsAccessRevokerTest.php`, and `GrafanaAccessAuthorizationTest.php` cover repeatable grant and membership revocation, retry after reload failure, and fail-closed authorization. Discovery observation `grafana-access-revocation` opened a credential-authenticated WebSocket with HTTP 101, removed the app-dev Gateway grant, observed the stream close and later HTTP 403, repeated removal without restoring access, restored the grant, then opened a fresh authenticated stream from app-prod. After normal app-prod role removal, product Node removal deleted its membership and WireGuard peer, closed the fresh stream, and denied later access; repeated membership removal returned the expected not-found outcome and access stayed denied. This observation ran last on the mutated lease.
5. `GrafanaAccessAuthorizationTest.php`, `MetricsRoutesTest.php`, `MetricsInfrastructureTest.php`, and `MetricsPublicationSshExecutorTest.php` retain Grafana login, credential response boundaries, and the Grafana-only firewall exception. Discovery observation `grafana-login-and-network-regression` returned the Grafana login without the stored administrator password in headers or body and reached the unrelated Gateway SSH port over WireGuard.
6. `MetricsPublicationManagerTest.php`, `MetricsPublicationSshExecutorTest.php`, and `MetricsPublicationReceiptTest.php` cover withdrawal, secure receipt restoration, retained isolation, failure, and retry. Discovery observation `grafana-publication-retry` cut over from a temporary legacy fragment with one injected Caddy service-command failure. The user endpoint stayed unavailable, the Metrics assignment was not ready, and app-prod still could not reach port 3000. After removing the injection, `node:role:add 2 metrics --converge --json` restored active status and authorized login HTTP 200 while direct upstream access stayed denied.
7. Planning commit `c7169ed87c1e94328a996fad51a27f54d0029837` changes `docs/reference/metrics.md`, `docs/reference/routes.md`, and `docs/generated/context.json`. The implementation matches those pages. `composer docs-build` regenerated identical context and `composer docs-lint` passed with zero issues, errors, or warnings.
8. `cd apps/gateway && composer check` passed: 12 guidance tests with 267 assertions, Rector, Pint, and PHPStan level 6.

## Focused checks

- `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Http/GrafanaAccessAuthorizationTest.php tests/Feature/Api/MetricsRoutesTest.php tests/Feature/Api/NodeAccessTest.php tests/Feature/Api/RemoveNodeTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsAccessRevokerTest.php tests/Feature/Infrastructure/Metrics/NativeMetricsFirewallExpectationProviderTest.php tests/Feature/Infrastructure/Nodes/RoleCatalogContractsTest.php tests/Feature/Domain/FirewallDoctorProbeTest.php tests/Unit/Infrastructure/Metrics/MetricsInfrastructureTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationSshExecutorTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationManagerTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationReceiptTest.php tests/Unit/Infrastructure/Metrics/MetricsLocalPublicationTest.php`: passed, 118 tests and 519 assertions.
- `cd apps/gateway && composer check`: passed; guidance 12 tests and 267 assertions, Rector, Pint, and PHPStan level 6 all passed.
- `composer docs-build`: passed; `docs/generated/context.json` stayed unchanged.
- `composer docs-lint`: passed with 0 issues, errors, or warnings.
- `git diff --check`: passed.
- Independent root `composer check`: pending reviewer.

## Discovery observations

- Initial required acquisition: `bin/e2e-topology acquire ORB-229 /fast/worktrees/orbit/orb-229` produced lease `14b58de279e262f8e1d0c7972fd3573d` before implementation edits.
- `grafana-gateway-authority`: Gateway and the granted peer received login HTTP 200; Metrics-only, ungranted, inactive, and public callers were denied. Temporary grant and status changes were restored.
- `grafana-request-identity`: browser and API requests reached Grafana for the granted peer; its unauthenticated WebSocket reached Grafana's 401; all three shapes returned Gateway 403 for spoofing app-prod; unavailable peer or Gateway authority returned 403. Temporary status changes were restored.
- `grafana-login-and-network-regression`: Grafana login remained present, the stored password was absent from its ordinary response, and private SSH stayed reachable.
- `grafana-upstream-isolation`: separate-role and co-located layouts denied peer direct port 3000 while the Gateway proxy worked; the owned allow rule preceded the deny rule, which preceded general WireGuard trust when that rule existed. Lease `14b58de279e262f8e1d0c7972fd3573d` was released after co-location.
- `grafana-publication-retry`: on lease `6c9ee3753cbebd4281ec6644ad4d5d70`, a one-call service fault during legacy cutover left the route unavailable, upstream deny intact, and the role failed; removing the fault and retrying restored authorized readiness. Temporary source and `/usr/local/sbin/systemctl` injection state were removed.
- `grafana-access-revocation`: on the same lease, grant and membership removal each closed a credential-authenticated WebSocket and denied later requests; repeats did not restore access. The lease was released after membership removal.
- The final fresh registered lease is `b474feab2596b0228c30daace662c433`. Its Metrics publication was reconverged from candidate code. After candidate commit, `bin/e2e-topology sync ORB-229` returned `ready b474feab2596b0228c30daace662c433`, and `bin/e2e-topology verify ORB-229` returned `verified b474feab2596b0228c30daace662c433`.
- Discovery remains active for reviewer inspection.
- Discovery development only; isolated acceptance proof not run.

## Documentation, deviations, and limitations

- Documentation changed in planning: `docs/reference/metrics.md` describes the endpoint, directed Gateway grant, request identity, login, upstream isolation, and revocation; `docs/reference/routes.md` names the Grafana exception; `docs/generated/context.json` carries ADR 0055 context. No implementation correction was needed.
- Documentation audit findings: all fixed in the planning commit; no reported findings or owners remain.
- Implementation deviations: none. The authorization route, publication, firewall, failure/retry, and revocation behavior match the reviewed acceptance map.
- Discovery execution limitation: standard reacquisition compares the promoted snapshot's existing Metrics fragment with the mounted current renderer but does not reconverge it. Two direct reacquisition attempts therefore failed at `metrics.publication` and rolled back cleanly after the renderer changed. For each successful restoration, the renderer alone was temporarily returned to its baseline output for acquisition, immediately restored to candidate code, and the Metrics publication was reconverged before candidate `sync` and `verify`. No harness file changed.
- Diagnostic command limitations: early read-only commands used the wrong `node_access` table name, omitted required `--force`, lacked Caddy directory privilege, assumed compact health JSON, or over-required a general trust rule on the co-located Gateway. They exited nonzero without product-state changes; corrected checks passed. A normal and then offline Node removal were also correctly refused while app-prod still had a live role; the role was removed through the normal product path before membership removal.
- Independent root `composer check` and formal code review remain reviewer work.
