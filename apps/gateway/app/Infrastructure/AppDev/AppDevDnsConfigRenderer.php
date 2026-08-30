<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

final readonly class AppDevDnsConfigRenderer
{
    public function __construct(
        private AppDevSiteRepository $sites,
    ) {}

    public function render(?Node $pendingNode = null): string
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
            ->whereNotNull('wireguard_address')
            ->whereNotNull('tld')
            ->get()
            ->flatMap(static fn (Node $n): array => [
                "address=/.{$n->tld}/{$n->wireguard_address}",
                "local=/{$n->tld}/",
            ]);
        $records = $nodes
            ->toBase()
            ->merge($this->sites->all()->map(
                static fn (AppDevSite $s): string => "host-record={$s->hostname},{$s->nodeAddress}",
            ));
        $gateway = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_address')
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->first();
        if ($gateway instanceof Node) {
            $records->push("host-record=gateway.orbit,{$gateway->wireguard_address}");

            $metrics = Node::query()
                ->where(static function (Builder $q) use ($pendingNode): void {
                    $q->where('status', LifecycleStatus::Active->value);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->whereNotNull('wireguard_address')
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
                $records->push("host-record=metrics.orbit,{$gateway->wireguard_address}");
            }
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }
}
