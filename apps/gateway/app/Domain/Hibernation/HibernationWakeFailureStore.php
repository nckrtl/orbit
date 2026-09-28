<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

interface HibernationWakeFailureStore
{
    public function remember(int $instanceId, string $message): void;

    public function pull(int $instanceId): ?string;
}
