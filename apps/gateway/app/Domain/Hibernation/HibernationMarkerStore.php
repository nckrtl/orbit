<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\Node;

interface HibernationMarkerStore
{
    public function markAwake(Node $node, string $key): void;

    public function markAsleep(Node $node, string $key): void;

    public function markCold(Node $node, string $key): void;

    public function clearCold(Node $node, string $key): void;

    public function lastActivityUnix(Node $node, string $key): ?int;

    public function isAwake(Node $node, string $key): bool;

    public function isCold(Node $node, string $key): bool;
}
