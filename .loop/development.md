# ORB-338 development record

Flow: discovery. Incus: not required. Candidate: ef19609b2927b7c58c2976f66ed73e843ef4ec9f.

Parent: ORB-337. Keep-alive remains ORB-339. Cold prune stays out of program scope.

## Nick constraints applied

- Tip AppDev is PHP-FPM + Caddy, not Docker app containers. Hibernation never stops the shared per-version PHP-FPM service or per-site pools (ADR 0021, `pm=ondemand`).
- First HTTP request after idle must not reach the app until desired-running Processes (and Vite on `127.0.0.1:5173` when present) are ready. The intercept returns the Orbit progress page (401) immediately; Caddy does not proxy. Processes start after that response. Concurrent wake: same progress page. Failed wake: HTML 503 + retry on the next intercept.

## Focused checks

Gateway `composer check`: rector, pint, phpstan, and guidance TIA passed on this candidate.

`composer docs-lint`: passed (0 issues).

Targeted Pest (not the official TIA run):

- `tests/Unit/Domain/Hibernation/RuntimeHibernationTest.php` — 2 passed
- `tests/Unit/Infrastructure/Hibernation/*` — marker store, hibernator converger, unit renderer passed
- `tests/Feature/Domain/Hibernation/*` — policy, idle halt, wake readiness, FPM converge never called on wake/halt, leave desired-stopped stopped passed
- `tests/Feature/Console/RuntimeHibernatorCommandTest.php` — 1 passed
- `tests/Feature/Api/RuntimeActivationsTest.php` — owning node 200, stranger 403, production 503, busy 401, start fail 503
- `tests/Unit/Infrastructure/AppDev/AppDevCaddyConfigRendererTest.php` — development sites get `forward_auth` + activity log; production/node do not
- `tests/Feature/Configuration/NodeAccessRouteScopeTest.php` — `runtime-activation:app-instance` is a node-access route
- `tests/Feature/Http/Authorization/ServingNodeResolverTest.php` — 45 passed including AppInstanceHost
- `tests/Unit/Infrastructure/Processes/DockerProcessRendererTest.php` — on-demand `always` maps to `unless-stopped`
- AppDev Caddy publish harness (package-default retire, activation rollback, hibernation dir args) — 3 passed

## Builder gate

Root `composer check` (`bin/review-check`) on this candidate could not produce a passing receipt.

This VM had no `orbit-tia/v1/published` baselines. Pest TIA therefore recorded a fresh graph and executed the entire Gateway suite (3871 tests). Failures were AppDev checkout ACL/xattr traversal scripts and Doctor Git inspectors. Those tests do not assert hibernation behavior. Gateway `composer check` on the same head passed.

Receipt directory: `.git/orbit-checks/f44946512f8fbc44ce429e6a631acbcbb1594b32/review-ztk702lk/`

## Documentation

No plan existed. Docs written before codify, then lint-fixed.

- ADR 0074 accepted 2026-09-14
- `docs/reference/app-dev-runtime-hibernation.md`
- `docs/concepts.md`, `docs/architecture.md`, `docs/README.md`, `docs/reference/app-processes-and-schedules.md`
- `docs/generated/context.json`
