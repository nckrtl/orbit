<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskRunReceipt;
use App\Domain\Tasks\TaskRunReceiptException;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/**
 * Keeps the run script and receipt in `.git/orbit/`, which Git never tracks.
 */
final readonly class RemoteTaskRunReceipts implements TaskRunReceipts
{
    private const string Directory = <<<'BASH'
        dir=$(git -C "$checkout" rev-parse --absolute-git-dir)/orbit
        BASH;

    public function __construct(private DevelopmentSshExecutor $ssh) {}

    public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null): void
    {
        $script = file_get_contents(resource_path('tasks/run'));
        if ($script === false) {
            throw new TaskRunReceiptException('The run script is missing from the Gateway.');
        }
        $turnFields = [
            'role' => $role->value,
            'final' => $final,
            'deliverables' => array_map(static function (TaskDeliverable $deliverable): array {
                $fields = [
                    'id' => $deliverable->id, 'type' => $deliverable->type->value, 'description' => $deliverable->description,
                ];
                if ($deliverable->type === TaskDeliverableType::Command && $deliverable->fails_on_base) {
                    $fields['fails_on_base'] = true;
                    $fields['paths'] = $deliverable->paths;
                }

                return $fields;
            }, $deliverables),
        ];
        if ($threadId !== null) {
            $turnFields['thread'] = $threadId;
        }
        $turn = json_encode($turnFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->run($instance, [], "script='".base64_encode($script)."'\nturn='".base64_encode($turn)."'\n".<<<'BASH'
            install -d -m 0755 -- "$dir"
            rm -f -- "$dir/run.json"
            printf '%s' "$script" | base64 -d > "$dir/run.new"
            chmod 0755 "$dir/run.new"
            mv -f -- "$dir/run.new" "$dir/run"
            printf '%s' "$turn" | base64 -d > "$dir/turn.new"
            printf '\n' >> "$dir/turn.new"
            mv -f -- "$dir/turn.new" "$dir/turn.json"
            BASH);
    }

    public function read(Instance $instance, ?int $actingThreadId = null): ?TaskRunReceipt
    {
        $output = $this->run($instance, [], <<<'BASH'
            if [ -f "$dir/run.json" ]; then
                printf 'receipt\n'
                if [ -f "$dir/turn.json" ]; then
                    cat -- "$dir/turn.json"
                fi
                cat -- "$dir/run.json"
            else
                printf 'none\n'
            fi
            BASH);
        if ($output === "none\n") {
            return null;
        }
        if (! str_starts_with($output, "receipt\n")) {
            throw new TaskRunReceiptException('The run receipt could not be read.');
        }
        [$expectedThread, $contents] = self::split(substr($output, 8));
        $receipt = TaskRunReceipt::parse($contents);
        if ($actingThreadId !== null) {
            return $receipt->threadId === $actingThreadId ? $receipt : null;
        }
        if ($expectedThread !== null && $receipt->threadId !== $expectedThread) {
            return null;
        }

        return $receipt;
    }

    public function hasLegacyTurn(Instance $instance): bool
    {
        $output = $this->run($instance, [], <<<'BASH'
            if [ -f "$dir/turn.json" ]; then
                printf 'turn\n'
                cat -- "$dir/turn.json"
            else
                printf 'missing\n'
            fi
            BASH);
        if (! str_starts_with($output, "turn\n")) {
            return false;
        }
        $turn = json_decode(substr($output, 5), true);
        $thread = is_array($turn) ? ($turn['thread'] ?? null) : null;
        $bound = (is_int($thread) && $thread > 0) || (is_string($thread) && preg_match('/\A[1-9][0-9]*\z/', $thread) === 1);

        return ! $bound;
    }

    /**
     * A turn file on its own line names the acting thread. Older readers, and tests that supply only
     * the receipt, leave the body as one receipt.
     *
     * @return array{0: ?int, 1: string}
     */
    private static function split(string $body): array
    {
        $newline = strpos($body, "\n");
        if ($newline === false) {
            return [null, $body];
        }
        $turn = json_decode(substr($body, 0, $newline), true);
        if (! is_array($turn) || ! is_string($turn['role'] ?? null)) {
            return [null, $body];
        }
        $thread = $turn['thread'] ?? null;
        $expected = is_int($thread) ? $thread : (is_string($thread) && preg_match('/\A[1-9][0-9]*\z/', $thread) === 1 ? (int) $thread : null);

        return [$expected !== null && $expected > 0 ? $expected : null, substr($body, $newline + 1)];
    }

    public function clear(Instance $instance, TaskRunReceipt $receipt): void
    {
        $this->run($instance, [$receipt->hash], <<<'BASH'
            if [ -f "$dir/run.json" ] && [ "$(sha256sum -- "$dir/run.json" | cut -d ' ' -f 1)" = "$2" ]; then
                rm -f -- "$dir/run.json"
            fi
            BASH);
    }

    /** @param list<string> $arguments */
    private function run(Instance $instance, array $arguments, string $command): string
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
