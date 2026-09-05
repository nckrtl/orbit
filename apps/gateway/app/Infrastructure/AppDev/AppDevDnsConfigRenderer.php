<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppDevDnsConfigRenderer
{
    public function __construct(
        private AppDevSiteRepository $sites,
    ) {}

    public function render(?Node $pendingNode = null, ?Route $pendingRoute = null): string
    {
        $nodes = Node::query()
            ->where(static function (Builder $q) use ($pendingNode): void {
                $q->where('status', LifecycleStatus::Active->value);
                if (
                    $pendingNode instanceof Node
                    && $pendingNode->exists
                    && $pendingNode->status === LifecycleStatus::Provisioning
                ) {
                    $q->orWhere('id', $pendingNode->id);
                }
            })
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where(
                'role',
                RoleName::AppDev->value,
            )->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]))
            ->whereNotNull('wireguard_ip')
            ->whereNotNull('tld')
            ->get()
            ->flatMap(static fn (Node $n): array => [
                "address=/.{$n->tld}/{$n->wireguard_ip}",
                "local=/{$n->tld}/",
            ]);
        $records = $nodes
            ->toBase()
            ->merge($this->sites
                ->all($pendingRoute)
                ->groupBy('hostname')
                ->map(static function ($sites): string {
                    /** @var AppDevSite $site */
                    $site = $sites->first(
                        static fn (AppDevSite $candidate): bool => $candidate->isProxy(),
                    ) ?? $sites->first();

                    return "host-record={$site->hostname},{$site->nodeAddress}";
                }));
        $gateway = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->first();
        if ($gateway instanceof Node) {
            $records->push("host-record=gateway.orbit,{$gateway->wireguard_ip}");

            $metrics = Node::query()
                ->where(static function (Builder $q) use ($pendingNode): void {
                    $q->where('status', LifecycleStatus::Active->value);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static function (Builder $q) use ($pendingNode): void {
                    $q->where('role', RoleName::Metrics->value)
                        ->where(static function (Builder $q) use ($pendingNode): void {
                            $q->where('status', LifecycleStatus::Active->value);

                            if ($pendingNode instanceof Node && $pendingNode->exists) {
                                $q->orWhere(static fn (Builder $q): Builder => $q
                                    ->where('node_id', $pendingNode->id)
                                    ->where('status', LifecycleStatus::Provisioning->value));
                            }
                        });
                })
                ->first();
            if ($metrics instanceof Node) {
                $records->push("host-record=metrics.orbit,{$gateway->wireguard_ip}");
            }
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }
}
