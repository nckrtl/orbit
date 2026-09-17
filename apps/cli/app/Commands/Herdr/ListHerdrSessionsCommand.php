<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionsResponse;

final class ListHerdrSessionsCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:list
        {--node= : Node ID or registered name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List named Herdr sessions on one managed Node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        if (($failure = $this->guardExtension()) !== null) {
            return $failure;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->requiredNodeId($connector);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListHerdrSessionsRequest($nodeId),
            HerdrSessionsResponse::class,
            ['List Herdr sessions', 'Listing Herdr sessions', 'Listed Herdr sessions'],
        );

        if (! $response instanceof HerdrSessionsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->sessions as $session) {
            $rows[] = [
                $session->id,
                $session->session,
                $session->node,
                $session->user,
                $session->processId,
                $session->status,
                $session->observerUrl,
                $session->herdrVersion,
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Session', 'Node', 'User', 'Process', 'Status', 'Observer', 'Version'],
            $rows,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
