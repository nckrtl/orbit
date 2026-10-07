<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Models\GatewayRelease;

/**
 * Runs a deploy or rollback that the API queued, inside its release unit. The record carries the
 * request, so the unit takes nothing but the record id. A refusal before the release claimed the
 * record, such as a busy lock, still ends the record.
 */
final readonly class RunRequestedGatewayReleaseAction
{
    public function __construct(
        private DeployGatewayReleaseAction $deploy,
        private RollbackGatewayReleaseAction $rollback,
        private GatewayReleaseRecorder $recorder,
    ) {}

    public function execute(string $record): GatewayRelease
    {
        $row = preg_match('/\A[1-9][0-9]{0,18}\z/D', $record) === 1 ? GatewayRelease::query()->find((int) $record) : null;

        if (! $row instanceof GatewayRelease) {
            throw new GatewayReleaseException(
                step: 'run',
                errorCode: 'gateway.release_not_found',
                message: "No release record exists for [{$record}].",
                status: 404,
            );
        }

        if ($row->outcome !== GatewayRelease::Queued) {
            throw new GatewayReleaseException(
                step: 'run',
                errorCode: 'gateway.release_not_queued',
                message: "Release record [{$row->id}] is {$row->outcome}, not queued.",
                status: 422,
            );
        }

        try {
            if ($row->trigger === 'rollback') {
                $this->rollback->execute((string) $row->requested, $row->force, $row);
            } else {
                $this->deploy->execute((string) $row->requested, force: $row->force, trigger: 'deploy', record: $row);
            }
        } catch (GatewayReleaseException $exception) {
            $this->recorder->fail($row, $exception, retryable: true, durationMs: 0);

            throw $exception;
        }

        return $row->refresh();
    }
}
