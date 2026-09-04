<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RoutePublication;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class UpdateRouteRequest extends FormRequest
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
            'hostname' => ['sometimes', 'required', 'string', 'max:253'],
            'publication' => ['sometimes', 'required', Rule::enum(RoutePublication::class)],
        ];
    }

    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['hostname', 'publication']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('hostname') && ! $this->exists('publication')) {
                $validator->errors()->add('body', 'Provide at least one Route update.');
            }

            $hostname = $this->input('hostname');

            if (is_string($hostname) && ! RouteHostname::isValid($hostname)) {
                $validator->errors()->add('hostname', 'The Route hostname is invalid.');
            }
        }];
    }

    public function payload(): UpdateRouteData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new UpdateRouteData(
            hostnameProvided: array_key_exists('hostname', $validated),
            hostname: is_string($validated['hostname'] ?? null) ? $validated['hostname'] : null,
            publicationProvided: array_key_exists('publication', $validated),
            publication: is_string($validated['publication'] ?? null)
                ? RoutePublication::from($validated['publication'])
                : null,
        );
    }
}
