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
use InvalidArgumentException;
use JsonException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\ScanInstanceDependenciesRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Orbit\Sdk\Responses\Dependencies\DependencyInventoryResponse;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Response;
use stdClass;

final class ScanInstanceDependenciesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:dependencies:scan
        {--app= : Full Route domain selecting one instance}
        {--all : Scan every authorized instance}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Scan root dependency manifests and lockfiles for one instance or every authorized instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors, DependencyInstanceSelector $selector): int
    {
        $app = $this->option('app');
        $all = $this->option('all') === true;
        if ($all && $app !== null) {
            return $this->renderGatewayFailure('dependencies.target_conflict', 'App and all-instance selectors cannot be combined.');
        }
        if ($app !== null && trim($app) === '') {
            return $this->renderGatewayFailure('dependencies.domain_invalid', 'Supply a full Route domain.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        return $all ? $this->scanAll($connector) : $this->scanOne($connector, $selector, is_string($app) ? $app : null);
    }

    private function scanOne(GatewayConnector $connector, DependencyInstanceSelector $selector, ?string $app): int
    {
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
            $this->renderIdentity($result->instanceId, $target->appId, $target->nodeId, $target->environment, $succeeded, $result->requestId);
            $this->renderEcosystem('Composer', $result->composer);
            $this->renderEcosystem('JavaScript', $result->javascript);
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function scanAll(GatewayConnector $connector): int
    {
        $progress = $this->progressDisplay('Scan instance dependencies');
        $progress->admit('targets', 'Resolve instances', 'Resolving instances', 'Resolved instances');
        try {
            $list = $progress->during('targets', fn () => $this->captureAuthorizedInstances($connector));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception->errorCode() ?? 'gateway.request_failed', $exception->getMessage(), $exception->requestId());
        } catch (ConsoleInterrupted) {
            return $this->renderGatewayFailure('input.cancelled', 'Instance listing was cancelled.');
        }

        $targets = $list->appInstances;
        $progress->complete('targets', ProgressState::Success, count($targets).' instances');
        $progress->finish(count($targets) === 0 ? 'No instances to scan.' : 'Resolved instances.');

        $outcomes = [];
        $skipped = 0;
        foreach ($targets as $index => $instance) {
            $scan = $this->progressDisplay("Scan instance #{$instance->id}");
            $scan->admit('scan', 'Scan dependencies', 'Scanning dependencies', 'Scanned dependencies');
            try {
                /** @var InstanceDependencyInventoryResponse $result */
                $result = $scan->during('scan', fn () => $this->sendOrThrow(
                    $connector, new ScanInstanceDependenciesRequest($instance->id), InstanceDependencyInventoryResponse::class,
                ));
                $succeeded = $result->succeeded === true;
                $scan->complete('scan', $succeeded ? ProgressState::Success : ProgressState::Failure);
                $scan->finish($succeeded ? 'Dependency scan complete.' : 'Dependency scan failed.');
                $outcome = $this->inventoryOutcome($instance, $result);
                $this->renderInventoryAttempt($instance, $result);
            } catch (GatewayApiException $exception) {
                $code = $exception->errorCode() ?? 'gateway.request_failed';
                $outcome = $this->errorOutcome($instance, $code, $exception->getMessage(), $exception->requestId());
                $this->renderErrorAttempt($instance, $code, $exception->requestId());
            } catch (ConsoleInterrupted) {
                $outcome = $this->errorOutcome($instance, 'input.cancelled', 'Dependency scan was cancelled.', null);
                $outcomes[] = $outcome;
                $this->renderErrorAttempt($instance, 'input.cancelled', null);
                $skipped = count($targets) - $index - 1;
                break;
            }

            $outcomes[] = $outcome;
        }

        $succeededCount = count(array_filter($outcomes, static fn (array $outcome): bool => $outcome['succeeded'] === true));
        $summary = [
            'attempted' => count($outcomes),
            'succeeded' => $succeededCount,
            'failed' => count($outcomes) - $succeededCount,
            'skipped' => $skipped,
        ];
        $allSucceeded = $summary['failed'] === 0 && $summary['skipped'] === 0;

        if ($this->option('json') === true) {
            $this->writeJson([
                'succeeded' => $allSucceeded,
                'summary' => $summary,
                'instances' => $outcomes,
                'request_id' => $list->requestId,
            ]);
        } else {
            $this->renderFleetSummary($summary, $list->requestId);
        }

        return $allSucceeded ? self::SUCCESS : self::FAILURE;
    }

    private function captureAuthorizedInstances(GatewayConnector $connector): AppInstancesResponse
    {
        try {
            $response = $connector->send(new ListAppInstancesRequest);
        } catch (FatalRequestException) {
            throw new GatewayApiException('Could not reach the gateway.', 'gateway.unreachable');
        }

        $requestId = $this->listingRequestId($response);
        $this->guardCompleteListing($response, $requestId);

        try {
            $dto = $response->dto();
        } catch (GatewayApiException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new GatewayApiException(
                message: 'Gateway response is invalid.',
                errorCode: 'gateway.invalid_response',
                previous: $exception,
                requestId: $requestId,
            );
        }

        if (! $dto instanceof AppInstancesResponse) {
            throw new GatewayApiException(
                message: 'Gateway response is invalid.',
                errorCode: 'gateway.invalid_response',
                requestId: $requestId,
            );
        }

        return $dto;
    }

    private function guardCompleteListing(Response $response, ?string $requestId): void
    {
        $decoded = $this->decodedListingEnvelope($response, $requestId);

        if (! $decoded instanceof stdClass || ! property_exists($decoded, 'data') || ! is_array($decoded->data) || ! array_is_list($decoded->data)) {
            throw new GatewayApiException(
                message: 'Gateway response contains invalid collection data.',
                requestId: $requestId,
            );
        }

        foreach ($decoded->data as $row) {
            if (! $this->isAuthorizedListingRow($row)) {
                throw new GatewayApiException(
                    message: 'Gateway response contains invalid collection data.',
                    requestId: $requestId,
                );
            }
        }
    }

    private function decodedListingEnvelope(Response $response, ?string $requestId): mixed
    {
        try {
            return json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GatewayApiException(
                message: 'Gateway response is not valid JSON.',
                requestId: $requestId,
            );
        }
    }

    private function isAuthorizedListingRow(mixed $row): bool
    {
        if (! $row instanceof stdClass) {
            return false;
        }

        $domain = $row->domain ?? null;

        return $this->listingPositiveId($row->id ?? null)
            && $this->listingPositiveId($row->app_id ?? null)
            && $this->listingPositiveId($row->node_id ?? null)
            && $this->listingNonEmptyString($row->name ?? null)
            && $this->listingEnvironment($row->environment ?? null)
            && ($domain === null || is_string($domain));
    }

    private function listingPositiveId(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    private function listingNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function listingEnvironment(mixed $value): bool
    {
        return $value === 'development' || $value === 'production';
    }

    private function listingRequestId(Response $response): ?string
    {
        try {
            $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $candidate = $decoded instanceof stdClass && isset($decoded->meta) && $decoded->meta instanceof stdClass
            ? ($decoded->meta->request_id ?? null)
            : null;
        $candidate ??= $response->header('X-Orbit-Request-Id');
        if (
            is_string($candidate)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
                $candidate,
            ) === 1
        ) {
            return $candidate;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function inventoryOutcome(AppInstanceResponse $instance, InstanceDependencyInventoryResponse $result): array
    {
        return [
            ...$this->instanceIdentity($instance),
            'succeeded' => $result->succeeded,
            'composer' => $result->composer->toArray(),
            'javascript' => $result->javascript->toArray(),
            'request_id' => $result->requestId,
        ];
    }

    /** @return array<string, mixed> */
    private function errorOutcome(AppInstanceResponse $instance, string $code, string $message, ?string $requestId): array
    {
        return [
            ...$this->instanceIdentity($instance),
            'succeeded' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ];
    }

    /** @return array{instance_id: int, app_id: int, node_id: int, name: string, environment: string, domain: ?string} */
    private function instanceIdentity(AppInstanceResponse $instance): array
    {
        return [
            'instance_id' => $instance->id,
            'app_id' => $instance->appId,
            'node_id' => $instance->nodeId,
            'name' => $instance->name,
            'environment' => $instance->environment,
            'domain' => $instance->domain,
        ];
    }

    private function renderInventoryAttempt(AppInstanceResponse $instance, InstanceDependencyInventoryResponse $result): void
    {
        if ($this->option('json') === true) {
            return;
        }

        $this->renderIdentity(
            $instance->id,
            $instance->appId,
            $instance->nodeId,
            $instance->environment,
            $result->succeeded === true,
            $result->requestId,
            $instance->name,
            $instance->domain,
        );
        $this->renderEcosystem('Composer', $result->composer);
        $this->renderEcosystem('JavaScript', $result->javascript);
    }

    private function renderErrorAttempt(AppInstanceResponse $instance, string $error, ?string $requestId): void
    {
        if ($this->option('json') === true) {
            return;
        }

        $this->renderIdentity(
            $instance->id,
            $instance->appId,
            $instance->nodeId,
            $instance->environment,
            false,
            $requestId,
            $instance->name,
            $instance->domain,
            $error,
        );
    }

    private function renderIdentity(
        int $instanceId,
        int $appId,
        int $nodeId,
        string $environment,
        bool $succeeded,
        ?string $requestId,
        ?string $name = null,
        ?string $domain = null,
        ?string $error = null,
    ): void {
        $fields = [
            'Project' => $appId,
            'Node' => $nodeId,
            'Environment' => $environment,
        ];
        if ($name !== null) {
            $fields['Name'] = $name;
        }
        if ($domain !== null) {
            $fields['Domain'] = $domain;
        }
        $fields['Result'] = $succeeded ? 'Complete' : 'Failed';
        if ($error !== null) {
            $fields['Error'] = $error;
        }
        $fields['Request ID'] = $requestId;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Instance: #{$instanceId}", $fields));
    }

    /** @param array{attempted: int, succeeded: int, failed: int, skipped: int} $summary */
    private function renderFleetSummary(array $summary, string $requestId): void
    {
        $text = "Summary: {$summary['attempted']} attempted, {$summary['succeeded']} complete, {$summary['failed']} failed";
        if ($summary['skipped'] > 0) {
            $text .= ", {$summary['skipped']} skipped";
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($text.'.', [
            'Request ID' => $requestId,
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
