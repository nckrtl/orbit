<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Models\Route;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceAnalyticsHostData extends Data
{
    /** @param array{type: string, name: string, value: string}|null $dns */
    public function __construct(
        public string $host,
        public int $routeId,
        public string $status,
        public string $publication,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $scriptUrl,
        public string $eventUrl,
        public ?array $dns,
    ) {}

    /** The Gateway knows no public address, so the record points the host at the instance's own domain. */
    public static function fromRoute(Route $route, ?string $instanceDomain): self
    {
        return new self(
            host: $route->domain,
            routeId: $route->id,
            status: $route->status->value,
            publication: $route->publication->value,
            failedStep: $route->failed_step,
            errorCode: $route->error_code,
            scriptUrl: AnalyticsTrackingHosts::scriptUrl($route->domain),
            eventUrl: AnalyticsTrackingHosts::eventUrl($route->domain),
            dns: $instanceDomain === null
                ? null
                : ['type' => 'CNAME', 'name' => $route->domain, 'value' => $instanceDomain],
        );
    }
}
