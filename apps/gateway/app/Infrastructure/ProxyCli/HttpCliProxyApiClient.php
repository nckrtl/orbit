<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliManagementClient;
use App\Domain\ProxyCli\ProxyCliUsageResponse;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

final readonly class HttpCliProxyApiClient implements ProxyCliManagementClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function authFiles(string $baseUrl, #[SensitiveParameter] string $managementKey): array
    {
        $response = $this->request($baseUrl, $managementKey)->get($this->url($baseUrl, '/auth-files'));
        $this->guard($response);
        $payload = $response->json();
        $files = is_array($payload['files'] ?? null) ? $payload['files'] : (is_array($payload) ? $payload : []);

        return array_values(array_filter($files, static fn (mixed $file): bool => is_array($file)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function apiCall(
        string $baseUrl,
        #[SensitiveParameter] string $managementKey,
        string $authIndex,
        string $url,
        array $headers = [],
    ): ProxyCliUsageResponse {
        $response = $this->request($baseUrl, $managementKey)->post($this->url($baseUrl, '/api-call'), [
            'auth_index' => $authIndex,
            'method' => 'GET',
            'url' => $url,
            'header' => [
                'Authorization' => 'Bearer $TOKEN$',
                'Accept' => 'application/json',
                ...$headers,
            ],
        ]);
        $this->guard($response);
        $payload = $response->json();
        $status = is_int($payload['status_code'] ?? null) ? $payload['status_code'] : $response->status();
        $body = $payload['body'] ?? null;

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : $body;
        }

        $retryAfter = $this->retryAfter($payload['header'] ?? $response->header('Retry-After'));

        return new ProxyCliUsageResponse(
            $status,
            $body,
            is_array($payload['header'] ?? null) ? $this->stringHeaders($payload['header']) : [],
            $retryAfter,
        );
    }

    public function setDisabled(
        string $baseUrl,
        #[SensitiveParameter] string $managementKey,
        string $account,
        bool $disabled,
    ): void {
        $response = $this->request($baseUrl, $managementKey)->patch($this->url($baseUrl, '/auth-files/status'), [
            'name' => $account,
            'disabled' => $disabled,
        ]);
        $this->guard($response);
    }

    private function request(string $baseUrl, #[SensitiveParameter] string $managementKey): PendingRequest
    {
        return Http::timeout(10)
            ->acceptJson()
            ->withToken($managementKey)
            ->baseUrl(rtrim($baseUrl, '/'));
    }

    private function url(string $baseUrl, string $path): string
    {
        $origin = preg_replace('#/v0/management$#', '', rtrim($baseUrl, '/')) ?? rtrim($baseUrl, '/');

        return $origin.'/v0/management'.$path;
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ResourceOperationException(
            'proxycli.upstream_failed',
            'CLIProxyAPI Management API refused the request.',
            $response->status() === 401 || $response->status() === 403 ? 502 : 502,
        );
    }

    private function retryAfter(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value['Retry-After'] ?? $value['retry-after'] ?? null;
            $value = is_array($value) ? ($value[0] ?? null) : $value;
        }

        if (is_numeric($value)) {
            return max(1, (int) $value);
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }
}
