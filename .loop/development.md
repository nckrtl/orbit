# ORB-263 development

Flow: discovery
Candidate: a5a649b124540b0bd0e1fb5e7c400d948b4f3e2b
Incus: not required

## Acceptance

- Creating a Route with `--target` for an AppInstance that already has a Route returns `route.target_conflict` and does not store the unused hostname.
- Proof: `apps/gateway/tests/Feature/Api/RoutesTest.php` — `returns 409 with route.target_conflict when creating a Route for an AppInstance that already has one`
- `vendor/bin/pest --compact tests/Feature/Api/RoutesTest.php`: passed, 14 tests, 158 assertions
- `apps/gateway` `composer check`: passed (guidance, rector, pint, phpstan)

## Notes

Root Builder `composer check` could not produce a valid receipt in this environment: TIA skipped without PCOV, so every project ran its full suite. Unrelated CLI and AppDev ACL/Doctor tests failed for missing host users and TIA baselines. Discovery development only; isolated acceptance proof not run.
