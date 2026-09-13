# ORB-305 development record

Flow: discovery

Candidate: `12540c7a0ee644c17c1e9627ff240c80207d1f2e`
Branch: `cursor/orb-305-homebrew-7-0-0-e3e0`
Base: `origin/main` (`40c692007a018397df69a406273f550a4306700d`)

## Change

- Pin Homebrew bootstrap to 7.0.0 at `d79ef822ab8136e393ed5f86e2b56afc68d04874`.
- When an existing prefix is Orbit-owned, official-origin, and clean, fetch that revision and check it out detached before the same verification a fresh install uses.
- Do not fetch or mutate a foreign, dirty, or mis-owned prefix.
- `managerVersion` accepts only `Homebrew 7.0.0`.

## Checks

- `HomebrewToolManagerTest`: 43 passed, 114 assertions.
- Local `git fetch --filter=blob:none origin <sha>` + detached checkout moved a 6.0.6 clone (`2b3683acbeac84c27669195235785694b72e253e`) to `d79ef822ab8136e393ed5f86e2b56afc68d04874`.
- `vendor/bin/pint --test`: passed
- `vendor/bin/rector process --dry-run`: passed
- `vendor/bin/phpstan analyse`: passed
- `git diff --check -- apps/gateway`: passed
- `composer docs-lint`: passed

## Limitations

- Issue has no `incus` label and no preflight; Incus topology was not acquired.
- `bin/pest-setup` on current main rejects Pest 5.1.4 (pin is 5.1.3). The 5.1.3 monorepo patch still applies and was used locally to run TIA.
- Cold TIA without PCOV ran the full gateway suite; later TIA selected an unrelated previously failed console test. Homebrew tests were already green.
- `composer guidance:check` fails on main-state Boost guidance drift (`BoostGuidanceTest` vs `AGENTS.md` record-rule wording). Not caused by this change.
- Root `composer check` / Builder gate was not recorded: guidance and host-tool gaps (caddy, getfacl, WireGuard) fail in this environment.

Discovery development only; isolated acceptance proof not run.
Incus: not required
