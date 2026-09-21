<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Node;

interface AgentDriver
{
    public function key(): string;

    public function allows(Node $node): bool;

    public function create(AgentThreadStart $intent): string;

    public function send(AgentThread $thread, string $message): void;

    /** @param array<string, mixed> $answers */
    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void;

    public function interrupt(AgentThread $thread): void;

    public function observe(AgentThread $thread): AgentObservation;

    /** @return iterable<AgentThreadEvent> */
    public function events(AgentThread $thread, ?string $cursor): iterable;
}
