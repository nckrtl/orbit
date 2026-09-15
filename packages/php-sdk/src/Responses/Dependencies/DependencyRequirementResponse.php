<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencyRequirementResponse
{
    public function __construct(
        public ?string $from,
        public ?string $to,
        public string $name,
        public string $constraint,
        public string $kind,
        public string $scope,
        public bool $optional,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'name' => $this->name,
            'constraint' => $this->constraint,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'optional' => $this->optional,
        ];
    }
}
