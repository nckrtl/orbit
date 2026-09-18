<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\CreateManagedMysqlUserData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\ManagedMysqlProcess;
use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class CreateManagedMysqlUserAction
{
    public function __construct(
        private ManagedMysqlUserProvisioner $provisioner,
    ) {}

    public function execute(
        #[SensitiveParameter]
        Process $process,
        CreateManagedMysqlUserData $data,
        string $createdBy,
    ): DatabaseConnection {
        $managed = ManagedMysqlProcess::from($process);
        $this->assertMysqlSlug($data->slug);

        $this->provisioner->ensureUser(
            $managed,
            $data->database,
            $data->username,
            $data->password,
        );

        return DB::transaction(function () use ($managed, $data, $createdBy): DatabaseConnection {
            $existing = DatabaseConnection::query()
                ->where('slug', $data->slug)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof DatabaseConnection && $existing->driver !== DatabaseDriver::Mysql) {
                throw new ResourceOperationException(
                    errorCode: 'database.slug_conflict',
                    message: "Database connection [{$data->slug}] already exists.",
                    status: 409,
                );
            }

            $attributes = [
                'slug' => $data->slug,
                'driver' => DatabaseDriver::Mysql->value,
                'node_id' => $managed->node->id,
                'host' => $managed->host,
                'port' => $managed->port,
                'database' => $data->database,
                'path' => null,
                'username' => $data->username,
                'password' => $data->password,
            ];

            if ($existing instanceof DatabaseConnection) {
                $existing->update($attributes);
                $connection = $existing->refresh();
            } else {
                $connection = DatabaseConnection::query()->create($attributes);
            }

            DatabaseUser::query()->updateOrCreate(
                [
                    'database_connection_id' => $connection->id,
                    'username' => $data->username,
                ],
                [
                    'privileges' => "ALL PRIVILEGES ON `{$data->database}`.*",
                    'created_by' => $createdBy,
                ],
            );

            return $connection;
        });
    }

    private function assertMysqlSlug(string $slug): void
    {
        $existing = DatabaseConnection::query()->where('slug', $slug)->first();

        if ($existing instanceof DatabaseConnection && $existing->driver !== DatabaseDriver::Mysql) {
            throw new ResourceOperationException(
                errorCode: 'database.slug_conflict',
                message: "Database connection [{$slug}] already exists.",
                status: 409,
            );
        }
    }
}
