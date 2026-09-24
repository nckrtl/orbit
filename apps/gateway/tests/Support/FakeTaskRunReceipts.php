<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskRunReceipt;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\AppInstance;

final class FakeTaskRunReceipts implements TaskRunReceipts
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
    public static function contents(string $outcome, string $summary = 'Done.', ?string $question = null, array $deliverables = []): string
    {
        $receipt = ['outcome' => $outcome, 'summary' => $summary];
        if ($question !== null) {
            $receipt['question'] = $question;
        }
        if ($deliverables !== []) {
            $receipt['deliverables'] = $deliverables;
        }

        return json_encode([...$receipt, 'nonce' => bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR);
    }

    /** @var list<list<string>> the deliverable IDs written into each prepared turn */
    public array $turnDeliverables = [];

    public function prepare(AppInstance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = []): void
    {
        $this->prepared[] = $role->value.($final ? ':final' : '');
        $this->turnDeliverables[] = array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables);
    }

    public function read(AppInstance $instance): ?TaskRunReceipt
    {
        $this->reads++;
        $contents = array_shift($this->receipts);

        return $contents === null ? null : TaskRunReceipt::parse($contents);
    }

    public function clear(AppInstance $instance, TaskRunReceipt $receipt): void
    {
        $this->cleared[] = $receipt->hash;
    }

    public function reads(): int
    {
        return $this->reads;
    }
}
