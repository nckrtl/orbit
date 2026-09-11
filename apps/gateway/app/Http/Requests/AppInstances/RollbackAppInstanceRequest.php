<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RollbackAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'release' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! DeploymentRelease::isValidName($value)) {
                        $fail('The release field must be a valid retained release name.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['release']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function release(): string
    {
        $release = $this->validated('release');
        assert(is_string($release));

        return $release;
    }
}
