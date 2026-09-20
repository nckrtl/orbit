<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Routes\RouteDomain;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class EnableInstanceAnalyticsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'hosts' => ['sometimes', 'array', 'list', 'max:'.AnalyticsTrackingHosts::MAXIMUM],
            'hosts.*' => ['required', 'string', 'max:253'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // A PHP client encodes an empty body as an empty list; both mean the default host.
        $content = trim($this->getContent()) === '[]' ? '' : $this->getContent();

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['hosts']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hosts = $this->hosts();

            foreach ($hosts as $index => $host) {
                $reason = AnalyticsTrackingHosts::reason($host);

                if ($reason !== null) {
                    $validator->errors()->add("hosts.{$index}", $reason);
                }
            }

            if (count($hosts) !== count(array_unique($hosts))) {
                $validator->errors()->add('hosts', 'Name each tracking host once.');
            }
        }];
    }

    /** @return list<string> The normalised hosts, in request order. */
    public function hosts(): array
    {
        $hosts = $this->validationData()['hosts'] ?? [];

        return is_array($hosts)
            ? array_values(array_map(
                static fn (mixed $host): string => RouteDomain::normalize(is_string($host) ? $host : ''),
                $hosts,
            ))
            : [];
    }
}
