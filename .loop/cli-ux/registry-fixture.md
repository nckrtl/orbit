# ORB-351 registry proof fixture

This fixture enumerates the actual CLI registry with Herdr enabled in a private temporary ORBIT_HOME. It dispatches no command, uses no Gateway profile, redirects bootstrap cache manifests to that private directory and removes it afterward. The caller's ORBIT_HOME is untouched.

From a retained candidate guest:

```sh
php /var/lib/orbit-e2e/proof/orb-351-registry.php --root=/home/orbit/orbit --expected=/var/lib/orbit-e2e/proof/orb-351-inventory.json --candidate=FULL_CANDIDATE_SHA
```

Use the real full candidate SHA supplied and verified by the harness. A CLI source/hash match does not authenticate an arbitrary commit label. The fixture prints its own hash, the expected-fixture hash, a source-manifest digest, PHP version, root and candidate label. The harness binds the mounted source and fixture artifacts to the candidate. No Git executable or reachable worktree .git link is needed inside the guest.

The expected JSON binds 108 public commands, 20 framework/internal surfaces, 128 registration keys, aliases, visibility, argument order and option names/defaults/types/descriptions, common global options, and 176 runtime/source/lock-file hashes. Full framework signatures were added from this observation because the older inventory held only their names, descriptions and visibility. Installed dependencies must match the candidate lock files. No absolute host paths occur in the compared registry object; preparation metadata retains the actual checkout path for provenance.

Exit zero means the exact recorded registry and source hashes match. A missing command, extra alias, signature change, framework drift or changed recorded source file exits one. That result is registry coverage only: every command's UX adoption remains Unverified.

Local verification was refreshed at 79a129447f58cec2cfb7fef3d922256e4162cf11 after reviewing the accepted ORB-204 Doctor family delta. A mutated public option default, a mutated framework option default and the stale Doctor workspace-family description were separately rejected with exit one. The existing caller-home sentinel remained unchanged. registry-fixture-checks.json retains these results. Actual Incus execution belongs to the root proof plan and has not been performed by this task.

To inspect a later candidate without dispatching commands:

```sh
php .loop/proof/orb-351-registry.php --root="$PWD" --inspect
```

Review the new registry against this expected fixture before updating it. Do not regenerate expected signatures merely to make a failure pass. A reviewed source-only change may update the corresponding source hashes and preparation identity; a command or signature change requires inventory/matrix reconciliation. Root-owned commits after this observation need fresh candidate binding even if runtime hashes are unchanged.

The historical source-manifest.json is preserved unchanged. registry-source-identity.json is the current source observation. Retired deployment vocabulary is resolved in current source/docs after the upstream integration. Approved default-No/--yes consent changes remain implementation gaps and have not been applied by the foundation.

The current Doctor family description excludes workspace. The accepted main change is recorded in registry-delta-79a12944.json; older registry/source/check observations remain under registry-history/. No public command has gained a UX compliance verdict.

Historical candidate binding: `02c1a55c22cf12c31278ff415051907817425836`. Accepted DNS help/resolver changes and the three source hashes are in registry-delta-1ae62908.json. Verifier flags/semantics are unchanged. Exact positive and negative cases below were rerun for this candidate; registry coverage is not UX adoption.

Gateway-only main integration into02c1a55c preserved the compared registry and every recorded source hash. Exact comparison and all four negative cases passed again; see registry-delta-02c1a55c.json.

## Current App update registry refresh

Historical candidate binding: `c652f0b4af5645c3718461771bd48e830d53a8fd`, integrating main `6eeb4d6b2e5d4150ffe5d8abe93e4208e548b77d`. Accepted ORB-101 adds `app:update`; all106 previous public definitions and20 framework definitions are unchanged. Exact enumeration now requires107 public,20 framework and127 registration keys. The reviewed verifier count changes from106 to107. Both CLI and SDK lock hashes are unchanged; the runtime manifest adds UpdateAppCommand.php. Supplemental SDK request, tests and accepted documentation hashes are recorded separately in registry-source-identity.json.

The exact registry case and deliberate negative fixtures are rerun in registry-fixture-checks.json. Negatives include omitted app:update, a count-preserving replacement of app:update, changed update option/defaults, stale command source and a changed SDK lock hash, alongside public/framework/DNS regressions. These checks establish coverage and drift detection only. All107 command UX statuses remain Unverified. Root owns candidate-bound Incus execution and publication. Prior02c1a55c artifacts/verifier remain preserved under registry-history; the published d261c10264b6663a744a63c314a996315ed72ba5 proof/export is immutable and was not modified.

## Current transfer registry refresh

Current candidate binding: `c5cd0bd521483edc197175c5f5ecc895b85e82e0`, integrating main `44fa6c92dcdbd39ae40b5b3867554dce0de9c608` after transfer main `e89af206eb2066d7ac9709dbc08ada5da522ecbb`. The verifier now requires108 public and20 framework commands. Exact comparison covers128 keys and176 runtime/source/lock hashes. The source delta is TransferInstanceCommand.php plus CommandVocabulary.php; locks and prior public/framework definitions are unchanged. Registry negatives include omitted transfer, a count-preserving transfer replacement, changed force default, transfer source, and stale vocabulary source, alongside prior App/DNS/framework checks. All command UX adoption remains Unverified.

Guest proof inputs are staged under `/var/lib/orbit-e2e/proof/`; the candidate source root remains `/home/orbit/orbit`. The retained-guest example above now uses those actual fixture paths. Historical fixtures and checks are preserved under registry-history/c652f0b4af5645c3718461771bd48e830d53a8fd/. Root owns new candidate-bound Incus execution/publication.
