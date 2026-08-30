<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;
use JsonException;

final readonly class IssueStateSnapshot
{
    private const array TERMINAL = ['completed', 'canceled', 'cancelled', 'duplicate'];

    /** @param array<string, string> $issues */
    public function __construct(
        public array $issues,
    ) {
        foreach ($issues as $issue => $state) {
            TopologyTarget::assertIssue($issue);
            if (! in_array(strtolower($state), self::TERMINAL, true)) {
                throw new InvalidArgumentException('The issue snapshot contains a non-terminal state.');
            }
        }
    }

    public static function fromFile(string $path): self
    {
        if ($path === '' || ! is_file($path) || is_link($path) || (fileperms($path) & 0o777) !== 0o600) {
            throw new InvalidArgumentException('The issue state snapshot must be a 0600 regular file.');
        }

        try {
            $value = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The issue state snapshot is malformed.', previous: $exception);
        }

        if (
            ! is_array($value)
            || array_keys($value) !== ['schema', 'issues']
            || $value['schema'] !== 1
            || ! is_array($value['issues'])
            || array_is_list($value['issues'])
        ) {
            throw new InvalidArgumentException('The issue state snapshot schema is invalid.');
        }

        $issues = [];
        foreach ($value['issues'] as $issue => $state) {
            if (! is_string($issue) || ! is_string($state)) {
                throw new InvalidArgumentException('The issue state snapshot schema is invalid.');
            }
            $issues[$issue] = $state;
        }

        return new self($issues);
    }

    public function isTerminal(string $issue): bool
    {
        return array_key_exists($issue, $this->issues);
    }
}
