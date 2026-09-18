<?php

declare(strict_types=1);

use App\Commands\Realtime\RealtimeTailCommand;

/**
 * The command only ever prints a human line through a live interactive terminal (verified with
 * PTY evidence per the verifying-cli-output skill, not an automated assertion). These tests
 * cover the pure line-formatting logic that line depends on directly, through reflection.
 */
describe('RealtimeTailCommand line formatting', function (): void {
    it('summarizes a data record as space-separated key=value pairs', function (): void {
        $summarize = new ReflectionMethod(RealtimeTailCommand::class, 'summarize');
        $command = new RealtimeTailCommand;

        expect($summarize->invoke($command, ['id' => 7, 'name' => 'beast', 'status' => 'provisioning']))
            ->toBe('id=7 name=beast status=provisioning');
    });

    it('renders an empty data record as an em dash', function (): void {
        $summarize = new ReflectionMethod(RealtimeTailCommand::class, 'summarize');
        $command = new RealtimeTailCommand;

        expect($summarize->invoke($command, []))->toBe('—');
    });

    it('scalarizes booleans, null, and nested values distinctly from strings', function (): void {
        $scalarize = new ReflectionMethod(RealtimeTailCommand::class, 'scalarize');
        $command = new RealtimeTailCommand;

        expect($scalarize->invoke($command, true))->toBe('true')
            ->and($scalarize->invoke($command, false))->toBe('false')
            ->and($scalarize->invoke($command, null))->toBe('—')
            ->and($scalarize->invoke($command, 42))->toBe('42')
            ->and($scalarize->invoke($command, 1.5))->toBe('1.5')
            ->and($scalarize->invoke($command, 'beast'))->toBe('beast')
            ->and($scalarize->invoke($command, ['a', 'b']))->toBe('["a","b"]');
    });

    it('matches a trailing-wildcard type filter and an exact one', function (): void {
        $matches = new ReflectionMethod(RealtimeTailCommand::class, 'matchesTypeFilter');
        $command = new RealtimeTailCommand;

        expect($matches->invoke($command, 'node.created', []))->toBeTrue()
            ->and($matches->invoke($command, 'node.created', ['node.*']))->toBeTrue()
            ->and($matches->invoke($command, 'node.created', ['process.status']))->toBeFalse()
            ->and($matches->invoke($command, 'process.status', ['node.*', 'process.status']))->toBeTrue();
    });
});
