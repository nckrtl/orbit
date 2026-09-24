<?php

declare(strict_types=1);

use App\E2E\EvidenceLog;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\EvidenceLabel;
use App\E2E\Value\GuestCommandResult;

it('appends a greppable, redacted entry with millisecond UTC timestamps', function (): void {
    $worktree = temporaryPath('orbit-evidence-', 4);
    mkdir($worktree, 0700, true);

    new EvidenceLog(new SecretRedactor(['s3cret-value']))->append(
        $worktree,
        new EvidenceLabel('viewer crash 1'),
        'app-dev',
        ['sh', '-c', "echo 'it''s' done", '--token=abc', 'orbit', 's3cret-value'],
        new GuestCommandResult("line one\nBearer abc.def\n", 'uses s3cret-value', 3),
        new DateTimeImmutable('2026-09-24T08:40:00.123456+02:00'),
        new DateTimeImmutable('2026-09-24T06:40:01.357999Z'),
    );

    expect(file_get_contents($worktree.'/.e2e/evidence.log'))->toBe(<<<'LOG'
        === 2026-09-24T06:40:00.123Z viewer crash 1 node=app-dev exit=3 duration=1234ms end=2026-09-24T06:40:01.357Z
        $ sh -c 'echo '\''it'\'''\''s'\'' done' '--token=[REDACTED]' orbit '[REDACTED]'
        --- stdout
        line one
        Bearer [REDACTED]
        --- stderr
        uses [REDACTED]


        LOG);
});

it('appends without rewriting earlier entries and creates the log private', function (): void {
    $worktree = temporaryPath('orbit-evidence-', 4);
    mkdir($worktree, 0700, true);
    $log = new EvidenceLog(new SecretRedactor);
    $append = static fn (string $label, string $stdout) => $log->append(
        $worktree,
        new EvidenceLabel($label),
        'gateway',
        ['orbit', 'node:list'],
        new GuestCommandResult($stdout, '', 0),
        new DateTimeImmutable('2026-09-24T06:40:00Z'),
        new DateTimeImmutable('2026-09-24T06:40:00Z'),
    );
    $previousUmask = umask(0022);
    try {
        $append('first', 'one');
        $first = (string) file_get_contents($worktree.'/.e2e/evidence.log');
        $append('second', '');
    } finally {
        umask($previousUmask);
    }
    $content = (string) file_get_contents($worktree.'/.e2e/evidence.log');

    expect(fileperms($worktree.'/.e2e/evidence.log') & 0777)
        ->toBe(0600)
        ->and($first)
        ->toBe("=== 2026-09-24T06:40:00.000Z first node=gateway exit=0 duration=0ms end=2026-09-24T06:40:00.000Z\n"
            ."\$ orbit node:list\n--- stdout\none\n--- stderr\n\n")
        ->and($content)
        ->toStartWith($first)
        ->and(substr($content, strlen($first)))
        ->toBe("=== 2026-09-24T06:40:00.000Z second node=gateway exit=0 duration=0ms end=2026-09-24T06:40:00.000Z\n"
            ."\$ orbit node:list\n--- stdout\n--- stderr\n\n");
});

it('refuses a symbolic link in place of the log', function (): void {
    $worktree = temporaryPath('orbit-evidence-', 4);
    mkdir($worktree.'/.e2e', 0700, true);
    $target = temporaryFile('orbit-evidence-target-');
    symlink($target, $worktree.'/.e2e/evidence.log');

    expect(fn () => new EvidenceLog(new SecretRedactor)->append(
        $worktree,
        new EvidenceLabel('linked'),
        'gateway',
        ['true'],
        new GuestCommandResult('', '', 0),
        new DateTimeImmutable,
        new DateTimeImmutable,
    ))->toThrow(InvalidArgumentException::class, 'symbolic link')
        ->and(file_get_contents($target))
        ->toBe('');
});

it('accepts labels of 1 to 80 letters, digits, spaces, dots, dashes, and underscores', function (string $label): void {
    expect(new EvidenceLabel($label)->value)->toBe($label);
})->with(['a', 'Presence viewer 2.1_after-crash', str_repeat('x', 80)]);

it('refuses other labels', function (string $label): void {
    expect(fn () => new EvidenceLabel($label))
        ->toThrow(InvalidArgumentException::class, 'The --record label must be 1 to 80');
})->with(['', ' ', str_repeat('x', 81), "two\nlines", 'a/b', 'quote"', 'tab	']);
