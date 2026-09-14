# ORB-341 development record

Flow: discovery
Incus: not required
Candidate: `14c80a47697a723647a7665531c0fcdb221974cc`
Base: `origin/main` `83fb3b78bd809b8312454292924e8ba8ec3078cb`

## Change

`RuntimeHibernatorUnitRenderer::renderService` now requires the Gateway account and writes `User=` from that identity. `NativeRuntimeHibernatorConverger` republishes `User=orbit` by default (same account as Gateway FPM). A configured non-default account is republished as supplied. Remote hibernation marker SSH still uses sudo on app-dev Nodes. Artisan is not required to run as root.

Tip pattern: Gateway-local FPM hardcodes `orbit`; schedule oneshots take `User=` from the target. The hibernator is a Gateway-local artisan oneshot, so the renderer is parameterized like schedules and defaults to the Gateway account like FPM.

## Acceptance

1. Converged hibernator unit runs as the Gateway user on a Gateway check.
   - Renderer emits `User=orbit` (not `User=root`) for the default account.
   - Converger publishes that unit text through `sudo install`.
   - Evidence: `RuntimeHibernatorUnitRendererTest`, `NativeRuntimeHibernatorConvergerTest`.

2. Existing hibernator tests updated; live idle `systemctl start` not run here.
   - Tests assert `User=orbit` on the default path and `User=gateway` when the converger is given that account.
   - Live Gateway `systemctl start` was not executed (no `incus` label, no live host).

## Checks

- `apps/gateway` `composer guidance:check`: passed (22 tests)
- `apps/gateway` `composer check`: passed (guidance, rector, pint, phpstan)
- `apps/gateway` `vendor/bin/pint --dirty`: passed
- `git diff --check -- apps/gateway`: clean
- First cold TIA `composer test:affected`: 3916 tests, hibernator unit tests not in the failure list (passed). Residual failures were missing host tools (`caddy`, `getfacl`, `wg`) plus `ApplicationStateInspectorsTest` `origin_matches` on this Ubuntu 24.04 / git 2.43 cloud VM.
- After installing `caddy`, `acl`, `attr`, and `wireguard-tools`, TIA residual failures were only the four `ApplicationStateInspectorsTest` origin-match cases. Those files were not changed by this candidate.
- Builder gate: not passed on this cloud VM (no TIA publications; residual doctor inspector host failures). Reviewer should rerun root `composer check` on a seeded worktree.

## Documentation

none: issue has no `docs` label. `docs/reference/app-dev-runtime-hibernation.md` does not state `User=root`. ADR 0074 does not prescribe the systemd user.

## Deviations and limits

- No live `systemctl start orbit-runtime-hibernator.service` on a Gateway host.
- Builder candidate gate did not pass on this cloud VM.
- No Incus topology.

Discovery development only; isolated acceptance proof not run.
