<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class AppInstanceEnvironmentRenderer
{
    private const string HostnamePlaceholder = '{{app_instance.hostname}}';

    private const string EnvironmentPlaceholder = '{{app_instance.environment}}';

    /** @param array<string, string> $values */
    public function render(
        AppInstanceEnvironmentContext $context,
        #[\SensitiveParameter]
        array $values,
    ): string {
        if ($context->routeHostname === '' || ! in_array($context->environment, ['development', 'production'], true)) {
            $this->referenceUnavailable();
        }

        ksort($values, SORT_STRING);
        $rendered = '';

        foreach ($values as $key => $value) {
            $resolved = str_replace(
                [self::HostnamePlaceholder, self::EnvironmentPlaceholder],
                [$context->routeHostname, $context->environment],
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
