# Implementation loop

This page is for contributors who prepare a candidate for review. It describes delivery flow selection, local checks, and the Git references that hold plans and development evidence. [ADR 0049](../decisions/0049-keep-delivery-artifacts-off-the-merge-head.md) governs artifact storage; [proof plans](proof-plans.md) describes Incus evidence.

## Select a flow

Discovery is the built-in default for new clones and unselected worktrees. Proof requires an explicit selection. A worktree uses either `discovery` or `proof`, as governed by [ADR 0051](../decisions/0051-select-discovery-only-feature-delivery.md). The selected flow lives in ignored `.loop/flow.json` and travels with the candidate-bound artifact ref. Each plan, implementation, review, and closeout handoff names the flow. The reviewer verifies the published selection matches the handoff and local selection.

| Command | Result |
| --- | --- |
| `bin/loop-flow default --flow=discovery` | Selects discovery-only delivery for new worktrees in this repository |
| `bin/loop-flow default --flow=proof` | Selects the proof flow for new worktrees |
| `bin/loop-flow default` | Prints the repository default; an unset default is `discovery` |
| `bin/worktree-create ISSUE --flow=discovery` | Creates and bootstraps a worktree with discovery-only delivery |
| `bin/worktree-create ISSUE --flow=proof` | Creates and bootstraps a worktree with the proof flow |
| `bin/worktree-create ISSUE` | Creates a worktree using the repository default; keeps an existing worktree's selection |
| `bin/loop-flow init` | Initializes a manually created worktree from the repository default without replacing an existing selection |
| `bin/loop-flow status` | Prints the current worktree's flow; an unselected worktree is `discovery` |
| `bin/loop-flow select --flow=discovery` | Explicitly changes the current worktree's flow |
| `bin/loop-flow select --flow=proof` | Explicitly restores the current worktree's proof flow |

The default uses repository-local Git configuration `orbit.loopFlow`, shared across linked worktrees. It does not change selections already saved in worktrees. Selection commands also accept `--worktree=PATH`. A malformed selection fails instead of falling back. A flow switch changes reviewed artifacts: update the plan, publish for a new candidate, and review under the new flow. Switching flows never creates or deletes topology resources.

## Incus requirement

The `incus` issue label identifies acceptance that needs real machines. [ADR 0058](../decisions/0058-separate-incus-requirements-from-delivery-flow.md) separates that requirement from the selected flow. The implementer resolves the label and flow before acquiring resources; preflight and independent plan review precede acquisition.

| Issue label | Selected flow | Required topology |
| --- | --- | --- |
| No `incus` | `discovery` | None; use focused local tests and the candidate quality gate |
| `incus` | `discovery` | Discovery for development and acceptance observations |
| No `incus` | Explicit `proof` | None for automated-only acceptance; the other selected-flow rules still apply |
| `incus` | Explicit `proof` | Discovery for development plus a separate proof topology for isolated acceptance evidence |

Apply `incus` when acceptance depends on a real operating system, service manager, privilege boundary, network, certificate, filesystem ownership, or multiple machines. Planning and review check this classification. An acceptance item that needs Incus without the label is a contract mismatch to correct, not permission to omit its machine checks. The label never changes `.loop/flow.json` or the repository default.

Retained issue snapshots and plans may call this label `proof:incus`; interpret that name as the same Incus requirement. A label rename alone does not change acceptance or the selected flow and does not require new candidate artifacts or another preflight. Publish new issues and handoffs with `incus`.

## Scripted orchestration

An installed external controller can expose one command through `bin/loop ISSUE`. Set repository-local Git configuration `orbit.deliveryDriver` to its absolute executable path. The entry point works from primary main or a linked issue worktree and passes the primary repository to that driver. Orbit owns worktree preparation, checks, plan validation, artifact validation, and the role processes; the external controller owns worker prompts, identities, dispatch, retries, and merge decisions under its orchestration contract.

The Hermes controller starts new discovery issues by creating and bootstrapping the worktree, running root `composer check` once before planning, saving the Linear issue snapshot under `.loop/issue.json`, and starting a retained Builder in Herdr. This startup check validates the prepared dependencies and warms private caches. It does not replace the Builder's gate on the finished candidate. Existing worktrees without a controller journal retain their current orchestration, and explicit proof delivery retains its existing process.

Tom repeats `bin/loop ISSUE` on worker events. The controller validates the completed phase receipt, starts an independent plan or PR reviewer, resumes the retained Builder with findings or implementation authority, or lands an independently approved candidate. An idle worker is only a wake signal. Structural receipt validation does not decide review quality. `bin/loop ISSUE status` reports the recorded phase, worker identities, and any owned wait or error. Long preparation runs have a retained process, journal, and log; an accepted background command reports its process instead of claiming the phase completed.

Mutable session state and worker completion receipts live under `.loop/runtime/`, with the controller's durable journal in the Git common directory. Artifact save and publication exclude exactly `.loop/runtime/`. Plans, issue snapshots, development evidence, and proof inputs outside that directory remain in the artifact snapshot. The controller retains its review and completion records after worktree cleanup; recording a session or receipt never requires changing an already published candidate artifact.

## Start planning or implementation

The orchestrator assigns an issue, registered worktree, phase, Incus requirement, selected flow, and any prior handoff. The planner or implementer reads its branch, `HEAD`, and working changes from that worktree. A startup SHA copied into a prompt is context, not a candidate gate, unless the task explicitly requests work on a particular revision. A stale or mistyped startup SHA does not require stopping, changing the checkout, or fetching main to find a matching object. Record the observed revision in the handoff.

Verify that the worktree belongs to the assigned issue and preserve existing work. Resolve a wrong checkout, unresolved merge conflicts, or an unexpected writer before editing. Keep delivery phases serialized within each issue worktree; separate issue worktrees can progress independently. Main movement does not restart discovery planning or implementation. When correcting review findings, use the reviewed SHA as the findings' reference and assess them against the current worktree.

Exact binding starts with produced review inputs: the plan reviewer checks the actual plan and documentation artifacts, and the PR reviewer checks the pushed candidate, its artifacts, and the Builder gate receipt. The orchestrator copies these identifiers from verified Git or repository-tool output and merges only the approved candidate. A working revision is discovered locally; an approval remains bound to the revision reviewed.

## Discovery-only delivery

The discovery flow follows this order.

1. Run `bin/worktree-create` to update clean primary main and bootstrap the selected flow using compatible caches. Cache maintenance runs in the background.
2. Run preflight through `planning-features`, including the documentation audit and acceptance map.
3. Obtain an independent preflight review through `reviewing-feature-plans`.
4. For an `incus` issue, acquire discovery with `bin/e2e-topology acquire ISSUE WORKTREE` after preflight passes. Otherwise proceed without a topology.
5. Implement and run focused local tests and project checks. For an `incus` issue, use `shell`, `exec`, `sync`, and `verify` on discovery as development tools.
6. Commit and gate the candidate, publish and push its artifacts, then obtain independent code review.
7. Merge the approved candidate, then release any discovery resources and remove the worktree.

The issue's acceptance outcomes stay required. Existing Incus `Proof:` venues map to reproducible discovery observations, affected tests selected by TIA, and the Builder candidate gate. The handoff identifies each actual check and says `Discovery development only; isolated acceptance proof not run`. It does not claim immutable acceptance proof. A proof plan, proof fixtures, observations manifest, equivalence report, candidate-convergence attempt, or snapshot refresh is not required. The harness refuses `prove`, `equivalence`, and `candidate` for a worktree selected as `discovery`.

Discovery uses the existing isolated topology machinery and mounts the changing worktree. Acquisition validates the saved snapshot against its recorded generation and checks cold-base compatibility, ownership, capacity, and readiness. It does not require that snapshot to match current main. An incompatible cold base or absent snapshot still needs an explicit infrastructure repair. Optional extended discovery reuses the existing extension declaration format described in [Incus topologies](incus-topologies.md); its actions do not run as acceptance proof.

An advance of main alone does not require integration, another approval, proof, or local checks on a replacement candidate. The orchestrator merges when GitHub reports the approved candidate can merge and the Builder's gate receipt validates for that candidate. If actual conflicts block merging, the implementer fetches main, merges it into the branch, resolves conflicts, runs affected checks and a fresh candidate gate, publishes artifacts for the new head, and pushes again. The reviewer checks the resolution changes and affected acceptance items on that head; preflight does not restart.

GitHub CI is disabled and no GitHub status check is required for merge. [ADR 0059](../decisions/0059-make-the-builder-own-the-candidate-quality-gate.md) governs the local candidate gate. Conflict-free candidates can merge after exact-candidate review without including newer main.

Closeout verifies authoritative GitHub merge state and runs `bin/loop-flow verify-merge --candidate=SHA --merge=SHA`. The command requires the approved candidate as the exact second parent and the conflict-free merge tree of the recorded parents. That tree can differ from the candidate when main has advanced. Closeout advances the primary checkout, releases any discovery resources, and runs `bin/worktree-remove ISSUE`. Snapshot promotion and refresh are separate infrastructure operations in this flow.

## Proof delivery

The proof flow uses the same worktree, preflight, documentation, review, artifacts, and local checks. Issues with `incus` also require the isolated acceptance proof and captured evidence described in [Proof plans](proof-plans.md). Release idle discovery resources before handing the candidate to review. Candidate preparation includes current main; review and closeout enforce that binding. A later candidate uses the retained-proof evaluation and main-delta review rules in the existing skills. `verify-merge` requires the feature to include the merged base and the merge tree to equal the approved candidate's tree.

After every declared proof action and general verification exits `0`, the harness captures the proof result, action evidence, topology inventory, input manifest, and candidate identity before it permits successful-proof inspection. It retains every standard or declared extended proof Node through review. Reviewers may use proof `shell` and `exec` access with the ordinary guest privilege boundary, including commands that change live application or machine state. The harness records review actions, results, required-check status, and findings separately from the immutable captured proof. [ADR 0056](../decisions/0056-retain-proof-topologies-for-interactive-review.md) governs this retained-proof review lifecycle.

A required review check that fails or has an incomplete record prevents approval. An exploratory command failure remains distinct and does not replace a required result. A code or configuration fix requires a new candidate and fresh proof from declared inputs; an edit left on a reviewed machine and an equivalence report for the old proof cannot establish the fix. The old attempt may be released before replacement or explicit abandonment, but its captured proof and review record remain available.

After the approved candidate merges, `bin/e2e-topology closeout` verifies the accepted merge and refreshes the shared snapshot from merged main without promoting reviewer-modified live state. When the proof plan declared a cold snapshot replacement before construction, closeout instead constructs and verifies a clean replacement from merged main and its recorded inputs, then installs it transactionally.

A failed refresh or replacement keeps the complete retained proof topology, captured evidence, and review record for retry and preserves the prior usable generation or an explicit recovery state. Only a successful snapshot step permits the command to release the retained topology. Failed proof keeps its diagnosis and explicit release path.

## Local checks

Run `composer test:affected` for the affected behavior and failure modes, then run the changed project's `composer check`. The check runs the project's dedicated guidance configuration with fresh TIA so its contracts execute deterministically, then Rector, Pint formatting and syntax checks, and static analysis; it does not run the full project test configuration. Run `composer docs-lint` when documentation changes. After committing the clean candidate, the Builder runs root `composer check` before implementation handoff. This candidate gate covers all five projects with test impact analysis (TIA). Root `bin/test` and project `composer test` also use TIA.

Apply this check policy when an issue or retained plan names a generic full suite. The planner maps that wording to TIA development checks and the Builder's candidate gate, notes the policy correction, and returns it to the orchestrator for issue text alignment. Product acceptance outcomes stay required. Every Pest invocation enables TIA without a path, filter, group, or suite; Pest disables TIA for those partial selections even when `--tia` is present.

Each project keeps its formatter configuration in `pint.json` and its analysis configuration in `phpstan.neon`. `composer format` applies Pint's Laravel preset. `composer format:check` checks without editing, and `composer lint` is an alias for that check. `composer analyse` runs PHPStan with Larastan in the applications and PHPStan directly in the framework-neutral SDK.

Every project runs analysis at level 6. The configured paths keep the existing analysis scopes. Tests remain covered by Pint and Pest.

| Project | Analysis level | Analyzed paths |
| --- | --- | --- |
| CLI | 6 | `app` |
| Gateway | 6 | `app`, `routes`, `database/migrations` |
| Docs | 6 | `app`, `config` |
| E2E | 6 | `app` |
| PHP SDK | 6 | `src` |

CLI and E2E have counted exceptions for Larastan findings on inherited command helpers. The exceptions match exact command names, inputs, and files. Unmatched exceptions and new findings fail analysis.

`bin/bootstrap` seeds missing quality caches after installing dependencies, so `bin/worktree-create` gives new worktrees a warm starting point. It prefers compatible successful main publications of Pint and PHPStan result caches, then falls back to compatible registered worktrees. Compatibility requires identical project Composer lock files and that tool's configuration; main publications also bind the PHP minor version, checksum, and commit ancestry. Existing destination caches are preserved, and copied caches are independent files. Run `bin/worktree-cache` to seed an existing checkout after installing dependencies.

Pint stores its cache in `vendor/pint.cache`. PHPStan stores analysis results in `vendor/phpstan/cache/resultCache.php`. Both tools validate cached results and recheck changed inputs. PHPStan's path-specific compiled container and Larastan's migration cache stay local and rebuild when needed; bootstrap copies only portable result caches. A missing or incompatible source cache falls back to a normal first run.

`bin/worktree-create ORB-217` creates `/fast/worktrees/orbit/orb-217` on branch `orb-217`. The issue ID determines both names; no slug is needed. Set a different absolute base path with `git config orbit.worktreeRoot /path/to/worktrees/orbit`. The base must be outside the primary checkout, which prevents an enclosing ignore rule from hiding TIA inputs. Git stores this setting locally and shares it among linked worktrees.

Creation and `bin/worktree-remove ORB-217` also resolve an existing branch with an issue-ID prefix and legacy slug. Multiple matching branches are refused before worktree changes. Creation reuses an already registered branch at its current path. Existing worktrees can finish in their original locations. Discovery commands locate registered worktrees by issue branch or directory name; `--worktree=PATH` resolves an ambiguity. Cleanup also follows the registered branch, including after a worktree moves. The legacy `.worktrees` directory can be removed after its remaining worktrees have closed out.

Each feature worker uses one whole-repository worktree. Run Composer and Pest from the affected project directory, such as `apps/gateway` or `packages/php-sdk`. Projects keep separate dependencies, test configurations, and TIA baselines. Run checks in each project that a change affects.

Worktree bootstrap installs all five projects. Their Composer hooks apply the pinned Pest monorepo and consumer-autoloader fixes before generating autoloaders. This also runs on a direct `composer install` or `composer dump-autoload` in a project. Each worktree has its own installed package; setup needs no external local fork or shared vendor symlink. A modified or unsupported Pest build fails setup. Installations without development dependencies skip Pest setup.

Use these commands from the affected project directory.

| Command | Result |
| --- | --- |
| `composer test:affected` | Runs TIA with two parallel workers; selects affected tests when a valid baseline exists |
| `composer check` | Runs project quality checks without the full test suite |
| `composer test` | Runs the project suite through TIA in parallel |

TIA requires PCOV or Xdebug to record dependencies. The first run, or a run without a usable baseline, can execute the full project suite. Later runs reuse the baseline and select tests affected by changes. Run `test:affected` in each affected project for development feedback; acceptance evidence and the Builder candidate gate remain required. A TIA skip or zero selected tests is not new acceptance evidence.

Baselines stay separate between projects. Bootstrap seeds absent worktree caches from a compatible successful main baseline. A missing or incompatible publication still needs an initial recording run. Discovery and proof flow selection do not change test-runner setup.

## Candidate quality gate

The Builder runs root `composer check` in the clean issue worktree at the committed candidate. The command first seeds absent TIA caches, then runs strict Composer validation, project `composer check`, and `composer test:affected` in each project, sequentially. Project quality checks use the project's configured tools, including Rector in dry-run mode. A failure in any project returns directly to the Builder and prevents review dispatch.

The gate writes command logs and `result.json` under the Git common directory at `orbit-checks/<candidate>/review-*/`. The receipt records `role: builder`, the exact candidate and tree, each command, exit code, duration, and log path. It reports success only when every check passes and the candidate remains clean and unchanged. The Builder includes the path in its implementation handoff. The orchestrator validates it before review dispatch, and the reviewer validates the same receipt while assessing the candidate. The reviewer does not repeat the full gate solely to approve. A later candidate needs a new gate and approval.

GitHub's workflow is available only for manual diagnostics and remains disabled in the repository settings. It does not run automatically on pushes or pull requests. Acceptance evidence remains required; TIA selection alone does not establish acceptance. Missing or incompatible caches can cause the candidate gate or root `bin/test` to record a full project suite, but Pest always remains in TIA mode.

## Review handoff

The implementer returns one proposed PR body. The orchestrator publishes its evidence without replacing it with aggregate test counts, then reads back the body before requesting review. The reviewer uses that same body and the referenced artifacts. Keep the body concise and put detailed command output in the retained development record.

| Body field | Required content |
| --- | --- |
| Binding | `Issue: <ID>`, Incus required or not required, selected flow, candidate SHA, artifact ref and SHA |
| Acceptance | One row per item, in order: item number or brief outcome, test or discovery check, observed result, and precise artifact path or log reference for details |
| Checks | Focused tests and changed-project checks with results; `Builder gate: passed (<receipt>)` for the exact candidate |
| Documentation | Every changed maintained page with its purpose, audit findings and owners, or the applicable reason for unchanged documentation |
| Deviations and limits | Actual deviations and unverified behavior, or `none` |
| Discovery | Observations and resource state for `incus` issues, or `Incus: not required`; include `Discovery development only; isolated acceptance proof not run` for discovery flow |

One check can support several acceptance rows. A test count alone does not identify which outcome was checked. Discovery observations need enough context to inspect or repeat the check; they do not require a separate proof plan or immutable runtime capture. The reviewer assesses the evidence and performs additional focused checks when a concrete uncertainty warrants them.

If publication omits supplied evidence, the orchestrator restores it from the implementation handoff. If evidence is absent, the implementer supplies the missing check or states the limitation. The reviewer can continue substantive review while the body is corrected. Correcting only PR text or superseded generic check wording does not change the candidate, restart preflight, or require repeating a passing gate on that unchanged candidate. Product contract changes, source changes, and changes to published candidate artifacts follow their existing review rules. Approval still requires adequate acceptance evidence and the validated Builder receipt.

## Main test baselines

[ADR 0052](../decisions/0052-seed-worktrees-from-successful-main-test-baselines.md) governs baseline ownership. Each repository stores one successful publication per Composer project in its Git common directory under `orbit-tia/v1/published`. Linked worktrees share these publications and keep their writable Pest caches separate. Other repositories and separate clones need their own initial refresh.

Worktree creation requires clean primary main, fetches origin, fast-forwards main, and queues background maintenance without waiting for newer caches. It then calls bootstrap, which installs the patched Pest runner and copies a compatible main dependency graph into each absent private cache. Bootstrap preserves an existing cache and reports a cache miss without running tests. Manually created worktrees get the same setup through `bin/bootstrap`.

The repository commands manage this lifecycle.

| Command | Result |
| --- | --- |
| `bin/tia-cache seed` | Copies compatible published graphs into absent caches in the current worktree |
| `bin/tia-cache refresh --background` | Records a durable request, starts one worker when needed, and returns without waiting for checks |
| `bin/tia-cache refresh` | Requests refresh and waits for the repository worker; exits nonzero when a requested project fails |
| `bin/tia-cache status` | Prints test publications and maintenance state, including pending requests, current publications, failures, and log paths |
| `bin/tia-cache status --json --remote` | Reads authoritative remote main and returns maintenance state as JSON without changing the checkout or queue |
| `bin/worktree-remove ISSUE` | Verifies the feature merged, queues background refresh, then releases resources and removes the worktree |

Cache commands accept `--repository=PATH`. Seed and refresh accept repeatable `--project=apps/docs` options; their default covers all five Composer projects. Maintenance status covers the whole monorepo. Seed reports missing or incompatible graphs and leaves those projects cold; a cache miss does not fail setup.

Refresh fetches current main and advances an owned clean maintenance checkout under `orbit-tia/v1/checkout`. Its separate Git directory gives Pest an isolated cache while preserving the repository's remote URL. The command leaves the user's primary checkout and feature checkouts untouched. Closeout still advances a clean primary main when possible. A dirty maintenance checkout causes refresh to fail and retain its files for inspection.

Maintenance installs dependencies and runs `composer test:affected`, `composer format:check`, and `composer analyse` one project at a time, with two test workers and PCOV or Xdebug coverage enabled. It starts from compatible successful results and records again when tooling changes. TIA graphs and each quality tool publish independently after their checks succeed. Quality publications live under `orbit-tia/v1/quality` and contain portable results with dependency, configuration, runtime, checksum, and main-commit bindings. Complete publications at the same main commit and worker version avoid repeated installation and checks.

One worker holds the repository refresh lock. Requests live in `orbit-tia/v1/requests.json` and remain pending until a worker records their outcome. Repeated requests for the same target combine; requests arriving during a run remain pending when they name newer work. Each batch fetches newest main. An interrupted worker leaves recoverable requests. Failed checks retain their logs and previous successful publications without an automatic retry loop. Background workers have reduced CPU priority and keep their runner and logs in the Git common directory so worktree removal cannot interrupt them. When closeout does not use `bin/worktree-remove`, the orchestrator queues refresh explicitly after verifying the merge.

Publication replaces one complete snapshot atomically after testing succeeds on clean main. The snapshot contains only the portable dependency graph and its metadata. Seed checks the project, Pest patch and test configuration, Pest fingerprint including dependencies and PHP minor version, checksum, and commit ancestry. It does not copy affected-test lists, worker partials, coverage reports, or download state. A no-affected-tests run can publish a newer tested main commit while retaining an older graph anchor; both commits are recorded.

New worktrees may use the previous successful compatible publication while a refresh runs. Failed refreshes retain that publication and report the failure in `refresh.log`; retry with the refresh command after resolving the failure. Background baseline maintenance does not delay merge or resource cleanup. New worktree setup uses a compatible successful publication immediately after pulling main; missing or incompatible caches use cold checks. TIA remains optional local feedback; a warm cache or zero selected tests does not establish acceptance evidence.

## Maintenance recovery

[ADR 0054](../decisions/0054-maintain-main-caches-asynchronously.md) governs asynchronous maintenance. The orchestrator inspects `bin/tia-cache status --json --remote` through its existing watchdog. The result names remote main, whether a worker holds the lock, pending project requests, publication freshness, per-project command results, retained correctness failures, and log paths. A remote-read failure is an inspection error. A successful status command reports observed state; it does not mean checks passed.

When publications lag main and no worker is active, the orchestrator queues refresh. This also recovers merges outside its closeout flow and requests left after interruption. A reported failure is an owned recovery task. Cache transport, installation, and publication failures can use prior compatible caches or cold checks.

A nonzero test, formatting, or analysis command is a correctness signal requiring diagnosis and a hold on unrelated feature merges. Later infrastructure failures retain the earlier correctness failure. The failed tool must pass again before its retained failure clears. The orchestrator permits a reviewed repair or revert through that hold and clears it only after verification on main containing the repair.

One maintenance owner covers all five projects. Routine warming uses scripts; failures needing investigation use [maintaining-monorepo](../../.agents/skills/maintaining-monorepo/SKILL.md). The agent diagnoses the exact failed commit, preserves evidence, and performs source repairs in a separate worktree. It returns verification to the orchestrator instead of approving its own change or mutating primary main. Feature development can continue during a correctness hold. Cache freshness alone never holds creation, merge, or cleanup.

## Plan validation

The [plan template](../../.agents/skills/planning-features/template.md) is the
source for new worktrees and revised plans. Change the template, format number,
linter, and fixtures together when the format changes. Completed reviews and
saved artifacts do not need migration merely because the format advances.

| Command | Result |
| --- | --- |
| `bin/plan-lint check ISSUE` | Checks current format, issue, flow, required sections, filled acceptance cells, and unfinished scaffold text without writing |
| `bin/plan-lint record ISSUE` | Runs the same checks and writes `.loop/plan-lint.json` on success; removes old success before an attempted replacement |
| `bin/plan-lint verify ISSUE --artifact=SHA` | Rechecks the plan, requires its receipt, and compares local inputs with the submitted saved artifact |

All three commands accept `--worktree=PATH`. An existing worktree can use the
command and template from updated primary main without merging main into its
feature branch. Use the same tool version for recording and verification.
Verification without `--artifact` checks only the local plan and receipt.

The planner records after its final edit, saves with `bin/loop-artifacts save`,
then verifies that exact artifact before returning a completed handoff. The
orchestrator runs that verification before dispatching independent plan review.
A missing, failed, or stale receipt returns to the planner with the command's
error. An idle worker alone is not a completed plan. A real planning stop still
returns its classification and evidence through the existing resolution route.

The deterministic receipt binds the plan bytes, issue, selected flow, and
validator with its template. It is carried in the existing ignored `.loop/`
artifact snapshot. A plan edit, including review findings or verdict, requires
recording again before the next save. Unrelated main movement does not invalidate
it. The reviewer refreshes the receipt after recording its independent verdict.

Agents trust these structural checks and skip duplicate format inspections.
Reviewers still judge acceptance coverage, design, scope, ADRs, and proof quality.
The linter does not test code, require future files to exist, approve a plan,
or authorize development. Orbit owns this validation contract; the external
orchestrator owns dispatch and recovery.

## Artifact references

The local `.loop/` directory is ignored. It holds the flow selection, plan, plan review, development notes, and any proof plan and fixtures for one issue. The product candidate contains no `.loop/` paths. The commands use a temporary Git index and leave the feature head and its real index unchanged.

| Command | Result |
| --- | --- |
| `bin/loop-artifacts save ISSUE` | Saves the local workspace at `refs/orbit/loop/<issue-lowercase>/draft` for planning and plan review |
| `bin/loop-artifacts publish ISSUE` | Creates or reuses the artifact, validates it, then pushes `refs/tags/loop/<issue-lowercase>/<candidate-sha>` and prints the binding |
| `bin/loop-artifacts fetch ISSUE --candidate=SHA --expected-artifact=SHA` | Fetches the artifact ref, validates its structure and expected commit, then prints the binding |
| `git show <artifact-sha>:.loop/plan.md` | Reads the exact plan from the submitted snapshot |
| `git diff <candidate-sha> <artifact-sha> -- .loop/` | Shows the plan and every fixture for independent review |

Publication and retrieval use the same validator. Each artifact commit has the candidate as its sole parent and adds at least one regular file under `.loop/`. The candidate contains no `.loop` entry, and every product entry stays unchanged. Artifact files may use regular or executable mode; symlinks and gitlinks inside `.loop/` fail validation. Existing product symlinks remain valid. The validator reads immutable Git objects without interpreting or executing their contents.

Repeating publication with identical valid contents succeeds. Different artifacts for an already published candidate require a new candidate commit. Validation failure returns a nonzero exit and a specific error without a success binding; publication does not push an invalid artifact. Git fetch errors remain distinct from validation failures. A failed fetch validation can leave the downloaded ref and objects locally; their presence is not successful validation. Proof reads the committed artifact snapshot and refuses a working plan that differs from it.

The optional `--expected-artifact` argument accepts a full artifact commit SHA and applies only to `fetch`. Review and closeout require it to match the handoff. Callers that omit it still receive structural validation, but no comparison with a submitted artifact SHA. Successful output retains the `candidate`, `ref`, and `artifacts` fields. Draft plan review continues to use `save`.

Run the command from the assigned issue worktree. When that worktree has an older helper, invoke `bin/loop-artifacts` by its absolute path in the current primary main checkout while keeping the issue worktree as the working directory. The helper validates objects in the caller's repository. No main integration or worktree recreation is needed to use it.

The developer includes the artifact ref, SHA, and Builder gate receipt in the pull request body. The reviewer fetches and validates the submitted artifact binding and gate receipt, then reads the plan and every fixture. Successful artifact validation establishes the parent, file-boundary, file-type, and expected-SHA checks; agents do not repeat them manually. Validate again when either SHA changes and investigate failures before continuing. Artifact validation establishes no plan quality, acceptance, selected-flow evidence, quality-gate result, or approval.

Approval binds both SHAs. The orchestrator merges that exact candidate after approval and the Builder's candidate gate. Main integration creates a new candidate and requires artifact publication, a fresh Builder gate, and approval under the selected flow. Discovery-only delivery does not integrate main solely because it advanced.

## Existing worktrees

Preserve the local workspace when converting an existing feature. Run `git rm -r --cached .loop`, commit that candidate change, then publish its artifacts before the next review. The harness can read existing tracked proof inputs for retained evidence. Feature closeout requires the separate artifact binding and a candidate without `.loop/`.

Artifact refs remain after feature branch and worktree cleanup. They retain the exact candidate as their parent and permit later inspection of the reviewed inputs.
