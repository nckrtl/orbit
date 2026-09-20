<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class InstallGitHubAppRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'regex:/\A[A-Za-z0-9](?:[A-Za-z0-9 ._-]{0,32}[A-Za-z0-9])?\z/D'],
            'owner' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['name', 'owner'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function name(): string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : 'orbit';
    }

    public function owner(): ?string
    {
        $owner = $this->validated('owner');

        return is_string($owner) ? $owner : null;
    }
}
