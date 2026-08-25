<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;

final class GatewayStatusCommand extends Command
{
    protected $signature = 'gateway:status
        {--json : Return machine-readable JSON}';

    protected $description = 'Show the active gateway status.';

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
            /** @var GatewayStatusResponse $status */
            $status = $connectors->make($profile)->send(new ShowGatewayStatusRequest)->dto();
        } catch (GatewayApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'gateway' => $profile->name,
            'url' => $profile->url,
            ...$status->toArray(),
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("{$profile->name}: {$status->status} ({$status->version})");
        $this->line("URL: {$profile->url}");
        $this->line("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }
}
