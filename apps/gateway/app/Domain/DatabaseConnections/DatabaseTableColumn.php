<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

final readonly class DatabaseTableColumn
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $primary,
    ) {}

    /** @return array{name: string, type: string, nullable: bool, default: string|null, primary: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'primary' => $this->primary,
        ];
    }
}
