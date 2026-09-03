<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\RegisterAppInstanceData;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class RegisterAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app' => ['required', 'string', Rule::exists(new OrbitApp()->getTable(), 'slug')],
            'checkout_path' => ['required', 'string', 'max:4096'],
            'name' => [
                'sometimes',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
            'root' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['app', 'checkout_path', 'name', 'root'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $checkout = $this->input('checkout_path');
            $root = $this->input('root');

            if (is_string($checkout) && ! StoragePath::tryParse($checkout) instanceof StoragePath) {
                $validator->errors()->add('checkout_path', 'The checkout path must be a normalized absolute path.');
            }

            if (is_string($root) && ! RelativeWebRoot::isValid($root)) {
                $validator->errors()->add('root', 'The root must be a normalized relative web path.');
            }
        }];
    }

    public function payload(): RegisterAppInstanceData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new RegisterAppInstanceData(
            appSlug: (string) $validated['app'],
            checkoutPath: (string) $validated['checkout_path'],
            name: is_string($validated['name'] ?? null) ? $validated['name'] : null,
            root: is_string($validated['root'] ?? null) ? $validated['root'] : null,
        );
    }
}
