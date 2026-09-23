<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Pi server on a Node. Every failure becomes an AgentDriverException that names the
 * server's error code, never its credentials.
 */
final readonly class PiClient
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 15.0;

    private const float STREAM_SECONDS = 20.0;

    private const float STREAM_READ_TIMEOUT = 5.0;

    public function __construct(private PiConnection $connection) {}

    /** @param array{id: string, cwd: string, model: string, thinkingLevel: string, appendSystemPrompt: string|null} $session */
    public function create(Node $node, array $session): void
    {
        $this->ensure($this->call(fn (): Response => $this->request($node)->post($this->url($node, 'sessions'), $session)));
    }

    /** Starts a turn. Returns true when the server had already accepted this key. */
    public function send(Node $node, string $sessionId, string $key, string $text): bool
    {
        $response = $this->ensure($this->call(fn (): Response => $this->request($node)->post(
            $this->url($node, 'sessions', $sessionId, 'messages'),
            ['key' => $key, 'text' => $text],
        )));

        return $response->json('duplicate') === true;
    }

    public function interrupt(Node $node, string $sessionId): void
    {
        $this->ensure($this->call(fn (): Response => $this->request($node)->post($this->url($node, 'sessions', $sessionId, 'interrupt'))));
    }

    /** @return array<string, mixed> */
    public function snapshot(Node $node, string $sessionId): array
    {
        $payload = $this->ensure($this->call(fn (): Response => $this->request($node)->get($this->url($node, 'sessions', $sessionId))))->json();
        if (! is_array($payload) || ($payload['kind'] ?? null) !== 'snapshot') {
            throw new AgentDriverException('The Pi server returned an invalid snapshot.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Streams one bounded connection. Without a cursor it starts with a snapshot. With a cursor
     * from the server's current run it starts with a `resumed` event and the events after the
     * cursor; the server sends a snapshot instead when it cannot resume. Then entry and state
     * events follow. Yields a heartbeat whenever no event arrives within the read timeout.
     *
     * @param  array{run: string, sequence: int}|null  $after
     * @return iterable<array<string, mixed>>
     */
    public function stream(Node $node, string $sessionId, ?array $after = null): iterable
    {
        // HTTP/1.0 keeps the response unchunked. PHP's dechunk filter holds small NDJSON lines
        // until 8 KiB arrive, which would stall every event behind the next heartbeats.
        $response = $this->ensure($this->call(fn (): Response => $this->request($node)
            ->timeout(0)
            ->withOptions(['stream' => true, 'read_timeout' => self::STREAM_READ_TIMEOUT, 'version' => '1.0'])
            ->accept('application/x-ndjson')
            ->get($this->url($node, 'sessions', $sessionId, 'stream'), $after === null ? [] : ['run' => $after['run'], 'after' => $after['sequence']])));
        $body = $response->toPsrResponse()->getBody();

        try {
            yield from new PiEventLines(self::STREAM_SECONDS)->read($body);
        } finally {
            $body->close();
        }
    }

    /** @param callable(): Response $send */
    private function call(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new AgentDriverException('The Pi server is unavailable.');
        }
    }

    private function ensure(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        throw new AgentDriverException(is_string($code) && is_string($message)
            ? 'The Pi server refused the request ('.$code.'): '.$message
            : 'The Pi server failed with HTTP '.$response->status().'.');
    }

    private function request(Node $node): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->withToken($this->connection->token($node));
    }

    private function url(Node $node, string ...$segments): string
    {
        return $this->connection->baseUrl($node).'/'.implode('/', array_map(rawurlencode(...), $segments));
    }
}
