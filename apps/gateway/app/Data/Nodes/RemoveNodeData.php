<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class RemoveNodeData extends Data
{
    /**
     * @param list<string> $rolesShed
     * @param list<string> $retainedOnNode
     *
     * @mago-expect lint:excessive-parameter-list Removal reports every outcome the operator has to act on.
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $removed,
        public bool $wireguardPeerRemoved,
        public bool $dnsRecordsRemoved,
        public ?string $degradation = null,
        public array $rolesShed = [],
        public array $retainedOnNode = [],
        public ?string $followUp = null,
    ) {}
}
