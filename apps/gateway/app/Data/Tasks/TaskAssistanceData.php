<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Models\TaskGroup;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskAssistanceData extends Data
{
    public function __construct(
        public int $id,
        public int $appId,
        public string $app,
        public string $projectCode,
        public string $title,
        public TaskGroupStatus $status,
        public ?string $assistanceReason,
    ) {}

    public static function fromModel(TaskGroup $group): self
    {
        $group->loadMissing('app');

        return new self(
            id: $group->id,
            appId: $group->app_id,
            app: $group->app->slug,
            projectCode: $group->app->code,
            title: $group->title,
            status: $group->status,
            assistanceReason: $group->assistance_reason,
        );
    }
}
