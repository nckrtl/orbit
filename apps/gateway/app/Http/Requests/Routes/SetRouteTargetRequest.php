<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\AppInstance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetRouteTargetRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_instance_id' => ['required', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['app_instance_id']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function appInstanceId(): int
    {
        return (int) $this->validated('app_instance_id');
    }
}
