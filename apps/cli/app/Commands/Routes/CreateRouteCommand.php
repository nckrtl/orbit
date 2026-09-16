<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;

final class CreateRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:create
        {app? : Numeric App ID or custom proxy domain}
        {domain? : Route domain}
        {--publication=private : Publication intent}
        {--target= : Numeric AppInstance target ID}
        {--node= : Numeric Node scope ID or custom proxy serving Node}
        {--cluster= : Numeric Cluster scope ID for a targetless Route}
        {--upstream= : Loopback HTTP URL for a custom proxy Route}
        {--process= : Node Process name or ID for a custom proxy Route}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create an explicit Route.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        return $this->isCustomProxyForm()
            ? $this->createCustomProxy($repository, $connectors)
            : $this->createAppRoute($repository, $connectors);
    }

    private function isCustomProxyForm(): bool
    {
        return $this->option('upstream') !== null || $this->option('process') !== null;
    }

    private function createAppRoute(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');
        if ($appId === null) {
            return self::FAILURE;
        }

        $domain = $this->stringArgument('domain', 'Route domain', 'route.domain_required');
        if ($domain === null) {
            return self::FAILURE;
        }

        $targetId = $this->optionId('target', 'AppInstance');
        if ($targetId === 0) {
            return self::FAILURE;
        }

        $nodeId = $this->optionId('node', 'Node');
        if ($nodeId === 0) {
            return self::FAILURE;
        }

        $clusterId = $this->optionId('cluster', 'Cluster');
        if ($clusterId === 0) {
            return self::FAILURE;
        }

        $publication = $this->publication($this->option('publication'));
        if ($publication === null) {
            return self::FAILURE;
        }

        if ($targetId !== null && ($nodeId !== null || $clusterId !== null)) {
            return $this->renderGatewayFailure('route.scope_conflict', 'Do not combine a target with Route scope.');
        }

        if ($targetId === null && ($nodeId === null) === ($clusterId === null)) {
            return $this->renderGatewayFailure(
                'route.scope_required',
                'A targetless Route requires exactly one Node or Cluster scope.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $route = $this->sendWithProgress(
            $connector,
            new CreateRouteRequest(
                appId: $appId,
                domain: $domain,
                publication: $publication,
                appInstanceId: $targetId,
                nodeId: $nodeId,
                clusterId: $clusterId,
            ),
            RouteResponse::class,
            ['Create Route', 'Creating Route', 'Created Route'],
        );

        return $route instanceof RouteResponse
            ? $this->renderRoute($route)
            : self::FAILURE;
    }

    private function createCustomProxy(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $second = $this->argument('domain');
        if (is_string($second) && $second !== '') {
            return $this->renderGatewayFailure(
                'route.scope_required',
                'A custom proxy Route uses the domain as its first argument.',
            );
        }

        $domain = $this->stringArgument('app', 'Route domain', 'route.domain_required');
        if ($domain === null) {
            return self::FAILURE;
        }

        if ($this->option('target') !== null || $this->option('cluster') !== null) {
            return $this->renderGatewayFailure(
                'route.scope_required',
                'A custom proxy Route cannot own an App, App instance, or Cluster scope.',
            );
        }

        $publication = $this->option('publication');
        if ($publication !== null && $publication !== 'private') {
            return $this->renderGatewayFailure(
                'route.publication_invalid',
                'A custom proxy Route is private only.',
            );
        }

        $upstream = $this->stringOption('upstream');
        $process = $this->option('process');
        $processRef = is_string($process) && $process !== '' ? $process : null;

        if (($upstream === null) === ($processRef === null)) {
            return $this->renderGatewayFailure(
                'route.upstream_invalid',
                'Supply exactly one custom proxy upstream or Process.',
            );
        }

        $nodeRef = $this->option('node');
        if (! is_string($nodeRef) || trim($nodeRef) === '') {
            return $this->renderGatewayFailure(
                'route.scope_required',
                'A custom proxy Route requires a serving Node.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $nodeRef);
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $processId = $processRef === null ? null : $this->resolveProcessId($connector, $nodeId, $processRef);
        if ($processRef !== null && $processId === null) {
            return self::FAILURE;
        }

        $route = $this->sendWithProgress(
            $connector,
            new CreateRouteRequest(
                appId: null,
                domain: $domain,
                publication: 'private',
                nodeId: $nodeId,
                upstream: $upstream,
                processId: $processId,
            ),
            RouteResponse::class,
            ['Create Route', 'Creating Route', 'Created Route'],
        );

        return $route instanceof RouteResponse
            ? $this->renderRoute($route)
            : self::FAILURE;
    }

    private function resolveProcessId(GatewayConnector $connector, int $nodeId, string $reference): ?int
    {
        $reference = trim($reference);

        if (preg_match('/\A-?[0-9]+\z/D', $reference) === 1) {
            $id = filter_var($reference, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($id)) {
                $this->renderGatewayFailure('process.id_invalid', 'Process ID must be a positive integer.');

                return null;
            }

            return $id;
        }

        $processes = $this->send(
            $connector,
            new ListProcessesRequest(new NodeProcessTarget($nodeId)),
            ProcessesResponse::class,
        );

        if (! $processes instanceof ProcessesResponse) {
            return null;
        }

        $matches = array_values(array_filter(
            $processes->processes,
            static fn ($process): bool => $process->name === $reference,
        ));

        if (count($matches) !== 1) {
            $this->renderGatewayFailure(
                'process.not_found',
                "Process [{$reference}] is not registered on the serving Node.",
            );

            return null;
        }

        return $matches[0]->id;
    }
}
