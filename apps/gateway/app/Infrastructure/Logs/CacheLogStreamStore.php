<?php

declare(strict_types=1);

namespace App\Infrastructure\Logs;

use App\Domain\Logs\LogRelayCursor;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamStore;
use App\Infrastructure\AgentView\CacheAgentStateView;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps open live log streams in the agent view's file cache store, which PHP-FPM workers and the
 * agent view subscriber share. Streams are disposable: a lost store only ends the streams, and
 * viewers open new ones. Renewals therefore never write the Gateway's SQLite database.
 *
 * One index entry maps each stream to its Node and lease end. Every change to the index runs under
 * one cache lock, so two workers cannot both pass the per-Node limit.
 */
final readonly class CacheLogStreamStore implements LogStreamStore
{
    private const string INDEX_KEY = 'log-streams.index';

    private const string STREAM_KEY = 'log-streams.stream.';

    private const string LOCK_KEY = 'log-streams.lock';

    private const string CURSOR_KEY = 'log-streams.cursor.';

    /** Seconds an entry outlives its lease, so a store without a subscriber still empties itself. */
    private const int GraceSeconds = 60;

    public function __construct(private Repository $cache) {}

    #[\Override]
    public function open(LogStream $stream): bool
    {
        return $this->locked(function () use ($stream): bool {
            $index = $this->index();
            $now = CacheAgentStateView::now();
            $open = array_filter($index, static fn (array $entry): bool => $entry['node_id'] === $stream->nodeId && $entry['expires_at'] > $now);

            if (count($open) >= self::MaxPerNode) {
                return false;
            }

            $index[$stream->id] = ['node_id' => $stream->nodeId, 'expires_at' => $stream->expiresAt];
            $this->putStream($stream);
            $this->putIndex($index);

            return true;
        });
    }

    #[\Override]
    public function find(string $id): ?LogStream
    {
        if (preg_match(LogStream::ID, $id) !== 1) {
            return null;
        }

        $stored = $this->cache->get(self::STREAM_KEY.$id);
        $stream = is_array($stored) ? LogStream::fromArray($stored) : null;

        return $stream !== null && ! $stream->isExpired(CacheAgentStateView::now()) ? $stream : null;
    }

    #[\Override]
    public function renew(string $id, float $expiresAt): ?LogStream
    {
        return $this->locked(function () use ($id, $expiresAt): ?LogStream {
            $stream = $this->find($id);
            $index = $this->index();

            if ($stream === null || ! isset($index[$id])) {
                return null;
            }

            $renewed = $stream->withExpiry($expiresAt)->activated();
            $index[$id]['expires_at'] = $expiresAt;
            $this->putStream($renewed);
            $this->putIndex($index);

            return $renewed;
        });
    }

    #[\Override]
    public function close(string $id): ?LogStream
    {
        return $this->locked(function () use ($id): ?LogStream {
            $stream = $this->find($id);
            $index = $this->index();
            unset($index[$id]);
            $this->putIndex($index);
            $this->cache->forget(self::STREAM_KEY.$id);
            $this->cache->forget(self::CURSOR_KEY.$id);

            return $stream;
        });
    }

    #[\Override]
    public function all(): array
    {
        $streams = [];

        foreach (array_keys($this->index()) as $id) {
            $stream = $this->find($id);

            if ($stream !== null) {
                $streams[] = $stream;
            }
        }

        return $streams;
    }

    #[\Override]
    public function forNode(int $nodeId): array
    {
        $streams = [];

        foreach ($this->index() as $id => $entry) {
            if ($entry['node_id'] !== $nodeId) {
                continue;
            }

            $stream = $this->find($id);

            if ($stream !== null && $stream->active) {
                $streams[] = $stream;
            }

            if (count($streams) === self::MaxPerNode) {
                break;
            }
        }

        return $streams;
    }

    #[\Override]
    public function sweep(): array
    {
        $now = CacheAgentStateView::now();

        if (array_all($this->index(), static fn (array $entry): bool => $entry['expires_at'] > $now)) {
            return [];
        }

        return $this->locked(function () use ($now): array {
            $index = $this->index();
            $ended = [];

            foreach ($index as $id => $entry) {
                if ($entry['expires_at'] > $now) {
                    continue;
                }

                $stored = $this->cache->get(self::STREAM_KEY.$id);
                $stream = is_array($stored) ? LogStream::fromArray($stored) : null;

                if ($stream !== null) {
                    $ended[] = $stream;
                }

                unset($index[$id]);
                $this->cache->forget(self::STREAM_KEY.$id);
                $this->cache->forget(self::CURSOR_KEY.$id);
            }

            $this->putIndex($index);

            return $ended;
        });
    }

    #[\Override]
    public function cursor(string $id): ?LogRelayCursor
    {
        return preg_match(LogStream::ID, $id) === 1 ? LogRelayCursor::fromArray($this->cache->get(self::CURSOR_KEY.$id)) : null;
    }

    #[\Override]
    public function saveCursor(string $id, LogRelayCursor $cursor): void
    {
        if (preg_match(LogStream::ID, $id) === 1) {
            $this->cache->put(self::CURSOR_KEY.$id, $cursor->toArray(), self::LeaseSeconds + self::GraceSeconds);
        }
    }

    /** @return array<string, array{node_id: int, expires_at: float}> */
    private function index(): array
    {
        $stored = $this->cache->get(self::INDEX_KEY);
        $index = [];

        foreach (is_array($stored) ? $stored : [] as $id => $entry) {
            if (
                is_string($id) && preg_match(LogStream::ID, $id) === 1 && is_array($entry)
                && is_int($entry['node_id'] ?? null) && is_numeric($entry['expires_at'] ?? null)
            ) {
                $index[$id] = ['node_id' => $entry['node_id'], 'expires_at' => (float) $entry['expires_at']];
            }
        }

        return $index;
    }

    /** @param array<string, array{node_id: int, expires_at: float}> $index */
    private function putIndex(array $index): void
    {
        if ($index === []) {
            $this->cache->forget(self::INDEX_KEY);

            return;
        }

        $this->cache->put(self::INDEX_KEY, $index, self::LeaseSeconds + self::GraceSeconds);
    }

    private function putStream(LogStream $stream): void
    {
        $ttl = (int) ceil($stream->expiresAt - CacheAgentStateView::now()) + self::GraceSeconds;
        $this->cache->put(self::STREAM_KEY.$stream->id, $stream->toArray(), max(1, $ttl));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store->lock(self::LOCK_KEY, 5)->block(3, $callback);
    }
}
