<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\AppInstances\RemoveAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalProgressResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalResponse;

final class RemoveInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:remove
        {instance : Numeric instance ID}
        {--force : Delete dirty or unpublished source after identity checks}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove an instance.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        try {
            $response = $this->sendOrThrow(
                $connector,
                new RemoveAppInstanceRequest(
                    $instanceId,
                    force: $this->option('force') === true ? true : null,
                ),
                AppInstanceRemovalResponse::class,
            );
        } catch (GatewayApiException $exception) {
            $this->renderRemovalFailure($exception);

            return self::FAILURE;
        }

        if (! $response instanceof AppInstanceRemovalResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->info("Instance [{$response->removal->name}] removed.");
        $this->writeProgress($response->removal);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function renderRemovalFailure(GatewayApiException $exception): void
    {
        $value = $exception->details()['removal'] ?? null;
        $progress = null;

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            $progress = AppInstanceRemovalProgressResponse::fromGatewayData($value);
        }

        GatewayFailureRenderer::write(
            $this,
            $exception->errorCode() ?? 'gateway.request_failed',
            $exception->getMessage(),
            $exception->requestId(),
            details: $progress instanceof AppInstanceRemovalProgressResponse
                ? ['removal' => $progress->toArray()]
                : [],
        );

        if ($this->option('json') !== true && $progress instanceof AppInstanceRemovalProgressResponse) {
            $this->writeProgress($progress);
        }
    }

    private function writeProgress(AppInstanceRemovalProgressResponse $progress): void
    {
        $this->line('Mode: '.($progress->force ? 'forced' : 'normal'));
        $this->line("Progress: {$progress->completed}/{$progress->total} completed; {$progress->remaining} remaining");
        $this->line('Current step: '.($progress->currentStep ?? '-'));

        if ($progress->failedStep !== null) {
            $this->line("Failed step: {$progress->failedStep}");
            $this->line('Error code: '.($progress->errorCode ?? '-'));
        }
    }
}
