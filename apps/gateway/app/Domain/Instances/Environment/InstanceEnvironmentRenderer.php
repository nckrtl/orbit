<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class InstanceEnvironmentRenderer
{
    private const string DomainPlaceholder = '{{instance.domain}}';

    private const string EnvironmentPlaceholder = '{{instance.environment}}';

    /** @param array<string, string> $values */
    public function render(
        InstanceEnvironmentContext $context,
        #[\SensitiveParameter]
        array $values,
    ): string {
        if (! in_array($context->environment, ['development', 'production'], true)) {
            $this->referenceUnavailable();
        }

        if (
            $context->routeDomain === ''
            || ($context->routeDomain === null && array_any(
                $values,
                static fn (string $value): bool => str_contains($value, self::DomainPlaceholder),
            ))
        ) {
            $this->referenceUnavailable();
        }

        ksort($values, SORT_STRING);
        $rendered = '';

        foreach ($values as $key => $value) {
            $resolved = str_replace(
                [self::DomainPlaceholder, self::EnvironmentPlaceholder],
                [$context->routeDomain ?? '', $context->environment],
                $value,
            );

            if (str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
                $this->referenceUnavailable();
            }

            $rendered .= $key.'='.self::quote($resolved)."\n";

            if (strlen($rendered) > InstanceEnvironmentValidator::MaximumFileBytes) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete Instance environment configuration is invalid.',
                    details: [
                        'key' => $key,
                        'rule' => InstanceEnvironmentValidator::RuleFileSize,
                    ],
                );
            }
        }

        return $rendered;
    }

    /** Quote one value as a double-quoted dotenv value. */
    public static function quote(#[\SensitiveParameter] string $value): string
    {
        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
        ]).'"';
    }

    private function referenceUnavailable(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.reference_unavailable',
            message: 'The stored Instance environment configuration has an unavailable reference.',
            status: 409,
        );
    }
}
