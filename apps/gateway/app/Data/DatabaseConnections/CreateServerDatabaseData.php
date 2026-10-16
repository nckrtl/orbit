<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use Spatie\LaravelData\Data;

/**
 * `patterns`, `allow`, and `database` describe an ACL user on a Valkey server (ADR 0221). A MySQL server takes none
 * of them.
 */
final class CreateServerDatabaseData extends Data
{
    /**
     * @param  list<string>  $patterns  Key patterns, without the `~`.
     * @param  list<string>  $allow  Command categories or commands, without the `+`.
     */
    public function __construct(
        public string $slug,
        public string $server,
        public ?int $instanceId,
        public array $patterns = [],
        public array $allow = [],
        public ?int $database = null,
    ) {}
}
