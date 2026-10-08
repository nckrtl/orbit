<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final class FakeFootprintArtifact implements NodeFootprintArtifact
{
    /** @var list<string> */
    public array $applied = [];

    public function __construct(
        private string $name,
        public string $digest,
        public bool $applies = true,
        public ?bool $changes = true,
        public bool $fails = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function applies(Node $node): bool
    {
        return $this->applies;
    }

    public function digest(Node $node): string
    {
        return $this->digest;
    }

    public function apply(Node $node): ?bool
    {
        $this->applied[] = $node->name;

        if ($this->fails) {
            throw new ResourceOperationException('fake.artifact_failed', 'The fake artifact failed.', 502);
        }

        return $this->changes;
    }
}
