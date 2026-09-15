# ORB-351 foundation documentation audit

## Documentation audit

Scope: ORB-351 preflight in `/Users/nckrtl/orbit-worktrees/orb-351`, HEAD `25038b816423859874caa73e9c5dafba463ecb9e`, selected flow `proof` supplied by the orchestrator. Read-only `composer docs-context -- --component=apps/cli` passed and returned the CLI-linked decision set plus `docs/architecture.md`, `docs/tech-stack.md`, `docs/reference/cli-command-vocabulary.md`, and `docs/reference/incus-topologies.md`. The required new page is `docs/reference/cli-ux.md`. Contributor routing and navigation were inspected as explicit issue scope. No concepts filter was added: the foundation outcome/scope does not introduce a product concept.

Prepared standard SHA-256: `3fd1d150952a6222bb6820ae3cb8a47da26ad8a661077ccad1e1add75f6b7388`. This is a preflight target standard, not a claim that current commands comply. The published ORB-351 acceptance and attached ADRs 0014/0036/0058/0071 were read. Authority follows auditing-documentation and writing-documentation.

Fixed:
- None. This delegated audit permits reporting only; repository, Linear and formal plan were not edited.

Reported:
- The in-scope changes below belong to ORB-351. The out-of-scope product findings have separate owners and do not block the foundation.

Verification: filtered `composer docs-context` passed. No docs-build, docs-lint, Mintlify, guidance, bootstrap or project checks were run: this audit changes no repository file and the root is coordinating those checks. After the actual edits, the root owns docs-build followed by docs-lint, committed context regeneration, and Mintlify validation for navigation changes. This report is not their receipt.

## In-scope findings and exact changes

### 1. Make the standard discoverable by CLI context selection

The prepared page never names the literal component `apps/cli`. `apps/docs/app/Documentation/DocumentationContextMetadataExtractor.php:62-73` assigns components only when the page content contains their exact path. ADR links do not propagate component membership. Adding the page and rebuilding the index alone therefore does not make `docs-context --component=apps/cli` select it.

Owner: ORB-351. Add `apps/cli` naturally in the first paragraph, for example “This page tells authors and reviewers of commands in `apps/cli` how commands collect input and present results.” Regenerate `docs/generated/context.json`, then assert that the filtered command includes `docs/reference/cli-ux.md`. No docs-tool implementation change is needed.

### 2. Add concise navigation, with one canonical rules page

- `docs/README.md`: add a contributor-facing link to `/reference/cli-ux` beside CLI vocabulary or in the development references.
- `docs/docs.json`: add a small CLI/contributor navigation group containing `reference/cli-command-vocabulary` and `reference/cli-ux`. Current Mintlify navigation exposes neither page.
- `docs/reference/cli-command-vocabulary.md`: link presentation and interaction guidance to `/reference/cli-ux`, while retaining ownership of the current command vocabulary only.
- Root `AGENTS.md`: route CLI implementation/review work to the new designing-cli-commands and verifying-cli-output skills. Keep the role workflow intact; do not turn the generic skills into a new private orchestration phase.
- `apps/cli/AGENTS.md`, `.ai/rules/commands.md`, `.ai/skills/orbit-cli-development/SKILL.md`, and the existing command-designer skill: route to the canonical page and new skills rather than copy its rules.
- `apps/cli/README.md`: a short contributor link in Development is useful; avoid duplicating the standard. `docs/index.mdx` and the architecture product narrative do not need new rule bodies or cards merely to expose contributor guidance.

Owner: ORB-351. Links inside docs use root-relative page URLs; references from docs to repository skills use GitHub file URLs. Generated CLI guidance must be refreshed from `.ai` sources, not manually patched in installed skill copies. `.ai/rules/repository-bootstrap.md:21-30` and BoostGuidanceTest.php:348-367 bind source skill content to installed output. Preserve the existing command-designer entry point as a concise routing adapter unless its removal and every caller are deliberately reconciled.

### 3. Remove conflicting generic rules in the existing command-designer skill

`apps/cli/.ai/skills/command-designer/SKILL.md` has these conflicts:

| Lines | Existing rule | Reconciliation |
| --- | --- | --- |
| 23 | JSON only when machine-readable use is required | Link to the standard's data-returning public-command JSON requirement and command-specific streaming contract. |
| 29 | Every destructive command uses interactive consent or --force before one request | Link to approved independent-consent rules; existing force may be a safety override rather than consent. Do not force one-request execution onto preview/resolution/stream contracts. |
| 30 | Every JSON result is success/error; errors contain exactly three fields | Remove the invented universal envelope. Current success DTOs are command-specific; GatewayFailureRenderer.php:182-190 supports safe error details; deploy has NDJSON frames. |
| 12 | Only config.json may be stateful | Link to explicit local profile/trust/DNS contracts; current registration stores CA files and changes OS trust. |

Owner: ORB-351. Keep bounded typed HTTP transport and explicit input contracts. The prepared standard correctly preserves current machine schemas, distinguishes consent from override, and does not authorize a Gateway/SDK migration. The restored renderer/consent target can be documented now because ORB-351 publishes the standard and adoption machinery, not command compliance. Preserve its opening non-compliance statement.

### 4. Correct generic contributor boundaries that contradict current local/credential behavior

`apps/cli/.ai/rules/commands.md:9` limits privileged local changes to gateway:trust and dns:resolve, but gateway:add also calls native trust installation (`GatewayAddCommand.php:83`; `GatewayRootCaTrustService.php:60,91,265`). `apps/cli/README.md` already states that registration installs its root CA. Name all three explicit local adapters or link their owning reference; do not weaken the prohibition on operational SSH or hidden local transport.

`apps/cli/.ai/rules/app.md:9` bans all credential output. `CredentialsMetricsCommand.php:34-42` deliberately returns credentials under the current explicit Metrics contract. The prepared standard already makes the correct distinction: do not echo/log secret inputs; preserve an explicit credential-delivery contract, use disposable credentials for proof, keep raw artifacts private, and redact shared excerpts. Route the generic rule there instead of making credential delivery noncompliant.

Root CLI AGENTS' config-only state sentence should also route to explicit local contracts. Do not change product code to make it match the narrower stale guidance.

Owner: ORB-351, as correction of generic CLI guidance. No product authority change is needed.

### 5. Remove retired vocabulary from the current command reference

`docs/reference/cli-command-vocabulary.md:58` still advertises instance:deployment-config and instance:prepare-deployment. `apps/cli/tests/Feature/CommandSurfaceTest.php:358-359,384-385` rejects both; the active named deploy-step commands are asserted at lines 105-108 and tested in DeployStepCommandsTest.php.

Owner: ORB-351 for this code-derived reference correction within the returned docs-context page and live inventory scope. Remove the two retired actions and their layout/config result phrase, and keep named deploy steps in their existing family row linked to the deployment reference. Do not reintroduce commands or alter accepted ADR history. The operation-by-operation deployment reference remains owned by ORB-356/ORB-361.

## Out-of-scope product drift and owners

| Finding | Evidence | Owner and treatment |
| --- | --- | --- |
| Application hostname terminology and in-place Route change remain in current code/docs despite ADRs 0064/0065 | architecture.md describes application hostnames; commands.md:13 retains exact Route hostname and app_instance.hostname; current CLI --hostname and Gateway UpdateRouteAction still implement those contracts | ORB-256, confirmed In Progress. It explicitly owns CLI/Gateway/SDK/environment/Activity/docs cutover. Report it; do not perform a terminology-only schema migration in foundation guidance. ORB-354/355/356/357/361 already follow it. |
| Node-first generated naming conflicts with accepted ADR 0063 | RouteStateResolver.php:39 uses nodeTld before clusterTld; routes.md:27 describes the same order | ORB-354 tracks the unresolved implementation-owner assignment, with existing reconciliation owners ORB-191/192/193/194. Preserve that bounded Readiness item; foundation does not own precedence or Gateway behavior. |
| Architecture and CLI README still describe Doctor inspection of legacy Instance/Workspace state | architecture.md Applications paragraph; apps/cli/README.md Doctor example; RunDoctorAction.php:28-29,119-120 still wires both probes | ORB-88, confirmed Todo, owns removal of legacy product surfaces. This is a current runtime versus ADR0036 implementation gap, not prose that can truthfully be fixed by deleting the sentence alone. ORB-361 owns the later Doctor UX adoption. Do not make legacy compatibility a permanent foundation exception. |
| Historical ADR text mentions removed commands or superseded workflows | ADR0071 consequences still names workspace:php; ADR0014 historical sections describe former CI/site state | Accepted ADR history is not maintained current command documentation. Do not rewrite ADRs in ORB-351. Use live registry and current superseding authority, and make the standard link to authority rather than reproduce historical examples. |

One adjacent generic workflow discrepancy is also visible: `apps/cli/.ai/guidelines/orbit.md:16` (and its generated AGENTS block) says reviewers run the root gate, while current implementation-loop and ADR0059 assign it to the Builder. Owner: the repository-maintenance guidance owner through maintaining-monorepo, unless the root deliberately includes this small source-guidance correction while regenerating ORB-351 routing. It is not a CLI UX or topology behavior change; do not expand this audit into all five projects.

## Positive alignment and limits

The prepared standard separates input interactivity, output format, decoration and live repaint; respects explicit-only targets; preserves response/stream schemas and stdout/stderr ownership; rejects fabricated progress; requires actual frame transitions and terminal restoration; and requires source/launcher/candidate identity. It does not import the old Agent/SSH product architecture. ADR0014 supports incremental reuse in one root corpus; ADR0036 forbids resurrected retired surfaces; ADR0071 owns vocabulary; ADR0058 keeps Incus requirement distinct from the selected proof flow.

The current tech-stack and relevant Incus-topology guidance need no foundation-specific behavior rewrite. Keep topology acquisition, retention and closeout mechanics in their owning references. The new verification skill should link them and specify PTY capture/export as its own mechanism. A raw expected-failure command may exit nonzero; the enclosing verifier must assert that outcome and exit zero when the case passes. Combined PTY output does not establish stdout/stderr separation. No harness changes are required by this documentation audit.

The standard's adoption statement is essential: most current CLI renderers have not yet been migrated. Their unchanged behavior is not documentation drift against a page that explicitly defines a target standard with per-command adoption records. The root plan should list every in-scope page/guidance change above and carry every out-of-scope owner into its Documentation section and later PR body.
