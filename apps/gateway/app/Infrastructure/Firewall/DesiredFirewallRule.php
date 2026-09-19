<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

final readonly class DesiredFirewallRule
{
    public function __construct(
        public string $name,
        public ?string $role,
        public UfwRuleShape $shape,
    ) {}

    /**
     * @return array{name: string, role: ?string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string}
     */
    public function toListRow(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role,
            'action' => $this->shape->action,
            'source' => $this->shape->source,
            'destination' => $this->shape->destination,
            'port' => $this->shape->port,
            'protocol' => $this->shape->protocol,
            'interface' => $this->shape->inInterface,
        ];
    }
}
