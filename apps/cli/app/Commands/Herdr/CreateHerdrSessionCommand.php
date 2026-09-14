<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\CreateHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class CreateHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:create
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--user= : Unix user that owns the Herdr session and socket}
        {--publish-observer : Publish the private receive-only observer}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create or ensure one named Herdr session on a managed Node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $session = $this->sessionName();

        if ($session === null) {
            return self::FAILURE;
        }

        $user = $this->stringOption('user');

        if ($user === null) {
            return $this->renderGatewayFailure(
                'herdr.user_required',
                'Unix user is required.',
            );
        }

        if (strlen($user) > 32 || preg_match('/\A[a-z_][a-z0-9_-]*\z/D', $user) !== 1) {
            return $this->renderGatewayFailure(
                'herdr.user_invalid',
                'Unix user is invalid.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->requiredNodeId($connector);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new CreateHerdrSessionRequest(
                nodeId: $nodeId,
                session: $session,
                user: $user,
                publishObserver: $this->option('publish-observer') === true,
            ),
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
