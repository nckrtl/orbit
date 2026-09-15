<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseConnections\ManagedMysqlProcess;
use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

final class FakeManagedMysqlUserProvisioner implements ManagedMysqlUserProvisioner
{
    /** @var list<array{process_id: int, database: string, username: string}> */
    public array $calls = [];

    public ?ResourceOperationException $failure = null;

    public function ensureUser(
        ManagedMysqlProcess $process,
        string $database,
        string $username,
        #[SensitiveParameter]
        string $password,
    ): void {
        $this->calls[] = [
            'process_id' => $process->process->id,
            'database' => $database,
            'username' => $username,
        ];

        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }
    }
}
