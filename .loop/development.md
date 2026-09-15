# ORB-320 development record

Flow: discovery
Incus: not required
Candidate: 6430ae8107fcca000166f1ef9c5f1ecb3960a11b
Base: bf8395a5f823a4131ed4d0d02ec3b75487dc431e (origin/main; includes ORB-175 3092c3a8 and ORB-321)

## Outcome

`database:user:create` creates a MySQL user and database through an existing Node-targeted Docker MySQL Process, then registers or refreshes the Gateway connection. CLI does not open SSH. Secrets stay out of argv, responses, activity, and debug output.

## Acceptance

1. Process-boundary create + register/refresh — `apps/gateway/tests/Feature/Api/DatabaseUsersTest.php`, `apps/gateway/tests/Feature/Infrastructure/DatabaseConnections/RemoteManagedMysqlUserProvisionerTest.php`, `apps/gateway/tests/Feature/Domain/DatabaseConnections/ManagedMysqlProcessTest.php`, `apps/gateway/tests/Unit/Domain/DatabaseConnections/ManagedMysqlUserStatementsTest.php`, `apps/cli/tests/Feature/Database/DatabaseConnectionCommandsTest.php`, `packages/php-sdk/tests/Unit/Requests/DatabaseConnections/DatabaseConnectionRequestsTest.php`
2. Project checks + Builder gate — `orbit-checks/6430ae8107fcca000166f1ef9c5f1ecb3960a11b/review-coj9mw85/result.json`

## Checks

- `composer docs-lint`: passed
- CLI `composer check` + `test:affected`: 952 passed
- Gateway `composer check` + `test:affected`: 4067 passed
- PHP SDK `composer check` + `test:affected`: 591 passed
- Builder gate: passed (`orbit-checks/6430ae8107fcca000166f1ef9c5f1ecb3960a11b/review-coj9mw85/result.json`); no TIA selection warnings

## Discovery

Discovery development only; isolated acceptance proof not run.
