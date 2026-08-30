<?php

declare(strict_types=1);

namespace App\Http\Requests\Metrics;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class EnableMetricsRequest extends FormRequest
{
    public function rules(): array
    {
        return ['node_id' => ['required', $this->strictInteger(...), 'min:1', Rule::exists(Node::class, 'id')]];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['node_id']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @mago-expect analysis:mixed-assignment Validated request input is an untyped transport boundary. */
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
