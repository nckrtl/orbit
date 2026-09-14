<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class DatabaseRoleSettings
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public static function from(array $settings): self
    {
        if ($settings !== []) {
            throw new NodeRoleValidationException(
                'The database role does not accept settings.',
                details: ['field' => 'settings'],
            );
        }

        return new self;
    }
}
