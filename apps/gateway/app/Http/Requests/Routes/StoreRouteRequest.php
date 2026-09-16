<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\CreateRouteData;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RoutePublication;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
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
            'app_id' => ['required', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'domain' => ['required', 'string', 'max:253'],
            'publication' => ['required', Rule::enum(RoutePublication::class)],
            'app_instance_id' => ['sometimes', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
            'node_id' => ['sometimes', 'integer', Rule::exists(new Node()->getTable(), 'id')],
            'cluster_id' => ['sometimes', 'integer', Rule::exists(new Cluster()->getTable(), 'id')],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                [
                    'app_id',
                    'domain',
                    'publication',
                    'app_instance_id',
                    'node_id',
                    'cluster_id',
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

            $hasTarget = $this->input('app_instance_id') !== null;
            $hasNode = $this->input('node_id') !== null;
            $hasCluster = $this->input('cluster_id') !== null;

            if ($hasTarget && ($hasNode || $hasCluster)) {
                $validator->errors()->add('scope', 'Do not supply Route scope with a target.');
            }

            if (! $hasTarget && $hasNode === $hasCluster) {
                $validator->errors()->add('scope', 'Supply exactly one Node or Cluster scope without a target.');
            }
        }];
    }

    public function payload(): CreateRouteData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        if ($this->isCustomProxy()) {
            return new CreateRouteData(
                domain: (string) $validated['domain'],
                publication: RoutePublication::Private,
                nodeId: (int) $validated['node_id'],
                upstream: is_string($validated['upstream'] ?? null) ? $validated['upstream'] : null,
                processId: isset($validated['process_id']) ? (int) $validated['process_id'] : null,
            );
        }

        return new CreateRouteData(
            domain: (string) $validated['domain'],
            publication: RoutePublication::from((string) $validated['publication']),
            appId: (int) $validated['app_id'],
            appInstanceId: is_int($validated['app_instance_id'] ?? null) ? $validated['app_instance_id'] : null,
            nodeId: is_int($validated['node_id'] ?? null) ? $validated['node_id'] : null,
            clusterId: is_int($validated['cluster_id'] ?? null) ? $validated['cluster_id'] : null,
        );
    }

    private function isCustomProxy(): bool
    {
        return $this->input('upstream') !== null || $this->input('process_id') !== null;
    }

    private function validateCustomProxy(Validator $validator): void
    {
        if ($this->input('app_id') !== null || $this->input('app_instance_id') !== null || $this->input('cluster_id') !== null) {
            $validator->errors()->add('scope', 'A custom proxy Route cannot own an App, App instance, or Cluster scope.');
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
