<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskAgentStream;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class T3TaskAgentStream implements TaskAgentStream
{
    /** @return iterable<array<string, mixed>> */
    public function events(Node $node, string $threadId, ?int $afterSequence): iterable
    {
        $host = $node->wireguard_ip;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('T3 Node address unavailable.');
        }
        $host = str_contains($host, ':') ? '['.$host.']' : $host;
        $base = $host.':'.(int) config('orbit.t3.port', 3773);
        $token = config('orbit.t3.token');
        $headers = is_string($token) && $token !== '' ? ['Authorization' => 'Bearer '.$token] : [];
        $ticket = Http::timeout(5)->withHeaders($headers)->post('http://'.$base.'/api/auth/websocket-ticket');
        $value = $ticket->json('ticket') ?? $ticket->json('wsTicket');
        $suffix = $ticket->successful() && is_string($value) ? '?wsTicket='.rawurlencode($value) : '';
        $socket = new T3WebSocket;
        try {
            $socket->connect('ws://'.$base.'/ws'.$suffix, $headers, 5);
            $id = (string) Str::uuid();
            $payload = ['threadId' => $threadId, 'reasoningMessages' => true, 'requestCompletionMarker' => true];
            if ($afterSequence !== null) {
                $payload['afterSequence'] = $afterSequence;
            }
            $socket->send(['_tag' => 'Request', 'id' => $id, 'tag' => 'orchestration.subscribeThread', 'payload' => $payload, 'headers' => []]);
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline && ! connection_aborted()) {
                $frame = $socket->receive(min(5, max(0.01, $deadline - microtime(true))));
                if ($frame === null) {
                    yield ['kind' => 'heartbeat'];

                    continue;
                }
                if (($frame['requestId'] ?? $frame['id'] ?? null) !== $id) {
                    continue;
                }
                if (($frame['_tag'] ?? null) === 'Exit') {
                    throw new RuntimeException('T3 subscription ended.');
                }
                if (($frame['_tag'] ?? null) !== 'Chunk' || ! is_array($frame['values'] ?? null)) {
                    continue;
                }
                foreach ($frame['values'] as $item) {
                    if (is_array($item)) {
                        /** @var array<string, mixed> $item */
                        yield $item;
                    }
                }
            }
        } finally {
            $socket->close();
        }
    }
}
