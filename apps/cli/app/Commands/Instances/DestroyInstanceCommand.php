<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use App\Support\GatewayFailureRenderer;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\AppInstances\DestroyAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalProgressResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class DestroyInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:destroy
        {instance : Numeric instance ID}
        {--yes : Confirm removal without prompting}
        {--force : Delete dirty or unpublished source after identity checks}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy an Instance.';

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

        if ($this->option('yes') !== true) {
            $existing = $this->sendWithProgress($connector, new ShowAppInstanceRequest($instanceId), AppInstanceResponse::class,
                ['Resolve Instance', 'Loading Instance', 'Loaded Instance']);
            if (! $existing instanceof AppInstanceResponse) {
                return self::FAILURE;
            }
            $effect = $existing->environment === 'production'
                ? 'remove its Route and runtime while retaining production content'
                : 'delete its owned development source, Route and runtime';
            if ($this->option('force') === true && $existing->environment !== 'production') {
                $effect .= ', including dirty or unpublished work and registered linked worktrees';
            }
            if (! $this->confirmAction("Remove Instance [{$existing->name}] (#{$existing->id}) and {$effect}?", 'Instance removal cancelled.')) {
                return self::FAILURE;
            }
        }

        $progress = $this->progressDisplay('Remove Instance');
        $progress->admit('remove', 'Remove Instance', 'Removing Instance', 'Removed Instance');
        try {
            $response = $progress->during('remove', fn (): object => $this->sendOrThrow(
                $connector,
                new DestroyAppInstanceRequest(
                    $instanceId,
                    force: $this->option('force') === true ? true : null,
                ),
                AppInstanceRemovalResponse::class,
            ));
        } catch (GatewayApiException $exception) {
            $this->renderRemovalFailure($exception);

            return self::FAILURE;
        }

        if (! $response instanceof AppInstanceRemovalResponse) {
            return self::FAILURE;
        }

        $progress->complete('remove', ProgressState::Success);
        $progress->finish('Instance removed.');

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Instance [{$response->removal->name}] removed.");
        $this->writeProgress($response->removal);
        $this->writeHumanMessage("Request ID: {$response->requestId}");

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
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Removal: {$progress->name}", [
            'ID' => $progress->id,
            'Mode' => $progress->force ? 'forced' : 'normal',
            'Status' => $progress->status,
            'Progress' => "{$progress->completed}/{$progress->total} completed; {$progress->remaining} remaining",
            'Current step' => $progress->currentStep,
            'Failed step' => $progress->failedStep,
            'Error code' => $progress->errorCode,
        ]));
    }
}
