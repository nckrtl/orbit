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

    it('redacts secrets stored under keys that look like settings', function (string $key, string $value): void {
        $instance = new AppInstance;
        $instance->setRelation('environmentValues', new Collection([
            new AppInstanceEnvironmentValue(['env_key' => $key, 'env_value' => $value]),
        ]));
        $redactor = app(LogRedactor::class);

        expect($redactor->valuesFor($instance))->toBe([$value])
            ->and($redactor->redact("posting to {$value} failed", $redactor->valuesFor($instance)))->toBe('posting to [REDACTED] failed');
    })->with([
        'Slack webhook of the log stack' => ['LOG_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/T0FAKE000/B0FAKE000/token0123456789'],
        'Papertrail host' => ['LOG_PAPERTRAIL_URL', 'logs7.papertrailapp.com'],
        'database host' => ['DB_HOST', 'db-private.internal.example'],
        'database URL' => ['DB_URL', 'mysql://app:pass@db.internal/app'],
        'application URL' => ['APP_URL', 'https://staging-secret.example.com'],
        'Redis URL' => ['REDIS_URL', 'redis://:pass@cache.internal:6379'],
        'Pusher app secret' => ['PUSHER_APP_SECRET', 'pusher-secret-value'],
        'a custom driver key' => ['PAYMENT_DRIVER', 'stripe-live-sk-12345'],
        'a custom connection key' => ['LEGACY_CONNECTION', 'user:pass@legacy-host'],
        'a custom store key' => ['SECRETS_STORE', 'vault-token-123456'],
    ]);

    it('applies the same rules to a Docker Process environment', function (): void {
        $process = new Process(['runtime_config' => ['environment' => [
            'APP_ENV' => 'production',
            'QUEUE_CONNECTION' => 'database',
            'TOKEN' => 'abc',
            'API_TOKEN' => 'token-value-123',
            'LOG_SLACK_WEBHOOK_URL' => 'https://hooks.slack.com/services/x',
        ]]]);

        expect(app(LogRedactor::class)->valuesFor($process))->toBe(['https://hooks.slack.com/services/x', 'token-value-123']);
    });
});
