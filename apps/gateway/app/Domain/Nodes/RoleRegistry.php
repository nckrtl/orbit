<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class RoleRegistry
{
    /** @return list<RoleName> */
    public function names(): array
    {
        return [
            RoleName::Gateway,
            RoleName::Vpn,
            RoleName::Router,
            RoleName::Ingress,
            RoleName::AppDev,
            RoleName::AppProd,
            RoleName::Metrics,
            RoleName::Database,
            RoleName::WebSocket,
            RoleName::Analytics,
        ];
    }

    public function definition(RoleName $role): RoleDefinition
    {
        return match ($role) {
            RoleName::Gateway => new RoleDefinition(
                name: $role,
                singleton: true,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: true,
                // Ingress is public and the Gateway is private, so they never share a Node (ADR 0157).
                conflicts: [RoleName::AppDev, RoleName::AppProd, RoleName::Database, RoleName::Analytics, RoleName::Ingress],
            ),
            RoleName::Vpn => new RoleDefinition(
                name: $role,
                singleton: true,
                assignableDuringProvisioning: true,
                mutable: false,
                relocatable: false,
                conflicts: [RoleName::Database],
            ),
            RoleName::Router => new RoleDefinition(
                name: $role,
                singleton: false,
                assignableDuringProvisioning: false,
                mutable: false,
                relocatable: false,
            ),
            RoleName::Ingress => new RoleDefinition(
                name: $role,
                singleton: false,
                assignableDuringProvisioning: false,
                mutable: true,
                relocatable: false,
                conflicts: [RoleName::AppDev, RoleName::Database],
            ),
            RoleName::AppDev => new RoleDefinition(
                name: $role,
                singleton: false,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: false,
                conflicts: [RoleName::Gateway, RoleName::AppProd],
            ),
            RoleName::AppProd => new RoleDefinition(
                name: $role,
                singleton: false,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: false,
                conflicts: [RoleName::Gateway, RoleName::AppDev, RoleName::Database],
            ),
            RoleName::Metrics => new RoleDefinition(
                name: $role,
                singleton: true,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: true,
            ),
            RoleName::Database => new RoleDefinition(
                name: $role,
                singleton: false,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: false,
                conflicts: [
                    RoleName::Gateway,
                    RoleName::Vpn,
                    RoleName::Ingress,
                    RoleName::AppProd,
                ],
            ),
            RoleName::WebSocket => new RoleDefinition(
                name: $role,
                singleton: true,
                assignableDuringProvisioning: true,
                mutable: true,
                relocatable: true,
            ),
            RoleName::Analytics => new RoleDefinition(
                name: $role,
                singleton: true,
                assignableDuringProvisioning: false,
                mutable: true,
                // Moving the role means moving its Process and its publication; remove and add it instead.
                relocatable: false,
                conflicts: [RoleName::Gateway],
            ),
        };
    }

    public function conflicts(RoleName $first, RoleName $second): bool
    {
        return
            in_array($second, $this->definition($first)->conflicts, strict: true)
            || in_array($first, $this->definition($second)->conflicts, strict: true);
    }
}
