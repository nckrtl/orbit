<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\RestartHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class RestartHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:restart
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--handoff : Use Herdr live handoff when the inspected server reports support}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Restart one named Herdr session on a managed Node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        if (($failure = $this->guardExtension()) !== null) {
            return $failure;
        }

        $name = $this->sessionName();

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->requiredNodeId($connector);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $listed = $this->resolveSession($connector, $nodeId, $name);

        if ($listed === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new RestartHerdrSessionRequest($listed->id, $this->option('handoff') === true),
            HerdrSessionResponse::class,
        );

        if (! $response instanceof HerdrSessionResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedSessionPayload($response));

            return self::SUCCESS;
        }

        $this->info("Herdr session [{$response->session}] on [{$response->node}] is {$response->status}.");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
