# Feature plan

Issue: ORB-126
Review verdict: PASS

## Outcome

Give every App one persisted repository identity that treats its supported SSH and HTTPS access forms as the same repository and rejects ambiguous ownership before creation or upgrade changes App state.

## Code boundaries

In:

- `apps/gateway/app/Domain/SourceControl/GitRepositoryIdentity.php`, `apps/gateway/app/Models/App.php`, and `apps/gateway/tests/Unit/Domain/SourceControl/GitRepositoryIdentityTest.php`: derive one bounded canonical host-and-path value from every supported scp-like SSH, `ssh://`, and HTTPS origin, ignoring transport, SSH user, and an optional terminal `.git` while preserving different hosts and paths. Store that value as App control-plane state, derive it for every new App model, and provide one App lookup by a checkout origin through the unique persisted identity. Keep the selected `repository_url` unchanged and out of the canonical value.
- `apps/gateway/database/migrations/*add_repository_identity_to_apps.php` and `apps/gateway/tests/Feature/Database/AppRepositoryIdentityMigrationTest.php`: preflight all existing Apps before schema or row mutation, derive each identity, collect every duplicate group in stable App-ID order, and refuse with only the conflicting IDs when any identity has more than one owner. With a clean preflight, backfill every App and make the identity required and unique. The rollback removes only the added identity boundary.
- `apps/gateway/app/Actions/Apps/CreateAppAction.php`, `apps/gateway/tests/Feature/Api/AppsTest.php`, and `apps/gateway/tests/Feature/Api/AppSourceDefaultsTest.php`: keep slug retry checks first, then reject a different App that already owns the derived repository identity with `app.repository_identity_conflict` before branch access or persistence. Translate a repository-identity uniqueness race into the same bounded conflict while preserving exact retry and slug-conflict behavior. Cover response, database, activity, remote-call, credential, and diagnostic non-effects on every refusal.

Out:

- Keep App update endpoints and actions absent. Do not switch a stored repository access URL, implement the ADR 0016 update state machine, or reconcile any checkout, clone, worktree, or shared-repository origin.
- Keep duplicate Apps and their AppInstances, Routes, source defaults, and access URLs unchanged when upgrade preflight refuses. Do not merge, delete, relink, or choose a winner.
- Keep Git credentials, remote branches, source adoption, AppInstance placement and relocation, source creation and removal, and Doctor behavior unchanged. Identity derivation performs no Git or remote command.
- Keep the Gateway App response, PHP SDK, CLI, E2E harness, root proof machinery, and unrelated components unchanged. The persisted canonical identity is an internal Gateway lookup key, and this automated-only issue adds no `.loop/proof/ORB-126.json`.

## Documentation

Documentation audit scope: ORB-126; pages selected by `composer docs-context` for component `apps/gateway` and the App and Repository identity concepts.

Fixed in `b6725641` and `7f32c8c6`:

- `docs/concepts.md`: defines an App as the owner of one repository identity and access URL and defines Repository identity as the transport-independent Git host and path with optional terminal `.git` removal.
- `docs/reference/apps.md`: states derivation, access-form equivalence, creation uniqueness and error codes, same-slug equivalent-URL refusal without switching, bounded validation and lookup, duplicate-migration refusal, and the separate explicit App update lifecycle. The later correction removes the old contract's unsupported update claims.
- `docs/generated/context.json`: indexes the Repository identity concept and ADR 0026 link; the final documentation build reproduced it without a further diff.

Reported: none.

Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check -- docs` passed; all documentation changes are committed.

## Acceptance map

| Acceptance | Boundary | Focused proof |
| --- | --- | --- |
| Supported SSH and HTTPS forms for the same host and path share one canonical identity, optional terminal `.git` is ignored, and different hosts or paths stay distinct. | `GitRepositoryIdentity.php`; canonicalization cases in `GitRepositoryIdentityTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Unit/Domain/SourceControl/GitRepositoryIdentityTest.php` exits `0` for scp-like SSH, `ssh://`, HTTPS, `.git` equivalence, and different-host and different-path cases. |
| A second App cannot claim an owned repository identity and refusal creates or changes no App. | App identity persistence and uniqueness; `CreateAppAction.php`; repository ownership API cases in `AppsTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Feature/Api/AppsTest.php` exits `0` with `app.repository_identity_conflict`, one unchanged App, and no branch lookup for the rejected request. |
| Exact App creation remains idempotent, while a different access URL through the same slug remains `app.identity_conflict` even when its canonical identity is equivalent. | Slug-first retry boundary in `CreateAppAction.php`; source-default retry cases in `AppSourceDefaultsTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Feature/Api/AppSourceDefaultsTest.php` exits `0` with the same App ID for identical input and the original stored URL after equivalent-URL conflict. |
| Checkout-origin lookup returns at most one App across supported transport forms. | App repository-origin finder backed by `repository_identity`; lookup cases in `GitRepositoryIdentityTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Unit/Domain/SourceControl/GitRepositoryIdentityTest.php` exits `0` for absent, SSH-created/HTTPS-looked-up, and HTTPS-created/SSH-looked-up origins without first-match ambiguity. |
| Upgrade preflight reports every duplicate identity before uniqueness is authoritative and preserves schema and rows on refusal. | Repository identity migration; `AppRepositoryIdentityMigrationTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Feature/Database/AppRepositoryIdentityMigrationTest.php` exits `0` for multiple duplicate groups, stable conflicting App IDs, unchanged refusal snapshots, successful backfill, required uniqueness, and rollback. |
| Repository validation and errors disclose neither embedded credentials nor raw remote diagnostics. | Bounded identity derivation and creation-conflict translation; API redaction cases in `AppsTest.php` | From `apps/gateway`, `./vendor/bin/pest tests/Feature/Api/AppsTest.php` exits `0` with credential and remote-output sentinels absent from responses, activity properties, exception messages, and debug text. |
| Maintained App documentation and generated context state the canonical identity contract and the update-lifecycle boundary. | Every page and generated index in the Documentation section | From the repository root, `composer docs-lint` exits `0`; `composer docs-build` followed by `git diff --exit-code -- docs/generated/context.json` confirms the generated index is current. |
| Gateway checks pass. | All Gateway implementation and test boundaries above | From `apps/gateway`, `composer check` exits `0`. |
| Every repository test suite passes. | All implementation, migration, compatibility, and documentation boundaries above | From the repository root, `bin/test` exits `0`. |

## Implementation order

1. Keep documentation commits `b6725641` and `7f32c8c6` as the reader-facing contract and leave the generated context clean after the final build.
2. Add the canonicalization and lookup cases to `GitRepositoryIdentityTest.php`, then implement the pure repository-identity derivation without network access or credential-bearing errors.
3. Add migration failure, success, constraint, and rollback coverage. Implement duplicate preflight before schema or row changes, then add, backfill, require, and uniquely index `apps.repository_identity`; update the App model to derive new-row identity and look up one App from any supported origin.
4. Add second-owner API cases for equivalent and exact repository URLs, including multiple stored fields, activity, branch-resolver calls, and race-safe failure. Update `CreateAppAction` to preserve slug-first retries and reject or translate repository-identity ownership conflicts before any new App becomes observable.
5. Extend source-default retry coverage so a different but equivalent URL still returns `app.identity_conflict`, leaves the selected access URL and source defaults unchanged, and performs no branch resolution or verification.
6. Complete API-level credential and remote-output sentinel coverage without widening accepted repository forms or exposing the internal identity. Run the focused tests after each change, then `composer check` in `apps/gateway`, root `bin/test`, `composer docs-build`, `composer docs-lint`, and `git diff --check`.

## Must preserve

- ADR 0016 creation boundary: `app:new` creates and remains idempotent only for the same complete creation identity and source defaults; conflicting input never updates the App. Repository access-URL changes remain a separate explicit update lifecycle, and ORB-126 adds no part of that operation or its clone-origin reconciliation.
- ADR 0016 stable identity and safety: the App database ID remains stable, validation happens before dependent work, and bounded failures expose no repository credentials or raw diagnostics. Existing clone paths, origins, branches, commits, Routes, roots, runtime projections, and update lifecycle state remain untouched.
- ADR 0026 ownership: one App owns one canonical repository identity and one supported access URL; supported SSH and HTTPS forms of one host and path are equivalent; another App cannot claim that identity; and the database uniqueness boundary also reserves this rule for the later update lifecycle without implementing it here.
- ADR 0026 lookup and migration: checkout lookup uses canonical identity and cannot select a first match; migration refuses every duplicate group before mutation; and refusal never merges, deletes, relinks, or chooses between conflicting Apps.
- Existing `GitRepositoryOrigin` rules continue to accept only supported scp-like SSH, `ssh://`, and HTTPS origins; reject whitespace, control characters, credentials, query strings, fragments, and unsupported schemes; and keep the operator-selected valid URL byte-for-byte as `repository_url`.
- Existing App creation keeps slug uniqueness, default-name behavior, exact retry response and activity identity, strict top-level JSON, normalized source defaults, omitted-branch resolution once, explicit-branch verification, stable `app.default_branch_unavailable`, request correlation, recursive defaults redaction, and no remote access on a conflicting retry.
- Existing legacy Apps retain nullable main branch and root values. App, AppInstance, Instance, Route, Workspace, source lifecycle, Doctor, PHP SDK, and CLI response fields remain unchanged; `repository_identity` is not a new public field.
- SQLite remains the central control-plane store. The migration preserves every existing App ID, slug, name, repository access URL, main branch, root, defaults, timestamps, relationship, and dependent row when it succeeds, and preserves both schema and rows when duplicate preflight refuses.
- Documentation commits, dependency manifests, E2E harness, proof machinery, unrelated components, GitHub, Linear, discovery and production state, and every Out boundary remain unchanged during implementation.

## Open questions

- none; the corrected ORB-126 contract, accepted ADRs 0016 and 0026, current App creation and validation seams, and named automated proofs determine the implementation.

## Deviations

- none.

## Review findings
