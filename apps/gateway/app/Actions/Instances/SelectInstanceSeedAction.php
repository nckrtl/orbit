<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\InstanceState;
use App\Models\Instance;

/** Source selection and pruning share the Node lock. Instance rows retain their seed until removal. */
final readonly class SelectInstanceSeedAction
{
    public function __construct(
        private DevelopmentDeployment $deployment,
        private AppDevSourceOperationLock $sourceLock,
    ) {}

    public function execute(Instance $instance): Instance
    {
        return $this->sourceLock->synchronized($instance->node_id, fn (): Instance => $this->select($instance->refresh()));
    }

    private function select(Instance $instance): Instance
    {
        if ($instance->name === 'default') {
            if ($instance->development_release_layout) {
                // Filesystem selection is authoritative, including a switch whose Gateway response was lost.
                $release = $this->deployment->selected($instance);
                $instance->update(['seed_path' => $release->path, 'seed_commit' => $release->commit, 'seed_repository' => $instance->checkout_path]);
            }

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
                ->where('development_release_layout', true)
                ->first();

            if ($seed instanceof Instance) {
                $this->select($seed);
                // Registered source is already at a caller-selected commit. Do not pair it with another release.
                if ($instance->starting_commit === null || $instance->starting_commit === $seed->seed_commit) {
                    $selection = [
                        'seed_selected' => true,
                        'seed_path' => $seed->seed_path,
                        'seed_commit' => $seed->seed_commit,
                        'seed_repository' => $seed->checkout_path,
                    ];
                }
            }
        }
        $instance->update($selection);

        return $instance;
    }
}
