<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\DependencyCopy\InstanceDependencyCopier;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use Illuminate\Support\Facades\Log;

/**
 * Gives a new development checkout the dependency directories of the Project's active `default`
 * Instance on the same Node. The copy holds that Instance's Process admission lock, so the
 * hibernation prune and restore never change the directories while they are copied. The copy only
 * makes the setup steps fast, so a failure is logged and creation continues.
 */
final readonly class CopyInstanceDependenciesAction
{
    public const string SOURCE_INSTANCE = 'default';

    public function __construct(
        private InstanceDependencyCopier $copier,
        private ProcessAdmissionLock $admissions,
    ) {}

    public function execute(Instance $target): void
    {
        if ($target->name === self::SOURCE_INSTANCE) {
            return;
        }

        $source = Instance::query()
            ->where('project_id', $target->project_id)
            ->where('name', self::SOURCE_INSTANCE)
            ->first();

        if (
            ! $source instanceof Instance
            || $source->node_id !== $target->node_id
            || $source->status !== InstanceState::Active
        ) {
            return;
        }

        try {
            $this->admissions->run([$source->id], fn () => $this->copier->copy($source, $target));
        } catch (ResourceOperationException $exception) {
            Log::warning('The dependency copy failed; setup installs the dependencies instead.', [
                'error_code' => $exception->errorCode,
                'source_instance_id' => $source->id,
                'target_instance_id' => $target->id,
                ...$exception->details,
            ]);
        }
    }
}
