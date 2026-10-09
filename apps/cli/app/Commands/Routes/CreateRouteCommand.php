<?php

declare(strict_types=1);

namespace App\Commands\Routes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Responses\Instances\InstancesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;

class CreateRouteCommand extends RouteCommand
{
    #[\Override]
    protected $signature = 'route:create
        {instance? : Numeric Instance ID (custom proxy: domain)}
        {domain? : Route domain}
        {--publication=private : Publication intent}
        {--node= : Custom proxy serving Node}
        {--upstream= : Loopback HTTP URL for a custom proxy Route}
        {--process= : Node Process name or ID for a custom proxy Route}
        {--web-root= : Web root inside the Instance checkout, such as apps/docs/public}
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
        $instance = $this->argument('instance');
        $connector = null;
        if ($instance === null && $this->consoleMode()->mayPrompt) {
            $connector = $this->gatewayConnector($repository, $connectors);
            if ($connector === null) {
                return self::FAILURE;
            }

            $instances = $this->sendWithProgress($connector, new ListInstancesRequest, InstancesResponse::class, ['Instances', 'Fetching Instances', 'Fetched Instances'], dismiss: true);
            if (! $instances instanceof InstancesResponse) {
                return self::FAILURE;
            }

            $rows = [];
            foreach ($instances->instances as $candidate) {
                $rows[$candidate->id] = [
                    (string) $candidate->id,
                    $candidate->name,
                    $candidate->project->name ?? (string) $candidate->projectId,
                    $candidate->node->name ?? (string) $candidate->nodeId,
                    $candidate->status,
                ];
            }

            $instance = $this->commandPrompts()->selectEntity('Instance', ['ID', 'Name', 'Project', 'Node', 'Status'], $rows);
        }

        $instanceId = filter_var($instance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($instanceId)) {
            return $this->renderGatewayFailure('instance.id_invalid', 'Instance ID must be a positive integer.');
        }

        $domain = $this->routeDomain('domain');
        if ($domain === null) {
            return self::FAILURE;
        }

        $publication = $this->publication($this->option('publication'));
        if ($publication === null) {
            return self::FAILURE;
        }

        if ($this->option('node') !== null) {
            return $this->renderGatewayFailure('route.scope_conflict', 'An app Route derives its scope from the Instance.');
        }

        $connector ??= $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $route = $this->sendWithProgress(
            $connector,
            new CreateRouteRequest(
                domain: $domain,
                publication: $publication,
                instanceId: $instanceId,
                webRoot: $this->stringOption('web-root'),
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

        $domain = $this->routeDomain('instance');
        if ($domain === null) {
            return self::FAILURE;
        }

        $publication = $this->option('publication');
        if ($publication !== null && $publication !== 'private') {
            return $this->renderGatewayFailure(
                'route.publication_invalid',
                'A custom proxy Route is private only.',
            );
        }

        if ($this->stringOption('web-root') !== null) {
            return $this->renderGatewayFailure('route.web_root_unsupported', 'A custom proxy Route has no web root.');
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

    private function routeDomain(string $argument): ?string
    {
        $domain = $this->argument($argument);
        if ($domain === null && $this->consoleMode()->mayPrompt) {
            $domain = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt('Route domain', required: true));
        }

        if (! is_string($domain) || $domain === '') {
            $this->renderGatewayFailure('route.domain_required', 'Route domain is required.');

            return null;
        }

        return $domain;
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
