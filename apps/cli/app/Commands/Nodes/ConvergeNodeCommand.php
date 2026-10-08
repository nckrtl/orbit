<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Fleet\NodeFootprintResponse;

final class ConvergeNodeCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:converge
        {node : Node ID or name}
        {--force : Re-apply every artifact, not only the ones whose digest changed}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Re-apply the Gateway-rendered Orbit footprint of a Node. It never changes an Instance or a role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new ConvergeNodeRequest($nodeId, $this->option('force') === true),
            NodeFootprintResponse::class,
            ['Converge Node footprint', 'Converging Node footprint', 'Converged Node footprint'],
        );

        if (! $result instanceof NodeFootprintResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage($result->changed
            ? "Node #{$result->nodeId} [{$result->node}] footprint re-applied."
            : "Node #{$result->nodeId} [{$result->node}] footprint is current. Nothing changed.");
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Artifact', 'Result'],
            array_map(static fn (string $name, string $outcome): array => [$name, $outcome], array_keys($result->artifacts), $result->artifacts),
            'The Node has no Gateway-rendered footprint.',
        ));
        $this->writeHumanMessage('Digest: '.$result->digest);
        $this->writeHumanMessage("Request ID: {$result->requestId}");

        return self::SUCCESS;
    }
}
