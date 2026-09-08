# Feature plan

Issue: ORB-186
Review verdict: APPROVED (user-confirmed contract)

## Outcome

Operators can manage compatible bottled Homebrew Core formulae through the existing Tool lifecycle, with Herdr proving the generic path.

## Code boundaries

In:
- `apps/gateway/app/Infrastructure/Tools/HomebrewToolManager.php` and supporting Tool infrastructure: materialize or recognize the pinned shared Homebrew scope, validate canonical Core formulae, parse formula and bottle metadata, and implement version, install, update, and exact-removal operations.
- `apps/gateway/app/Providers/AppServiceProvider.php` and `apps/gateway/app/Domain/Tools`: register `brew` as the fourth adapter while preserving the on-demand manager lifecycle.
- Existing Gateway Tool actions and API failure tests: transport Homebrew adapter results through the existing stable, bounded Tool outcomes without exposing raw manager output.
- `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php` and existing Tool domain tests: cover bootstrap, recognition, validation, bottle eligibility, install, update, removal, and retryable failures.
- Existing CLI and PHP SDK Tool response tests: prove `brew` manager discovery and transport through the generic command and DTO contracts.

Out:
- Taps, casks, URLs, local formulae, Git references, caller-supplied options, private registries, source builds, and non-Linux Homebrew remain unsupported.
- Homebrew services and Herdr process lifecycle remain outside Tool operations.
- Tool Manager removal remains absent; the Homebrew prefix and active manager state remain after the final formula removal.
- No Herdr-specific adapter, catalog entry, process definition, or other package-specific product code is added.

## Documentation

- `docs/reference/tools.md`: adds the Homebrew shared scope, first-use recognition, Core and compatible-bottle limits, update and exact-removal behavior, and process exclusions.
- Documentation audit fixed the Tool reference's missing Homebrew behavior required by ADR 0043 and ORB-186; no findings remain reported for another owner.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Register `brew` and list it as uninstalled before first use | Tool manager registry and generic CLI/SDK transport | `apps/gateway/tests/Feature/Domain/ToolReadActionsTest.php`; `apps/cli/tests/Feature/Tools/ListToolCommandsTest.php`; `apps/cli/tests/Feature/Tools/InstallToolCommandTest.php`; `packages/php-sdk/tests/Unit/Responses/Tools/ToolResponsesTest.php` |
| Materialize or recognize Homebrew, verify a Core bottle, install Herdr, and leave it callable but not started | Homebrew adapter materialization, metadata parsing, and install operation | `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php`; Incus `install-herdr-through-homebrew` |
| Recognize only a compliant existing Homebrew scope without adopting formulae | Homebrew adapter scope verification and manager materialization failure mapping | `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php`; Incus `recognize-homebrew-scope` |
| Reject unsupported input and source-only or architecture-incompatible formulae before mutation | Homebrew package grammar and formula/bottle metadata gates | `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php`; Incus `reject-unsupported-homebrew-input` |
| Update through a verified bottle and retain a callable Tool when unchanged | Homebrew candidate and update operations through the existing Tool update action | `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php`; Incus `update-herdr-through-homebrew` |
| Remove only the recorded formula without autoremove and retain the manager | Homebrew removal plan and operation through the existing Tool removal action | `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php`; `apps/gateway/tests/Feature/Domain/RemoveToolActionTest.php`; Incus `remove-herdr-through-homebrew` |
| Return bounded stable failures for bootstrap and formula errors | Homebrew adapter exceptions and existing API error translation | `apps/gateway/tests/Feature/Api/ToolsTest.php`; `apps/gateway/tests/Feature/Infrastructure/Tools/HomebrewToolManagerTest.php` |
| Publish the Homebrew Tool contract | `docs/reference/tools.md` | `composer docs-lint` |

Incus observations: `observed_inputs: false`. The decisive proof includes remote Homebrew Git state, formula metadata, bottle installation, executable behavior, and absence of service lifecycle outside complete PHP coverage, so the proof uses the broad static input policy.

## Implementation order

1. Register the existing `brew` identifier with a Homebrew adapter and extend generic manager discovery coverage in Gateway, CLI, and SDK tests.
2. Add fixed Homebrew bootstrap inputs, protected APT prerequisites, prefix ownership and Git-origin verification, and recognition of the exact clean pinned installation.
3. Add strict formula grammar and parse `brew info --json=v2 --formula` into canonical Core, stable-version, Linux architecture bottle, and SHA-256 eligibility decisions.
4. Implement fixed-environment manager probes plus forced-bottle install and update commands, exact-formula removal, and no-autoremove removal plans.
5. Cover bounded failure translation and the existing managed-intent, retry, locking, and retained-manager paths with focused tests.
6. Create the Incus proof plan and fixture for Herdr install, existing-scope recognition, rejection, update, and removal, then run component and repository gates and prove the exact commit.

## Must preserve

- ADR 0001: Tool identity remains Node plus manager plus package; Orbit never adopts host inventory, and Homebrew prerequisites receive no Tool rows.
- ADR 0001: callers provide only Node, manager, package, and an optional constraint; the adapter validates the package and constructs fixed commands in its shared scope.
- ADR 0001: raw versions, conservative SemVer gates, no alternative release search, no automatic downgrade, exact managed-root removal, serialized mutations, retryable bounded failures, and redacted diagnostics remain unchanged.
- ADR 0001: Gateway owns manager policy and mutation, SDK owns typed transport, CLI owns rendering and prompts, and Tool operations own no processes.
- ADR 0012 and ADR 0042: Tool mutations remain limited to active Linux Nodes under Gateway-owned SSH management; roleless operator clients remain unmanaged.
- ADR 0042: Homebrew materializes only on first use, failed materialization is retryable, roles do not own it, the manager remains after its final Tool, and no public manager removal exists.
- ADR 0043: Homebrew uses one shared Orbit-owned Node scope whose bootstrap source, pinned version and revision, integrity, prefix, environment, and upgrade policy are code-owned.
- ADR 0043: an existing scope is recognized only when its ownership, upstream origin, version, and integrity satisfy policy.
- ADR 0043: only canonical unqualified Homebrew Core formulae with compatible verified bottles are accepted; taps, casks, URLs, local definitions, Git references, services, caller options, and source builds remain closed.
- ADR 0043: installed formulae are not adopted without Tool intent, removal targets only the recorded formula without dependency autoremove, and process lifecycle remains outside Tool operations.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
