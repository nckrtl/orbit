<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class TopologyProfile
{
    public const string NAME = 'gateway_app-dev_app-prod';
    public const array ROLES = ['gateway', 'app-dev', 'app-prod'];
    public const array CHECKOUT_ROLES = ['gateway', 'app-dev'];

    public function __construct(
        public string $name = self::NAME,
    ) {
        if ($name !== self::NAME) {
            throw new InvalidArgumentException('The topology profile is unsupported.');
        }
    }
}
