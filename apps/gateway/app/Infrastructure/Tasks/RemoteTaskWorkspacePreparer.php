<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskWorkspacePreparer;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Throwable;

final readonly class RemoteTaskWorkspacePreparer implements TaskWorkspacePreparer
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private TaskMainCache $cache,
        private ProjectLifecycleStepStore $steps,
    ) {}

    public function prepare(AppInstance $instance): void
    {
        $instance->loadMissing(['app', 'node']);
        $steps = $this->steps->ordered($instance->app, LifecyclePhase::Setup);
        if ($steps === []) {
            throw new ResourceOperationException(
                'tasks.workspace_setup_unconfigured',
                'Configure the Orbit Project setup list to run bin/bootstrap before starting tasks.',
            );
        }
        $input = null;
        try {
            $script = file_get_contents(resource_path('tasks/prepare.py'));
            $lifecycle = file_get_contents(resource_path('instances/lifecycle.py'));
            if ($script === false || $lifecycle === false) {
                throw new ResourceOperationException('tasks.workspace_setup_failed', 'Orbit task setup is unavailable.');
            }
            $publications = gzencode(json_encode($this->cache->publications(), JSON_THROW_ON_ERROR));
            if ($publications === false) {
                throw new ResourceOperationException('tasks.workspace_setup_failed', 'Orbit task cache transport is unavailable.');
            }
            $input = ProtectedInput::fromString(json_encode([
                'publications' => base64_encode($publications),
                'steps' => array_map(static fn (LifecycleStep $step): array => $step->toArray(), $steps),
                'lifecycle' => $lifecycle,
            ], JSON_THROW_ON_ERROR));
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['python3', '-c', $script, $instance->checkout_path, (string) $instance->branch, (string) $instance->starting_commit],
                protectedInput: $input,
                maxOutputBytes: 4096,
                timeout: 880.0,
            ), 'task-bootstrap', 'tasks.workspace_setup_failed', 880.0);
            $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($data) || ($data['prepared'] ?? null) !== true
                || ($data['checkout'] ?? null) !== $instance->checkout_path
                || ($data['commit'] ?? null) !== $instance->starting_commit) {
                throw new ResourceOperationException('tasks.workspace_setup_failed', 'Orbit task setup returned an incomplete result.');
            }
        } catch (Throwable) {
            throw new ResourceOperationException(
                'tasks.workspace_setup_failed',
                'Orbit task setup failed. Inspect .git/orbit-task-setup/bootstrap.log in the assigned checkout; the group will retry before starting an agent.',
            );
        } finally {
            $input?->close();
        }
    }
}
