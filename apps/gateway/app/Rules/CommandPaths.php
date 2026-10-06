<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskDeliverableVerifier;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/** Require nonempty overlay paths for base runs and restrict paths to command deliverables. */
final class CommandPaths implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parent = $this->parent($attribute);
        $id = is_array($parent) ? ($parent['id'] ?? null) : null;
        $who = is_string($id) && $id !== '' ? "deliverable {$id}" : 'this deliverable';
        $type = is_array($parent) ? ($parent['type'] ?? null) : null;
        $hasPaths = is_array($parent) && array_key_exists('paths', $parent);

        if ($type !== TaskDeliverableType::Command->value) {
            if ($hasPaths) {
                $fail("The paths field is only allowed on a command deliverable ({$who}).");
            }

            return;
        }

        if (($parent['fails_on_base'] ?? false) === true && (! is_array($value) || $value === [])) {
            $fail("The paths value for {$who} must contain at least one path when fails_on_base is true.");
        }
    }

    /**
     * @param  list<string>  $baseFiles
     * @param  list<string>  $createdPatterns
     */
    public static function pathViolation(string $path, array $baseFiles, array $createdPatterns, bool $failsOnBase): ?string
    {
        if ($failsOnBase && preg_match('#(?:\A|/)tests/|Test\.php\z|\.test\.ts\z|\.spec\.ts\z|_test\.go\z#', $path) !== 1) {
            return 'must be a test file for fails_on_base';
        }
        if (! in_array($path, $baseFiles, true) && ! array_any($createdPatterns, static fn (string $pattern): bool => TaskDeliverableVerifier::matches($pattern, $path))) {
            return 'is missing';
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /** @return array<array-key, mixed>|null */
    private function parent(string $attribute): ?array
    {
        if (! str_ends_with($attribute, '.paths')) {
            return null;
        }

        $cursor = $this->data;
        foreach (explode('.', substr($attribute, 0, -strlen('.paths'))) as $segment) {
            if ($segment === '' || ! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return is_array($cursor) ? $cursor : null;
    }
}
