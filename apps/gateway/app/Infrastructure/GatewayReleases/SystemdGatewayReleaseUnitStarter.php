<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;

/**
 * Starts `orbit-gateway-release-run@<record>.service` with the Gateway account's `sudo systemctl`,
 * the same privilege the Gateway uses for its other units. `--no-block` returns once systemd queued
 * the start, which proves nothing, so the starter then reads the unit state until the unit runs or
 * claimed the record, and fails when it does not within a few seconds.
 */
final readonly class SystemdGatewayReleaseUnitStarter implements GatewayReleaseUnitStarter
{
    /** @param  Closure(int): void|null  $sleep  waits the given milliseconds */
    public function __construct(
        private ProcessRunner $processes,
        private GatewayReleaseUnitRenderer $units = new GatewayReleaseUnitRenderer,
        private int $attempts = 10,
        private int $intervalMs = 500,
        private ?Closure $sleep = null,
    ) {}

    public function start(int $record, Closure $claimed): void
    {
        $unit = $this->units->runUnitName($record);
        $result = $this->processes->run(new ProcessInvocation(
            ['sudo', '-n', 'systemctl', 'start', '--no-block', $unit],
            timeout: 30.0,
        ));

        if (! $result->succeeded()) {
            throw $this->failed($unit, 'systemd refused to start', $result);
        }

        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            $state = $this->state($record);

            if (in_array($state, ['active', 'activating', 'reloading', 'deactivating'], true) || $claimed()) {
                return;
            }

            if ($state === 'failed') {
                throw $this->failed($unit, 'the unit failed');
            }

            ($this->sleep ?? static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            })($this->intervalMs);
        }

        if ($claimed()) {
            return;
        }

        throw $this->failed($unit, 'the unit did not start');
    }

    public function isActive(int $record): bool
    {
        return in_array($this->state($record), ['active', 'activating', 'reloading', 'deactivating'], true);
    }

    private function state(int $record): string
    {
        $result = $this->processes->run(new ProcessInvocation(
            ['systemctl', 'is-active', $this->units->runUnitName($record)],
            timeout: 10.0,
        ));

        return trim($result->stdout);
    }

    private function failed(string $unit, string $why, ?CommandResult $result = null): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'start',
            errorCode: 'gateway.release_unit_failed',
            message: "{$unit}: {$why}. Run orbit:gateway-web on the Gateway to install the release units, then check journalctl -u {$unit}.",
            status: 503,
            result: $result,
        );
    }
}
