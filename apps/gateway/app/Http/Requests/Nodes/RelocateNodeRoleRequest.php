<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\RoleName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RelocateNodeRoleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(RoleName::class)],
            'force' => ['sometimes', $this->strictBoolean(...)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $input = app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['force'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        $role = $this->route('role');

        if (is_string($role)) {
            $input['role'] = $role;
        }

        return $input;
    }

    public function role(): RoleName
    {
        return RoleName::from((string) $this->validated('role'));
    }

    public function force(): bool
    {
        return $this->validated('force', false) === true;
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
