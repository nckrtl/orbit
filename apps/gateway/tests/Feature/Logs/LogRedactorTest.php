<?php

declare(strict_types=1);

use App\Domain\Logs\LogRedactor;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Process;
use Illuminate\Database\Eloquent\Collection;

describe('the stored environment values a log redacts', function (): void {
    it('skips the values of setting keys and values shorter than eight characters on an Instance', function (): void {
        $instance = new AppInstance;
        $instance->setRelation('environmentValues', new Collection(array_map(
            static fn (array $pair): AppInstanceEnvironmentValue => new AppInstanceEnvironmentValue(['env_key' => $pair[0], 'env_value' => $pair[1]]),
            [
                ['APP_ENV', 'production'],
                ['APP_URL', 'https://shop.example.com'],
                ['LOG_CHANNEL', 'stack-daily'],
                ['DB_CONNECTION', 'sqlite-main'],
                ['CACHE_STORE', 'database'],
                ['SESSION_DRIVER', 'database'],
                ['STRIPE_SECRET', 'sk_live_abcdef123'],
                ['DB_PASSWORD', 'short'],
                ['MAIL_PASSWORD', 'mail-password-1'],
            ],
        )));
        $redactor = app(LogRedactor::class);
        $values = $redactor->valuesFor($instance);

        expect($values)->toBe(['sk_live_abcdef123', 'mail-password-1'])
            ->and($redactor->redact('[2026-09-25] production.INFO: charged with sk_live_abcdef123', $values))
            ->toBe('[2026-09-25] production.INFO: charged with [REDACTED]');
    });

    it('applies the same rules to a Docker Process environment', function (): void {
        $process = new Process(['runtime_config' => ['environment' => [
            'APP_ENV' => 'production',
            'QUEUE_CONNECTION' => 'database',
            'TOKEN' => 'abc',
            'API_TOKEN' => 'token-value-123',
        ]]]);

        expect(app(LogRedactor::class)->valuesFor($process))->toBe(['token-value-123']);
    });
});
