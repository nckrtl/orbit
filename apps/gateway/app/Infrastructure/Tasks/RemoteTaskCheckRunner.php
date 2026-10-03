<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Support\ValidatedData;
use JsonException;

/**
 * Installs `.git/orbit/check` and drives it over SSH. Each call returns at once; the check itself runs detached.
 */
final readonly class RemoteTaskCheckRunner implements TaskCheckRunner
{
    /** A finished status carries the result, the deliverable evidence and a 16 KiB output tail. It outgrows the 64 KiB process default. */
    public const int OutputLimitBytes = 8 * 1024 * 1024;

    public function __construct(private DevelopmentSshExecutor $ssh) {}

    public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess
    {
        $instance->refresh();
        $script = file_get_contents(resource_path('tasks/check'));
        if ($script === false) {
            throw new TaskCheckException('The check script is missing from the Gateway.');
        }
        $steps = json_encode($setup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $verify = $deliverables === null ? null : json_encode($deliverables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $install = TaskWorkspaceMetadata::operation('check', [
            'script' => base64_encode($script), 'setup' => $steps, 'command' => $command ?? '', 'deliverables' => $verify,
        ]);
        $stepsArgument = $setup === [] ? '-' : '"$dir/setup.json"';
        $deliverablesArgument = $deliverables === null ? '-' : '"$dir/deliverables.json"';
        $data = $this->run($instance, [], $install.'check_python "$dir/check" start "$checkout" '.$stepsArgument.' '.$deliverablesArgument.' "$dir/check-command"');
        $pid = $data['pid'] ?? null;
        $started = $data['started'] ?? null;
        $head = $data['head'] ?? null;
        $tree = $data['tree'] ?? null;
        if (! is_int($pid) || $pid < 1 || ! is_string($started) || $started === '' || ! is_string($head) || ! is_string($tree)) {
            throw new TaskCheckException('The check did not start.');
        }

        return new TaskCheckProcess($pid, $started, $head, $tree);
    }

    public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        $data = $this->run($instance, [(string) $process->pid, $process->started], 'check_python "$dir/check" status "$2" "$3"');
        $output = is_string($data['output'] ?? null) ? $data['output'] : '';

        return match ($data['state'] ?? null) {
            'running' => TaskCheckReading::running(),
            'lost' => TaskCheckReading::lost($output),
            'finished' => $this->finished($data['result'] ?? null, $output),
            default => throw new TaskCheckException('The check state could not be read.'),
        };
    }

    public function cancel(Instance $instance, TaskCheckProcess $process): void
    {
        $this->run($instance, [(string) $process->pid, $process->started], 'check_python "$dir/check" cancel "$2" "$3"');
    }

    public function snapshot(Instance $instance): TaskWorkspaceSnapshot
    {
        $script = file_get_contents(resource_path('tasks/check'));
        if ($script === false) {
            throw new TaskCheckException('The check script is missing from the Gateway.');
        }
        $data = $this->run($instance, [], TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" snapshot "$checkout"', 'The task workspace could not be reached for the workspace tree.', 'The workspace tree could not be read.');
        $head = $data['head'] ?? null;
        $tree = $data['tree'] ?? null;
        if (! is_string($head) || $head === '' || ! is_string($tree) || $tree === '') {
            throw new TaskCheckException('The workspace tree could not be read.');
        }
        $parent = $data['parent'] ?? null;
        $commitTree = $data['commit_tree'] ?? null;

        return new TaskWorkspaceSnapshot(
            $head,
            $tree,
            is_string($parent) && $parent !== '' ? $parent : null,
            is_string($commitTree) && $commitTree !== '' ? $commitTree : null,
        );
    }

    private function finished(mixed $result, string $output): TaskCheckReading
    {
        if (! is_array($result) || ! is_int($result['exit_code'] ?? null) || ! is_string($result['head_after'] ?? null)
            || ! is_string($result['tree_after'] ?? null) || ! is_array($result['changed_paths'] ?? null)) {
            throw new TaskCheckException('The check result could not be read.');
        }
        $paths = array_values(array_filter($result['changed_paths'], is_string(...)));

        $finishedAt = $result['finished_at'] ?? null;
        $treeBefore = $result['tree_before'] ?? null;
        $failedStep = $result['failed_step'] ?? null;
        $evidence = $result['deliverables'] ?? null;

        return TaskCheckReading::finished(
            $result['exit_code'],
            $result['head_after'],
            $result['tree_after'],
            $paths,
            $output,
            is_int($finishedAt) || is_float($finishedAt) ? (float) $finishedAt : null,
            is_string($treeBefore) ? $treeBefore : null,
            is_string($failedStep) ? $failedStep : null,
            is_array($evidence) ? $evidence : null,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function run(
        Instance $instance,
        array $arguments,
        string $command,
        string $unreachable = 'The task workspace could not be reached for the check.',
        string $invalid = 'The check answered with invalid output.',
    ): array {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            throw new TaskCheckException('The task workspace has no checkout.');
        }
        try {
            // The check runs as the managed user, so host-dependent tests keep its sudo, ACL and caddy access.
            // It shares what it creates with the task worker before it reports a result.
            $worker = TaskWorkerUser::name() ?? '';
            $prefix = "checkout=\$1\nworker=".escapeshellarg($worker)."\nseed_path=".escapeshellarg($instance->seed_path ?? '')."\nseed_commit=".escapeshellarg($instance->seed_commit ?? '')."\n".<<<'BASH'
                dir="$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --absolute-git-dir)/orbit"
                check_python() {
                    ORBIT_TASK_WORKER_USER="$worker" ORBIT_SEED_PATH="$seed_path" ORBIT_SEED_COMMIT="$seed_commit" python3 "$@"
                }

                BASH;
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: $prefix.TaskWorkspaceMetadata::bashPreamble().$command."\n",
                maxOutputBytes: self::OutputLimitBytes,
            ), 'task-check', 'tasks.check_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException($unreachable, previous: $exception);
        }
        try {
            $data = json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TaskCheckException($invalid, previous: $exception);
        }
        if (! is_array($data)) {
            throw new TaskCheckException($invalid);
        }

        try {
            return ValidatedData::object($data);
        } catch (\InvalidArgumentException $exception) {
            throw new TaskCheckException($invalid, previous: $exception);
        }
    }
}
