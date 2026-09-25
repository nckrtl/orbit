<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\ProcessAgentViewPublisher;
use Psr\Log\NullLogger;

function publisher_clock(): object
{
    return new class
    {
        public float $now = 100.0;
    };
}

describe('the agent view publisher', function (): void {
    it('starts a run for each lane with the queued work and never waits for them', function (): void {
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'usleep(300000); file_put_contents($argv[1], json_encode(array_slice($argv, 2)).PHP_EOL, FILE_APPEND);', '--', $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueWorkspaces(2, [5, 6]);
        $publisher->queueUsage(1_790_000_000);
        $started = hrtime(true);
        $publisher->poll();
        $elapsed = (hrtime(true) - $started) / 1e9;

        expect($elapsed)->toBeLessThan(0.25)
            ->and($publisher->isRunning())->toBeTrue();

        while ($publisher->isRunning()) {
            usleep(20_000);
        }
        $publisher->poll();

        $runs = array_map(static fn (string $line): mixed => json_decode($line, true), file($out, FILE_IGNORE_NEW_LINES) ?: []);
        sort($runs);

        expect($runs)->toBe([['--usage=1790000000'], ['--workspace=2:5', '--workspace=2:6']])
            ->and($publisher->isRunning())->toBeFalse();
        unlink($out);
    });

    it('keeps one run at a time, stops an overdue run, and backs off before the next', function (): void {
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'sleep(30);', '--'],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueUsage(1);
        $publisher->poll();
        $publisher->queueUsage(2);
        $clock->now += 5;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue();

        $clock->now += ProcessAgentViewPublisher::DeadlineSeconds;
        $started = hrtime(true);
        $publisher->poll();
        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
            ->and($publisher->isRunning())->toBeFalse();

        $publisher->poll();
        expect($publisher->isRunning())->toBeFalse();

        $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue();
        $publisher->stop();
        expect($publisher->isRunning())->toBeFalse();
    });

    it('puts the workspaces of a failed or stopped run back and runs them again after the backoff', function (string $code): void {
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', $code, '--'],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueWorkspaces(2, [5]);
        $publisher->poll();
        expect($publisher->pendingWorkspaces())->toBe([]);

        $clock->now += ProcessAgentViewPublisher::DeadlineSeconds;
        $deadline = microtime(true) + 5;
        while ($publisher->pendingWorkspaces() === [] && microtime(true) < $deadline) {
            usleep(20_000);
            $publisher->poll();
        }

        expect($publisher->pendingWorkspaces())->toBe(['2:5'])
            ->and($publisher->isRunning())->toBeFalse();

        $publisher->queueWorkspaces(2, [6]);
        $publisher->poll();
        expect($publisher->isRunning())->toBeFalse();

        $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue()
            ->and($publisher->pendingWorkspaces())->toBe([]);
        $publisher->stop();
    })->with([
        'failed' => ['exit(1);'],
        'stopped at the deadline' => ['sleep(30);'],
    ]);

    it('runs task workspaces while a usage run is still busy', function (): void {
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'if (str_starts_with($argv[2], "--usage")) { sleep(30); } file_put_contents($argv[1], $argv[2]);', '--', $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueUsage(1);
        $publisher->poll();
        $publisher->queueWorkspaces(2, [5]);
        $publisher->poll();

        $deadline = microtime(true) + 5;
        while (file_get_contents($out) === '' && microtime(true) < $deadline) {
            usleep(20_000);
        }

        expect(file_get_contents($out))->toBe('--workspace=2:5');
        $publisher->stop();
        unlink($out);
    });
});
