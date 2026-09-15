# ORB-320 development record

Flow: discovery
Incus: not required
Candidate: 03006e89de82bb26c03b5b095763f265e9c3e3a4
Base: 02623e8c3b7b654df7831c6839f273c20006aa38 (origin/main; includes ORB-175 3092c3a8, ORB-321, contributor-flow #438, and ORB-352)

## Outcome

`database:user:create` creates a MySQL user and database through an existing Node-targeted Docker MySQL Process, then registers or refreshes the Gateway connection. CLI does not open SSH. Secrets stay out of argv, responses, activity, and debug output.

## Acceptance

1. Process-boundary create + register/refresh — `apps/gateway/tests/Feature/Api/DatabaseUsersTest.php`, `apps/gateway/tests/Feature/Infrastructure/DatabaseConnections/RemoteManagedMysqlUserProvisionerTest.php`, `apps/gateway/tests/Feature/Domain/DatabaseConnections/ManagedMysqlProcessTest.php`, `apps/gateway/tests/Unit/Domain/DatabaseConnections/ManagedMysqlUserStatementsTest.php`, `apps/cli/tests/Feature/Database/DatabaseConnectionCommandsTest.php`, `packages/php-sdk/tests/Unit/Requests/DatabaseConnections/DatabaseConnectionRequestsTest.php`
2. Project checks + Builder gate — `orbit-checks/03006e89de82bb26c03b5b095763f265e9c3e3a4/review-45owqety/result.json`

## Checks

- `composer docs-lint`: passed
- CLI `composer check` + `test:affected`: 1027 passed
- Gateway `composer check` + `test:affected`: 4066 passed
- PHP SDK `composer check` + `test:affected`: 591 passed
- Builder gate: passed (`orbit-checks/03006e89de82bb26c03b5b095763f265e9c3e3a4/review-45owqety/result.json`); no TIA selection warnings

## Discovery

Discovery development only; isolated acceptance proof not run.
