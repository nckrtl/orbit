<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DatabaseConnectionAttachmentData extends Data
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public int $appInstanceId,
        public string $slug,
        public string $prefix,
        public array $keys,
        public ?string $host,
        public ?int $port,
        public string $operation,
        public bool $changed,
        public int $keyCount,
    ) {}
}
