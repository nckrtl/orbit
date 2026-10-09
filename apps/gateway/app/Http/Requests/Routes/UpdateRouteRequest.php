<?php

declare(strict_types=1);

namespace App\Http\Requests\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RoutePublication;
use App\Domain\SourceControl\RelativeWebRoot;
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
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['domain', 'publication', 'web_root']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('domain') && ! $this->exists('publication') && ! $this->exists('web_root')) {
                $validator->errors()->add('body', 'Provide at least one Route update.');
            }

            $domain = $this->input('domain');

            if (is_string($domain) && ! RouteDomain::isValid($domain)) {
                $validator->errors()->add('domain', 'The Route domain is invalid.');
            }

            $webRoot = $this->input('web_root');

            if (is_string($webRoot) && ! RelativeWebRoot::isValid($webRoot)) {
                $validator->errors()->add('web_root', StoreRouteRequest::WebRootMessage);
            }
        }];
    }

    public function payload(): UpdateRouteData
    {
        $validated = $this->validated();

        return new UpdateRouteData(
            domainProvided: array_key_exists('domain', $validated),
            domain: is_string($validated['domain'] ?? null) ? $validated['domain'] : null,
            publicationProvided: array_key_exists('publication', $validated),
            publication: is_string($validated['publication'] ?? null)
                ? RoutePublication::from($validated['publication'])
                : null,
            webRootProvided: array_key_exists('web_root', $validated),
            webRoot: is_string($validated['web_root'] ?? null) ? $validated['web_root'] : null,
        );
    }
}
