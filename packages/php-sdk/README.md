# Orbit PHP SDK

The typed Saloon client for the Orbit gateway API.

The SDK contains the transport defaults, response envelopes, errors, and typed
requests needed by the small public CLI surface. It does not depend on the
gateway application.

During monorepo development, `apps/cli` consumes this package through a
Composer path repository with symlinking enabled.

The SDK exposes exactly 72 public Gateway operations. It preserves typed
payloads, bounded responses, structured errors, and request IDs without
applying Gateway policy. It does not define command-line presentation or
remote execution behavior.

For example, typed Tool transport stays small and explicit:

```php
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

$response = $connector
    ->send(new InstallToolRequest(7, 'composer', 'vendor/package', '^1.2'))
    ->dto();

assert($response instanceof ToolResponse);
```

## AppInstance environment

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

## Requirements

- PHP 8.5
- Composer 2

## Quality

```bash
composer test       # full Pest suite (parallel, no TIA)
composer format     # Laravel Pint formatter
composer check      # guidance, Rector, and Pint and PHPStan checks
```
