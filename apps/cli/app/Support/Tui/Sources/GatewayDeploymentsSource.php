<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceDeploymentsRequest;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentResponse;
use Orbit\Sdk\Responses\Deployments\AppInstanceDeploymentsResponse;

/** Deployment history for one AppInstance, from `GET /instances/{instance}/deployments`. */
final readonly class GatewayDeploymentsSource implements DeploymentsSource
{
    use LimitsBackgroundRequestTime;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forInstance(int $instanceId): ?array
    {
        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ListAppInstanceDeploymentsRequest($instanceId)), AppInstanceDeploymentsResponse::class);
        } catch (GatewayApiException) {
            return null;
        }

        assert($response instanceof AppInstanceDeploymentsResponse);

        return array_map(self::row(...), $response->deployments);
    }

    /**
     * @return array{
     *     id: int,
     *     release: string,
     *     branch: string,
     *     commit: string,
     *     started: string,
     *     finished: string,
     *     duration: string,
     *     status: string,
     *     failed_step: string|null,
     *     error_code: string|null,
     *     selected_release: string|null,
     *     by: string,
     * }
     */
    private static function row(AppInstanceDeploymentResponse $deployment): array
    {
        return [
            'id' => $deployment->id,
            'release' => $deployment->release ?? '—',
            'branch' => $deployment->branch ?? '—',
            'commit' => $deployment->commit !== null ? substr($deployment->commit, 0, 7) : '—',
            'started' => $deployment->startedAt,
            'finished' => $deployment->finishedAt ?? '—',
            'duration' => $deployment->durationSeconds !== null ? "{$deployment->durationSeconds}s" : '—',
            'status' => $deployment->status,
            'failed_step' => $deployment->failedStep,
            'error_code' => $deployment->errorCode,
            'selected_release' => $deployment->selectedRelease,
            'by' => $deployment->triggeredBy ?? '—',
        ];
    }
}
