<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

final readonly class DeploymentEvent
{
    public function __construct(
        public string $step,
        public DeploymentOutputStream $stream,
        #[\SensitiveParameter]
        public string $value,
    ) {}

    /** @return array{step: string, stream: string, value: string} */
    public function __debugInfo(): array
    {
        return [
            'step' => $this->step,
            'stream' => $this->stream->value,
            'value' => '[OUTPUT]',
        ];
    }
}
