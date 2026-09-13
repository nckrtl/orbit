<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Domain\Shared\ResourceOperationException;

final readonly class AppInstanceEnvironmentValidator
{
    public const int MaximumFileBytes = 1_048_576;

    public const int MaximumKeys = 1_024;

    public const int MaximumValueBytes = 65_536;

    public const string RuleKey = 'key';

    public const string RuleValue = 'value';

    public const string RulePlaceholder = 'placeholder';

    public const string RuleKeyCount = 'key_count';

    public const string RuleFileSize = 'file_size';

    public const string RuleLaravelAppUrl = 'laravel_app_url';

    private const string HostnamePlaceholder = '{{app_instance.hostname}}';

    private const string EnvironmentPlaceholder = '{{app_instance.environment}}';

    /** @param array<string, string> $values */
    public function validate(#[\SensitiveParameter] array $values): void
    {
        if (count($values) > self::MaximumKeys) {
            $this->fail($this->details(self::RuleKeyCount));
        }

        $generatedBytes = 0;

        foreach ($values as $key => $value) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D', $key) !== 1) {
                $this->fail($this->details(self::RuleKey, $key));
            }

            if (
                ! mb_check_encoding($value, 'UTF-8')
                || strlen($value) > self::MaximumValueBytes
                || str_contains($value, "\0")
            ) {
                $this->fail($this->details(self::RuleValue, $key));
            }

            $expanded = str_replace(
                [self::HostnamePlaceholder, self::EnvironmentPlaceholder],
                [str_repeat('h', 253), 'development'],
                $value,
            );

            if (str_contains($expanded, '{{') || str_contains($expanded, '}}')) {
                $this->fail($this->details(
                    self::RulePlaceholder,
                    $key,
                    $this->namedPlaceholder($expanded),
                ));
            }

            // A future renderer can quote every value and escape every byte.
            $generatedBytes += strlen($key) + 4 + (2 * strlen($expanded));

            if ($generatedBytes > self::MaximumFileBytes) {
                $this->fail($this->details(self::RuleFileSize, $key));
            }
        }
    }

    /**
     * @param  array<string, string>  $details
     */
    private function fail(array $details): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.configuration_invalid',
            message: 'The complete AppInstance environment configuration is invalid.',
            details: $details,
        );
    }

    /** @return array<string, string> */
    private function details(string $rule, string $key = '', string $placeholder = ''): array
    {
        $details = ['rule' => $rule];

        if ($key !== '') {
            $details = ['key' => $key, ...$details];
        }

        if ($placeholder !== '') {
            $details['placeholder'] = $placeholder;
        }

        return $details;
    }

    private function namedPlaceholder(#[\SensitiveParameter] string $expanded): string
    {
        if (preg_match('/\{\{([A-Za-z0-9_.]+)\}\}/', $expanded, $matches) !== 1) {
            return '';
        }

        return '{{'.$matches[1].'}}';
    }
}
