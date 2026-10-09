<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * Resolves the log source of an Instance or a Process from the Gateway's own records: the same file,
 * unit, or container that the one-shot SSH read uses (ADR 0153).
 */
final readonly class LogStreamTargetResolver
{
    public function __construct(
        private ProcessTargetResolver $processes,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
    ) {}

    public function forInstance(Instance $instance): LogStreamTarget
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        // Logs have no app selector yet; a Project with several apps returns app.required.
        $app = $instance->appConfiguration()['name'];
        $checkout = StoragePath::tryParse($instance->runtimeForApp($app)['laravel'] === true ? $instance->applicationDirectory($app) : $instance->checkout_path);

        if ($checkout === null) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_invalid',
                message: "Instance [{$instance->name}] has an invalid checkout path.",
            );
        }

        return new LogStreamTarget(
            LogStreamRecordType::Instance,
            (int) $instance->id,
            Node::query()->findOrFail($instance->node_id),
            LogStreamSource::laravel($checkout),
            // A production Instance lives in its own home, which the agent's unit hides (ADR 0151).
            sshOnly: $instance->placedOnAppProd(),
        );
    }

    public function forProcess(#[SensitiveParameter] Process $process): LogStreamTarget
    {
        $node = $this->processes->forInspection($process)->node;
        $source = match ($process->runtime) {
            ProcessRuntime::Systemd => LogStreamSource::journal($this->systemd->unitName($process)),
            ProcessRuntime::Docker => LogStreamSource::docker($this->docker->containerName($process), (int) $process->id),
        };

        return new LogStreamTarget(LogStreamRecordType::Process, (int) $process->id, $node, $source);
    }
}
