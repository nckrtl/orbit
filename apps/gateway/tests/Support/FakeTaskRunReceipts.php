<?php

declare(strict_types=1);

namespace Tests\Support;

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
     *                                            null writes a new ready_for_review receipt for every read
     */
    public function __construct(private ?array $receipts = null) {}

    public static function contents(string $outcome, string $summary = 'Done.'): string
    {
        return json_encode(['outcome' => $outcome, 'summary' => $summary, 'nonce' => bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR);
    }

    public function prepare(AppInstance $instance, TaskThreadRole $role): void
    {
        $this->prepared[] = $role->value;
    }

    public function read(AppInstance $instance): ?TaskRunReceipt
    {
        $this->reads++;
        if ($this->receipts === null) {
            return TaskRunReceipt::parse(self::contents('ready_for_review'));
        }
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
