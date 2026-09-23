<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The pull request description the reviewer gives when it approves the last subtask.
 */
final readonly class TaskRunPullRequest
{
    /**
     * @param  non-empty-list<string>  $changes
     * @param  list<string>  $breaking  empty when nothing breaks
     */
    public function __construct(public string $summary, public array $changes, public array $breaking) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['summary'] ?? null) || trim($data['summary']) === '') {
            return null;
        }
        $changes = self::lines($data['changes'] ?? null);
        $breaking = self::lines($data['breaking'] ?? null);
        if ($changes === null || $changes === [] || $breaking === null) {
            return null;
        }

        return new self(trim($data['summary']), $changes, $breaking);
    }

    /** @return array{summary: string, changes: list<string>, breaking: list<string>} */
    public function toArray(): array
    {
        return ['summary' => $this->summary, 'changes' => $this->changes, 'breaking' => $this->breaking];
    }

    /** @return list<string>|null */
    private static function lines(mixed $values): ?array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return null;
        }
        $lines = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            $lines[] = trim($value);
        }

        return $lines;
    }
}
