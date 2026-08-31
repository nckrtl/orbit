<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class TopologyProfile
{
    public const string NAME = 'gateway_app-dev_app-prod';
    public const array ROLES = ['gateway', 'app-dev', 'app-prod'];
    public const array CHECKOUT_ROLES = ['gateway', 'app-dev'];
    public const array ASSIGNMENTS = [
        'gateway' => ['gateway', 'vpn'],
        'app-dev' => ['app-dev', 'metrics'],
        'app-prod' => ['app-prod'],
    ];
}
