<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\ReleaseArtisan;
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
    it('runs the serve phase of the handoff with the code of the release it hands over to', function (): void {
        $serve = ['caddy' => 'unchanged', 'fpm' => 'unchanged', 'opcache' => ['outcome' => 'reset', 'cache_full' => false], 'agent_view' => 'restarted'];
        [$runtime, $processes] = artisan_runtime(0, json_encode($serve, JSON_THROW_ON_ERROR)."\n");

        expect($runtime->handoff('0123456789ab'))->toBe($serve)
            ->and($processes->ran)->toBe([
                ReleaseArtisan::command('/usr/bin/php8.5', '/home/orbit/releases/0123456789ab/apps/gateway/artisan', ['gateway:release:handoff', '--phase=serve', '--no-interaction']),
            ]);
    });

    it('runs the schedule phase separately and reads cleanup_paused strictly', function (): void {
        $schedule = ['scheduler' => 'restarted', 'scheduler_unit' => 'orbit-process-1-schedule-work.service', 'scheduler_drain' => ['outcome' => 'drained'], 'cleanup' => 'resumed'];
        [$runtime, $processes] = artisan_runtime(0, json_encode($schedule, JSON_THROW_ON_ERROR));

        expect($runtime->schedule('0123456789ab'))->toBe([...$schedule, 'cleanup_paused' => true])
            ->and($processes->ran[0])->toContain('--phase=schedule');
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

    it('hands over in this process when the release has no handoff command or phases, such as adoption\'s first release', function (string $output): void {
        $processes = new class($output) implements ProcessRunner
        {
            public function __construct(private readonly string $output) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(1, '', $this->output, 1, false);
            }
        };
        $local = new class implements GatewayReleaseRuntime
        {
            /** @var list<string> */
            public array $calls = [];

            public function handoff(string $id): array
            {
                $this->calls[] = 'handoff:'.$id;

                return ['caddy' => 'unchanged'];
            }

            public function schedule(string $id): array
            {
                $this->calls[] = 'schedule:'.$id;

                return ['scheduler' => 'restarted', 'cleanup_paused' => false];
            }
        };
        $runtime = new ArtisanGatewayReleaseRuntime(new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway'), $processes, fallback: $local);

        expect($runtime->handoff('0123456789ab'))->toBe(['caddy' => 'unchanged'])
            ->and($runtime->schedule('0123456789ab')['scheduler'])->toBe('restarted')
            ->and($local->calls)->toBe(['handoff:0123456789ab', 'schedule:0123456789ab']);
    })->with([
        'no command' => 'Command "gateway:release:handoff" is not defined.',
        'no release commands at all, like 6daef4579' => 'ERROR  There are no commands defined in the "gateway:release" namespace.',
        'no phases' => 'The "--phase" option does not exist.',
    ]);
});
