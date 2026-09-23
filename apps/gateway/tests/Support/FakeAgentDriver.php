<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadStart;
use App\Models\AgentThread;
use App\Models\Node;

final class FakeAgentDriver implements AgentDriver
{
    public ?AgentObservation $observation = null;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public bool $eligible = true;

    public function __construct(private readonly string $key = 'example') {}

    public function key(): string
    {
        return $this->key;
    }

    public function allows(Node $node): bool
    {
        return $this->eligible;
    }

    public function create(AgentThreadStart $intent): string
    {
        $this->calls[] = ['operation' => 'create', 'prompt' => $intent->prompt];

        return 'conversation-'.count($this->calls);
    }

    public function send(AgentThread $thread, string $message): void
    {
        $this->calls[] = ['operation' => 'send', 'thread' => $thread->external_id, 'message' => $message];
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        $this->calls[] = ['operation' => 'respond', 'thread' => $thread->external_id, 'request' => $request->id, 'answers' => $answers];
    }

    public function interrupt(AgentThread $thread): void
    {
        throw AgentDriverException::unsupported('interruption');
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        return $this->observation ?? throw new AgentDriverException('Unavailable');
    }

    public function events(AgentThread $thread, ?string $cursor): iterable
    {
        return [];
    }
}
