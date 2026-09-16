<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;

final class ReservedPrivateHostname
{
    /** @var list<string> */
    public const array NAMES = ['gateway.orbit', 'metrics.orbit'];

    public static function assertAvailable(string $domain): void
    {
        if (in_array($domain, self::NAMES, true)) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_conflict',
                message: "Route domain [{$domain}] is reserved.",
                status: 409,
            );
        }
    }
}
