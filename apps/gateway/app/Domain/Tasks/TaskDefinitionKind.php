<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * A task-definition kind declares the fields and outcomes validation uses.
 */
enum TaskDefinitionKind: string
{
    case Agent = 'agent';
    case Check = 'check';
    case Merge = 'merge';
    case Action = 'action';
    case Decide = 'decide';

    /** @return list<string> */
    public function outcomes(): array
    {
        return match ($this) {
            self::Agent, self::Check, self::Merge => ['passed', 'skipped', 'failed'],
            self::Action => ['passed', 'failed'],
            self::Decide => [],
        };
    }

    /** @return list<string> */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Action => ['operation', 'arguments'],
            self::Decide => ['question', 'options', 'evidence'],
            default => [],
        };
    }

    /** @return list<string> */
    public function optionalFields(): array
    {
        return match ($this) {
            self::Agent => ['implementer_model', 'reviewer_model'],
            self::Decide => ['min_probability'],
            default => [],
        };
    }

    public function requiresCommandDeliverable(): bool
    {
        return $this === self::Check;
    }
}
