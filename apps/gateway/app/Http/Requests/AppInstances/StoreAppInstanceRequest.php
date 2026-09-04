<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\Routes\RouteHostname;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreAppInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'node_id' => ['required', 'integer', Rule::exists(new Node()->getTable(), 'id')],
            'name' => [
                'required',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/',
            ],
            'root' => ['sometimes', 'string', 'max:255'],
            'hostname' => ['sometimes', 'string', 'max:253'],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['app_id', 'node_id', 'name', 'root', 'hostname'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $root = $this->input('root');

            if (is_string($root) && ! RelativeWebRoot::isValid($root)) {
                $validator->errors()->add('root', 'The root must be a normalized relative web path.');
            }

            $hostname = $this->input('hostname');

            if (is_string($hostname) && ! RouteHostname::isValid($hostname)) {
                $validator->errors()->add('hostname', 'The Route hostname is invalid.');
            }
        }];
    }

    public function payload(): CreateAppInstanceData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new CreateAppInstanceData(
            appId: (int) $validated['app_id'],
            nodeId: (int) $validated['node_id'],
            name: (string) $validated['name'],
            root: is_string($validated['root'] ?? null) ? $validated['root'] : null,
            hostname: is_string($validated['hostname'] ?? null)
                ? RouteHostname::normalize($validated['hostname'])
                : null,
        );
    }
}
