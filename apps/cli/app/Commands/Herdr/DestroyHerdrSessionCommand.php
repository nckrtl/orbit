<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\DestroyHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class DestroyHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:destroy
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--accept-termination : Accept termination of live panes}
        {--yes : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one Herdr session from Orbit and destroy its Process only when Orbit manages it.';

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

        $effect = $listed->management === 'managed'
            ? 'This destroys its Process and removes its Orbit record and observer.'
            : 'This removes only its Orbit record and observer; its external service keeps running.';

        if (! $this->confirmAction(
            "Destroy Herdr session [{$name}] on [{$listed->node}]? {$effect}",
            'Herdr session destruction cancelled.',
        )) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new DestroyHerdrSessionRequest($listed->id, $this->option('accept-termination') === true),
            HerdrSessionResponse::class,
            ['Destroy Herdr session', 'Destroying Herdr session', 'Destroyed Herdr session'],
        );

        if (! $response instanceof HerdrSessionResponse) {
            return self::FAILURE;
        }

        return $this->renderSession($response, "Herdr session [{$response->session}] on [{$response->node}] was removed from Orbit.");
    }
}
