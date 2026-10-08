<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Models\GatewayRelease;

/**
 * Queues a release an operator requested through the API and starts its unit. The release runs in
 * `orbit-gateway-release-run@<record>.service`, never in the PHP-FPM worker: it can restart the
 * scheduler and reload PHP-FPM. A request is refused while another release step holds the lock or
 * another requested release has not started yet.
 */
final readonly class GatewayReleaseRequests
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseUnitStarter $units,
    ) {}

    public function start(string $trigger, string $requested, ?string $sha, bool $force = false): GatewayRelease
    {
        $this->assertIdle();
        $record = $this->recorder->queue($trigger, $requested, $sha, $force);

        try {
            $this->units->start(
                $record->id,
                static fn (): bool => GatewayRelease::query()->whereKey($record->id)->value('outcome') !== GatewayRelease::Queued,
            );
        } catch (GatewayReleaseException $exception) {
            $this->recorder->fail($record, $exception, retryable: true, durationMs: 0);

            throw $exception;
        }

        return $record->refresh();
    }

    /**
     * Holds the lock for a moment to end records whose owner is gone, so a dead release never blocks
     * the next request. A queued record whose unit still may start refuses the request.
     */
    private function assertIdle(): void
    {
        $this->lock->run(fn (): array => $this->recorder->settleDead());

        $waiting = GatewayRelease::query()
            ->where('outcome', GatewayRelease::Queued)
            ->latest('id')
            ->first();

        if ($waiting instanceof GatewayRelease) {
            throw new GatewayReleaseException(
                step: 'lock',
                errorCode: 'gateway.release_in_progress',
                message: "Release record [{$waiting->id}] is queued. Wait for it to finish.",
            );
        }
    }
}
