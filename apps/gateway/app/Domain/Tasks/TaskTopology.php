<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

/** The workload inventory a subtask requires in its private Orbit sandbox. */
final class TaskTopology
{
    public const array Roles = ['app-dev', 'app-prod', 'app-prod-2'];

    public static function valid(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > count(self::Roles)) {
            return false;
        }
        $seen = [];
        foreach ($value as $role) {
            if (! is_string($role) || ! in_array($role, self::Roles, true) || in_array($role, $seen, true)) {
                return false;
            }
            $seen[] = $role;
        }

        return true;
    }

    /** @return list<string> */
    public static function from(mixed $value): array
    {
        if (! is_array($value) || ! self::valid($value)) {
            throw new ResourceOperationException('tasks.invalid_topology', 'Topology must be a list of distinct app-dev, app-prod, or app-prod-2 nodes.', 422);
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
