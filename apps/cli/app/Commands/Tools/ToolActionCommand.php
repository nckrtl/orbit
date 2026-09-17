<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

abstract class ToolActionCommand extends ToolCommand
{
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $toolId = $this->toolId();

        if ($toolId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $tool = $this->sendWithProgress($connector, $this->request($toolId), ToolResponse::class, $this->progressLabels());

        if (! $tool instanceof ToolResponse) {
            return self::FAILURE;
        }

        if (! $this->accepts($tool)) {
            return $this->renderGatewayFailure(
                'gateway.invalid_response',
                'Gateway response is invalid.',
                $tool->requestId,
            );
        }

        return $this->renderTool($tool, $this->message($tool));
    }

    abstract protected function request(int $toolId): GatewayRequest;

    abstract protected function message(ToolResponse $tool): string;

    abstract protected function accepts(ToolResponse $tool): bool;

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
