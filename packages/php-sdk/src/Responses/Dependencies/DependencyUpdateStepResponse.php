<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencyUpdateStepResponse
{
    public function __construct(
        public string $ecosystem,
        public string $status,
        public bool $mayHaveMutated,
        public ?string $errorCode,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ecosystem' => $this->ecosystem,
            'status' => $this->status,
            'may_have_mutated' => $this->mayHaveMutated,
            'error_code' => $this->errorCode,
        ];
    }
}
