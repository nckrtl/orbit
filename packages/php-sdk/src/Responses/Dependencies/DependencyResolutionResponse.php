<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencyResolutionResponse
{
    public function __construct(
        public string $id,
        public string $ecosystem,
        public string $name,
        public string $version,
        public bool $regular,
        public bool $development,
        public ?string $sourceReference,
        public ?string $integrity,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ecosystem' => $this->ecosystem,
            'name' => $this->name,
            'version' => $this->version,
            'regular' => $this->regular,
            'development' => $this->development,
            'source_reference' => $this->sourceReference,
            'integrity' => $this->integrity,
        ];
    }
}
