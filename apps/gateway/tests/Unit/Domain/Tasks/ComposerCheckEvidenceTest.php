<?php

declare(strict_types=1);

use App\Domain\Tasks\ComposerCheckEvidence;

it('reads a passing composer check from tool output and ignores an assistant claim', function (): void {
    $evidence = ComposerCheckEvidence::fromMessages([
        ['id' => 'claim', 'kind' => 'message', 'label' => 'assistant', 'text' => 'composer check passed exit code 0', 'at' => '2026-09-22T11:00:00Z'],
        ['id' => 'check', 'kind' => 'activity', 'label' => 'tool', 'text' => 'composer check exit code 0', 'at' => '2026-09-22T12:00:00Z'],
    ]);

    expect($evidence->invoked)->toBeTrue()
        ->and($evidence->passed)->toBeTrue()
        ->and($evidence->current)->toBeTrue();
});

it('fails the check when the exit code is missing or a file edit follows it', function (): void {
    $missing = ComposerCheckEvidence::fromMessages([
        ['id' => 'check', 'kind' => 'activity', 'label' => 'tool', 'text' => 'composer check passed', 'at' => '2026-09-22T12:00:00Z'],
    ]);
    $edited = ComposerCheckEvidence::fromMessages([
        ['id' => 'check', 'kind' => 'activity', 'label' => 'tool', 'text' => 'composer check exit code 0', 'at' => '2026-09-22T12:00:00Z'],
        ['id' => 'edit', 'kind' => 'activity', 'label' => 'tool', 'text' => 'apply_patch src/Task.php', 'at' => '2026-09-22T12:05:00Z'],
    ]);

    expect($missing->invoked)->toBeTrue()->and($missing->passed)->toBeFalse()
        ->and($edited->passed)->toBeTrue()->and($edited->current)->toBeFalse();
});

it('does not read other composer check commands as a composer check run', function (string $command): void {
    $evidence = ComposerCheckEvidence::fromMessages([
        ['id' => 'other', 'kind' => 'activity', 'label' => 'tool', 'text' => $command.' exit code 0', 'at' => '2026-09-22T12:00:00Z'],
    ]);

    expect($evidence->invoked)->toBeFalse()->and($evidence->passed)->toBeFalse();
})->with(['composer check-platform-reqs', 'composer check:types']);
