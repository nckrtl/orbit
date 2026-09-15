<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use SensitiveParameter;

interface ManagedMysqlUserProvisioner
{
    public function ensureUser(
        ManagedMysqlProcess $process,
        string $database,
        string $username,
        #[SensitiveParameter]
        string $password,
    ): void;
}
