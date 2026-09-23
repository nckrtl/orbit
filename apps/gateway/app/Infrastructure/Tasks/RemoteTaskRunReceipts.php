<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskRunReceipt;
use App\Domain\Tasks\TaskRunReceiptException;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

/**
 * Keeps the run script and receipt in `.git/orbit/`, which Git never tracks.
 */
final readonly class RemoteTaskRunReceipts implements TaskRunReceipts
{
    private const string Directory = <<<'BASH'
        dir=$(git -C "$checkout" rev-parse --absolute-git-dir)/orbit
        BASH;

    public function __construct(private AppDevSshExecutor $ssh) {}

    public function prepare(AppInstance $instance, TaskThreadRole $role, bool $final = false): void
    {
        $script = file_get_contents(resource_path('tasks/run'));
        if ($script === false) {
            throw new TaskRunReceiptException('The run script is missing from the Gateway.');
        }
        $this->run($instance, [$role->value, $final ? 'true' : 'false'], "script='".base64_encode($script)."'\n".<<<'BASH'
            install -d -m 0755 -- "$dir"
            rm -f -- "$dir/run.json"
            printf '%s' "$script" | base64 -d > "$dir/run.new"
            chmod 0755 "$dir/run.new"
            mv -f -- "$dir/run.new" "$dir/run"
            printf '{"role":"%s","final":%s}\n' "$2" "$3" > "$dir/turn.new"
            mv -f -- "$dir/turn.new" "$dir/turn.json"
            BASH);
    }

    public function read(AppInstance $instance): ?TaskRunReceipt
    {
        $output = $this->run($instance, [], <<<'BASH'
            if [ -f "$dir/run.json" ]; then
                printf 'receipt\n'
                cat -- "$dir/run.json"
            else
                printf 'none\n'
            fi
            BASH);
        if (str_starts_with($output, "receipt\n")) {
            return TaskRunReceipt::parse(substr($output, 8));
        }
        if ($output === "none\n") {
            return null;
        }

        throw new TaskRunReceiptException('The run receipt could not be read.');
    }

    public function clear(AppInstance $instance, TaskRunReceipt $receipt): void
    {
        $this->run($instance, [$receipt->hash], <<<'BASH'
            if [ -f "$dir/run.json" ] && [ "$(sha256sum -- "$dir/run.json" | cut -d ' ' -f 1)" = "$2" ]; then
                rm -f -- "$dir/run.json"
            fi
            BASH);
    }

    /** @param list<string> $arguments */
    private function run(AppInstance $instance, array $arguments, string $command): string
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            throw new TaskRunReceiptException('The task workspace has no checkout.');
        }
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: "checkout=\$1\n".self::Directory."\n{$command}\n",
            ), 'task-run-receipt', 'tasks.run_receipt_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskRunReceiptException('The task workspace could not be reached for the run receipt.', previous: $exception);
        }

        return $result->stdout;
    }
}
