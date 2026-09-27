<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Project test command template must explicitly consume the former file and test name. */
final class FileAwareTestCommand implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! str_contains($value, '{file}') && ! str_contains($value, '{project_file}')) {
            $fail('The test_command must include the {file} or {project_file} placeholder.');
        }
        if (! str_contains($value, '{name}')) {
            $fail('The test_command must include the {name} placeholder.');
        }
    }
}
