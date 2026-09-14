<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\HibernationWakeFailureStore;
use Illuminate\Contracts\Cache\Repository;

final readonly class CacheHibernationWakeFailureStore implements HibernationWakeFailureStore
{
    public function __construct(private Repository $cache) {}

    public function remember(int $appInstanceId, string $message): void
    {
        $this->cache->put($this->key($appInstanceId), $message, 120);
    }

    public function pull(int $appInstanceId): ?string
    {
        $key = $this->key($appInstanceId);
        $message = $this->cache->get($key);
        $this->cache->forget($key);

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function key(int $appInstanceId): string
    {
        return 'hibernation.wake-failed.'.$appInstanceId;
    }
}
