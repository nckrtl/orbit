<?php

declare(strict_types=1);

namespace App\Domain\Instances\Copy;

use App\Models\Instance;
use App\Models\Route;

/**
 * Checkout paths and route domains a copy rewrites from the source onto the target.
 */
final readonly class InstanceCopyNames
{
    public function __construct(
        public string $sourceCheckout,
        public string $targetCheckout,
        public string $sourceDomain,
        public string $targetDomain,
    ) {}

    public static function between(Instance $source, Instance $target): self
    {
        return new self(
            sourceCheckout: $source->checkout_path,
            targetCheckout: $target->checkout_path,
            sourceDomain: self::domain($source),
            targetDomain: self::domain($target),
        );
    }

    private static function domain(Instance $instance): string
    {
        $instance->unsetRelation('routes');
        $instance->load('routes');
        $route = $instance->routes->first(
            static fn (Route $route): bool => $route->isAuthoritative(),
        ) ?? $instance->routes->sortBy('id')->first();

        return is_string($route?->domain) && $route->domain !== '' ? $route->domain : '';
    }
}
