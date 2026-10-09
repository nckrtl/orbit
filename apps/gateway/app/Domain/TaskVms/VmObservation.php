<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** What the provider saw of one existing VM. The provider validates it before it returns. */
final readonly class VmObservation
{
    public function __construct(public bool $running, public ?string $address) {}
}
