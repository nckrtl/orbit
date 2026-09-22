<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskWorkspacePreparer;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Throwable;

final readonly class RemoteTaskWorkspacePreparer implements TaskWorkspacePreparer
{
    public function __construct(private AppDevSshExecutor $ssh, private TaskMainCache $cache) {}

    public function prepare(AppInstance $instance): void
    {
        try {
            $script = file_get_contents(resource_path('tasks/prepare.py'));
            if ($script === false) {
                throw new ResourceOperationException('tasks.workspace_setup_failed', 'Orbit task setup is unavailable.');
            }
            $publications = gzencode(json_encode($this->cache->publications(), JSON_THROW_ON_ERROR));
            if ($publications === false) {
                throw new ResourceOperationException('tasks.workspace_setup_failed', 'Orbit task cache transport is unavailable.');
            }
            $input = "PUBLICATIONS = '".base64_encode($publications)."'\n".$script;
            $instance->loadMissing('node');
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['python3', '-', $instance->checkout_path, (string) $instance->branch, (string) $instance->starting_commit],
                input: $input,
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
                'Orbit task bootstrap failed. Inspect .git/orbit-task-setup/bootstrap.log in the assigned checkout; the group will retry before starting an agent.',
            );
        }
    }
}
