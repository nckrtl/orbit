# ORB-320 development record

Flow: discovery
Incus: not required
Candidate: e0269ecbb3e7cb75e51571b12ddd312f1556b640

## Outcome

`database:user:create` creates a MySQL user and database through an existing Node-targeted Docker MySQL Process, then registers or refreshes the Gateway connection. CLI does not open SSH. Secrets stay out of argv, responses, activity, and debug output.

## Acceptance

1. Process-boundary create + register/refresh — `apps/gateway/tests/Feature/Api/DatabaseUsersTest.php`, `apps/gateway/tests/Feature/Infrastructure/DatabaseConnections/RemoteManagedMysqlUserProvisionerTest.php`, `apps/cli/tests/Feature/Database/DatabaseConnectionCommandsTest.php`, `packages/php-sdk/tests/Unit/Requests/DatabaseConnections/DatabaseConnectionRequestsTest.php`
2. Project checks + Builder gate — `orbit-checks/e0269ecbb3e7cb75e51571b12ddd312f1556b640/review-6a3yojgz/result.json`

## Discovery

Discovery development only; isolated acceptance proof not run.
