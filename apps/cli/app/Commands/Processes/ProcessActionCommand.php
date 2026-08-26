<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
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

        $process = $this->send($connector, $this->request($processId), ProcessResponse::class);

        if (! $process instanceof ProcessResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedProcessPayload($process->toArray()));

            return self::SUCCESS;
        }

        $this->info("Process [{$process->name}] {$this->pastTense()}.");
        $this->line("Request ID: {$process->requestId}");

        return self::SUCCESS;
    }

    abstract protected function request(int $processId): GatewayRequest;

    abstract protected function pastTense(): string;
}
