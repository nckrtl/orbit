<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Domain\Instances\Deployment\DeploymentReleaseState;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DeploymentReleaseStateData extends Data
{
    /** @param list<string> $releases */
    public function __construct(
        public array $releases,
        public ?string $selectedRelease,
    ) {}

    public static function fromDomain(DeploymentReleaseState $state): self
    {
        return new self($state->releases, $state->selectedRelease);
    }
}
