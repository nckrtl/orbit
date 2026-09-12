# ORB-246 development record

Flow: discovery. Incus: required and exercised. No merge or deployment authorized or performed.

## Candidate

- Worktree: `/fast/worktrees/orbit/orb-246`, branch `orb-246`.
- Base: `1ca124147b22336ff38f1677e1c1d29a12fc0de9`.
- Documentation commit: `7d31beecd963b053013a486e1478dd9a986aea7e`.
- Implementation candidate: `3017b416a52007b5848a7f73322414b8ba9ffa52`.
- Independent preflight: `/root/orb246_plan_review`, PASS, draft artifact `688bb2eb391f8b4ba3851ce48f9ac9b0d01e3a8c`.

## Acceptance evidence

| Item | Result |
| --- | --- |
| 1. Acquisition identity | `DiscoveryGuestPreparerTest.php` verifies Gateway preparation before peers and PHP-FPM, current mounted PHP helper, current peer script through stdin, and standard-clone-only behavior in extended discovery. Fresh acquisition `c625005c2cb23fd34a75912ecef4f3bb` passed on slot 3, network `oe-5d99a076e7bf`, from snapshot generation `d5983f5bd21b-a2f0796e31c5` on slot 1. Stored public SSH hosts changed from `10.232.1.10/.11/.12` to `10.232.3.10/.11/.12`; stored Gateway endpoint and both saved peers changed to `10.232.3.10:51820`. |
| 2. Regenerated configuration and reachability | `bin/e2e-topology exec ORB-246 gateway --argv='["php","/home/orbit/orbit/.loop/read-peer-config.php","10.232.3.10"]'` passed. The actual Gateway configuration repository selected `10.232.3.10:51820` for both peers; both overrides remain null. Root read-only peer checks compared the saved endpoint with `wg show orbit endpoints`; both matched that generated endpoint. `bin/e2e-topology verify ORB-246` passed: `wireguard.reachability` observed `app-dev,app-prod:wireguard-route+ssh`; `https.gateway-internal` observed `https://gateway.orbit/up:vpn-dns+reachable,tries=1`. |
| 3. Preservation | The exact `.loop/acquire-observed.php` launcher forwards ordinary acquisition processes unchanged and runs read-only ownership-bound probes before Gateway preparation and after peer repair. `.loop/clone-observations-c625005c2cb23fd34a75912ecef4f3bb.json` retains the before/after states. All 21 normalized Gateway table hashes and all file fingerprints match, including keys, private addresses, DNS, timestamps, SSH ports, roles, workloads and unrelated configuration. Endpoint host components and the three public addresses are the only normalized fields. Executable guest tests additionally cover null/absent settings, per-peer precedence, distinct non-default ports, idempotence, and exact preservation of peer configuration bytes outside its endpoint. |
| 4. Failure and rollback | Executable guest tests reject invalid addresses, missing/duplicate/inactive inventory and roles, foreign/secret/malformed settings, mismatched peer ports, missing or malformed peer config, and SQL publication failure; all failed Gateway transactions retain their original tables. Preparer tests refuse failed/malformed publication before peers. Two full acquisition tests assert no ready record, forgotten lease after successful cleanup, exact three-VM deletion and exact network deletion, with no readiness/provisioning calls. First live attempt `29cbf566ef92f4c8f3a7d7d5cb180fc1` exposed the implementation's PHP stdin privilege-switch problem; acquisition failed, exact clones and network were removed, and status returned `absent`. Other attempts and stopped snapshot VMs were preserved. |
| 5. Documentation | `docs/reference/incus-topologies.md` describes automatic identity preparation, preservation, conflicting inputs and rollback, without a manual provisioning step. `composer docs-build` and `composer docs-lint` passed; the generated index did not change. |

## Checks

- `apps/e2e`: `composer test:affected` passed twice, including after the final transport correction: 1,488 tests, 7,990 assertions; final run 91.192 seconds. New guest cases executed, not merely a TIA skip.
- `apps/e2e`: `composer test -- --processes=2` exited zero with no affected tests; this is a cached follow-up, not additional acceptance evidence.
- `apps/e2e`: `composer check` passed after the final correction: guidance 12 tests/136 assertions, Rector, Pint, PHPStan.
- `apps/e2e`: `vendor/bin/pint --dirty --format agent` passed; the first run formatted changed test files.
- Root `composer docs-lint`: zero issues/errors/warnings.
- PHP syntax checks for the guest resource and changed test files, shell syntax check for peer repair, and `git diff --check` passed.
- Builder gate: passed on clean candidate `3017b416a52007b5848a7f73322414b8ba9ffa52`, tree `2acbc13f5e4894e27c9a3cb6379582b25dd5f217`. Receipt: `/home/nckrtl/orbit/.git/orbit-checks/3017b416a52007b5848a7f73322414b8ba9ffa52/review-u96_67_z/result.json`. All 15 strict validation, project check, and affected-test commands exited zero across CLI, Docs, Gateway, E2E, and PHP SDK; `passed` and `unchanged` are true.
- After commit, `bin/e2e-topology sync ORB-246` and `bin/e2e-topology verify ORB-246` passed again. Both recorded host and guest SHA equal `3017b416a52007b5848a7f73322414b8ba9ffa52`, with clean mounted source. Private SSH passed at 18:31:34 UTC and internal HTTPS passed at 18:31:33 UTC on 2026-09-12.
- No browser test or browser inspection: this change has no user-visible UI surface. Incus exercises the real network, processes, privilege change and filesystem boundary.

## Delivery details and limits

- The first implementation tried to run the PHP helper through `/dev/stdin` after switching to the Orbit user. A live check rejected that transport. The final implementation runs current source from the verified Gateway worktree mount. App-prod still receives the current Bash resource through stdin because it has no checkout. No snapshot refresh is needed.
- Strict peer validation is selected only by the optional expected-endpoint argument used in discovery. Both existing one-argument compatibility tests remain unchanged.
- The shared topology test fixture now returns Gateway publication data, distinct clone addresses and realistic owned-resource deletion state for the acquisition regression. This is test support, not additional runtime behavior.
- No product acceptance deviations; no DNS-policy, role/workload reprovisioning, public command, worktree-create, VM-capacity or snapshot changes.
- Helper `/root/orb246_guest_tests` added only executable guest tests. The root lead inspected the diff and ran all combined tests/checks. The independent preflight reviewer did not implement the change.
- No temporary firewall rule, endpoint override, DNS override, or role-provisioning workaround was used on the successful topology.
- ORB-242, Commander, other worktrees, promoted snapshots, and the live fleet were not changed. The new three-Node discovery topology remains available for independent inspection.
- This verifies fresh acquisition and subsequent configuration generation, not full peer reprovisioning or role restart. The separate ORB-242 work owns its DNS/AppArmor provisioning behavior.

Discovery development only; isolated acceptance proof not run.
