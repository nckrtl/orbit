<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\NodeSettingOptions;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class UpdateNodeSettingsCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:settings
        {node : Node ID or name}
        {--setting=* : Repeatable node setting as setting-path:value}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update typed storage settings on a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $settings = NodeSettingOptions::parse($this->option('setting'));

        if ($settings['ok'] === false) {
            return $this->renderGatewayFailure($settings['code'], $settings['message']);
        }

        if ($settings['provided'] === false) {
            return $this->renderGatewayFailure(
                'node.setting_required',
                'Provide at least one --setting option.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $node = $this->sendWithProgress(
            $connector,
            new UpdateNodeSettingsRequest(
                nodeId: $nodeId,
                hasApps: array_key_exists('apps', $settings['body']),
                apps: NodeSettingOptions::apps($settings['body']['apps'] ?? null),
            ),
            NodeResponse::class,
            ['Update Node settings', 'Updating Node settings', 'Updated Node settings'],
        );

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Node [{$node->name}] settings updated.");
        $this->writeHumanMessage("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
