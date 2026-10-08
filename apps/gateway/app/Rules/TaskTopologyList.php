<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Tasks\TaskTopology;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class TaskTopologyList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! TaskTopology::valid($value)) {
            $fail('Topology must be a list of distinct app-dev, app-prod, or app-prod-2 nodes.');
        }
    }
}
