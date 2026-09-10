<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Infrastructure\Processes\ProtectedInput;
use InvalidArgumentException;

final readonly class RemoteCommand
{
    /** @var non-empty-list<string> */
    public array $arguments;

    /** @param list<string> $arguments */
    public function __construct(
        array $arguments,
        public ?string $input = null,
        public ?ProtectedInput $protectedInput = null,
        public ?int $maxOutputBytes = null,
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
