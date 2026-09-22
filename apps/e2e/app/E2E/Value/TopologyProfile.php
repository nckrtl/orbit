<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class TopologyProfile
{
    public const string NAME = 'gateway_app-dev_app-prod';

    public const array ROLES = ['gateway', 'app-dev', 'app-prod'];

    public const array CHECKOUT_ROLES = ['gateway', 'app-dev'];

    /** Kept readable so a saved generation can be inspected and explicitly refreshed. */
    public const array PREVIOUS_ASSIGNMENTS = [
        'gateway' => ['gateway', 'vpn'],
        'app-dev' => ['app-dev', 'metrics'],
        'app-prod' => ['app-prod'],
    ];

    public const array ASSIGNMENTS = [
        'gateway' => ['gateway', 'vpn', 'websocket', 'router'],
        'app-dev' => ['app-dev', 'metrics', 'database'],
        'app-prod' => ['app-prod', 'ingress'],
    ];
}
