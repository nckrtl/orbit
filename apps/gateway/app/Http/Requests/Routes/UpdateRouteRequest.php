<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Domain\Routes\RouteDomain;
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
        $domain = $this->input('domain');

        if (is_string($domain)) {
            $this->merge(['domain' => RouteDomain::normalize($domain)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'domain' => ['sometimes', 'required', 'string', 'max:253'],
            'publication' => ['sometimes', 'required', Rule::enum(RoutePublication::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['domain', 'publication']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('domain') && ! $this->exists('publication')) {
                $validator->errors()->add('body', 'Provide at least one Route update.');
            }

            $domain = $this->input('domain');

            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }
        }];
    }

    public function payload(): UpdateRouteData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new UpdateRouteData(
            domainProvided: array_key_exists('domain', $validated),
            domain: is_string($validated['domain'] ?? null) ? $validated['domain'] : null,
            publicationProvided: array_key_exists('publication', $validated),
            publication: is_string($validated['publication'] ?? null)
                ? RoutePublication::from($validated['publication'])
                : null,
        );
    }
}
