<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3DispatchException;
use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

final readonly class HttpT3Dispatcher implements T3Dispatcher
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 10.0;

    public function dispatch(Node $node, array $command): array
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw new T3DispatchException('The Node has no WireGuard address.');
        }

        $credentials = $this->credentials($node);

        $threadId = $this->string($command['threadId'] ?? $command['thread_id'] ?? null) ?? '';
        $payload = $command;
        $payload['headers'] = [];

        try {
            $response = $this->request($credentials['token'])->post($this->url($host, $credentials['base_url']), $payload);
        } catch (ConnectionException) {
            throw new T3DispatchException;
        }

        if (! $response->successful()) {
            throw new T3DispatchException(
                existingProjectId: $this->existingProjectId($host, $command, $response, $credentials),
            );
        }

        $sequence = $response->json('sequence');
        $returnedThreadId = $this->string($response->json('threadId') ?? $response->json('thread_id'));

        if (! is_int($sequence)) {
            throw new T3DispatchException;
        }

        return [
            'sequence' => $sequence,
            'thread_id' => $returnedThreadId ?? $threadId,
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     */
    /**
     * @param  array{token: string|null, base_url: string|null}  $credentials
     */
    private function existingProjectId(string $host, array $command, Response $response, array $credentials): ?string
    {
        $existingProjectId = T3DispatchException::existingProjectId($this->errorHaystack($response));

        if ($existingProjectId !== null || ($command['type'] ?? null) !== 'project.create') {
            return $existingProjectId;
        }

        $workspaceRoot = $this->string($command['workspaceRoot'] ?? $command['workspace_root'] ?? null);

        return $workspaceRoot === null ? null : $this->existingProjectIdFromSnapshot($host, $workspaceRoot, $credentials);
    }

    private function errorHaystack(Response $response): string
    {
        $parts = [(string) $response->body()];

        foreach ($response->headers() as $name => $values) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && $value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array{token: string|null, base_url: string|null}  $credentials
     */
    private function existingProjectIdFromSnapshot(string $host, string $workspaceRoot, array $credentials): ?string
    {
        try {
            $response = $this->request($credentials['token'])->get($this->url($host, $credentials['base_url'], '/api/orchestration/snapshot'));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $projects = $response->json('projects');

        if (! is_array($projects)) {
            return null;
        }

        $wanted = $this->normalizedWorkspaceRoot($workspaceRoot);

        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }

            $deletedAt = $project['deletedAt'] ?? $project['deleted_at'] ?? null;

            if ($deletedAt !== null && $deletedAt !== '') {
                continue;
            }

            $root = $project['workspaceRoot'] ?? $project['workspace_root'] ?? null;
            $id = $project['id'] ?? $project['projectId'] ?? $project['project_id'] ?? null;

            if (! is_string($root) || ! is_string($id) || $this->normalizedWorkspaceRoot($root) !== $wanted) {
                continue;
            }

            $projectId = T3DispatchException::projectId($id);

            if ($projectId !== null) {
                return $projectId;
            }
        }

        return null;
    }

    private function normalizedWorkspaceRoot(string $workspaceRoot): string
    {
        $normalized = trim($workspaceRoot);

        if ($normalized === '/' || $normalized === '') {
            return $normalized;
        }

        return rtrim($normalized, '/');
    }

    /**
     * @return array{token: string|null, base_url: string|null}
     */
    private function credentials(Node $node): array
    {
        $settings = $node->settings;
        $t3 = is_array($settings) && array_key_exists('t3', $settings) ? $settings['t3'] : null;

        if ($t3 !== null) {
            $token = is_array($t3) ? $this->string($t3['token'] ?? null) : null;

            if ($token === null) {
                throw new T3DispatchException('The Node has no T3 token configured.');
            }

            return [
                'token' => $token,
                'base_url' => is_array($t3) ? $this->string($t3['url'] ?? $t3['base_url'] ?? null) : null,
            ];
        }

        return [
            'token' => $this->string(config('orbit.t3.token')),
            'base_url' => null,
        ];
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson();

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function url(string $host, ?string $baseUrl = null, string $path = '/api/orchestration/dispatch'): string
    {
        if ($baseUrl !== null) {
            return rtrim($baseUrl, '/').$path;
        }

        $port = (int) config('orbit.t3.port', 3773);

        if ($port < 1 || $port > 65535) {
            $port = 3773;
        }

        return 'http://'.$host.':'.$port.$path;
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
