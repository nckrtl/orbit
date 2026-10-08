<?php

declare(strict_types=1);

namespace App\Http\Requests\GatewayReleases;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SmokeGatewayReleaseRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'commit' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{7,40}\z/D'],
            'since' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['commit', 'since']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function commit(): ?string
    {
        $commit = $this->validated('commit');

        return is_string($commit) ? $commit : null;
    }

    public function since(): ?string
    {
        $since = $this->validated('since');

        return is_string($since) ? $since : null;
    }
}
