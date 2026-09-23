<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0133: a deliverable ID is unique within its subtask. Laravel's `distinct` compares across every
 * subtask of a nested list, so each subtask's list checks itself.
 */
final readonly class DistinctDeliverableIds implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }
        $ids = array_values(array_filter(array_map(static fn (mixed $deliverable): mixed => is_array($deliverable) ? ($deliverable['id'] ?? null) : null, $value), is_string(...)));
        $repeated = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));
        if ($repeated !== []) {
            $fail('Each deliverable id must be unique within the subtask. Repeated: '.implode(', ', $repeated).'.');
        }
    }
}
