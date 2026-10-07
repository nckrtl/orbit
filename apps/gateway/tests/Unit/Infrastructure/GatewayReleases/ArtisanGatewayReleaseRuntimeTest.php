<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

function artisan_runtime(int $exit, string $stdout): array
{
    $processes = new class($exit, $stdout) implements ProcessRunner
    {
        /** @var list<list<string>> */
        public array $ran = [];

        public function __construct(private readonly int $exit, private readonly string $stdout) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->ran[] = $invocation->arguments;

            return new CommandResult($this->exit, $this->stdout, '', 1, false);
        }
    };

    return [new ArtisanGatewayReleaseRuntime(new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway'), $processes), $processes];
}

describe(ArtisanGatewayReleaseRuntime::class, function (): void {
    it('runs the handoff with the code of the release it hands over to', function (): void {
        [$runtime, $processes] = artisan_runtime(0, json_encode([
            'caddy' => 'unchanged', 'fpm' => 'unchanged', 'scheduler' => 'restarted', 'scheduler_unit' => 'orbit-process-1-schedule-work.service',
            'cleanup' => 'resumed', 'cleanup_error_code' => null, 'agent_view' => 'restarted', 'cleanup_paused' => false,
        ], JSON_THROW_ON_ERROR)."\n");

        expect($runtime->handoff('0123456789ab'))->toBe([
            'caddy' => 'unchanged', 'fpm' => 'unchanged', 'scheduler' => 'restarted', 'scheduler_unit' => 'orbit-process-1-schedule-work.service',
            'cleanup' => 'resumed', 'cleanup_error_code' => null, 'agent_view' => 'restarted', 'cleanup_paused' => false,
        ])->and($processes->ran)->toBe([[
            '/usr/bin/php8.5', '/home/orbit/releases/0123456789ab/apps/gateway/artisan', 'gateway:release:handoff', '--no-interaction',
        ]]);
    });

    it('fails with the handoff error the release reported', function (): void {
        [$runtime] = artisan_runtime(1, '{"error_code":"gateway.release_scheduler_busy","step":"handoff","message":"A tick held the lock."}');

        expect(fn () => $runtime->handoff('0123456789ab'))->toThrow(
            fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_scheduler_busy')
                ->and($exception->step)->toBe('handoff')
                ->and($exception->getMessage())->toBe('A tick held the lock.'),
        );
    });

    it('fails when the release prints no handoff result, such as a release without the command', function (): void {
        [$runtime] = artisan_runtime(1, 'Command "gateway:release:handoff" is not defined.');

        expect(fn () => $runtime->handoff('0123456789ab'))->toThrow(
            fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_handoff_failed'),
        );
    });
});
