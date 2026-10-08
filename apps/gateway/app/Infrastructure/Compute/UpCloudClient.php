<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class UpCloudClient
{
    public const string Api = 'https://api.upcloud.com/1.3';

    public function __construct(private UpCloudToken $token) {}

    public function credentialFingerprint(): string
    {
        return hash('sha256', $this->token->read());
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, string $credentialFingerprint, array $data = [], bool $allowMissing = false): ?array
    {
        $token = $this->token->read();
        if (! hash_equals($credentialFingerprint, hash('sha256', $token))) {
            throw new ComputeException('compute.credential_changed', 'The UpCloud credential changed; confirm provider ownership before recovery.');
        }
        try {
            $response = Http::connectTimeout(3)->timeout(15)->acceptJson()->asJson()
                ->withToken($token)->withOptions(['allow_redirects' => false])
                ->send($method, self::Api.'/'.$path, $data === [] ? [] : ['json' => $data]);
        } catch (Throwable) {
            throw new ComputeException('compute.provider_unavailable', 'The UpCloud request did not return a result.');
        }
        if ($allowMissing && $response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new ComputeException('compute.provider_failed', 'UpCloud refused the request (HTTP '.$response->status().').');
        }
        if ($response->body() === '') {
            return [];
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid response.');
        }

        foreach (array_keys($json) as $key) {
            if (! is_string($key)) {
                throw new ComputeException('compute.provider_invalid_response', 'UpCloud returned an invalid response.');
            }
        }
        /** @var array<string, mixed> $json */

        return $json;
    }
}
