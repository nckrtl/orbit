<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUserResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUsersResponse;

final class ListDatabaseUsersCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:user:list
        {slug : Database connection slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the recorded users for a registered Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListDatabaseUsersRequest($slug),
            DatabaseUsersResponse::class,
            ['List Database users', 'Loading Database users', 'Loaded Database users'],
        );

        if (! $response instanceof DatabaseUsersResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Username', 'Privileges', 'Created by', 'Created'],
            array_map(
                fn (DatabaseUserResponse $user): array => [
                    $user->username,
                    $user->privileges,
                    $user->createdBy ?? '—',
                    $user->createdAt,
                ],
                $response->users,
            ),
            'No users recorded.',
        ));
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
