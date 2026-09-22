<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class AppInstanceEnvironmentRenderer
{
    private const string DomainPlaceholder = '{{app_instance.domain}}';

    private const string EnvironmentPlaceholder = '{{app_instance.environment}}';

    /** @param array<string, string> $values */
    public function render(
        AppInstanceEnvironmentContext $context,
        #[\SensitiveParameter]
        array $values,
    ): string {
        if (! in_array($context->environment, ['development', 'production'], true)) {
            $this->referenceUnavailable();
        }

        ksort($values, SORT_STRING);
        $rendered = '';

        foreach ($values as $key => $value) {
            if (str_contains($value, self::DomainPlaceholder) && ($context->routeDomain === null || $context->routeDomain === '')) {
                $this->referenceUnavailable();
            }

            $resolved = str_replace(
                [self::DomainPlaceholder, self::EnvironmentPlaceholder],
                [$context->routeDomain ?? '', $context->environment],
                $value,
            );

            if (str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
                $this->referenceUnavailable();
            }

            $quoted = strtr($resolved, [
                '\\' => '\\\\',
                '"' => '\\"',
                '$' => '\\$',
                "\n" => '\\n',
                "\r" => '\\r',
            ]);
            $rendered .= "{$key}=\"{$quoted}\"\n";

            if (strlen($rendered) > AppInstanceEnvironmentValidator::MaximumFileBytes) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete AppInstance environment configuration is invalid.',
                    details: [
                        'key' => $key,
                        'rule' => AppInstanceEnvironmentValidator::RuleFileSize,
                    ],
                );
            }
        }

        return $rendered;
    }

    private function referenceUnavailable(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.reference_unavailable',
            message: 'The stored AppInstance environment configuration has an unavailable reference.',
            status: 409,
        );
    }
}
