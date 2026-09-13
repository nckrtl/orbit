# ORB-269 development record

Flow: discovery. Incus: not required. Candidate: 73e793cf65514c76d90d3ac9eb5449d4e4b70bd8.

## Change

- `CreateAppInstanceAction::recordFailure()` refreshes the AppInstance and returns before any write when its status is active. The row, including `failed_step`, `error_code`, and `updated_at`, and its Route stay untouched.
- Failure recording for reserved, checkout-prepared, source-resolved, and provisioning AppInstances and their non-active Routes is unchanged.

## Tests

- Added `tests/Feature/Api/AppInstancesTest.php` case: create `dev`, then retry with `hostname=x.orbit` one minute later; the Gateway answers 409 `route.retry_conflict` and the AppInstance and Route rows equal their pre-retry attributes byte for byte (`getAttributes()`), with `failed_step` and `error_code` null.
- Red-green: with the product change stashed, the same test fails showing `updated_at` advanced by one minute and `failed_step=provisioning`, `error_code=route.retry_conflict` (log: orb269-red.log on the developer machine).
- Existing pre-activation failure tests remain in the suite: `persists unexpected provisioning failures before releasing the lease` (failed_step=provisioning on a source-resolved AppInstance, Route failed) and `keeps a failed attempt from overwriting a successful retry after lease release` / hostname-taken case (failed_step=source-prepare).

## Checks on macOS (developer machine)

- `apps/gateway`: `composer test:affected` ran the full suite because PCOV/Xdebug are absent: 3463 tests, 3204 passed, 203 failed. Every failure is in Linux-only Infrastructure/Unit/Console tests (`stat -c`, systemd, OpenSSL, remote source lifecycle, gateway bootstrap); none in AppInstancesTest. Same macOS-only failure set as on the ORB-268 candidate.
- `apps/gateway`: `composer check` passed (guidance Pest 22/22, Rector, Pint, PHPStan).
- Builder gate: root `composer check` on beast (Linux); receipt path in the handoff.
