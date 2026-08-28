# Orbit PHP SDK

The typed Saloon client for the Orbit gateway API.

The SDK contains the transport defaults, response envelopes, errors, and typed
requests needed by the small public CLI surface. It does not depend on the
gateway application.

During monorepo development, `apps/cli` consumes this package through a
Composer path repository with symlinking enabled.

The SDK exposes exactly 44 public Gateway operations (the original 38 plus
tool manager list, tool list, show, install, update, and remove). It preserves
manager, package, nullable version constraints, outcomes, structured errors,
and request IDs without applying policy. It does not define CLI presentation
or manager command behavior.

For example, typed Tool transport stays small and explicit:

```php
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

$response = $connector
    ->send(new InstallToolRequest(7, 'composer', 'vendor/package', '^1.2'))
    ->dto();

assert($response instanceof ToolResponse);
```

## Requirements

- PHP 8.5
- Composer 2

## Quality

```bash
composer test       # full Pest suite (parallel, no TIA)
composer format     # Mago formatter
composer check      # full parallel no-TIA tests and all Mago checks
```
