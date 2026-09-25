<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use JsonException;

/**
 * Installs `.git/orbit/check` and drives it over SSH. Each call returns at once; the check itself runs detached.
 */
final readonly class RemoteTaskCheckRunner implements TaskCheckRunner
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function start(AppInstance $instance, array $setup = [], ?array $deliverables = null, ?string $command = 'composer check'): TaskCheckProcess
    {
        $script = file_get_contents(resource_path('tasks/check'));
        if ($script === false) {
            throw new TaskCheckException('The check script is missing from the Gateway.');
        }
        $steps = json_encode($setup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $verify = $deliverables === null ? '' : json_encode($deliverables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $checkCommand = base64_encode($command ?? '');
        $data = $this->run($instance, [], "script='".base64_encode($script)."'\nsetup='".base64_encode($steps)."'\ndeliverables='".base64_encode($verify)."'\ncheck_command='{$checkCommand}'\n".<<<'BASH'
            install -d -m 0755 -- "$dir"
            printf '%s' "$script" | base64 -d > "$dir/check.new"
            chmod 0755 "$dir/check.new"
            mv -f -- "$dir/check.new" "$dir/check"
            printf '%s' "$setup" | base64 -d > "$dir/setup.json"
            printf '%s' "$check_command" | base64 -d > "$dir/check-command"
            steps="$dir/setup.json"
            if [ "$(cat "$dir/setup.json")" = '[]' ]; then
                steps=-
            fi
            if [ -n "$deliverables" ]; then
                printf '%s' "$deliverables" | base64 -d > "$dir/deliverables.json"
                python3 "$dir/check" start "$checkout" "$steps" "$dir/deliverables.json" "$dir/check-command"
            else
                rm -f -- "$dir/deliverables.json"
                python3 "$dir/check" start "$checkout" "$steps" - "$dir/check-command"
            fi
            BASH);
        $pid = $data['pid'] ?? null;
        $started = $data['started'] ?? null;
        $head = $data['head'] ?? null;
        $tree = $data['tree'] ?? null;
        if (! is_int($pid) || $pid < 1 || ! is_string($started) || $started === '' || ! is_string($head) || ! is_string($tree)) {
            throw new TaskCheckException('The check did not start.');
        }

        return new TaskCheckProcess($pid, $started, $head, $tree);
    }

    public function read(AppInstance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        $data = $this->run($instance, [(string) $process->pid, $process->started], 'python3 "$dir/check" status "$2" "$3"');
        $output = is_string($data['output'] ?? null) ? $data['output'] : '';

        return match ($data['state'] ?? null) {
            'running' => TaskCheckReading::running(),
            'lost' => TaskCheckReading::lost($output),
            'finished' => $this->finished($data['result'] ?? null, $output),
            default => throw new TaskCheckException('The check state could not be read.'),
        };
    }

    public function cancel(AppInstance $instance, TaskCheckProcess $process): void
    {
        $this->run($instance, [(string) $process->pid, $process->started], 'python3 "$dir/check" cancel "$2" "$3"');
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
    private function run(AppInstance $instance, array $arguments, string $command): array
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            throw new TaskCheckException('The task workspace has no checkout.');
        }
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: "checkout=\$1\ndir=\$(git -C \"\$checkout\" rev-parse --absolute-git-dir)/orbit\n{$command}\n",
            ), 'task-check', 'tasks.check_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException('The task workspace could not be reached for the check.', previous: $exception);
        }
        try {
            $data = json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TaskCheckException('The check answered with invalid output.', previous: $exception);
        }
        if (! is_array($data)) {
            throw new TaskCheckException('The check answered with invalid output.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
