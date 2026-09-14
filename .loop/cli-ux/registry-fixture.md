# ORB-351 registry proof fixture

This fixture enumerates the actual CLI registry with Herdr enabled in a private temporary ORBIT_HOME. It dispatches no command, uses no Gateway profile, redirects bootstrap cache manifests to that private directory and removes it afterward. The caller's ORBIT_HOME is untouched.

From a retained candidate guest:

```sh
php /home/orbit/orbit/.loop/proof/orb-351-registry.php --root=/home/orbit/orbit --expected=/home/orbit/orbit/.loop/proof/orb-351-inventory.json --candidate=FULL_CANDIDATE_SHA
```

Use the real full candidate SHA supplied and verified by the harness. A CLI source/hash match does not authenticate an arbitrary commit label. The fixture prints its own hash, the expected-fixture hash, a source-manifest digest, PHP version, root and candidate label. The harness binds the mounted source and fixture artifacts to the candidate. No Git executable or reachable worktree .git link is needed inside the guest.

The expected JSON binds 106 public commands, 20 framework/internal surfaces, 126 registration keys, aliases, visibility, argument order and option names/defaults/types/descriptions, common global options, and 174 runtime/source/lock-file hashes. Full framework signatures were added from this observation because the older inventory held only their names, descriptions and visibility. Installed dependencies must match the candidate lock files. No absolute host paths occur in the compared registry object; preparation metadata retains the actual checkout path for provenance.

Exit zero means the exact recorded registry and source hashes match. A missing command, extra alias, signature change, framework drift or changed recorded source file exits one. That result is registry coverage only: every command's UX adoption remains Unverified.

Local verification was refreshed at 79a129447f58cec2cfb7fef3d922256e4162cf11 after reviewing the accepted ORB-204 Doctor family delta. A mutated public option default, a mutated framework option default and the stale Doctor workspace-family description were separately rejected with exit one. The existing caller-home sentinel remained unchanged. registry-fixture-checks.json retains these results. Actual Incus execution belongs to the root proof plan and has not been performed by this task.

To inspect a later candidate without dispatching commands:

```sh
php .loop/proof/orb-351-registry.php --root="$PWD" --inspect
```

Review the new registry against this expected fixture before updating it. Do not regenerate expected signatures merely to make a failure pass. A reviewed source-only change may update the corresponding source hashes and preparation identity; a command or signature change requires inventory/matrix reconciliation. Root-owned commits after this observation need fresh candidate binding even if runtime hashes are unchanged.

The historical source-manifest.json is preserved unchanged. registry-source-identity.json is the current source observation. Retired deployment vocabulary is resolved in current source/docs after the upstream integration. Approved default-No/--yes consent changes remain implementation gaps and have not been applied by the foundation.

The current Doctor family description excludes workspace. The accepted main change is recorded in registry-delta-79a12944.json; older registry/source/check observations remain under registry-history/. No public command has gained a UX compliance verdict.
