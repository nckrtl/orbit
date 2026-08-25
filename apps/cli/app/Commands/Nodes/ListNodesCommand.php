<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

final class ListNodesCommand extends Command
{
    #[\Override]
    protected $signature = 'node:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List nodes registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $repository->active();

        if ($profile === null) {
            $this->error('No active gateway profile.');

            return self::FAILURE;
        }

        try {
            /** @var NodesResponse $response */
            $response = $connectors->make($profile)->send(new ListNodesRequest)->dto();
        } catch (GatewayApiException $exception) {
            GatewayCommand::writeGatewayApiException($this, $exception);

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->nodes as $node) {
            $rows[] = [
                $node->id,
                $node->name,
                $node->status,
                $node->roles === [] ? '-' : implode(', ', $node->roles),
                "{$node->sshUser}@{$node->publicSshHost}:{$node->publicSshPort}",
                $node->wireguardAddress ?? '-',
            ];
        }

        if ($rows === []) {
            $this->line('No nodes.');
            $this->line("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Status', 'Roles', 'SSH', 'WireGuard'], $rows);

        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
