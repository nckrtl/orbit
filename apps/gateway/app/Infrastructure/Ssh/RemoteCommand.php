<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProtectedInput;
use Closure;
use InvalidArgumentException;

final readonly class RemoteCommand
{
    /** @var non-empty-list<string> */
    public array $arguments;

    /**
     * @param  list<string>  $arguments
     * @param  (Closure(ProcessOutput): void)|null  $output
     * @param  (Closure(): bool)|null  $cancelled
     */
    public function __construct(
        array $arguments,
        public ?string $input = null,
        public ?ProtectedInput $protectedInput = null,
        public ?int $maxOutputBytes = null,
        public ?Closure $output = null,
        public ?Closure $cancelled = null,
        public ?float $timeout = null,
    ) {
        if ($arguments === []) {
            throw new InvalidArgumentException('A remote command needs at least one argument.');
        }

        $this->arguments = $arguments;
    }

    public function shellCommand(): string
    {
        return implode(' ', array_map(escapeshellarg(...), $this->arguments));
    }
}
