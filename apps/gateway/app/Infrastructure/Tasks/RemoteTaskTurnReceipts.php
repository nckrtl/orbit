<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/**
 * Keeps the turn command and receipt in `.git/orbit/`, which Git never tracks.
 */
final readonly class RemoteTaskTurnReceipts implements TaskTurnReceipts
{
    public function __construct(private TaskWorkspaceExecutor $ssh) {}

    public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void
    {
        $script = file_get_contents(resource_path('tasks/turn'));
        if ($script === false) {
            throw new TaskTurnReceiptException('The turn command is missing from the Gateway.');
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
        if ($mode instanceof TaskTurnMode) {
            if ($mode->deliveryKey !== null) {
                $turnFields['delivery_key'] = $mode->deliveryKey;
            }
            if ($mode->consult) {
                $turnFields['consult'] = true;
            }
            if ($mode->relay) {
                $turnFields['relay'] = true;
            }
            if ($mode->causeRequired) {
                $turnFields['cause_required'] = true;
            }
        }
        $turn = json_encode($turnFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->run($instance, [], TaskWorkspaceMetadata::operation('turn', [
            'script' => base64_encode($script), 'turn' => $turn, 'context' => $context,
        ]));
    }

    public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
    {
        $output = $this->run($instance, [], TaskWorkspaceMetadata::operation('read'));
        if ($output === "none\n") {
            return null;
        }
        if (! str_starts_with($output, "receipt\n")) {
            throw new TaskTurnReceiptException('The turn receipt could not be read.');
        }
        [$expectedThread, $contents] = self::split(substr($output, 8));
        $receipt = TaskTurnReceipt::parse($contents);
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
        $output = $this->run($instance, [], TaskWorkspaceMetadata::operation('legacy'));
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

    public function clear(Instance $instance, TaskTurnReceipt $receipt): void
    {
        $this->run($instance, [], TaskWorkspaceMetadata::operation('clear', ['hash' => $receipt->hash]));
    }

    /** @param list<string> $arguments */
    private function run(Instance $instance, array $arguments, string $command): string
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            throw new TaskTurnReceiptException('The task workspace has no checkout.');
        }
        try {
            $result = $this->ssh->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: "checkout=\$1\n".TaskWorkspaceMetadata::bashPreamble().$command,
            ), 'task-turn-receipt', 'tasks.turn_receipt_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskTurnReceiptException('The task workspace could not be reached for the turn receipt.', previous: $exception);
        }

        return $result->stdout;
    }
}
