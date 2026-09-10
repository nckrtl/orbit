# Feature plan

Plan format: 1
Issue: ORB-229
Flow: discovery
Review verdict: PASS

## Outcome

Authorize every Grafana request at `metrics.orbit` against the active caller's Gateway authority while keeping Grafana login, upstream isolation, and revocation fail-closed.

## Code boundaries

In:
- `apps/gateway/routes/api.php`, `apps/gateway/app/Http/Controllers/Api/`, and `apps/gateway/app/Http/Middleware/`: add the narrow Caddy authorization check, reuse active WireGuard identity and Gateway node-access policy, and trust only the connection address rather than caller-supplied identity or forwarding headers.
- `apps/gateway/app/Domain/Metrics/`, `apps/gateway/app/Actions/Nodes/RemoveNodeAccessAction.php`, `apps/gateway/app/Actions/Nodes/RemoveNodeAction.php`, and `apps/gateway/app/Providers/AppServiceProvider.php`: coordinate current Grafana authority with grant and membership revocation, including repeatable fail-closed stream termination.
- `apps/gateway/app/Infrastructure/Metrics/` and `apps/gateway/app/Infrastructure/Firewall/NodeFirewallRuleCatalog.php`: publish only the `metrics.orbit` host, put the authorization check before every Grafana proxy request, isolate the Grafana upstream from the general WireGuard member rule in separate-role and co-located layouts, and retain retry-safe publication receipts.
- `apps/gateway/tests/Feature/Http/`, `apps/gateway/tests/Feature/Api/MetricsRoutesTest.php`, `apps/gateway/tests/Feature/Api/NodeAccessTest.php`, `apps/gateway/tests/Feature/Api/RemoveNodeTest.php`, and `apps/gateway/tests/Unit/Infrastructure/Metrics/`: cover caller identity, Gateway grants, all Grafana request shapes, bypass attempts, revocation, upstream firewall ordering, login and secret preservation, and publication failure and retry.

Out:
- Grafana credential lifecycle stays unchanged; do not change `apps/gateway/app/Infrastructure/Metrics/NativeMetricsCredentialManager.php` or add credential transport.
- Other private-service authorization stays unchanged; preserve ordinary active WireGuard member reachability outside Grafana.
- No SDK or CLI operation changes; leave `packages/php-sdk/` and `apps/cli/` unchanged.
- Operator-client enrollment stays unchanged; do not add or restore an operator client identity type.
- Harness code stays unchanged; leave `apps/e2e/` and `bin/e2e-*` unchanged and use the registered topology only through existing discovery commands.

## Documentation

Documentation audit scope: ORB-229, the `apps/gateway`, `Gateway`, and `Node` documentation context, and the Metrics page required by the `docs` label.

Fixed:
- `docs/reference/metrics.md`: replaced the claim that every WireGuard peer can open Grafana with the current Gateway-grant, request identity, revocation, upstream isolation, Grafana login, and credential-response contract.
- `docs/reference/routes.md`: named Grafana as the ADR 0055 exception to ordinary WireGuard member reachability and linked the owning Metrics guidance.
- `docs/generated/context.json`: added ADR 0055 to the generated Metrics page context after the maintained page linked the decision.

Reported: none.

Documentation commit: `c7169ed87c1e94328a996fad51a27f54d0029837` (`docs: describe authorized Grafana access`). Verification: `composer docs-build` and `composer docs-lint` passed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. The active Gateway and an active peer granted to it reach the Grafana login; Metrics-only, ungranted, inactive, and public callers do not. | Gateway Grafana authorization route and Metrics Caddy publication under `apps/gateway/app/Http/` and `apps/gateway/app/Infrastructure/Metrics/`. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Http/GrafanaAccessAuthorizationTest.php tests/Feature/Api/MetricsRoutesTest.php`; discovery observation `grafana-gateway-authority` uses `bin/e2e-topology exec ORB-229` from `gateway`, granted `app-dev`, and ungranted `app-prod`, adds only a Metrics-node grant for the negative case, marks the peer inactive for the inactive case, and checks that only Gateway-authorized callers receive the Grafana login response. |
| 2. Browser, API, and streaming paths share the check; spoofed headers and unavailable identity or authority fail closed. | The connection-address resolver, existing active-peer and node-access policy, the narrow authorization response, and the authorization-first Caddy route. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Http/GrafanaAccessAuthorizationTest.php tests/Unit/Infrastructure/Metrics/MetricsInfrastructureTest.php`; discovery observation `grafana-request-identity` requests a browser path and `/api/health`, opens a streaming upgrade, repeats denied calls with `Forwarded`, `X-Forwarded-For`, and Orbit identity headers, and observes denial when the peer or Gateway authorization state is unavailable. |
| 3. Raw Gateway addresses, alternate hosts, the Metrics address, and port 3000 cannot bypass authorization in separate-role or co-located layouts. | Host-only Metrics Caddy rendering plus ordered, owned Grafana upstream firewall rules in `MetricsPublicationRenderer`, `MetricsPublicationSshExecutor`, `MetricsFootprint`, and `NodeFirewallRuleCatalog`. | `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Infrastructure/Metrics/MetricsInfrastructureTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationSshExecutorTest.php`; discovery observation `grafana-upstream-isolation` checks the raw Gateway address and alternate `Host`, direct Metrics address and port, the ordered allow-Gateway/deny-other rules, then reconverges with Gateway and Metrics co-located and repeats the direct-port refusal. After this co-location observation exits zero, run `bin/e2e-topology release ORB-229` and `bin/e2e-topology acquire ORB-229 /fast/worktrees/orbit/orb-229` to restore the registered separate-role topology before later observations. |
| 4. Removing membership or the Gateway grant denies later requests, closes existing streams, and remains denied on repeat. | Grant removal and Node retirement actions plus the Metrics publication/Caddy revocation boundary. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/NodeAccessTest.php tests/Feature/Api/RemoveNodeTest.php tests/Feature/Http/GrafanaAccessAuthorizationTest.php`; discovery observation `grafana-access-revocation` opens a stream as the granted peer, removes and repeats removal of its Gateway grant, verifies stream closure and later denial, restores the grant, then removes membership and observes the same result. Run this observation last; after it exits zero, run `bin/e2e-topology release ORB-229` and `bin/e2e-topology acquire ORB-229 /fast/worktrees/orbit/orb-229`, then `bin/e2e-topology sync ORB-229` and `bin/e2e-topology verify ORB-229` against the fresh registered topology, requiring every command to exit zero. |
| 5. Gateway authority does not bypass Grafana login or leak stored credentials, and other private services keep WireGuard reachability. | Authorization response and proxy header policy, existing Metrics credential response boundary, and the Grafana-only firewall exception. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Http/GrafanaAccessAuthorizationTest.php tests/Feature/Api/MetricsRoutesTest.php tests/Unit/Infrastructure/Metrics/MetricsInfrastructureTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationSshExecutorTest.php`; discovery observation `grafana-login-and-network-regression` verifies an admitted request still reaches the Grafana login without a credential header or stored secret and verifies an unrelated private port remains reachable between active peers. |
| 6. Publication failure and retry preserve isolation and never make a partly authorized endpoint ready. | `MetricsPublicationManager`, Caddy and firewall publishers, their receipts, and Metrics role convergence status. | `cd apps/gateway && vendor/bin/pest --compact tests/Unit/Infrastructure/Metrics/MetricsPublicationManagerTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationSshExecutorTest.php tests/Unit/Infrastructure/Metrics/MetricsPublicationReceiptTest.php`; discovery observation `grafana-publication-retry` injects failure before authorized Caddy activation, verifies the user endpoint stays unavailable and direct port stays isolated, retries convergence, and then verifies authorized readiness. |
| 7. Metrics guidance covers endpoint, grant, login, and revocation behavior with current generated context. | `docs/reference/metrics.md`, `docs/reference/routes.md`, and `docs/generated/context.json`. | `composer docs-build && composer docs-lint` (passed during preflight). |
| 8. Gateway checks pass. | All changed files under `apps/gateway/`. | `cd apps/gateway && composer check`; the independent reviewer later runs root `composer check` as the local review gate. |

## Implementation order

1. After Tom receives an independent `PASS` for this plan and before changing product code, acquire the required discovery topology with `bin/e2e-topology acquire ORB-229 /fast/worktrees/orbit/orb-229`; require a zero exit and keep it mounted for development.
2. Add the narrow internal Grafana authorization request and route it through the existing active-peer identity and Gateway authority checks; cover direct, spoofed, missing-state, API, and streaming handshakes with focused HTTP tests.
3. Change the Metrics Caddy fragment to match only `metrics.orbit`, run authorization before the Grafana reverse proxy, preserve the original connection identity through the internal FastCGI check, and keep Grafana login and credential headers untouched.
4. Replace the ineffective single Grafana allow rule with an owned, ordered Gateway-allow and other-caller-deny boundary ahead of general WireGuard trust; make converge, remove, rollback, and co-located behavior exact and idempotent.
5. Make Metrics publication retract any legacy or incomplete route before cutover, retain upstream isolation on every failure, restore only a previously complete authorized publication, and let retry finish all stages before role readiness is reported.
6. Coordinate Gateway-grant removal and Node membership removal with a Caddy configuration unload so existing streams close; make repeated revocation retry the secure publication state without recreating authority.
7. Run all focused acceptance tests, `cd apps/gateway && composer check`, and `git diff --check`.
8. Run `grafana-gateway-authority`, `grafana-request-identity`, `grafana-login-and-network-regression`, and `grafana-publication-retry`, restoring their temporary grants, status, and fault injection within each observation. Run `grafana-upstream-isolation`; after its co-location check exits zero, run `bin/e2e-topology release ORB-229` and `bin/e2e-topology acquire ORB-229 /fast/worktrees/orbit/orb-229`. Run `grafana-access-revocation` last; after its membership-removal check exits zero, release and reacquire discovery again, then run `bin/e2e-topology sync ORB-229` and `bin/e2e-topology verify ORB-229` on the fresh registered separate-role topology. Require every topology command to exit zero and retain all observation commands and results for the implementation handoff.

## Must preserve

- ADR 0055: expose Grafana to users only through `metrics.orbit` on the Gateway WireGuard endpoint; resolve every caller to an active WireGuard Node; admit only the active Gateway or a peer with a directed grant to it; deny missing identity or authority; apply the check to browser, API, and streaming traffic; revoke on membership or grant loss; isolate the upstream even when roles are co-located; prevent direct peer and public access; and retain Grafana login.
- ADR 0003: private DNS resolves `metrics.orbit` to the Gateway, Gateway Caddy terminates the Orbit-CA certificate, only the Gateway reaches the Grafana upstream, and Prometheus remains unpublished; the active Gateway is implicit authority while every other caller needs a directed grant to it, Metrics-node or exporter permission is insufficient, and authorization runs before Metrics state or credentials; the `admin` credential remains an encrypted Metrics node setting returned only by the explicit non-cacheable credential response; Gateway continues to own publication, authorization, remote operations, stable failures, and redaction.
- ADR 0033 outside the ADR 0055 Grafana exception: active WireGuard membership still permits ordinary private Node traffic over all protocols and ports; Node grants still authorize Orbit commands and Gateway API actions rather than general private traffic; Ingress alone limits public traffic to HTTP and HTTPS; configured LAN traffic must preserve the registered-Node trust boundary; and removing WireGuard membership still revokes general private network trust.
- Existing Metrics convergence remains synchronous and idempotent, shares the development projection owner, publishes DNS last, removes owned state only, preserves exact prior secure state when rollback is safe, and leaves failed convergence retryable.
- Existing request handling continues to ignore spoofable forwarding headers for Node identity, preserve request correlation and stable error envelopes, and redact credentials from ordinary responses, errors, logs, debug data, and activity records.
- Product feature work does not change the E2E harness; discovery uses the registered three-Node profile and existing `shell`, `exec`, `sync`, and `verify` commands only.

## Open questions

none

## Deviations

- Incus is required by label. The issue's Incus `Proof:` actions are mapped to same-outcome observations on the registered discovery topology after independent plan review because the selected flow is `discovery`. Proof instrumentation, `.loop/proof/`, observed-input collection, and an isolated proof topology are not required. Discovery development only; isolated acceptance proof not run or planned.
- No issue-text check-policy correction is needed: the focused Gateway tests, changed-project `composer check`, documentation lint, discovery observations, and independent root local gate match the current policy.

## Review findings
