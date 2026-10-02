<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Node;

final readonly class AgentThreadStart
{
    public function __construct(
        public Node $node,
        public Instance $workspace,
        public string $title,
        public string $prompt,
        public string $model,
        public string $effort,
        public TaskThreadRole $role,
        public ?string $openingKey = null,
        public ?string $externalId = null,
        public bool $deferOpeningTurn = false,
    ) {}
}
