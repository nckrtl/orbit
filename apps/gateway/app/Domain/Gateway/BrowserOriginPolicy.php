<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use App\Models\Route;

/**
 * Names the browser origins that may call the Gateway API: the Gateway's own site and every
 * active App Route with private publication ([ADR 0125](/decisions/0125-limit-browser-api-calls-to-orbit-origins)).
 */
final readonly class BrowserOriginPolicy
{
    public function __construct(private VpnSettings $vpnSettings) {}

    public function allows(string $origin): bool
    {
        $host = $this->httpsHost($origin);

        if ($host === null) {
            return false;
        }

        if ($host === $this->gatewayHost()) {
            return true;
        }

        return Route::query()
            ->where('kind', RouteKind::App)
            ->where('status', RouteStatus::Active)
            ->where('publication', RoutePublication::Private)
            ->where('domain', $host)
            ->exists();
    }

    /** The lowercase host of an `https://host[:443]` origin, or null for anything else. */
    private function httpsHost(string $origin): ?string
    {
        if (preg_match('#^https://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::443)?\z#', strtolower($origin), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function gatewayHost(): ?string
    {
        $name = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query
                ->where('role', RoleName::Gateway)
                ->where('status', LifecycleStatus::Active))
            ->value('name');

        return is_string($name) ? strtolower("{$name}.{$this->vpnSettings->domain()}") : null;
    }
}
