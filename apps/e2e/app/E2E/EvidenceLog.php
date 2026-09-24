<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\EvidenceLabel;
use App\E2E\Value\GuestCommandResult;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The append-only proof evidence of one worktree in `<worktree>/.e2e/evidence.log`.
 *
 * `exec --record` and `logs --record` append one redacted, greppable entry per
 * command: a `===` header with the start time, label, Node, exit code, duration,
 * and end time, the argv as shell words, then the stdout and stderr sections.
 */
final readonly class EvidenceLog
{
    public const string FILE = 'evidence.log';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public function __construct(
        private SecretRedactor $redactor,
    ) {}

    /** @param list<string> $argv */
    public function append(
        string $worktree,
        EvidenceLabel $label,
        string $node,
        array $argv,
        GuestCommandResult $result,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
    ): void {
        $entry = $this->entry($label, $node, $argv, $result, $startedAt, $endedAt);
        $path = StatePaths::forWorktree($worktree)->ensureParent(self::FILE);
        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'ab');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('Unable to open the evidence log.');
        }
        try {
            if (! flock($handle, LOCK_EX) || fwrite($handle, $entry) !== strlen($entry) || ! fflush($handle)) {
                throw new RuntimeException('Unable to append to the evidence log.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param list<string> $argv */
    private function entry(
        EvidenceLabel $label,
        string $node,
        array $argv,
        GuestCommandResult $result,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
    ): string {
        $utc = new DateTimeZone('UTC');
        $start = $startedAt->setTimezone($utc);
        $end = $endedAt->setTimezone($utc);
        $duration = max(0, intdiv(
            (int) $end->format('Uu') - (int) $start->format('Uu'),
            1000,
        ));
        $words = array_map($this->shellWord(...), $this->redactor->redactArgv($argv));

        return '=== '.$start->format(self::TIME_FORMAT)." {$label->value} node={$node}"
            ." exit={$result->exitCode} duration={$duration}ms end=".$end->format(self::TIME_FORMAT)."\n"
            .'$ '.implode(' ', $words)."\n"
            ."--- stdout\n"
            .$this->section($result->stdout)
            ."--- stderr\n"
            .$this->section($result->stderr)
            ."\n";
    }

    private function section(string $output): string
    {
        $redacted = $this->redactor->redact($output);

        return $redacted === '' || str_ends_with($redacted, "\n") ? $redacted : $redacted."\n";
    }

    private function shellWord(string $word): string
    {
        if ($word !== '' && preg_match('/\A[A-Za-z0-9_@%+=:,.\/-]+\z/', $word) === 1) {
            return $word;
        }

        return "'".str_replace("'", "'\\''", $word)."'";
    }
}
