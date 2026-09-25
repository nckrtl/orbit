<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * Provisioning found Nodes that could host the group, but each is at the task ceiling.
 *
 * This is a wait, not a failure. `fleetFull` is true when no active Linux app-dev Node has task capacity.
 */
final class TaskCapacityException extends RuntimeException
{
    public function __construct(public readonly bool $fleetFull)
    {
        parent::__construct('No Node has task capacity.');
    }
}
