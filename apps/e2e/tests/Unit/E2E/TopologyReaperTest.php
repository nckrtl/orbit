<?php

declare(strict_types=1);

use App\E2E\Value\IssueStateSnapshot;

describe('topology reaping input', function () {
    it('requires a private external issue snapshot with exact terminal entries', function () {
        $path = sys_get_temp_dir().'/orbit-issues-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode([
            'schema' => 1,
            'issues' => ['NCK-12' => 'completed'],
        ], JSON_THROW_ON_ERROR));
        chmod(filename: $path, permissions: 0o644);

        expect(fn () => IssueStateSnapshot::fromFile($path))
            ->toThrow(InvalidArgumentException::class, '0600');

        chmod(filename: $path, permissions: 0o600);
        $snapshot = IssueStateSnapshot::fromFile($path);

        expect($snapshot->isTerminal('NCK-12'))->toBeTrue()->and($snapshot->isTerminal('NCK-13'))->toBeFalse();
        unlink($path);
    });

    it('rejects non-terminal issue states', function () {
        expect(fn () => new IssueStateSnapshot(['NCK-12' => 'in_progress']))
            ->toThrow(InvalidArgumentException::class, 'non-terminal');
    });
});
