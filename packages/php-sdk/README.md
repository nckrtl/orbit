# Orbit PHP SDK

The typed Saloon client for the Orbit gateway API.

The SDK contains the transport defaults, response envelopes, errors, and typed
requests needed by the small public CLI surface. It does not depend on the
gateway application.

During monorepo development, `apps/cli` consumes this package through a
Composer path repository with symlinking enabled.

The SDK exposes exactly 127 public Gateway operations. It preserves typed
payloads, bounded responses, structured errors, and request IDs without
applying Gateway policy. It does not define command-line presentation or
remote execution behavior.

## App runtime definitions

The SDK exposes typed list, create, show, update, and destroy requests for App process and Schedule definitions. Create and update requests send the caller's exact JSON document to the Gateway. Item and collection responses are immutable and bounded, preserve the request ID, and redact credential-shaped specification values. Collection responses omit definition commands.

## Schedules

The SDK exposes typed list, add, show, run, logs, complete, remove, and activate
requests for Node and AppInstance Schedules. Add requests preserve omitted
optional values separately from explicit values. Item and collection responses
are immutable, bounded, and redacted. Completion preserves the Gateway's empty
response and exposes only its validated response-header request ID. The Gateway
owns target resolution, validation, execution, and lifecycle policy.

For example, typed Tool transport stays small and explicit:

```php
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

$response = $connector
    ->send(new InstallToolRequest(7, 'composer', 'vendor/package', '^1.2'))
    ->dto();

assert($response instanceof ToolResponse);
```

## AppInstance deployment and environment

The SDK exposes typed deploy-step, deploy, rollback, and retained-release operations. Deployment streams are incremental, closeable, bounded, correlated, and never retried or replayed. Configuration commands and application output stay out of generic diagnostics.

The SDK exposes typed import, update, and synchronization requests for
AppInstance environment configuration. Import preserves omission and explicit
`false` for its optional replacement flag. Update preserves the exact string
value, and synchronization sends an empty JSON object. Each response contains
only the AppInstance ID, operation, changed flag, bounded key count, and request
ID. Environment values remain outside normal SDK diagnostics and errors.

## Doctor

The SDK exposes `RunDoctorRequest` and bounded typed report responses. It sends
`POST /api/v1/doctor` as JSON. It omits null filters and preserves explicit
filter values so the Gateway can validate them. It transports received health,
order, issues, and summary aggregates without applying Doctor policy.
Doctor accepts the current Gateway family set, including Schedule, Herdr, and Database connection.

## Herdr sessions

The SDK exposes typed list, add, adopt, show, restart, remove, and observation-grant
requests for Herdr sessions. Add, adopt, and list preserve a numeric Node ID.
Item operations use the numeric session ID. Observation grants send pane,
terminal, columns, rows, and the allowed HTTPS browser origin. Item and collection responses are immutable and
bounded and preserve the management mode and request ID. Adoption records an external service without taking over its lifecycle. Observation grant URLs stay out of generic diagnostics. The Gateway owns managed-session lifecycle, publication, and grant policy.

## Database connections

The SDK exposes typed list, show, add, update, remove, attach, detach, query, tables, schema, describe, and user create requests for Gateway-owned database connection records. Add and update send host, port, database, sqlite path, username, and password only when supplied. Attach and detach send an AppInstance selector, the connection slug, and an optional prefix. User create sends a numeric Process ID, slug, database, username, and password. Query sends SQL and an optional write flag against a registered slug. Tables, schema, and describe are bodyless reads. Item, collection, attachment, and inspection responses omit passwords and environment values, expose has_password on registry records, and redact credential-shaped values. The Gateway owns validation, encryption, persistence, Process execution, stored-environment writes, and inspection execution.

## Requirements

- PHP 8.5
- Composer 2

## Quality

```bash
composer test       # Pest suite with TIA (parallel)
composer format     # Laravel Pint formatter
composer check      # guidance, Rector, and Pint and PHPStan checks
```

Dependency inventory reads and scans use `ShowInstanceDependenciesRequest` and
`ScanInstanceDependenciesRequest` with numeric instance IDs. Both return the typed
`InstanceDependencyInventoryResponse`; inspect its nullable `succeeded` and each
ecosystem outcome even after HTTP 200. Development updates use
`UpdateInstanceDependenciesRequest` with the same numeric ID and empty JSON
object, and return typed step statuses plus nullable post-update inventory.
Inspect `succeeded`, each step, and inventory even after HTTP 200. See the
[dependency contract](../../docs/reference/instance-dependency-contracts.md)
for graph fields, freshness states, step outcomes, and SDK response limits.
