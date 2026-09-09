<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class AppInstanceEnvironmentValidator
{
    public const int MaximumFileBytes = 1_048_576;

    public const int MaximumKeys = 1_024;

    public const int MaximumValueBytes = 65_536;

    private const string HostnamePlaceholder = '{{app_instance.hostname}}';

    private const string EnvironmentPlaceholder = '{{app_instance.environment}}';

    /** @param array<string, string> $values */
    public function validate(#[\SensitiveParameter] array $values): void
    {
        if (count($values) > self::MaximumKeys) {
            $this->fail();
        }

        $generatedBytes = 0;

        foreach ($values as $key => $value) {
            if (
                preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D', $key) !== 1
                || ! mb_check_encoding($value, 'UTF-8')
                || strlen($value) > self::MaximumValueBytes
                || str_contains($value, "\0")
            ) {
                $this->fail();
            }

            $expanded = str_replace(
                [self::HostnamePlaceholder, self::EnvironmentPlaceholder],
                [str_repeat('h', 253), 'development'],
                $value,
            );

            if (str_contains($expanded, '{{') || str_contains($expanded, '}}')) {
                $this->fail();
            }

            // A future renderer can quote every value and escape every byte.
            $generatedBytes += strlen($key) + 4 + (2 * strlen($expanded));

            if ($generatedBytes > self::MaximumFileBytes) {
                $this->fail();
            }
        }
    }

    private function fail(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_invalid',
            message: 'The complete AppInstance environment configuration is invalid.',
        );
    }
}
