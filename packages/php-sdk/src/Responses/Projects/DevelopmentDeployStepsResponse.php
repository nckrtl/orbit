<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

final readonly class DevelopmentDeployStepsResponse
{
    /** @param list<DevelopmentDeployStepResponse> $steps */
    public function __construct(
        public array $steps,
        public string $requestId,
    ) {}

    /** @return array{steps: list<array{name: string, command: string, timeout_seconds: int, required: bool}>, request_id: string} */
    public function toArray(): array
    {
        return [
            'steps' => array_map(
                static fn (DevelopmentDeployStepResponse $step): array => $step->toArray(),
                $this->steps,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
