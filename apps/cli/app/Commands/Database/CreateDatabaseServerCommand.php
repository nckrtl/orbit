<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

final class CreateDatabaseServerCommand extends DatabaseCommand
{
    public const string TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D';

    #[\Override]
    protected $signature = 'database:server:create
        {slug : Database server slug}
        {--node= : Node ID or registered name}
        {--tag= : MySQL image tag. Defaults to 8.4}
        {--port= : Port to publish on the Node WireGuard address. Defaults to 3306}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run a MySQL server as a Docker Node Process and keep its root password in the Gateway.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->serverSlug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $tag = $this->stringOption('tag');

        if ($tag !== null && preg_match(self::TAG_PATTERN, $tag) !== 1) {
            return $this->renderGatewayFailure('database.server_tag_invalid', 'Tag must be a MySQL image tag such as 8.4.');
        }

        $port = $this->option('port');
        $parsedPort = null;

        if ($port !== null) {
            $parsedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if (! is_int($parsedPort)) {
                return $this->renderGatewayFailure('database.port_invalid', 'Port must be an integer from 1 through 65535.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->option('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $server = $this->sendWithProgress(
            $connector,
            new CreateDatabaseServerRequest(
                slug: $slug,
                nodeId: $nodeId,
                tag: $tag,
                port: $parsedPort,
            ),
            DatabaseServerResponse::class,
            ['Create Database server', 'Creating Database server', 'Created Database server'],
        );

        if (! $server instanceof DatabaseServerResponse) {
            return self::FAILURE;
        }

        return $this->renderServer($server, "Database server [{$server->slug}] is running.");
    }
}
