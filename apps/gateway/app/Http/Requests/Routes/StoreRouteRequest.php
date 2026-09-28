<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\CreateRouteData;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RoutePublication;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreRouteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');

        if (is_string($domain)) {
            $this->merge(['domain' => RouteDomain::normalize($domain)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if ($this->isCustomProxy()) {
            return [
                'domain' => ['required', 'string', 'max:253'],
                'publication' => ['sometimes', Rule::enum(RoutePublication::class)],
                'node_id' => ['required', 'integer', Rule::exists(new Node()->getTable(), 'id')],
                'upstream' => ['sometimes', 'string', 'max:253'],
                'process_id' => ['sometimes', 'integer', Rule::exists(new Process()->getTable(), 'id')],
            ];
        }

        return [
            'domain' => ['required', 'string', 'max:253'],
            'publication' => ['sometimes', Rule::enum(RoutePublication::class)],
            'app_instance_id' => ['required', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                [
                    'domain',
                    'publication',
                    'app_instance_id',
                    'node_id',
                    'upstream',
                    'process_id',
                ],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $domain = $this->input('domain');

            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }

            if ($this->isCustomProxy()) {
                $this->validateCustomProxy($validator);

                return;
            }

            if ($this->exists('node_id')) {
                $validator->errors()->add('node_id', 'An app Route derives its scope from the Instance.');
            }
        }];
    }

    public function payload(): CreateRouteData
    {
        $validated = $this->validated();

        if ($this->isCustomProxy()) {
            return new CreateRouteData(
                domain: $this->string('domain')->toString(),
                publication: RoutePublication::Private,
                nodeId: $this->integer('node_id'),
                upstream: is_string($validated['upstream'] ?? null) ? $validated['upstream'] : null,
                processId: isset($validated['process_id']) ? $this->integer('process_id') : null,
            );
        }

        return new CreateRouteData(
            domain: $this->string('domain')->toString(),
            publication: RoutePublication::from(is_string($validated['publication'] ?? null) ? $validated['publication'] : RoutePublication::Private->value),
            appInstanceId: $this->integer('app_instance_id'),
        );
    }

    private function isCustomProxy(): bool
    {
        return $this->input('upstream') !== null || $this->input('process_id') !== null;
    }

    private function validateCustomProxy(Validator $validator): void
    {
        if ($this->input('app_id') !== null || $this->input('app_instance_id') !== null || $this->input('cluster_id') !== null) {
            $validator->errors()->add('scope', 'A custom proxy Route cannot own an App, Instance, or Cluster scope.');
        }

        $publication = $this->input('publication');

        if ($publication !== null && $publication !== RoutePublication::Private->value) {
            $validator->errors()->add('publication', 'A custom proxy Route is private only.');
        }

        $hasUpstream = is_string($this->input('upstream')) && $this->input('upstream') !== '';
        $hasProcess = $this->input('process_id') !== null;

        if ($hasUpstream === $hasProcess) {
            $validator->errors()->add('upstream', 'Supply exactly one custom proxy upstream or Process.');
        }
    }
}
