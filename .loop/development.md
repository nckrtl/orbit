# Development record

Issue: ORB-233
Flow: discovery
Incus: not required
Candidate: `b59bfac70a6a5ae839740a4e35cf599c6601b933`

## Acceptance evidence

1. `RemoveNodeRoleActionTest.php` runs final-role removal for both `app-dev` and `app-prod` and confirms that all Tool Manager and Tool rows remain unchanged. The production action has no manager-retirement dependency or call.
2. The same focused test retains no-force preview, ordinary forced removal, manager-lock refusal, dependency refusal, policy checks, and existing results. A repository search confirms that the removed guard contract, implementation, calls, binding, dedicated test, and test double are absent.
3. The focused test injects each cleanup and baseline failure, confirms the role and dependents remain retryable, confirms the Tool stays installed at version `2.4.1`, and completes the retry with the manager and Tool still active and installed.
4. Gateway guidance, Rector, Pint, and PHPStan checks pass.

## Checks

- `cd apps/gateway && vendor/bin/pint --dirty --format agent`: passed.
- `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Domain/RemoveNodeRoleActionTest.php`: 31 passed, 200 assertions.
- `cd apps/gateway && ! rg -n 'NodeRoleToolIntentGuard|EloquentNodeRoleToolIntentGuard' app tests`: passed; no obsolete references remain.
- `cd apps/gateway && composer check`: passed; guidance 12 passed with 267 assertions, Rector passed, Pint passed, and PHPStan reported no errors.
- `git diff --check`: passed before commit.
- Independent root `composer check` receipt: pending formal PR review.

## Documentation

none: the change removes internal no-op plumbing; existing maintained documentation already states the role-independent Tool Manager and Tool behavior.

## Discovery

Incus is not required. No topology or discovery resource was acquired. Discovery development only; isolated acceptance proof not run.

## Deviations

- none

## Limitations

- The independent root local-review gate and its exact-candidate receipt remain pending for the formal PR reviewer.
