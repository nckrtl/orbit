<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class ShowNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'node:show
        {node : Numeric node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a node registered with the active gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $repository->active();

        if ($profile === null) {
            $this->error('No active gateway profile.');

            return self::FAILURE;
        }

        $nodeId = filter_var(
            $this->argument('node'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($nodeId)) {
            $this->error('Node ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            /** @var NodeResponse $node */
            $node = $connectors->make($profile)->send(new ShowNodeRequest($nodeId))->dto();
        } catch (GatewayApiException $exception) {
            GatewayCommand::writeGatewayApiException($this, $exception);

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($node->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $roles = $node->roles === [] ? '-' : implode(', ', $node->roles);
        $platform = match (true) {
            $node->platform !== null && $node->architecture !== null => "{$node->platform} ({$node->architecture})",
            $node->platform !== null => $node->platform,
            $node->architecture !== null => $node->architecture,
            default => '-',
        };

        $this->info("{$node->name}: {$node->status}");
        $this->line("Roles: {$roles}");
        $this->line("SSH: {$node->sshUser}@{$node->publicSshHost}:{$node->publicSshPort}");
        $this->line('WireGuard: '.($node->wireguardAddress ?? '-'));
        $this->line("Platform: {$platform}");

        if ($node->failedStep !== null || $node->errorCode !== null) {
            $failure = implode(' / ', array_filter([$node->failedStep, $node->errorCode], is_string(...)));
            $this->line("Failure: {$failure}");
        }

        $this->line("Request ID: {$node->requestId}");

        return self::SUCCESS;
    }
}
