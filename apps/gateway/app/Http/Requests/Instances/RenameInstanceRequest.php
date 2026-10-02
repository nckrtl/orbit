<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\RenameInstanceData;
use App\Domain\Routes\RouteDomain;
use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class RenameInstanceRequest extends FormRequest
{
    private ?RenameInstanceData $data = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'required', 'string', 'min:1'],
            'domain' => ['sometimes', 'required', 'string', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['branch', 'domain']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
        if ($payload === []) {
            throw ValidationException::withMessages(['body' => ['Provide branch or domain.']]);
        }
        foreach ($payload as $key => $value) {
            if (! is_string($value) || $value === '') {
                throw ValidationException::withMessages([$key => ['The field must be a nonempty string.']]);
            }
        }
        $branch = $payload['branch'] ?? null;
        if ($branch !== null) {
            try {
                GitBranchName::validate($branch);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['branch' => ['The branch is invalid.']]);
            }
        }
        $domain = isset($payload['domain']) ? RouteDomain::validate($payload['domain']) : null;
        $this->data = new RenameInstanceData($branch, $domain);

        return $payload;
    }

    public function payload(): RenameInstanceData
    {
        return $this->data ?? throw new UnexpectedValueException('The rename was not validated.');
    }
}
