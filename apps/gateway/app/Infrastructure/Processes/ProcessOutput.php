<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

final readonly class ProcessOutput
{
    public function __construct(
        public ProcessOutputStream $stream,
        #[\SensitiveParameter]
        public string $value,
    ) {}

    /** @return array{stream: string, value: string} */
    public function __debugInfo(): array
    {
        return ['stream' => $this->stream->value, 'value' => '[OUTPUT]'];
    }
}
