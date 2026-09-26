<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

/** The agent secret hash a converge stores once the agent can send the secret (ADR 0155). */
final readonly class AgentSecretHash
{
    public function __construct(
        public string $value,
        /** Whether the converge wrote a new secret file, so the agent must restart. */
        public bool $written,
    ) {}
}
