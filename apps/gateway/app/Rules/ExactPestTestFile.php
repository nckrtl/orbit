<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Tasks\TaskDeliverable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0133: a test deliverable names one exact Pest file. The error names that deliverable's id.
 */
final class ExactPestTestFile implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || TaskDeliverable::isExactTestFile($value)) {
            return;
        }

        $id = $this->deliverableId($attribute);
        $who = $id === null ? 'this deliverable' : "deliverable {$id}";
        $fail("The test file for {$who} must be one exact .php path.");
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    private function deliverableId(string $attribute): ?string
    {
        if (! str_ends_with($attribute, '.file')) {
            return null;
        }

        $cursor = $this->data;

        foreach (explode('.', substr($attribute, 0, -strlen('file')).'id') as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
