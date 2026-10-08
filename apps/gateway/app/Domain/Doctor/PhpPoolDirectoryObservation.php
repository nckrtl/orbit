<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class PhpPoolDirectoryObservation
{
    public function __construct(
        public string $pool,
        public string $version,
        public string $directory,
        /** The pool is in the live `orbit-scopes.conf`; otherwise only stored state renders it. */
        public bool $installed,
    ) {}
}
