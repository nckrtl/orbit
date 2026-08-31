<?php

declare(strict_types=1);

namespace App\Http\Requests\Clusters;

use App\Data\Clusters\CreateClusterData;
use App\Domain\Clusters\ClusterTld;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClusterRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('clusters', 'name')],
            'tld' => [
                'nullable',
                'string',
                'max:63',
                'regex:/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                Rule::unique('clusters', 'tld'),
            ],
        ];
    }

    public function payload(): CreateClusterData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new CreateClusterData(
            name: (string) $validated['name'],
            tld: is_string($validated['tld'] ?? null) ? $validated['tld'] : null,
        );
    }
}
