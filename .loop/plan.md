# Feature plan

Plan format: 1
Issue: ORB-199
Flow: discovery
Review verdict: PASS

## Outcome

A production clone placed in an active Cluster receives one private preview Route through the Cluster Router while preserving the standalone clone contract and exact workload serving identity.

## Code boundaries

In:
- `apps/gateway/app/Actions/AppInstances/CloneAppInstanceAction.php` and `apps/gateway/app/Domain/AppInstances/ProductionCloneRouteProjector.php`: resolve the destination's current Route placement before reservation, require the production Node's own TLD, require an active Router for Cluster scope, store the exclusive Node-or-Cluster Route scope, validate that scope during retry, and checkpoint the Router certificate, private path, workload certificate verification, Caddy, and DNS boundaries before activation.
- `apps/gateway/app/Infrastructure/AppInstances/NativeProductionRouteProjector.php`: extend the production clone projection with the proven Cluster behavior from `NativeDevelopmentRouteProjector`: keep the dedicated production runtime and workload leaf, add a separate Router leaf, add only the required LAN rule when LAN is selected, verify the workload leaf from the Router without an application request, publish workload and Router Caddy projections, and publish private DNS to the Router last. Preserve the local-runtime path when Router and workload roles share one Node.
- `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php`, `apps/gateway/tests/Feature/Api/CloneAppInstanceTest.php`, and a focused `apps/gateway/tests/Feature/Infrastructure/AppInstances/NativeProductionRouteProjectorTest.php`: cover authorized cross-Node Cluster cloning, production-Node TLD precedence, exclusive Route scope, missing or incompatible placement, separate certificates, private firewall/Caddy/DNS behavior, exact Host and TLS server name forwarding, no public projection, bounded failures, and idempotent checkpoint retry without duplicate records or certificate state.

Out:
- Public Ingress listeners, public certificates, public DNS, and public Route publication stay unchanged; cloning creates only the private Router path.
- Multi-target Route storage and round-robin selection stay unchanged; the clone creates one target on its sole Route.
- Deployment commands, deployment steps, release activation, rollback, and `current` selection stay unchanged and remain separate explicit operations.
- Node-TLD generation and reconciliation rules for Routes outside candidate cloning stay unchanged; the clone continues to store its preview as an explicit hostname.
- Application health gates, framework bootstrap, dependency installation, and inferred application setup stay unchanged and are not added to cloning.
- Clone request and response fields, SDK and CLI transport, candidate source inspection, environment copying, optional SQLite seeding, Process and Schedule copies, and standalone clone behavior stay unchanged.
- The Incus harness under `apps/e2e` and `bin/e2e-*` stays unchanged; discovery uses the registered three-Node topology and existing guest command surface.

## Documentation

- `docs/reference/appinstance-cloning.md`: now describes standalone and active-Cluster destinations, the production Node TLD rule, Router prerequisite, exclusive Route scope, private Router-to-workload TLS/Caddy/firewall/DNS path, dedicated PHP socket, no public Ingress projection, no application health gate, and recoverable projection failures.
- `docs/generated/context.json`: regenerated so the cloning page includes Router and Ingress terms and ADR 0023 in its routing context.
- Documentation audit scope: ORB-199 with component `apps/gateway` and the named Gateway, Node, Cluster, App, AppInstance, Route, Router, and Ingress concepts. Fixed: `docs/reference/appinstance-cloning.md` said the destination must be standalone and did not describe clustered preview publication; owner ORB-199. Audited with no change: the higher-authority concept and application pages and the Route, environment, deployment, PHP runtime, and App definition references already agree with the issue and accepted ADRs. Reported findings: none.
- Verification: `composer docs-build` and `composer docs-lint` passed; both documentation changes are committed as `ce5aae7b` (`docs: describe clustered candidate cloning`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. An authorized candidate on another Node clones to an active-Cluster app-prod destination with unchanged target configuration and optional SQLite behavior, and the preview uses the production Node TLD even when the Cluster TLD differs. | Clone destination preflight and reservation in `CloneAppInstanceAction`; existing authorization, environment, and SQLite clone boundaries remain in use. | Reproducible discovery observation `cluster-clone-preview`, backed by `apps/gateway/tests/Feature/Api/CloneAppInstanceTest.php` and `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php` through `cd apps/gateway && composer test:affected`. |
| 2. The sole private Route has Cluster scope, separate workload and Router Orbit-CA identities, private firewall policy, workload and Router Caddy projections, and the dedicated PHP socket when required; preparation completes before deployment without HTTP success. | Clone checkpoints, `ProductionCloneRouteProjector`, and `NativeProductionRouteProjector`, reusing the existing certificate, firewall, Caddy, private DNS, and dedicated production runtime services. | Reproducible discovery observation `cluster-clone-infrastructure`, backed by `apps/gateway/tests/Feature/Infrastructure/AppInstances/NativeProductionRouteProjectorTest.php` and the domain clone lifecycle tests through Gateway TIA. |
| 3. After an explicit minimal deployment, trusted Router and exact workload paths preserve Host and TLS server name and reach the selected release, while cloning creates no public listener or Ingress certificate. | Existing explicit deployment path plus the new private production Router projection; public Ingress and certificate services remain outside the clone call graph. | Reproducible discovery observation `cluster-clone-request-path`, backed by rendered production Router/workload Caddy assertions in `NativeProductionRouteProjectorTest.php` and the Builder gate. |
| 4. Missing Router, incompatible placement, certificate failure, or occupied hostname reports a bounded failed boundary; interruption and identical retry retain completed clone state without duplicate AppInstance, Route, target, or certificate state. | Mutation-free preflight, exclusive Route reservation, durable clone checkpoints, idempotent certificate/projection services, failure recording, and completion transaction in `CloneAppInstanceAction`. | `apps/gateway/tests/Feature/Domain/CloneAppInstanceTest.php` plus reproducible discovery observation `cluster-clone-retry`, through Gateway TIA and the Builder gate. |
| 5. Maintained documentation describes Cluster placement, preview routing, private TLS, and recovery, with current generated context. | `docs/reference/appinstance-cloning.md` and `docs/generated/context.json`. | `composer docs-build` and `composer docs-lint`. |
| 6. Changed Gateway checks and repository suites pass under the current local-gate policy. | All changed Gateway boundaries and the exact clean candidate. | `cd apps/gateway && composer test:affected`, `cd apps/gateway && composer check`, `git diff --check`, then root `composer check` as the Builder gate on the exact clean candidate. |

## Incus observations

- Incus: required. Flow: discovery. After independent plan approval, acquire the standard ORB-199 three-Node discovery topology. No proof topology, proof plan, immutable evidence capture, or observed-input instrumentation is required in discovery flow.
- Run `cluster-clone-preview` with the existing development AppInstance as the candidate on one authorized Node and the app-prod Node attached to an active Cluster whose Router is on another Node. Give the app-prod Node and Cluster different TLDs. Clone once without SQLite and once with the supported optional SQLite input as needed to compare the unchanged standalone data/configuration contract. Inspect the response and Gateway records for one target, one explicit private Cluster-scoped Route, the production Node TLD hostname, independent environment values, and the selected SQLite result.
- Run `cluster-clone-infrastructure` before any deployment. Inspect distinct workload and Router certificate scopes and key fingerprints, the workload's dedicated PHP service/socket when PHP applies, workload and Router Caddy sites, private DNS resolving the preview to the Router, the selected WireGuard or LAN rule, and the absence of public app-prod/Ingress listeners and certificates. Verify the private TLS hop only; do not require an application HTTP response.
- Run `cluster-clone-request-path` after an explicit deployment of a minimal release marker. Make one trusted request through Router DNS and one exact workload-address override while keeping the preview hostname as both HTTP Host and TLS server name. Require both paths to return the selected release marker and confirm that cloning created no public Route or Ingress certificate.
- Run `cluster-clone-retry` for missing Router, incompatible destination state, occupied hostname, and a controlled certificate/projection interruption. Confirm the bounded error and inactive checkpoint, restore the prerequisite, repeat the identical request, and inspect that completed configuration and SQLite data remain while AppInstance, Route, target, and certificate scope counts stay singular.
- Finish discovery with `bin/e2e-topology sync ORB-199` and `bin/e2e-topology verify ORB-199`. Record `Discovery development only; isolated acceptance proof not run` in the implementation handoff.

## Implementation order

1. Extend the clone domain tests for active-Cluster placement, production Node TLD precedence, missing Router and incompatible placement refusals, exclusive Route scope, failure checkpoints, and identical retry while retaining every standalone clone case.
2. Change clone preflight and reservation to use `RouteStateResolver`'s Node-or-Cluster placement, assert the Router before target reservation, persist that exclusive scope, and require the reserved scope and sole target to remain unchanged throughout retry and activation.
3. Extend the clone projection contract and `NativeProductionRouteProjector` with separate Router certificate, LAN-or-WireGuard private path verification, aggregate workload/Router Caddy publication, and Router-owned private DNS. Port the conditions and fixed command shapes already proved by `NativeDevelopmentRouteProjector`, but keep the production dedicated runtime and avoid self-proxy work when Router and workload co-locate.
4. Insert durable no-op-safe checkpoints for the added Cluster projection boundaries before Caddy and DNS-last publication, and revalidate the exact Route scope, target, certificates, runtime, firewall, and rendered aggregates before the final transaction activates the Route and AppInstance.
5. Add focused infrastructure coverage for remote and co-located Router paths, distinct certificate scopes, LAN and WireGuard selection, workload leaf verification, exact proxy Host/SNI, dedicated PHP socket, DNS owner, no public projection, and injected failures. Run Gateway TIA/project checks, the discovery observations, and retain the exact clean candidate for the Builder gate.

## Must preserve

- ADR 0009: keep one Router-selected target, separate Router and workload private keys, original hostname as HTTP Host and TLS server name, LAN-first routing with WireGuard only when LAN is absent, Caddy composition, private firewall ownership, and DNS-last publication; add no load-balancing policy.
- ADR 0011: keep Ingress ownership of public listeners, public TLS, and public policy separate from Router backend selection and workload serving; private Routes continue through Router without Ingress, and workload Nodes do not become public endpoints.
- ADR 0017 as narrowed by ADR 0023: retain standalone placement as a valid mode and derive Route scope from active Cluster membership, but do not revive the superseded Cluster-TLD precedence or direct public workload rules.
- ADR 0023: keep the Route as hostname authority, use explicit preview provenance, derive Cluster scope independently from hostname, require the active Router, project the hostname to Router and workload, preserve direct private workload access, and never publish the workload publicly.
- ADR 0028: create at most one Route association for the cloned AppInstance and establish exactly one Route before activation.
- ADR 0029 and ADR 0044: keep the sole Route authoritative for target URL placeholders, preserve independently encrypted stored values and unrelated settings, synchronize atomically after destination preflight, disclose no values, and run no framework, cache, or process side effects.
- ADR 0030: retain source, runtime, network, TLS, and firewall checks but require no application boot, dependency, database, health check, or successful HTTP response before clone activation.
- ADR 0031 only where retained by ADR 0047: do not change the transitional direct-production creation path or revive its superseded source-selection rules inside candidate cloning.
- ADR 0045: bind PHP targets to the owning production user's dedicated service, pool, socket, and OPcache, preserve local tuning, and never alter another production user's runtime.
- ADR 0046: keep persistent environment and optional SQLite data outside releases, leave `current` absent until an explicit first deployment, run no deployment step during cloning, and keep application compatibility and recovery with the operating agent.
- ADR 0047: require the eligible candidate, preserve its source and live state, reconstruct target source from the App repository, retain target configuration and optional SQLite behavior, preflight one available preview, keep deployment separate, and make identical retry preserve completed target state.
- ADR 0048: copy only production-applicable App definitions into independent stopped/disabled target Process and Schedule records, preserve completed copies on retry, and do not copy candidate overrides or running state.
- Existing authorization, source inspection, environment/SQLite isolation, standalone clone, Route lifecycle, production deployment, hostname reconciliation, removal, response redaction, activity, and private development Router tests remain green.

## Open questions

none

## Deviations

The issue's generic `all five full no-TIA CI suites` proof wording is superseded by the current repository policy. Development uses Gateway TIA and its project check, and the Builder runs root `composer check` with TIA on the exact clean candidate. GitHub CI is disabled. This venue correction does not change the acceptance outcome; the orchestrator should align the Linear text through the issue-creation process.

## Review findings
