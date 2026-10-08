<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Models\GatewayRelease;

/**
 * Ends the release records whose process died, as `interrupted`. The release units run it from
 * `ExecStopPost`, after their main process exited for any reason, including a kill or a timeout.
 *
 * - With a record id, it ends that record when it has no outcome yet and its unit has stopped. A
 *   running record ends only while the release lock is free, so a live release is never ended.
 * - Without one, it takes the release lock when it is free and ends every record whose owner is
 *   gone. A busy lock means a release runs, so nothing is dead.
 */
final readonly class SettleGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseRecorder $recorder,
    ) {}

    /** @return list<int> the ids of the records it ended */
    public function execute(?string $record = null): array
    {
        if ($record !== null) {
            $row = preg_match('/\A[1-9][0-9]{0,18}\z/D', $record) === 1 ? GatewayRelease::query()->find((int) $record) : null;

            if (! $row instanceof GatewayRelease) {
                throw new GatewayReleaseException(
                    step: 'settle',
                    errorCode: 'gateway.release_not_found',
                    message: "No release record exists for [{$record}].",
                    status: 404,
                );
            }

            if ($row->outcome !== GatewayRelease::Running) {
                // A queued record whose unit stopped was never claimed, so no release owns it.
                return $this->recorder->settle($row) ? [$row->id] : [];
            }

            try {
                // A running record is dead only while no release holds the lock.
                return $this->lock->run(fn (): bool => $this->recorder->settle($row)) ? [$row->id] : [];
            } catch (GatewayReleaseException $exception) {
                if ($exception->errorCode === 'gateway.release_in_progress') {
                    return [];
                }

                throw $exception;
            }
        }

        try {
            $settled = $this->lock->run(fn (): array => $this->recorder->settleDead());
        } catch (GatewayReleaseException $exception) {
            if ($exception->errorCode === 'gateway.release_in_progress') {
                return [];
            }

            throw $exception;
        }

        return array_map(static fn (GatewayRelease $row): int => $row->id, $settled);
    }
}
