<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\RemoveHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class RemoveHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:remove
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--accept-termination : Accept termination of live panes}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named Herdr session, its Process, and its private observer.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
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
            new RemoveHerdrSessionRequest($listed->id, $this->option('accept-termination') === true),
            HerdrSessionResponse::class,
        );

        if (! $response instanceof HerdrSessionResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedSessionPayload($response));

            return self::SUCCESS;
        }

        $this->info("Herdr session [{$response->session}] on [{$response->node}] was removed.");
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
