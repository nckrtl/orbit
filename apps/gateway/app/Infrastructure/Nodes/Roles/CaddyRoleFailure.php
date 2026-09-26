<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\RoleName;
use LogicException;

/**
 * The failure code and SSH wording of a role's own Caddy steps. Route publication keeps the
 * app-dev code, because the Route publisher requests that build, not the ingress role.
 */
final class CaddyRoleFailure
{
    public static function code(RoleName $role): string
    {
        return match ($role) {
            RoleName::AppDev, RoleName::AppProd, RoleName::Ingress, RoleName::Router => "{$role->value}.caddy_config_failed",
            default => throw new LogicException("Role [{$role->value}] has no Caddy configuration failure code."),
        };
    }

    public static function sshLabel(RoleName $role): string
    {
        return match ($role) {
            RoleName::Ingress => 'Ingress',
            RoleName::Router => 'Router',
            RoleName::AppDev => 'App development',
            RoleName::AppProd => 'App production',
            default => throw new LogicException("Role [{$role->value}] has no Caddy SSH failure label."),
        };
    }
}
