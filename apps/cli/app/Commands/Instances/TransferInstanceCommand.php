<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

final class TransferInstanceCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'instance:transfer
        {instance : Numeric AppInstance ID}
        {node : Numeric destination Node ID}
        {--name= : Optional destination AppInstance name}
        {--sqlite-source-path= : Optional SQLite database path on the source}
        {--force : Confirm downtime and old-placement deletion}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Transfer a development AppInstance to another app-dev Node.';

    #[\Override]
    protected $help = <<<'HELP'
Transfer moves one active development AppInstance to a distinct active app-dev Node in an active Cluster. The destination may be in the same Cluster or another Cluster.

Orbit stops source processes for the downtime window, copies the source checkout or worktree into an independent destination checkout, optionally copies one selected SQLite snapshot, rebuilds destination environment values, moves or replaces the Route, and deletes the old managed placement. Production, standalone, and same-Node transfers are refused.

Use --name when the destination path or generated domain must change. --json confirms the transfer and disables prompts.
HELP;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $nodeId = $this->positiveId('node', 'Node', 'node.id_invalid');

        if ($nodeId === null) {
            return self::FAILURE;
        }

        if (! $this->confirmed()) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $instance = $this->send(
            $connector,
            new TransferAppInstanceRequest(
                instanceId: $instanceId,
                nodeId: $nodeId,
                name: $this->stringOption('name'),
                sqliteSourcePath: $this->stringOption('sqlite-source-path'),
            ),
            AppInstanceResponse::class,
        );

        if (! $instance instanceof AppInstanceResponse) {
            return self::FAILURE;
        }

        if ($instance->transfer === null || $instance->domain === null || $instance->domain === '') {
            return $this->renderGatewayFailure(
                'gateway.invalid_response',
                'Gateway response is invalid.',
                $instance->requestId,
            );
        }

        if ($this->option('json') === true) {
            $this->writeJson([
                'id' => $instance->id,
                'node_id' => $instance->nodeId,
                'name' => $instance->name,
                'checkout_path' => $instance->checkoutPath,
                'domain' => $instance->domain,
                'transfer' => $instance->transfer->toArray(),
                'request_id' => $instance->requestId,
            ]);

            return self::SUCCESS;
        }

        $this->info("AppInstance [{$instance->name}] transferred.");
        $this->line("Instance ID: {$instance->id}");
        $this->line("Destination Node: {$instance->nodeId}");
        $this->line("Destination path: {$instance->checkoutPath}");
        $this->line("Authoritative domain: {$instance->domain}");
        $this->line('Cleanup: '.($instance->transfer->cleanupCompleted ? 'completed' : 'incomplete'));
        $this->line("Request ID: {$instance->requestId}");

        return self::SUCCESS;
    }

    private function confirmed(): bool
    {
        if ($this->option('force') === true || $this->option('json') === true) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return $this->confirm(
                'Transfer stops the source AppInstance, then deletes the old placement. Continue?',
                false,
            );
        }

        $this->renderGatewayFailure(
            'instance.confirmation_required',
            'Use --force to confirm AppInstance transfer downtime and old-placement deletion.',
        );

        return false;
    }
}
