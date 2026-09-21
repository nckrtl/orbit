<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;

abstract class ProxyCliCommand extends GatewayCommand
{
    public function isHidden(): bool
    {
        try {
            return ! app(LocalExtensionState::class)->enabled('proxycli');
        } catch (GatewayConfigException) {
            return true;
        }
    }

    protected function guardExtension(): ?int
    {
        try {
            $enabled = app(LocalExtensionState::class)->enabled('proxycli');
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        if ($enabled) {
            return null;
        }

        return $this->renderGatewayFailure(
            'extension.disabled',
            'The proxycli extension is disabled. Run `orbit extension:enable proxycli` first.',
        );
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
