<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class ShowHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:show
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one named Herdr session on a managed Node.';

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

        $response = $this->sendWithProgress(
            $connector,
            new ShowHerdrSessionRequest($listed->id),
            HerdrSessionResponse::class,
            ['Show Herdr session', 'Loading Herdr session', 'Loaded Herdr session'],
        );

        if (! $response instanceof HerdrSessionResponse) {
            return self::FAILURE;
        }

        return $this->renderSession($response, "Herdr session [{$response->session}] on [{$response->node}].");
    }
}
