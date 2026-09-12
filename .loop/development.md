# ORB-227 development record

Flow: discovery
Incus: required
Observation: `candidate-clone-cli`
Discovery attempt: `dbbe33b193b300f26a49d525b49e6464`
Candidate: `3d71a592c395c3e62f2943f0790e36738850469b`

## Local implementation

- Added the typed PHP SDK `CloneAppInstanceRequest` for `POST /api/v1/instances/{candidate}/clone`. It sends the three required fields, omits only null branch and SQLite path values, redacts the SQLite path, and returns the existing typed AppInstance response with its embedded Route and request ID.
- Added the HTTP-only `instance:clone CANDIDATE NODE NAME` CLI command. It validates scripted IDs and required names before mutation, sends one clone request, reads retained release state through the existing typed request, and renders the target, configured branch, actual preview hostname, nullable selected release, and both request IDs.
- Updated clone help, `node:provision --tld` help, command inventory guidance, and SDK public-operation guidance.
- Added focused SDK and CLI tests for exact transport, DTO mapping, optional omission, safe failures, output, help, and the no-shell boundary.

## Discovery setup

- Acquired the standard discovery topology after independent plan approval. The first acquire reached the host-wide VM limit, so acquisition was retried with `ORBIT_E2E_INCUS_MAX_VMS=27`; no other issue topology was released.
- `bin/e2e-topology sync ORB-227` passed.
- Provisioned production Node `3` with TLD `prod.orbit`; Gateway request `a1e49f85-194e-428b-a3f8-60774a2fed70` succeeded in 60.052 seconds.
- The retained snapshot predated the shipped clone migrations. The first clean reservation logged `table app_instances has no column named clone_candidate_id`. Running the existing Gateway migrations applied seven pending migrations, including `2026_09_12_000000_add_clone_evidence_to_app_instances`.
- The snapshot's legacy candidate `1` had an untracked generated `composer.lock` and null `source_is_laravel` metadata. The dirty request failed as `instance.clone_candidate_dirty`; after removing that disposable file, the stale metadata caused `env.owner_unavailable`. No successful target was produced from the legacy candidate.
- Fresh clean candidates were then created through the public CLI. The successful observations use separate Apps because one App can have only one production placement per Node.

## No-seed clone

Source AppInstance `4`:

- App `2`, repository `https://github.com/slimphp/Slim-Skeleton.git`
- Branch `main`, commit `0ef01549870b3234a3a9f602904a39c3ed73f44c`
- Clean Git status before and after the clone and deployment sequence
- Stored source marker `ORB227_MARKER=no-seed-source-stable`
- Source Process list stayed empty
- Served-state response stayed HTTP `500`, 453 bytes, SHA-256 `83bfa6c25c4c0b0399fb1b3ced62508a23e1d515012a0e142a563230a5a01bcf`

Operator command input:

```text
instance:clone 4 3 orb227-no-seed-target --preview-name=orb227-no-seed --branch=main --json --no-interaction
```

First result, exit `0`:

```json
{"target_id":6,"configured_branch":"main","preview_hostname":"orb227-no-seed.prod.orbit","selected_release":null,"request_ids":{"clone":"849c4513-3ebe-4114-9304-39d26e15bae4","releases":"93408cfc-fb9b-4e11-afb1-08cfb51769b0"}}
```

Identical repeat, exit `0`:

```json
{"target_id":6,"configured_branch":"main","preview_hostname":"orb227-no-seed.prod.orbit","selected_release":null,"request_ids":{"clone":"b9b4b9c7-364a-4dcc-97ac-6ce4c59fa5c3","releases":"3f780b9f-04bd-4b1d-8eca-0c743652026a"}}
```

Target-only follow-up:

- Updated and synchronized `ORB227_MARKER=no-seed-target-only` with request IDs `ec8974ff-e1d8-46fe-b969-3e5c82df0e7d` and `36cc0fc7-6a9e-4ec6-aa0d-74e5ef6eccf2`.
- Replaced deployment configuration through request `327b3cc6-c3f6-4b8c-b3fd-bff95771a970`.
- Explicit deployment request `74fa8162-e6ce-4e72-8067-bd7bde7bb230` passed every phase and selected release `20260912141656-9cb7299e0e5d724e`.
- The target preview returned HTTP `200` with `orb227-no-seed-deployed`.
- Target Process `1` (`orb227-worker`) remained `desired_state=stopped`, `runtime_status=inactive`, `status=active`.
- Target Schedule `12644e85-d4b8-4b61-ac0a-3d150a7fab99` (`orb227-hourly`) remained `desired_timer_state=disabled`, `status=active`.

## SQLite-seeded clone

Source AppInstance `8`:

- App `4`, repository `https://github.com/codeigniter4/appstarter.git`
- Branch `master`, commit `8d252c8f594921ec4629bbb253f9f2e8921b9e93`
- Clean Git status before and after the clone and deployment sequence; the seed lives under ignored `writable/cache/`
- Stored source marker `ORB227_MARKER=seed-deploy-source-stable`
- SQLite source `/home/orbit/apps/orb227-seed-deploy-app/orb227-seed-deploy-source/writable/cache/orb227.sqlite`
- Source SQLite SHA-256 remained `81548535f1ff2ff2ebe5e126a60982edf3618dec2e494441e0b570435c79ed85`; `seed_items.marker` remained `orb227-seed-source-stable`
- Source Process list stayed empty
- Around the post-deployment identical clone retry, served state stayed HTTP `500`, zero bytes, SHA-256 `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`

Operator command input:

```text
instance:clone 8 3 orb227-seed-deploy-target --preview-name=orb227-seed-deploy --branch=master --sqlite-source-path=/home/orbit/apps/orb227-seed-deploy-app/orb227-seed-deploy-source/writable/cache/orb227.sqlite --json --no-interaction
```

First result, exit `0`:

```json
{"target_id":9,"configured_branch":"master","preview_hostname":"orb227-seed-deploy.prod.orbit","selected_release":null,"request_ids":{"clone":"6f4b088d-87f3-4b87-8f5b-984532ada40f","releases":"3ca2cd2b-03fc-4862-9500-00e0e8060056"}}
```

Identical pre-deployment repeat, exit `0`:

```json
{"target_id":9,"configured_branch":"master","preview_hostname":"orb227-seed-deploy.prod.orbit","selected_release":null,"request_ids":{"clone":"49b2b4a4-7519-4672-bb6d-b95d756cd642","releases":"2337892f-303d-49c7-bca6-e3e84c227575"}}
```

Database and target-only cleanup:

- The installed target `/home/orbit-app-4/database.sqlite` contained `seed_items.marker=orb227-seed-source-stable`.
- The operator deleted the copied `seed_items` row on the target only. Target count became `0`; the source row and source digest stayed unchanged.
- A later identical clone kept target ID `9`, the target count at `0`, and the already selected release. It did not replace the cleaned target database or configuration.

Target-only follow-up:

- Updated and synchronized `ORB227_MARKER=seed-deploy-target-only` with request IDs `4ebbd321-36a0-4d54-b769-f4299ededa26` and `d7f28a3b-d8ac-42fe-b424-386927778a8f`.
- Replaced deployment configuration through request `9b565344-def3-4f4e-8941-667e9fc4ba09`.
- Explicit deployment request `a4d58785-e76d-49c8-ad55-e9f8ec7ea92f` passed every phase and selected release `20260912142222-c071c9d17ec3f574`.
- The target preview returned HTTP `200` with `orb227-seed-deployed`.
- Target Process `3` (`orb227-worker`) remained `desired_state=stopped`, `runtime_status=inactive`, `status=active`.
- Target Schedule `9d2fc4d2-6bdc-4900-8f4c-54ffef82bcc6` (`orb227-hourly`) remained `desired_timer_state=disabled`, `status=active`.
- Post-deployment identical clone request IDs were `0220edcd-349a-4ced-a269-f387e4af83fa` and `bd35d894-d205-492a-a445-988c320a5944`; it returned target `9` and selected release `20260912142222-c071c9d17ec3f574`.

## Additional bounded observation

AppInstance `5` from Symfony Demo also cloned with a SQLite seed as target `7`, and its identical repeat returned the same target. Its explicit deployment then failed at preparation because Symfony Demo tracks `.env`, while Orbit's production release layout requires `.env` to be its persistent symlink. This repository-specific incompatibility was not used as acceptance evidence; the clean CodeIgniter observation above completed the seeded clone and explicit deployment path.

## Verification

- `cd packages/php-sdk && composer test:affected` passed 490 tests and 2,018 assertions while rebuilding a fresh TIA graph. The earlier focused red-green run passed 12 tests and 132 assertions.
- `cd packages/php-sdk && composer check` passed 7 tests and 109 assertions, Rector, Pint, and PHPStan.
- `cd apps/cli && composer test:affected` found no further affected tests after its updated TIA graph. The focused red-green run passed 14 tests and 54 assertions.
- `cd apps/cli && composer check` passed 13 tests and 255 assertions, Rector, Pint, and PHPStan.
- `composer docs-build` rebuilt `docs/generated/context.json` without a Git change.
- `composer docs-lint` passed with zero issues, errors, and warnings.
- `git diff --check` passed.
- Retained Builder root `composer check` passed all five project validation, check, and affected-test gates on exact clean candidate `3d71a592c395c3e62f2943f0790e36738850469b`; receipt `/home/nckrtl/orbit/.git/orbit-checks/3d71a592c395c3e62f2943f0790e36738850469b/review-m1b91kqn/result.json`.
- `bin/e2e-topology verify ORB-227` passed for retained attempt `dbbe33b193b300f26a49d525b49e6464`.
- The discovery topology remains retained for independent reviewer inspection.

Discovery development only; isolated acceptance proof not run
