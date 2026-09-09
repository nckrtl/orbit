<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class ImportAppInstanceEnvironmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['replace' => ['sometimes', 'boolean:strict']];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['replace']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function shouldReplace(): bool
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ($validated['replace'] ?? false) === true;
    }
}
