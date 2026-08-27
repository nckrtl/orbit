<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;

final class RemoveNodeAccessCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'node:access:remove
        {consumer : Numeric consumer node ID}
        {serving : Numeric serving node ID}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one node access edge from the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $consumerId = $this->positiveId('consumer', 'Consumer', 'node_access.consumer_id_invalid');

        if ($consumerId === null) {
            return self::FAILURE;
        }

        $servingId = $this->positiveId('serving', 'Serving', 'node_access.serving_id_invalid');

        if ($servingId === null) {
            return self::FAILURE;
        }

        if (! $this->confirmed($consumerId, $servingId)) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $access = $this->send(
            $connector,
            new RemoveNodeAccessRequest($consumerId, $servingId),
            RemovedNodeAccessResponse::class,
        );

        if (! $access instanceof RemovedNodeAccessResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($access->toArray());

            return self::SUCCESS;
        }

        $message = $access->alreadyAbsent
            ? "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) was already absent."
            : "Access from [{$access->consumerNode->name}] (#{$access->consumerNode->id}) to [{$access->servingNode->name}] (#{$access->servingNode->id}) removed.";

        $this->info($message);

        if ($access->selfLockout) {
            $this->warn('Warning: This node no longer has Gateway access.');
        }

        $this->line("Request ID: {$access->requestId}");

        return self::SUCCESS;
    }

    private function confirmed(int $consumerId, int $servingId): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') === true || ! $this->input->isInteractive()) {
            $this->renderGatewayFailure(
                'node_access.confirmation_required',
                'Use --force to confirm node access removal.',
            );

            return false;
        }

        return $this->confirm(
            "Remove access from node #{$consumerId} to node #{$servingId}?",
            false,
        );
    }
}
