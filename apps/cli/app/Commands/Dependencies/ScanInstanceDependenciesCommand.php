<?php

declare(strict_types=1);

namespace App\Commands\Dependencies;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\DependencyInstanceSelector;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Orbit\Sdk\Responses\Dependencies\DependencyInventoryResponse;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use Saloon\Exceptions\Request\FatalRequestException;

final class ScanInstanceDependenciesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:dependencies:scan
        {--app= : Full Route domain selecting one instance}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Scan one instance’s root dependency manifests and lockfiles.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, DependencyInstanceSelector $selector): int
    {
        $app = $this->option('app');
        if ($app !== null && trim($app) === '') {
            return $this->renderGatewayFailure('dependencies.domain_invalid', 'Supply a full Route domain.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $progress = $this->progressDisplay('Scan instance dependencies');
        $progress->admit('target', 'Resolve instance', 'Resolving instance', 'Resolved instance');
        try {
            $target = $progress->during('target', fn () => $app === null
                ? $selector->resolveDirectory($connector)
                : $selector->resolveDomain($connector, $app));
            $progress->complete('target', ProgressState::Success, "Instance #{$target->instanceId}");
            $progress->admit('scan', 'Scan dependencies', 'Scanning dependencies', 'Scanned dependencies');
            /** @var InstanceDependencyInventoryResponse $result */
            $result = $progress->during('scan', fn () => $this->sendOrThrow(
                $connector, new ScanInstanceDependenciesRequest($target->instanceId), InstanceDependencyInventoryResponse::class,
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception->errorCode() ?? 'gateway.request_failed', $exception->getMessage(), $exception->requestId());
        } catch (FatalRequestException) {
            return $this->renderGatewayFailure('gateway.unreachable', 'Could not reach the gateway.');
        }

        $succeeded = $result->succeeded === true;
        $progress->complete('scan', $succeeded ? ProgressState::Success : ProgressState::Failure);
        $progress->finish($succeeded ? 'Dependency scan complete.' : 'Dependency scan failed.');
        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());
        } else {
            ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: #{$result->instanceId}", [
                'App' => $target->appId,
                'Node' => $target->nodeId,
                'Environment' => $target->environment,
                'Result' => $succeeded ? 'Complete' : 'Failed',
                'Request ID' => $result->requestId,
            ]));
            $this->renderEcosystem('Composer', $result->composer);
            $this->renderEcosystem('JavaScript', $result->javascript);
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function renderEcosystem(string $label, DependencyInventoryResponse $inventory): void
    {
        $snapshot = $inventory->snapshot;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($label, [
            'State' => $inventory->state,
            'Resolutions' => $snapshot === null ? null : count($snapshot->graph->resolutions ?? []),
            'Requirements' => $snapshot === null ? null : count($snapshot->graph->requirements ?? []),
            'Observed' => $snapshot?->observedAt,
            'Attempted' => $inventory->attemptedAt,
            'Error' => $inventory->errorCode,
        ]));
    }
}
