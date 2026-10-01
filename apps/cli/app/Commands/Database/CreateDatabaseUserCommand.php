<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Responses\DatabaseConnections\CreatedDatabaseUserResponse;

final class CreateDatabaseUserCommand extends DatabaseCommand
{
    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    #[\Override]
    protected $signature = 'database:user:create
        {slug : Connection slug of a database on a Database server}
        {--username= : Username, a 1-32 character identifier}
        {--password= : Password for the new user}
        {--read-only : Grant only SELECT on the database}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a user to a database on a Database server.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $username = $this->stringOption('username');
        $password = $this->input->getOption('password');

        if ($username === null) {
            return $this->renderGatewayFailure('database.username_required', 'A database user requires --username.');
        }

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return $this->renderGatewayFailure('database.username_invalid', 'Username must be a 1-32 character identifier of letters, digits, and underscores.');
        }

        if (! is_string($password) || $password === '') {
            return $this->renderGatewayFailure('database.password_required', 'A database user requires --password.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $created = $this->sendWithProgress(
            $connector,
            new CreateDatabaseUserRequest(
                slug: $slug,
                username: $username,
                password: $password,
                readOnly: $this->option('read-only') === true,
            ),
            CreatedDatabaseUserResponse::class,
            ['Create Database user', 'Creating Database user', 'Created Database user'],
        );

        if (! $created instanceof CreatedDatabaseUserResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($created->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Database user [{$created->user->username}] on [{$slug}].", [
            'Username' => $created->user->username,
            'Privileges' => $created->user->privileges,
            'Created by' => $created->user->createdBy,
            'Created' => $created->user->createdAt,
            'Request ID' => $created->requestId,
        ]));

        return self::SUCCESS;
    }
}
