<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class ComposerCheckEvidence
{
    public function __construct(
        public bool $invoked,
        public bool $passed,
        public bool $current,
    ) {}

    /** @param list<array{id: string, kind: string, label: string, text: string, at: string}> $messages */
    public static function fromMessages(array $messages): self
    {
        $runs = [];
        foreach ($messages as $index => $message) {
            if ($message['kind'] !== 'activity' || $message['label'] === 'assistant') {
                continue;
            }
            if (preg_match('/\bcomposer check\b/', $message['text']) !== 1) {
                continue;
            }
            $exit = null;
            if (preg_match('/exit code (-?\d+)/', $message['text'], $match) === 1) {
                $exit = (int) $match[1];
            }
            $runs[] = ['index' => $index, 'at' => $message['at'], 'exit' => $exit];
        }
        if ($runs === []) {
            return new self(false, false, false);
        }

        usort($runs, static fn (array $left, array $right): int => [$left['at'], $left['index']] <=> [$right['at'], $right['index']]);
        $latest = array_last($runs);
        $passed = $latest['exit'] === 0;

        return new self(true, $passed, $passed && ! self::mutatesAfter($messages, $latest['at'], $latest['index']));
    }

    /** @param list<array{id: string, kind: string, label: string, text: string, at: string}> $messages */
    private static function mutatesAfter(array $messages, string $at, int $index): bool
    {
        foreach ($messages as $position => $message) {
            if ($message['kind'] !== 'activity') {
                continue;
            }
            $after = $message['at'] > $at || ($message['at'] === $at && $position > $index);
            if (! $after) {
                continue;
            }
            $haystack = strtolower($message['label'].' '.$message['text']);
            if (preg_match('/\b(edit|write|patch|str_replace|apply_patch)\b/', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
