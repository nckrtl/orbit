<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0141: a role site renders in a Node Caddy build only once the Gateway recorded that its certificate
 * is on the Node. Every site that renders today already has its certificate there, so this records one
 * for each of them and a build keeps serving every live site.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now('UTC');

        foreach (['websocket' => 'websocket', 'analytics' => 'analytics'] as $role => $site) {
            foreach ($this->servingNodeIds($role) as $nodeId) {
                $this->record($nodeId, $site, $now);
            }
        }

        if ($this->servingNodeIds('metrics') !== []) {
            foreach ($this->servingNodeIds('gateway') as $nodeId) {
                $this->record($nodeId, 'metrics', $now);
            }
        }

        $enabled = $this->gatewaySetting('proxycli.enabled');
        $proxyCliNode = $this->gatewaySetting('proxycli.node_id');

        if ($enabled === '1' && is_string($proxyCliNode) && ctype_digit($proxyCliNode)) {
            $this->record((int) $proxyCliNode, 'proxycli', $now);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('scope_type', 'node')
            ->where('key', 'like', 'caddy.certificate.%')
            ->delete();
    }

    /**
     * The Nodes whose role row renders its site: converging, active, or after a failed convergence.
     *
     * @return list<int>
     */
    private function servingNodeIds(string $role): array
    {
        return DB::table('node_roles')
            ->where('role', $role)
            ->where(static fn ($query) => $query
                ->whereIn('status', ['active', 'provisioning'])
                ->orWhere(static fn ($failed) => $failed
                    ->where('status', 'failed')
                    ->where('failed_step', 'like', 'converge:%')))
            ->orderBy('node_id')
            ->pluck('node_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function gatewaySetting(string $key): ?string
    {
        $value = DB::table('settings')
            ->where('scope_type', 'gateway')
            ->where('scope_id', 0)
            ->where('key', $key)
            ->where('is_secret', false)
            ->value('value');

        return is_string($value) ? $value : null;
    }

    private function record(int $nodeId, string $site, mixed $now): void
    {
        DB::table('settings')->updateOrInsert(
            ['scope_type' => 'node', 'scope_id' => $nodeId, 'key' => "caddy.certificate.{$site}"],
            ['value' => $now->toIso8601String(), 'is_secret' => false, 'created_at' => $now, 'updated_at' => $now],
        );
    }
};
