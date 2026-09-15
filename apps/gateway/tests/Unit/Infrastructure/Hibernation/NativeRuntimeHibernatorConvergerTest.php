<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RuntimeHibernatorUnitRenderer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

it('installs the oneshot service and enables the timer', function (): void {
    $processes = new HibernatorPublicationProcessRunner;
    $units = new RuntimeHibernatorUnitRenderer;
    $converger = new NativeRuntimeHibernatorConverger(
        processes: $processes,
        units: $units,
        phpBinary: '/usr/bin/php8.5',
        artisan: '/home/orbit/orbit-gateway/artisan',
        orbitHome: '/home/orbit/.orbit',
        workingDirectory: '/home/orbit/orbit-gateway',
        sweepSeconds: 600,
    );

    $converger->converge();

    expect(array_map(static fn ($invocation): array => $invocation->arguments, $processes->invocations))
        ->toBe([
            ['sudo', 'install', '-m', '0644', $processes->sources[0]['path'], $units->servicePath()],
            ['sudo', 'install', '-m', '0644', $processes->sources[1]['path'], $units->timerPath()],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'enable', '--now', $units->timerName()],
        ])
        ->and($processes->sources[0]['contents'])
        ->toContain('orbit:runtime-hibernator')
        ->toContain('User=orbit')
        ->not->toContain('User=root')
        ->and($processes->sources[1]['contents'])
        ->toContain('OnUnitActiveSec=600s');

    foreach ($processes->sources as $index => $source) {
        expect($source['regular'])->toBeTrue();
        expect($source['mode'])->toBe(0o600);
        expect(file_exists($source['path']))->toBeFalse();
        expect($processes->invocations[$index]->input)->toBeNull();
    }
});

it('republishes the oneshot as the configured Gateway account', function (): void {
    $processes = new HibernatorPublicationProcessRunner;
    $units = new RuntimeHibernatorUnitRenderer;
    $converger = new NativeRuntimeHibernatorConverger(
        processes: $processes,
        units: $units,
        phpBinary: '/usr/bin/php8.5',
        artisan: '/home/gateway/orbit-gateway/artisan',
        orbitHome: '/home/gateway/.orbit',
        workingDirectory: '/home/gateway/orbit-gateway',
        user: 'gateway',
        sweepSeconds: 600,
    );

    $converger->converge();

    expect($processes->sources[0]['contents'])
        ->toContain('User=gateway')
        ->not->toContain('User=orbit')
        ->not->toContain('User=root');
});

it('retains the failed unit identity and removes temporary input without reloading or enabling', function (int $failedWrite, bool $throws): void {
    $processes = new HibernatorPublicationProcessRunner(failedWrite: $failedWrite, throws: $throws);
    $converger = new NativeRuntimeHibernatorConverger(
        processes: $processes,
        artisan: '/home/orbit/orbit-gateway/artisan',
        orbitHome: '/home/orbit/.orbit',
        workingDirectory: '/home/orbit/orbit-gateway',
    );
    $failure = null;

    try {
        $converger->converge();
    } catch (NodeProvisioningException $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(NodeProvisioningException::class);
    expect($failure->step)->toBe($failedWrite === 1 ? 'gateway-hibernator-service' : 'gateway-hibernator-timer');
    expect($failure->errorCode)->toBe('gateway.hibernator_install_failed');
    expect($failure->result)->toBe($throws ? null : $processes->failure);
    expect($failure->getPrevious())->toBe($throws ? $processes->exception : null);
    expect($processes->invocations)->toHaveCount($failedWrite);
    expect($processes->sources)->toHaveCount($failedWrite);

    foreach ($processes->sources as $source) {
        expect($source['regular'])->toBeTrue();
        expect($source['mode'])->toBe(0o600);
        expect(file_exists($source['path']))->toBeFalse();
    }
})->with([
    'service exits nonzero' => [1, false],
    'timer exits nonzero' => [2, false],
    'service execution throws' => [1, true],
    'timer execution throws' => [2, true],
]);

final class HibernatorPublicationProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    /** @var list<array{path: string, contents: string|false, regular: bool, mode: int}> */
    public array $sources = [];

    public readonly CommandResult $failure;

    public readonly RuntimeException $exception;

    public function __construct(
        private readonly int $failedWrite = 0,
        private readonly bool $throws = false,
    ) {
        $this->failure = new CommandResult(1, '', 'install failed', 1, false);
        $this->exception = new RuntimeException('process execution failed');
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if (array_slice($invocation->arguments, 0, 2) === ['sudo', 'install']) {
            $path = $invocation->arguments[4];
            $this->sources[] = [
                'path' => $path,
                'contents' => file_get_contents($path),
                'regular' => is_file($path),
                'mode' => fileperms($path) & 0o777,
            ];

            if (count($this->sources) === $this->failedWrite) {
                if ($this->throws) {
                    throw $this->exception;
                }

                return $this->failure;
            }
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
