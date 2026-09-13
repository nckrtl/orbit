# Orbit PHP SDK

The typed Saloon client for the Orbit gateway API.

The SDK contains the transport defaults, response envelopes, errors, and typed
requests needed by the small public CLI surface. It does not depend on the
gateway application.

During monorepo development, `apps/cli` consumes this package through a
Composer path repository with symlinking enabled.

The SDK exposes exactly 97 public Gateway operations. It preserves typed
payloads, bounded responses, structured errors, and request IDs without
applying Gateway policy. It does not define command-line presentation or
remote execution behavior.

## App runtime definitions

The SDK exposes typed list, create, show, replace, and remove requests for App process and Schedule definitions. Create and replace requests send the caller's exact JSON document to the Gateway. Item and collection responses are immutable and bounded, preserve the request ID, and redact credential-shaped specification values. Collection responses omit definition commands.

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

The SDK exposes typed deployment-layout preparation for one AppInstance and an
optional explicit SQLite source path. The SDK exposes typed deployment configuration, deploy, rollback, and retained-release operations. Deployment streams are incremental, closeable, bounded, correlated, and never retried or replayed. Configuration commands and application output stay out of generic diagnostics.

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
Doctor accepts the current Gateway family set, including Schedule.

## Requirements

- PHP 8.5
- Composer 2

## Quality

```bash
composer test       # Pest suite with TIA (parallel)
composer format     # Laravel Pint formatter
composer check      # guidance, Rector, and Pint and PHPStan checks
```
