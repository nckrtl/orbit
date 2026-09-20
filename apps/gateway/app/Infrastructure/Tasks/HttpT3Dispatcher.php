<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3DispatchException;
use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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

        $threadId = $this->string($command['threadId'] ?? $command['thread_id'] ?? null) ?? '';
        $payload = $command;
        $payload['headers'] = [];

        try {
            $response = $this->request()->post($this->url($host), $payload);
        } catch (ConnectionException) {
            throw new T3DispatchException;
        }

        if (! $response->successful()) {
            throw new T3DispatchException;
        }

        $sequence = $response->json('sequence');
        $returnedThreadId = $this->string($response->json('threadId') ?? $response->json('thread_id'));

        if (! is_int($sequence)) {
            throw new T3DispatchException;
        }

        $resolvedThreadId = $returnedThreadId ?? $threadId;

        if ($resolvedThreadId === '') {
            throw new T3DispatchException;
        }

        return [
            'sequence' => $sequence,
            'thread_id' => $resolvedThreadId,
        ];
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

    private function url(string $host): string
    {
        $port = (int) config('orbit.t3.port', 3773);

        if ($port < 1 || $port > 65535) {
            $port = 3773;
        }

        return 'http://'.$host.':'.$port.'/api/orchestration/dispatch';
    }

    private function string(#[SensitiveParameter] mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
