<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

/** What the running `orbit` is, and the file self-update would replace. */
final readonly class RunningBinary
{
    public function __construct(
        public RunningBinaryKind $kind,
        public ?string $path,
    ) {}
}
