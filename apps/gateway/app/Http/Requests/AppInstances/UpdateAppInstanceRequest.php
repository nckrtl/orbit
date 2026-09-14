<?php

declare(strict_types=1);

namespace App\Http\Requests\AppInstances;

use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class UpdateAppInstanceRequest extends FormRequest
{
    private ?string $branch = null;

    /** @return array{} */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['branch']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        if (! is_string($payload['branch'] ?? null)) {
            throw ValidationException::withMessages(['body' => ['The branch field must be a string.']]);
        }

        try {
            GitBranchName::validate($payload['branch']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['body' => ['The branch is invalid.']]);
        }

        $this->branch = $payload['branch'];

        return ['branch' => true];
    }

    public function branch(): string
    {
        if (! is_string($this->branch)) {
            throw new UnexpectedValueException('The AppInstance update was not validated.');
        }

        return $this->branch;
    }
}
