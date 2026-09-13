# ORB-278 development record

Flow: discovery. Incus: not required. Candidate: d0f44fa8ca12ccd66b1645ea9a23c69342c9944a (branch orb-278, base origin/main 3a12dad1).

## Change

- `apps/gateway/app/Infrastructure/AppInstances/RemoteProductionAppInstanceSourceLifecycle.php`: `inspectProfile` inspects the recorded `checkout_path`, accepted only when it equals `production_home` or matches `<home>/releases/<one segment>` (not `.` or `..`); any other path throws `app-prod.source_metadata_unsafe` at step `production-source-classification` before the SSH command. The script receives `user, root, checkout` and uses `$checkout` where it used `$home/releases/initial`.
- `apps/gateway/app/Infrastructure/AppInstances/NativeProductionAppInstanceProvisioner.php`: `recoverActiveSourceProfile` writes `source_is_laravel` and, only when `selected_php_version` is null and the inspected version is a string, `selected_php_version` plus `ProductionPhpRuntimeIdentity::forProvisioning(...)->attributes()` in the same update. `forProvisioning` throws `app-prod.php_runtime_identity_invalid` (409) before the update.
- `apps/gateway/app/Infrastructure/AppInstances/NativeDevelopmentAppInstanceProvisioner.php`: `recoverActiveSourceProfile` keeps a recorded `selected_php_version` and stores the inspected one only when it is null.
- `docs/reference/php-runtime.md`: creation-retry paragraph split; new paragraph describes the recovery retry.

## Checks

| Check | Where | Result |
| --- | --- | --- |
| `vendor/bin/pest --compact tests/Feature/Infrastructure/AppInstances/RemoteProductionAppInstanceSourceLifecycleTest.php` | macOS | 16 passed, 156 assertions |
| `vendor/bin/pest --compact tests/Feature/Api/AppInstancesTest.php` | macOS | 86 passed, 818 assertions |
| `vendor/bin/pest --compact` on AppInstanceEnvironmentTest, RoutesTest, Api/CloneAppInstanceTest, AppInstanceDeploymentLayoutTest, Domain/ProvisionProductionAppInstanceTest, Domain/CloneAppInstanceTest, Domain/AdoptProductionLayoutTest, ProductionPhpCacheTest | macOS | 143 passed, 902 assertions |
| `composer test:affected` (apps/gateway; TIA skipped without pcov, full suite) | macOS | 3633 tests: 3340 passed, 234 failed, 46 errors, 13 skipped; failures in 20 Linux-only Infrastructure/Unit suites (RemoteDevelopmentAppInstanceSourceLifecycleTest 107, ApplicationStateInspectorsTest 24, AppDevInfrastructureTest 14, OpenSslGatewayCertificateIssuerTest 11, ...); none in a touched suite |
| `vendor/bin/pest --tia --fresh --compact` (apps/gateway) | beast Linux at d0f44fa8 | 3633 passed, 20892 assertions, 185.06s, all passed (trailing "Could not write the dependency graph" is the cache write) |
| `composer check` (apps/gateway) | macOS | guidance 22 passed, rector passed, pint passed, phpstan 0 errors |
| `composer check` (root, Builder gate) | beast | Candidate gate PASSED at d0f44fa8; receipt /home/nckrtl/orbit/.git/orbit-checks/d0f44fa8ca12ccd66b1645ea9a23c69342c9944a/review-lat7_8e5/result.json; gateway `test:affected` step reported "No affected tests found" (ORB-277) |
| `composer docs:lint` (apps/docs) | macOS | passed, 0 issues (first run flagged a 129-word paragraph; split) |
| `composer context:build` (apps/docs) | macOS | `docs/generated/context.json` unchanged |

## Prior candidate

PR #329 (candidate 88d6fb66, closed) added a separate `inspectRecordedProfile` method and updated seven test fakes. This candidate keeps the interface unchanged: provisioning and clone always classify at `releases/initial`, which is the recorded `checkout_path` at that checkpoint, so one bounded `inspectProfile` serves all callers. Its PHP-version guard (store only when missing) is reused for both provisioners, extended with runtime identity derivation for production.
