<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use SensitiveParameter;

/**
 * Records the SQL a test sends to a Database server. `$rows` maps an SQL fragment to the rows a
 * matching query returns, and `$failOn` makes a statement that contains the fragment fail.
 */
final class FakeDatabaseServerAdmin implements DatabaseServerAdmin
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> */
    public array $readyChecks = [];

    /** @var list<bool> Readiness answers in order; once empty, every check succeeds. */
    public array $readiness = [];

    /** @var array<string, list<string>> */
    public array $rows = [];

    public ?string $failOn = null;

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        $this->readyChecks[] = $server->slug;

        return array_shift($this->readiness) ?? true;
    }

    public function execute(DatabaseServer $server, #[SensitiveParameter] string $sql): array
    {
        $this->statements[] = $sql;

        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new ResourceOperationException(
                errorCode: 'database.server_command_failed',
                message: "Database server [{$server->slug}] could not run an admin command.",
                status: 502,
            );
        }

        foreach ($this->rows as $fragment => $rows) {
            if (str_contains($sql, $fragment)) {
                return $rows;
            }
        }

        return [];
    }

    /** @var list<array{source: string, target: string}> */
    public array $copies = [];

    public bool $failCopy = false;

    public function copyDatabase(DatabaseServer $server, string $source, string $target): void
    {
        $this->copies[] = ['source' => $source, 'target' => $target];

        if ($this->failCopy) {
            throw new ResourceOperationException(
                errorCode: 'database.server_command_failed',
                message: "Database server [{$server->slug}] could not run an admin command.",
                status: 502,
            );
        }
    }

    public function sql(): string
    {
        return implode('', $this->statements);
    }
}
