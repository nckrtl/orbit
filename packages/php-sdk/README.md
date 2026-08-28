# Orbit PHP SDK

The typed Saloon client for the Orbit gateway API.

The SDK contains the transport defaults, response envelopes, errors, and typed
requests needed by the small public CLI surface. It does not depend on the
gateway application.

During monorepo development, `apps/cli` consumes this package through a
Composer path repository with symlinking enabled.

## Tool transport

```php
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

/** @var ToolResponse $tool */
$tool = $connector->send(new InstallToolRequest(
    nodeId: 12,
    manager: 'vp',
    package: '@openai/codex',
    versionConstraint: '^0.150',
))->dto();
```

The SDK preserves Tool transport values and request IDs. The Gateway owns
manager, package, version, node-state, and outcome policy.

## Requirements

- PHP 8.5
- Composer 2

## Quality

```bash
composer test       # full Pest suite (parallel, no TIA)
composer format     # Mago formatter
composer check      # full parallel no-TIA tests and all Mago checks
```
