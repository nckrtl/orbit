<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencyRequirementData extends Data
{
    public function __construct(
        public ?string $from,
        public ?string $to,
        public string $name,
        public string $constraint,
        public string $kind,
        public string $scope,
        public bool $optional,
    ) {}

    public static function fromDomain(DependencyRequirement $requirement): self
    {
        return new self($requirement->from, $requirement->to, $requirement->name, $requirement->constraint,
            $requirement->kind->value, $requirement->scope->value, $requirement->optional);
    }
}
