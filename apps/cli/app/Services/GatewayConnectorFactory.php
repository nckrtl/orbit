<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\GatewayProfile;
use Illuminate\Support\Str;
use Orbit\Sdk\GatewayConnector;

final readonly class GatewayConnectorFactory
{
    public function make(GatewayProfile $profile): GatewayConnector
    {
        return new GatewayConnector(
            baseUrl: $profile->url,
            caPemPath: $profile->caPath,
            requestIdResolver: static fn (): string => (string) Str::uuid(),
        );
    }
}
