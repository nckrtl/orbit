<?php

declare(strict_types=1);

namespace App\Data\AppInstances\Dependencies;

use App\Domain\AppInstances\Dependencies\DependencyUpdateStepResult;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DependencyUpdateStepData extends Data
{
    public function __construct(
        public string $ecosystem,
        public string $status,
        public bool $mayHaveMutated,
        public ?string $errorCode,
    ) {}

    public static function fromResult(DependencyUpdateStepResult $result): self
    {
        return new self($result->ecosystem->value, $result->status->value, $result->mayHaveMutated, $result->errorCode);
    }
}
