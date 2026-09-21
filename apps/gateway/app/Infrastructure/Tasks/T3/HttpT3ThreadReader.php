<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

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
            $response = $this->request($node)->get(new T3Connection()->baseUrl($node).'/api/orchestration/threads/'.rawurlencode($threadId));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    private function request(Node $node): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson();

        $token = new T3Connection()->credentials($node)['token'];

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
