<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Releases\ReleaseAlertWebhook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use SensitiveParameter;
use Throwable;

/**
 * Signs the body like the Coder webhook. The URL, the secret, and the response never leave this class,
 * because a chat webhook URL is itself a credential.
 */
final readonly class HttpReleaseAlertWebhook implements ReleaseAlertWebhook
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 10.0;

    public function send(array $payload): ReleaseAlertStep
    {
        $url = $this->string(config('orbit.releases.alert_webhook_url'));
        $secret = $this->string(config('orbit.releases.alert_webhook_secret'));

        if ($url === null || $secret === null) {
            return ReleaseAlertStep::skipped('not_configured');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return ReleaseAlertStep::failed('error');
        }

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->withHeaders([
                    'X-Orbit-Timestamp' => $timestamp,
                    'X-Orbit-Signature' => 'sha256='.$signature,
                ])
                ->post($url);
        } catch (ConnectionException) {
            return ReleaseAlertStep::failed('unreachable');
        } catch (Throwable) {
            return ReleaseAlertStep::failed('error');
        }

        if ($response->successful()) {
            return ReleaseAlertStep::done();
        }

        return ReleaseAlertStep::failed('rejected', $response->status());
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
