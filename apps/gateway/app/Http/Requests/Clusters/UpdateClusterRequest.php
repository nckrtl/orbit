<?php

declare(strict_types=1);

namespace App\Http\Requests\Clusters;

use App\Data\Clusters\UpdateClusterData;
use App\Domain\Clusters\ClusterState;
use App\Domain\Clusters\ClusterTld;
use App\Models\Cluster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClusterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        /** @mago-expect analysis:mixed-assignment Request input is an untyped boundary. */
        $tld = $this->input('tld');

        if (is_string($tld)) {
            $this->merge(['tld' => ClusterTld::normalize($tld)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $cluster = $this->route('cluster');
        $clusterId = $cluster instanceof Cluster ? $cluster->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('clusters', 'name')->ignore($clusterId)],
            'tld' => [
                'sometimes',
                'nullable',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                Rule::unique('clusters', 'tld')->ignore($clusterId),
            ],
            'state' => ['sometimes', Rule::enum(ClusterState::class)],
        ];
    }

    public function payload(): UpdateClusterData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new UpdateClusterData(
            nameProvided: array_key_exists('name', $validated),
            name: is_string($validated['name'] ?? null) ? $validated['name'] : null,
            tldProvided: array_key_exists('tld', $validated),
            tld: is_string($validated['tld'] ?? null) ? $validated['tld'] : null,
            stateProvided: array_key_exists('state', $validated),
            state: is_string($validated['state'] ?? null) ? ClusterState::from($validated['state']) : null,
        );
    }
}
