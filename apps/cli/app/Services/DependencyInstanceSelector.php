<?php

declare(strict_types=1);

namespace App\Services;

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use SensitiveParameter;

final readonly class DependencyInstanceSelector
{
    public function resolveDomain(GatewayConnector $connector, #[SensitiveParameter] string $domain): ResolvedAppInstanceResponse
    {
        return $connector->send(new ResolveAppInstanceRequest($domain))->dtoOrFail();
    }
}
