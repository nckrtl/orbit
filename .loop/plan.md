# Feature plan

Plan format: 1
Issue: ORB-324
Flow: discovery
Review verdict: PASS

## Outcome

An operator creates, lists, updates, and destroys named deploy steps on a production AppInstance and changes its branch with `orbit instance:update`, and a deployment runs the recorded steps in phase and placement order. Incus: required. Discovery is acquired after independent plan review. Proof instrumentation is not required.

## Code boundaries

In:
- Gateway storage and domain: add `app_instance_deploy_steps` (name unique per AppInstance, phase, command, timeout, explicit position within phase), migrate JSON `deployment_steps` into rows, drop that JSON column and `apps/gateway/app/Models/Casts/DeploymentStepsCast.php` (registered at `apps/gateway/app/Models/AppInstance.php`), keep `deployment_branch` on `app_instances`. Touch `apps/gateway/database/migrations/`, `apps/gateway/app/Models/AppInstance.php`, a new deploy-step model, `apps/gateway/app/Domain/AppInstances/Deployment/` (`DeploymentConfig`, `DeploymentStep`, resolver, placement), and `apps/gateway/tests/Feature/Database/AppInstanceDeploymentConfigMigrationTest.php`.
- Gateway HTTP: `apps/gateway/routes/api.php` route names `instance:deploy-step:list|create|update|destroy` on `/api/v1/instances/{instance}/deploy-steps` and `{step}` by name, plus `PATCH /api/v1/instances/{instance}` named `instance:update`. New controller, form requests, and actions under `apps/gateway/app/Http/` and `apps/gateway/app/Actions/AppInstances/`. Extend `AppInstanceData` so `instance:show` includes steps. Activity redaction in `RecordCommandActivity.php` and `CommandActivityTargetResolver.php`. Node-access map in `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`.
- Limits, placement, and exclusion: enforce unique name, 32 steps, 900 s per step, 3,600 s total, exclusive same-phase `before`/`after`, default end of phase, on every create, update, destroy, and document PUT. Share `AppInstanceEnvironmentOperationLock` (`env.operation_busy`) for step mutations, branch update, and document replacement. Replace the test that keeps document replacement outside that owner in `apps/gateway/tests/Feature/Domain/DeploymentOperationExclusionTest.php`.
- Execution and document: `DeployAppInstanceAction` and `AppInstanceDeploymentConfigResolver` read records in phase and placement order with each timeout. Document GET/PUT in `AppInstanceDeploymentConfigsController` and `UpdateAppInstanceDeploymentConfigAction` read and replace the same rows plus `deployment_branch`. Tests: `apps/gateway/tests/Feature/Api/AppInstanceDeployStepsTest.php` (new), `AppInstanceDeploymentConfigTest.php`, `AppInstancesTest.php`, `DeployAppInstanceTest.php`, `DeploymentConfigTest.php`, `CommandActivityTest.php`.
- CLI: `apps/cli/app/Commands/Instances/` add `instance:deploy-step:create|list|update|destroy` and `instance:update INSTANCE --branch=BRANCH`, each with `--json`; extend `ShowInstanceCommand` to print steps; update `CloneInstanceCommand` help to name the deploy-step commands. Tests: `apps/cli/tests/Feature/Instances/DeployStepCommandsTest.php` (new), `InstanceCommandsTest.php`, `CommandSurfaceTest.php`, `CloneInstanceCommandTest.php`.
- SDK: `packages/php-sdk/src/Requests/Deployments/` `CreateInstanceDeployStepRequest`, `ListInstanceDeployStepsRequest`, `UpdateInstanceDeployStepRequest`, `DestroyInstanceDeployStepRequest`; `packages/php-sdk/src/Requests/AppInstances/UpdateAppInstanceRequest` for branch; typed step responses; `AppInstanceResponse` includes steps. Tests: `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php` and the class inventory plus 110-operation count in `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` and `packages/php-sdk/.ai/rules/public-contract.md`.

Out:
- Do not remove `instance:deployment-config`, `GET|PUT /api/v1/instances/{instance}/deployment-config`, or their SDK requests. They read and replace the same records until a later issue removes them.
- Do not remove or change layout conversion (`instance:prepare-deployment`, deployment-layout routes, conversion action, layout table).
- Do not change deployment or rollback semantics: fetch, activation, `current` switch, PHP refresh, NDJSON events, cancellation, rollback-to-release, or retained-release list.
- Do not add deploy-step or branch update behavior on development AppInstances beyond the existing production-only refusal.
- Do not edit `apps/e2e/` harness, `bin/e2e-*`, or `docs/decisions/`.

## Documentation

- `docs/reference/deployments.md`: rewritten around named deploy-step records, same-phase placement, 32 / 900 s / 3,600 s limits, `instance:update --branch`, `instance:show` including steps, execution in phase and placement order, busy exclusion covering step and branch mutations, and the deployment-config document as a reader and atomic replacer of the same records.
- `docs/generated/context.json`: regenerated after the ADR 0073 link on that page.

Documentation audit:

Scope: ORB-324 with `composer docs-context` for `apps/cli`, `apps/gateway`, `packages/php-sdk`, and `AppInstance`, plus `docs/reference/deployments.md`.

Fixed:
- `docs/reference/deployments.md`: described one replace-all document as the store → describes named deploy-step records, placement, limits, the branch update, `instance:show`, and the document as a reader and replacer of the same records.

Reported:
- none

Issue-text mapping for the orchestrator: Acceptance Proof files stay as TIA development tests plus the Builder candidate gate. The Incus venue on the deployment item maps to a discovery `bin/e2e-topology exec` observation, not isolated proof.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Create records a before-activation step at the end of its phase; `--phase`, `--timeout`, `--before`, and `--after` select phase, timeout, and placement | Gateway deploy-step create plus CLI `instance:deploy-step:create` | `apps/gateway/tests/Feature/Api/AppInstanceDeployStepsTest.php` and `apps/cli/tests/Feature/Instances/DeployStepCommandsTest.php` |
| Duplicate name, unknown placement, thirty-third step, timeout over 900 s, or total over 3,600 s is refused without change | Gateway deploy-step validation | `apps/gateway/tests/Feature/Api/AppInstanceDeployStepsTest.php` |
| List returns phase and placement order; update and destroy address a step by name; `instance:show` includes the steps | Gateway list/update/destroy and show payload; CLI list/update/destroy and `instance:show` | `apps/cli/tests/Feature/Instances/DeployStepCommandsTest.php` and `apps/cli/tests/Feature/Instances/InstanceCommandsTest.php` |
| `instance:update --branch` changes the deployment branch without changing steps and refuses a development AppInstance | Gateway `PATCH /instances/{instance}` and CLI `instance:update` | `apps/gateway/tests/Feature/Api/AppInstancesTest.php` and `apps/cli/tests/Feature/Instances/InstanceCommandsTest.php` |
| Step mutation or branch change during a deployment or rollback of the same AppInstance is refused as busy | Shared `AppInstanceEnvironmentOperationLock` on step and branch actions | `apps/gateway/tests/Feature/Domain/DeploymentOperationExclusionTest.php` |
| A deployment runs the recorded steps in phase and placement order with each step's timeout | Gateway `DeployAppInstanceAction` plus discovery on beast | `apps/gateway/tests/Feature/Domain/DeployAppInstanceTest.php`; after plan review, `bin/e2e-topology exec ORB-324 app-dev --argv='["orbit","instance:deploy",INSTANCE,"--json"]'` following two recorded steps, capture `phase` events with `step_name` in that order, and read each timeout from `instance:deploy-step:list INSTANCE --json` |
| The deployment-config document reads and replaces the same records | Gateway document GET/PUT over the step table and branch column | `apps/gateway/tests/Feature/Api/AppInstanceDeploymentConfigTest.php` |
| SDK exposes create, list, update, and destroy deploy-step requests and an AppInstance update request | PHP SDK request classes | `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php` |
| Deployments reference describes deploy steps, placement, limits, and the branch update; generated context is current | `docs/reference/deployments.md` | `composer docs-lint` |

## Implementation order

1. Add the deploy-step table, migrate existing JSON steps, drop `deployment_steps`, keep `deployment_branch`, and resolve config from records.
2. Implement placement (`before` / `after` / end of phase) and enforce limits on every mutation, including document PUT.
3. Add Gateway deploy-step list/create/update/destroy and `instance:update` with Node-access, activity redaction, and production-only refusal.
4. Put step mutations, branch update, and document replacement on the shared AppInstance operation owner so a running deployment or rollback yields `env.operation_busy`.
5. Point deployment execution at the ordered records and keep NDJSON phase events and timeouts.
6. Keep document GET/PUT as an atomic reader and replacer of the same rows and branch.
7. Include ordered steps on `instance:show` and add the five CLI commands plus SDK requests, then extend CommandSurface, RepositoryGuidance, and the named tests.
8. After plan review, acquire discovery on beast from `/fast/worktrees/orbit/orb-324`. Exec `["orbit","instance:list","--json"]` on `app-dev`. If no `e2e-prod` AppInstance exists, import the candidate environment, clone with `["orbit","instance:clone",CANDIDATE_ID,PROD_NODE_ID,"e2e-prod","--preview-name=e2e-prod","--json"]` (`CANDIDATE_ID` from `instance:list --json` name `e2e-dev`, `PROD_NODE_ID` from `node:list --json` name `app-prod`), then `["orbit","env:sync","--instance=PROD_ID","--json"]`, matching `apps/e2e/resources/guest/converge-sample-app.sh` around line 196 (read, do not edit). Record `migrate` before activation and `optimize` after activation, run `instance:deploy INSTANCE --json`, capture streamed `phase` events for both `step_name` values in that order, and read timeouts from `instance:deploy-step:list INSTANCE --json`. Then show `instance:deploy-step:list` and `instance:deployment-config` agree. Show a refused duplicate name and a `--before` that names an unknown step. Keep the topology; do not run `release`, `prove`, `capture`, or `promote`.

## Must preserve

- ADR 0073: a production AppInstance owns named deploy steps with a unique name, command, phase, and timeout; CLI, Gateway, and SDK expose create, update, destroy, and list; placement is relative to a named step in the same phase and an unplaced step goes at the end of its phase; the Gateway enforces count and timeout limits on each mutation; the Gateway does not mutate a step while a deployment or rollback of the same AppInstance runs; the branch changes through an AppInstance update; cloning remains the sole producer of a production AppInstance and the first deployment produces the release layout. This issue keeps the replace-all document as a reader and replacer of the same records; it neither extends nor removes conversion, and it does not complete the ADR 0073 removal of that document or of layout conversion. Step 8 uses `instance:clone` as the producer.
- ADR 0046: Orbit still owns production release preparation, activation, and explicit rollback; an explicit deploy request is required; source is the configured branch with no caller-supplied commit; steps run as the AppInstance Unix user with bounded time and streamed output; Orbit does not insert unconfigured application commands; activation and failure boundaries stay unchanged.
- ADR 0071: command names are `instance:deploy-step:create|list|update|destroy` and `instance:update`; Gateway route names equal those commands; SDK classes carry the verb; no alias for a replaced name.
- Existing limits and name grammar in `DeploymentStep` and `DeploymentConfig` (32 steps, 900 s, 3,600 s total, unique names, default timeout 300 s).
- Command text stays out of Activity, validation errors, and generic diagnostics.
- `deployment_config.unavailable` for a non-production or incomplete production AppInstance on deploy-step and document endpoints.
- Empty step lists remain valid and run no application commands.
- Node-access: deploy-step and branch routes use `ServingNode::InstanceOwning`.

## Open questions

- Whether the disposable production sample can run `php artisan migrate --force` and `php artisan optimize` without extra setup. If either command fails for missing application state, record two commands that print distinct markers instead, still one before activation and one after.

## Deviations

- Document PUT joins the shared AppInstance operation owner. The previous test asserted replacement sat outside that owner; that case is replaced because the document now mutates the same step records the exclusion covers. Deployment and rollback semantics stay unchanged.

## Review findings

Verdict PASS. No blocking finding. Address each item below in phase 2 before the implementation handoff; none changes a boundary, an order step, or a proof.

1. addressed: ADR 0073 Must preserve now names cloning as the sole producer and that this issue neither extends nor removes conversion. Step 8 uses `instance:clone`.
2. addressed: Acceptance row 6 and step 8 observe order from `instance:deploy INSTANCE --json` phase `step_name` events and timeouts from `instance:deploy-step:list INSTANCE --json`.
3. addressed: Code boundaries name `DeploymentStepsCast.php` with the JSON column it reads; both leave in step 1.
4. addressed: SDK boundary uses `packages/php-sdk/.ai/rules/public-contract.md`.
5. addressed: Step 8 records the exact clone argv from `converge-sample-app.sh` around line 196, with `env:import` before and `env:sync` after, and checks for existing `e2e-prod` first.
