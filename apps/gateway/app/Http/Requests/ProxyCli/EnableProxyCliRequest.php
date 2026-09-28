<?php

declare(strict_types=1);

namespace App\Http\Requests\ProxyCli;

use App\Data\ProxyCli\EnableProxyCliData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class EnableProxyCliRequest extends FormRequest
{
    /** @return array<string, list<string|Closure|Exists>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', $this->strictInteger(...), 'min:1', Rule::exists(Node::class, 'id')],
            'cache_connection' => ['required', 'string', 'max:64'],
            'cliproxy_url' => ['required', 'string', 'url:http,https', 'max:512'],
            'cliproxy_management_key' => ['required', 'string', 'min:1', 'max:512'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'node_id',
                'cache_connection',
                'cliproxy_url',
                'cliproxy_management_key',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): EnableProxyCliData
    {
        $validated = $this->validated();

        return new EnableProxyCliData(
            ValidatedData::integer($validated['node_id'] ?? null),
            ValidatedData::string($validated['cache_connection'] ?? null),
            ValidatedData::string($validated['cliproxy_url'] ?? null),
            ValidatedData::string($validated['cliproxy_management_key'] ?? null),
        );
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
