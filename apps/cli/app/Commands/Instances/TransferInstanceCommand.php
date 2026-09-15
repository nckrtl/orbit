<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

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

Use --name when the destination path or generated domain must change. --json disables prompts and requires --force for transfer consent.
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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($this->option('force') !== true) {
            $source = $this->sendWithProgress($connector, new ShowAppInstanceRequest($instanceId), AppInstanceResponse::class,
                ['Resolve App instance', 'Loading App instance', 'Loaded App instance']);
            if (! $source instanceof AppInstanceResponse) {
                return self::FAILURE;
            }
            $destination = $this->sendWithProgress($connector, new ShowNodeRequest($nodeId), NodeResponse::class,
                ['Resolve destination Node', 'Loading destination Node', 'Loaded destination Node']);
            if (! $destination instanceof NodeResponse || ! $this->confirmAction(
                "Transfer App instance [{$source->name}] (#{$source->id}) from Node #{$source->nodeId} to Node [{$destination->name}] (#{$destination->id}) with downtime and deletion of the old placement?",
                'App instance transfer cancelled.',
                option: 'force',
                requiredCode: 'instance.confirmation_required',
                requiredMessage: 'Use --force to confirm AppInstance transfer downtime and old-placement deletion.',
            )) {
                return self::FAILURE;
            }
        }

        $instance = $this->sendWithProgress(
            $connector,
            new TransferAppInstanceRequest(
                instanceId: $instanceId,
                nodeId: $nodeId,
                name: $this->stringOption('name'),
                sqliteSourcePath: $this->stringOption('sqlite-source-path'),
            ),
            AppInstanceResponse::class,
            ['Transfer App instance', 'Transferring App instance', 'Transferred App instance'],
            static function (object $response): ProgressState {
                if (! $response instanceof AppInstanceResponse || $response->transfer === null || $response->domain === null || $response->domain === '') {
                    throw new GatewayApiException('Gateway response is invalid.', 'gateway.invalid_response', requestId: $response instanceof AppInstanceResponse ? $response->requestId : null);
                }

                return $response->transfer->cleanupCompleted ? ProgressState::Success : ProgressState::Warning;
            },
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

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("App instance: {$instance->name}", [
            'ID' => $instance->id,
            'Destination Node' => $instance->nodeId,
            'Destination path' => $instance->checkoutPath,
            'Authoritative domain' => $instance->domain,
            'Cleanup' => $instance->transfer->cleanupCompleted ? 'completed' : 'incomplete',
            'Request ID' => $instance->requestId,
        ]));

        return self::SUCCESS;
    }
}
