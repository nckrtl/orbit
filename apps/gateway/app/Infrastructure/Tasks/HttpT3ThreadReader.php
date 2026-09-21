<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\T3ThreadReader;
use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final readonly class HttpT3ThreadReader implements T3ThreadReader
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 10.0;

    public function snapshot(Node $node, string $threadId): ?array
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '' || $threadId === '') {
            return null;
        }

        try {
            $response = $this->request()->get($this->url($host, '/api/orchestration/threads/'.rawurlencode($threadId)));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    private function request(): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson();

        $token = config('orbit.t3.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function url(string $host, string $path): string
    {
        $port = (int) config('orbit.t3.port', 3773);

        if ($port < 1 || $port > 65535) {
            $port = 3773;
        }

        return 'http://'.$host.':'.$port.$path;
    }
}
