<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\CreateRouteData;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RoutePublication;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class StoreRouteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $hostname = $this->input('hostname');

        if (is_string($hostname)) {
            $this->merge(['hostname' => RouteHostname::normalize($hostname)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'integer', Rule::exists(new OrbitApp()->getTable(), 'id')],
            'hostname' => ['required', 'string', 'max:253'],
            'publication' => ['required', Rule::enum(RoutePublication::class)],
            'app_instance_id' => ['sometimes', 'integer', Rule::exists(new AppInstance()->getTable(), 'id')],
            'node_id' => ['sometimes', 'integer', Rule::exists(new Node()->getTable(), 'id')],
            'cluster_id' => ['sometimes', 'integer', Rule::exists(new Cluster()->getTable(), 'id')],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(
                $this->getContent(),
                ['app_id', 'hostname', 'publication', 'app_instance_id', 'node_id', 'cluster_id'],
            );
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $hostname = $this->input('hostname');

            if (is_string($hostname) && ! RouteHostname::isValid($hostname)) {
                $validator->errors()->add('hostname', 'The Route hostname is invalid.');
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

        return new CreateRouteData(
            appId: (int) $validated['app_id'],
            hostname: (string) $validated['hostname'],
            publication: RoutePublication::from((string) $validated['publication']),
            appInstanceId: is_int($validated['app_instance_id'] ?? null) ? $validated['app_instance_id'] : null,
            nodeId: is_int($validated['node_id'] ?? null) ? $validated['node_id'] : null,
            clusterId: is_int($validated['cluster_id'] ?? null) ? $validated['cluster_id'] : null,
        );
    }
}
