<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RemoveNodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'offline' => ['sometimes', $this->strictBoolean(...)],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['offline']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /**
     * The operator's claim that the node is gone.
     *
     * It is a claim, not an instruction: the action probes the node and takes
     * the ordinary fail-closed path when it answers.
     */
    public function offline(): bool
    {
        return $this->validated('offline', false) === true;
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
