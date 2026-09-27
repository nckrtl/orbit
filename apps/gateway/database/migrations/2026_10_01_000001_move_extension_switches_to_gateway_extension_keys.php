<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $setting = static fn (string $key): ?object => DB::table('settings')
            ->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', $key)->first();
        $store = static function (string $extension) use ($now): void {
            DB::table('settings')->updateOrInsert(
                ['scope_type' => 'gateway', 'scope_id' => 0, 'key' => "extension.{$extension}.enabled"],
                ['value' => '1', 'is_secret' => false, 'created_at' => $now, 'updated_at' => $now],
            );
        };

        if ($setting('tasks.enabled')?->value === '1') {
            $store('tasks');
        }

        $proxyCliEnabled = $setting('proxycli.enabled')?->value === '1';
        $proxyCliNode = $setting('proxycli.node_id')?->value;
        if ($proxyCliEnabled && is_string($proxyCliNode) && ctype_digit($proxyCliNode)
            && DB::table('processes')->where('owner_type', Node::class)->where('owner_id', (int) $proxyCliNode)
                ->where('name', 'cli-proxy-api-collector')->where('desired_state', 'running')->exists()) {
            $store('proxycli');
        }

        DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'tasks.enabled')->delete();
    }

    public function down(): void
    {
        foreach (['tasks', 'proxycli'] as $extension) {
            DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)
                ->where('key', "extension.{$extension}.enabled")->delete();
        }
    }
};
