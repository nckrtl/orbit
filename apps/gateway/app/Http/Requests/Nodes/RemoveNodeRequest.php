<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class RemoveNodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['sometimes', $this->strictBoolean(...)],
            'offline' => ['sometimes', $this->strictBoolean(...)],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['force', 'offline'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('offline') !== true || $this->input('force') === true) {
                return;
            }

            // Offline removal sheds the node's roles and deletes their
            // instances, workspaces and processes. That blast radius needs
            // consent at the Gateway, exactly as the role route requires it.
            $validator->errors()->add(
                'force',
                'The force field must be true when offline removal is requested.',
            );
        }];
    }

    /** Consent to the blast radius, which offline removal widens. */
    public function force(): bool
    {
        return $this->validated('force', false) === true;
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
