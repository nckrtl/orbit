<?php

declare(strict_types=1);

namespace App\Services\Trust;

use App\Data\GatewayProfile;

final readonly class GatewayRootCaTrustResult
{
    public function __construct(
        public GatewayProfile $profile,
        public RootCertificate $certificate,
        public string $status,
        public string $requestId,
    ) {}
}
