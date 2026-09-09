<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Models\AppInstanceRemoval;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceRemovalData extends Data
{
    /** @mago-expect lint:excessive-parameter-list Each parameter is one field of the bounded public removal progress contract. */
    public function __construct(
        public string $operationId,
        public int $id,
        public string $name,
        public bool $force,
        public string $status,
        public ?string $currentStep,
        public int $total,
        public int $completed,
        public int $remaining,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    public static function fromModel(AppInstanceRemoval $removal): self
    {
        $removal->loadMissing('members');
        $completed = $removal->members->whereNotNull('row_deleted_at')->count();

        return new self(
            operationId: $removal->id,
            id: $removal->requested_app_instance_id,
            name: $removal->requested_name,
            force: $removal->force,
            status: $removal->status->value,
            currentStep: $removal->current_step?->value,
            total: $removal->total,
            completed: $completed,
            remaining: $removal->total - $completed,
            failedStep: $removal->failed_step?->value,
            errorCode: $removal->error_code,
        );
    }
}
