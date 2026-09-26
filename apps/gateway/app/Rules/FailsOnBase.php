<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Tasks\TaskDeliverableType;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR 0163: fails_on_base is a JSON boolean, and only a test deliverable may set it.
 * The error names that deliverable's id. Implicit so an empty or null value is refused too.
 */
final class FailsOnBase implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = $this->sibling($attribute, 'id');
        $who = is_string($id) && $id !== '' ? "deliverable {$id}" : 'this deliverable';

        if ($this->sibling($attribute, 'type') !== TaskDeliverableType::Test->value) {
            $fail("The fails_on_base field is only allowed on a test deliverable ({$who}).");

            return;
        }

        if (! is_bool($value)) {
            $fail("The fails_on_base value for {$who} must be true or false.");
        }
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    private function sibling(string $attribute, string $key): mixed
    {
        if (! str_ends_with($attribute, '.fails_on_base')) {
            return null;
        }

        $cursor = $this->data;

        foreach (explode('.', substr($attribute, 0, -strlen('fails_on_base')).$key) as $segment) {
            if ($segment === '' || ! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
