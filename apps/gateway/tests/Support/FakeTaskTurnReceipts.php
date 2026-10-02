<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\Instance;

final class FakeTaskTurnReceipts implements TaskTurnReceipts
{
    /** @var list<string> */
    public array $prepared = [];

    /** @var list<string> */
    public array $cleared = [];

    private int $reads = 0;

    /**
     * @param  list<string|null>|null  $receipts  receipt contents for each read, with null for a missing receipt;
     *                                            null writes one ready_for_review receipt, as an agent that ends one turn
     */
    public function __construct(private ?array $receipts = null)
    {
        $this->receipts ??= [self::contents('ready_for_review')];
    }

    /** @param array<string, string> $deliverables confirmations by deliverable ID */
    public static function contents(string $outcome, string $summary = 'Done.', ?string $question = null, array $deliverables = [], ?string $cause = null): string
    {
        $receipt = ['outcome' => $outcome, 'summary' => $summary];
        if ($question !== null) {
            $receipt['question'] = $question;
        }
        if ($cause !== null) {
            $receipt['cause'] = $cause;
        }
        if ($deliverables !== []) {
            $receipt['deliverables'] = $deliverables;
        }

        return json_encode([...$receipt, 'nonce' => bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR);
    }

    /** @var list<list<string>> the deliverable IDs written into each prepared turn */
    public array $turnDeliverables = [];

    /** @var list<string|null> the review context written with each prepared turn, or null when that turn has none */
    public array $contexts = [];

    /** @var list<string|null> `consult`, `relay`, `cause`, or null for an ordinary turn */
    public array $modes = [];

    /** Preparing an implementer turn discards the next unread receipt, as the real prepare deletes receipt.json. */
    public bool $discardImplementerReceiptOnPrepare = false;

    public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void
    {
        $this->prepared[] = $role->value.($final ? ':final' : '');
        $this->turnDeliverables[] = array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables);
        $this->contexts[] = $context;
        $this->modes[] = match (true) {
            $mode?->consult === true => 'consult',
            $mode?->relay === true => 'relay',
            $mode?->causeRequired === true => 'cause',
            default => null,
        };
        if ($this->discardImplementerReceiptOnPrepare && $role === TaskThreadRole::Implementer && is_array($this->receipts)) {
            foreach ($this->receipts as $index => $contents) {
                if ($contents !== null) {
                    $this->receipts[$index] = null;

                    break;
                }
            }
        }
    }

    public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
    {
        $this->reads++;
        $contents = array_shift($this->receipts);
        if ($contents === null) {
            return null;
        }
        $receipt = TaskTurnReceipt::parse($contents);
        if ($receipt->threadId === null && $actingThreadId !== null) {
            return $receipt->withThread($actingThreadId);
        }

        return $receipt;
    }

    public function hasLegacyTurn(Instance $instance): bool
    {
        return false;
    }

    public function clear(Instance $instance, TaskTurnReceipt $receipt): void
    {
        $this->cleared[] = $receipt->hash;
    }

    public function reads(): int
    {
        return $this->reads;
    }
}
