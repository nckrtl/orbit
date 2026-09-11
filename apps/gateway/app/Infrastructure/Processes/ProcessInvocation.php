<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use Closure;

final readonly class ProcessInvocation
{
    /**
     * @param  non-empty-list<string>  $arguments
     * @param  (Closure(ProcessOutput): void)|null  $output
     * @param  (Closure(): bool)|null  $cancelled
     */
    public function __construct(
        public array $arguments,
        public float $timeout = 900.0,
        public ?string $input = null,
        public ?ProtectedInput $protectedInput = null,
        public ?int $maxOutputBytes = null,
        public ?Closure $output = null,
        public ?Closure $cancelled = null,
    ) {}
}
