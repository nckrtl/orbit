<?php

declare(strict_types=1);

namespace App\Commands\Dependencies;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\DependencyInstanceSelector;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\UpdateInstanceDependenciesRequest;
use Orbit\Sdk\Responses\Dependencies\DependencyInventoryResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyUpdateStepResponse;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyUpdateResponse;
use Saloon\Exceptions\Request\FatalRequestException;

final class UpdateInstanceDependenciesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:dependencies:update
        {--app= : Full Route domain selecting one development instance}
        {--all : Rejected; dependency updates are single-instance only}
        {--latest : Rejected; updates stay within declared constraints}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update development root packages within declared constraints and refresh inventory.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, DependencyInstanceSelector $selector): int
    {
        if ($this->option('all') === true) {
            return $this->renderGatewayFailure(
                'dependencies.all_forbidden',
                'Dependency updates accept one development instance; --all is not supported.',
            );
        }
        if ($this->option('latest') === true) {
            return $this->renderGatewayFailure(
                'dependencies.latest_forbidden',
                'Dependency updates stay within declared constraints; --latest is not supported.',
            );
        }

        $app = $this->option('app');
        if ($app !== null && trim($app) === '') {
            return $this->renderGatewayFailure('dependencies.domain_invalid', 'Supply a full Route domain.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->updateOne($connector, $selector, is_string($app) ? $app : null);
    }

    private function updateOne(GatewayConnector $connector, DependencyInstanceSelector $selector, ?string $app): int
    {
        $progress = $this->progressDisplay('Update instance dependencies');
        $progress->admit('target', 'Resolve instance', 'Resolving instance', 'Resolved instance');
        try {
            $target = $progress->during('target', fn () => $app === null
                ? $selector->resolveDirectory($connector)
                : $selector->resolveDomain($connector, $app));
            $progress->complete('target', ProgressState::Success, "Instance #{$target->instanceId}");
            $progress->admit('update', 'Update dependencies', 'Updating dependencies', 'Updated dependencies');
            /** @var InstanceDependencyUpdateResponse $result */
            $result = $progress->during('update', fn () => $this->sendOrThrow(
                $connector,
                new UpdateInstanceDependenciesRequest($target->instanceId),
                InstanceDependencyUpdateResponse::class,
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure(
                $exception->errorCode() ?? 'gateway.request_failed',
                $exception->getMessage(),
                $exception->requestId(),
            );
        } catch (FatalRequestException) {
            return $this->renderGatewayFailure('gateway.unreachable', 'Could not reach the gateway.');
        } catch (ConsoleInterrupted) {
            return $this->renderGatewayFailure(
                'input.cancelled',
                'Dependency update was cancelled. Package work is not rolled back automatically.',
            );
        }

        $succeeded = $result->succeeded === true;
        $progress->complete('update', $succeeded ? ProgressState::Success : ProgressState::Failure);
        $progress->finish($succeeded ? 'Dependency update complete.' : 'Dependency update failed.');

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());
        } else {
            $this->renderIdentity(
                $result->instanceId,
                $target->appId,
                $target->nodeId,
                $target->environment,
                $succeeded,
                $result->requestId,
                domain: $app,
                error: $result->errorCode,
                mayHaveMutated: $result->mayHaveMutated,
            );
            $this->renderStep('Composer', $result->composer);
            $this->renderStep('JavaScript', $result->javascript);
            if ($result->inventory === null) {
                ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Inventory', [
                    'State' => 'omitted',
                ]));
            } else {
                $this->renderEcosystem('Composer inventory', $result->inventory->composer);
                $this->renderEcosystem('JavaScript inventory', $result->inventory->javascript);
            }
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function renderIdentity(
        int $instanceId,
        int $appId,
        int $nodeId,
        string $environment,
        bool $succeeded,
        ?string $requestId,
        ?string $domain = null,
        ?string $error = null,
        ?bool $mayHaveMutated = null,
    ): void {
        $fields = [
            'Project' => $appId,
            'Node' => $nodeId,
            'Environment' => $environment,
        ];
        if ($domain !== null) {
            $fields['Domain'] = $domain;
        }
        $fields['Result'] = $succeeded ? 'Complete' : 'Failed';
        if ($mayHaveMutated !== null) {
            $fields['May have mutated'] = $mayHaveMutated ? 'yes' : 'no';
        }
        if ($error !== null) {
            $fields['Error'] = $error;
        }
        $fields['Request ID'] = $requestId;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: #{$instanceId}", $fields));
    }

    private function renderStep(string $label, DependencyUpdateStepResponse $step): void
    {
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($label, [
            'Status' => $step->status,
            'May have mutated' => $step->mayHaveMutated ? 'yes' : 'no',
            'Error' => $step->errorCode,
        ]));
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
