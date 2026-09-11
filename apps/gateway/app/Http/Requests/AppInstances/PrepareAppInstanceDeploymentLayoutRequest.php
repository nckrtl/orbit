<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\PrepareAppInstanceDeploymentLayoutData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class PrepareAppInstanceDeploymentLayoutRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sqlite_source_path' => [
                'sometimes',
                'filled',
                'string',
                'max:4096',
                'not_regex:/[\x00-\x1F\x7F]/',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['sqlite_source_path']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): PrepareAppInstanceDeploymentLayoutData
    {
        /** @var array{sqlite_source_path?: string} $validated */
        $validated = $this->validated();

        return new PrepareAppInstanceDeploymentLayoutData($validated['sqlite_source_path'] ?? null);
    }
}
