<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\ExtensionCommandVisibility;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

abstract class ProxyCliCommand extends GatewayCommand implements GatedExtensionCommand
{
    public function isHidden(): bool
    {
        return ExtensionCommandVisibility::listing()
            && app(GatewayExtensionState::class)->isEnabled('proxycli') !== true;
    }

    public function extensionSlug(): ?string
    {
        return 'proxycli';
    }

    protected function gatewayConnector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): ?GatewayConnector {
        $state = app(GatewayExtensionState::class)->discover();

        if (! $state->isKnown()) {
            $this->failUnknownExtensionState('proxycli', $state);

            return null;
        }

        return parent::gatewayConnector($repository, $connectors);
    }

    protected function renderStatus(ProxyCliStatusResponse $response, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'Enabled' => $response->enabled,
            'Hostname' => $response->hostname,
            'Node ID' => $response->nodeId,
            'Cache connection' => $response->cacheConnection,
            'Collected at' => $response->collectedAt,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
