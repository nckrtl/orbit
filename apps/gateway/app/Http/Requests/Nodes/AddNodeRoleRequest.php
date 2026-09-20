<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Nodes\RoleName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AddNodeRoleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(RoleName::class)],
            'converge_existing' => ['sometimes', 'boolean', $this->strictBoolean(...)],
            'postgres_process_id' => $this->analyticsProcessRules(),
            'clickhouse_process_id' => $this->analyticsProcessRules(),
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['role', 'converge_existing', 'postgres_process_id', 'clickhouse_process_id'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function role(): RoleName
    {
        return RoleName::from((string) $this->validated('role'));
    }

    public function convergeExisting(): bool
    {
        return $this->validated('converge_existing', false) === true;
    }

    /** The storage Processes of the analytics role, or null for every other role. */
    public function analyticsSettings(): ?AnalyticsRoleSettings
    {
        if ($this->role() !== RoleName::Analytics) {
            return null;
        }

        return new AnalyticsRoleSettings(
            postgresProcessId: $this->integer('postgres_process_id'),
            clickhouseProcessId: $this->integer('clickhouse_process_id'),
        );
    }

    /** @return list<mixed> */
    private function analyticsProcessRules(): array
    {
        return [
            'required_if:role,'.RoleName::Analytics->value,
            'prohibited_unless:role,'.RoleName::Analytics->value,
            $this->strictPositiveInteger(...),
        ];
    }

    private function strictPositiveInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) || $value < 1) {
            $fail("The {$attribute} field must be a positive integer.");
        }
    }

    private function strictBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail("The {$attribute} field must be true or false.");
        }
    }
}
