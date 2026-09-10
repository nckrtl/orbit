<?php

declare(strict_types=1);

namespace App\Http\Requests\Metrics;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class EnableMetricsRequest extends FormRequest
{
    /** @return array<string, list<string|Closure|Exists>> */
    public function rules(): array
    {
        return ['node_id' => ['required', $this->strictInteger(...), 'min:1', Rule::exists(Node::class, 'id')]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['node_id']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function nodeId(): int
    {
        $value = $this->validated('node_id');
        assert(is_int($value), description: 'Validated metrics node ID must be an integer.');

        return $value;
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
