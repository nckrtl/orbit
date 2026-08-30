<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\NodeSettingOptions;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class UpdateNodeSettingsCommand extends GatewayCommand
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

        $node = $this->send(
            $connector,
            new UpdateNodeSettingsRequest(
                nodeId: $nodeId,
                hasInstance: array_key_exists('instance', $settings['body']),
                instancePath: self::nestedPath($settings['body']['instance'] ?? null),
                hasWorktree: array_key_exists('worktree', $settings['body']),
                worktreePath: self::nestedPath($settings['body']['worktree'] ?? null),
            ),
            NodeResponse::class,
        );

        if (! $node instanceof NodeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($node->toArray());

            return self::SUCCESS;
        }

        $this->info("Node [{$node->name}] settings updated.");
        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }

    private static function nestedPath(mixed $value): ?string
    {
        if (! is_array($value) || ! is_string($value['path'] ?? null)) {
            return null;
        }

        return $value['path'];
    }
}
