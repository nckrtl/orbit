<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;

abstract class ProcessActionCommand extends ProcessCommand
{
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $processId = $this->positiveId('process', 'Process', 'process.id_invalid');

        if ($processId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        return $this->handleProcess($connector, $processId);
    }

    protected function handleProcess(GatewayConnector $connector, int $processId): int
    {
        $process = $this->sendWithProgress(
            $connector,
            $this->request($processId),
            ProcessResponse::class,
            $this->progressLabels(),
        );

        if (! $process instanceof ProcessResponse) {
            return self::FAILURE;
        }

        return $this->renderProcess($process, "Process [{$process->name}] {$this->pastTense()}.");
    }

    abstract protected function request(int $processId): GatewayRequest;

    abstract protected function pastTense(): string;

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
