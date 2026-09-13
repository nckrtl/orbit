# ORB-311 development record

Flow: discovery. Incus: not required. Candidate head recorded at publish time.

## What landed

Named Herdr sessions compose a node-targeted Process (ORB-308), a private receive-only observer, JWKS-backed RS256 grants, Doctor family `herdr`, PHP SDK operations, and CLI commands.

Assumed Herdr CLI: `herdr --session {name} server --observe-listen=127.0.0.1:{port} --observe-mode=read-only --observe-jwks=https://gateway.orbit/.well-known/jwks.json`, plus `identity --json`, `terminal session list --json`, and optional `--handoff`.

## Focused checks

- Gateway Herdr + HTTP surface + Doctor + RemoveNode: 96 passed
- CLI Herdr + CommandSurface + Doctor: 30 passed
- PHP SDK `composer check` and `composer test:affected`: passed (547)
- Gateway `composer check`: passed
- `composer docs-lint`: passed after Lifecycle table and JWT wording fixes

## Incus gap

No disposable topology starts a real Herdr listener or streams panes into a browser. Two-Node Commander observe is covered by Gateway grant-contract tests.

## Builder gate notes

This Cloud Agent environment had no published TIA baselines. Cold `composer test:affected` records a full Gateway suite. CLI `composer check` fails `BoostGuidanceTest` because Boost ApplicationInfo calls `DB::connection()` in a subprocess and Laravel Zero does not register `db`. That failure is independent of Herdr. Docs lock still pins Pest 5.1.3 while `bin/pest-setup` requires 5.1.4.

## Documentation

New `docs/reference/herdr-sessions.md`. Cross-links in concepts, architecture, README, processes, tools, private DNS, and node provisioning.
