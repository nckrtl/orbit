# ORB-348 development record

Flow: discovery
Candidate: b110a3322a48011dd3ceae87506a9c55fb9ff893
Branch: cursor/drop-retired-deployment-config-verbs-b7c3
Base: origin/main (25038b816423859874caa73e9c5dafba463ecb9e)
Incus: not required

## Acceptance evidence

1. Vocabulary page no longer lists `deployment-config` or `prepare-deployment`. `composer docs-build` rewrote `docs/generated/context.json` with no index change. `composer docs-lint` passed: `{"tool":"librarian","result":"passed","issues":0,"errors":0,"warnings":0}`.
2. `CommandVocabulary::allowsCommand('instance:deployment-config')` and `allowsCommand('instance:prepare-deployment')` are false in `apps/cli/tests/Feature/CommandSurfaceTest.php`, matching the existing unknown-command coverage for those names.
3. `cd apps/cli && composer test:affected` passed on a cold TIA graph: 901 tests, 5729 assertions. `cd apps/cli && composer check` passed (guidance 14/14, Rector, Pint, PHPStan 0 errors).

## Builder gate

Root `composer check` is not a usable receipt in this worktree: published TIA baselines are absent, so unchanged Gateway and E2E projects ran cold full suites. Gateway failed on missing host tools (WireGuard, getfacl/setfattr, Caddy). Stopped that run. Acceptance is proved by the CLI-scoped checks above.

Discovery development only; isolated acceptance proof not run.
