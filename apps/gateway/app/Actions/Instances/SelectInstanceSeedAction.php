<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Models\Instance;

/**
 * Records where a new development Instance starts: the default checkout on the same Node and the
 * last commit that deployed there. Selection shares the Node's source lock with the conversion of an
 * old release layout. Instance rows keep their seed until removal.
 */
final readonly class SelectInstanceSeedAction
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
    ) {}

    public function execute(Instance $instance): Instance
    {
        InstanceSandboxGuard::assertHostOperation($instance);

        return $this->sourceLock->synchronized($instance->node_id, fn (): Instance => $this->select($instance->refresh()));
    }

    private function select(Instance $instance): Instance
    {
        // A default's own deployment records its seed.
        if ($instance->name === 'default') {
            return $instance;
        }
        // A legacy snapshot is already a choice, even if an older writer omitted the flag.
        if (! $instance->seed_selected && $instance->seed_commit !== null) {
            $instance->update(['seed_selected' => true]);
        }
        if ($instance->seed_selected) {
            return $instance;
        }

        $selection = ['seed_selected' => true, 'seed_path' => null, 'seed_commit' => null, 'seed_repository' => null];
        // Setup may only consume the decision made when source was prepared or adopted.
        if ($instance->status === InstanceState::Reserved) {
            $seed = Instance::query()
                ->where('project_id', $instance->project_id)
                ->where('node_id', $instance->node_id)
                ->where('name', 'default')
                ->whereNotNull('seed_commit')
                ->first();

            // Registered source is already at a caller-selected commit. Do not pair it with another one.
            if ($seed instanceof Instance && ($instance->starting_commit === null || $instance->starting_commit === $seed->seed_commit)) {
                $selection = [
                    'seed_selected' => true,
                    'seed_path' => $seed->seed_path,
                    'seed_commit' => $seed->seed_commit,
                    'seed_repository' => $seed->checkout_path,
                ];
            }
        }
        $instance->update($selection);

        return $instance;
    }
}
