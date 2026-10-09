<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('instance_transfers')->whereNotNull('cutover_at')->whereNull('completed_at')->get() as $transfer) {
            if (DB::table('processes')->where('owner_type', 'instance')->where('owner_id', $transfer->instance_id)->where('runtime_config->preset', 'annotator')->exists()) {
                throw new RuntimeException('Complete cutover annotator transfers before migrating per-app transfer ownership.');
            }
        }
        Schema::table('instance_transfers', static function (Blueprint $table): void {
            $table->json('app_journal')->nullable();
            $table->unsignedBigInteger('source_route_id')->nullable()->change();
            $table->string('destination_domain', 253)->nullable()->change();
        });
        foreach (DB::table('instance_transfers')->get() as $transfer) {
            $importedKeys = json_decode($transfer->imported_environment_keys ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $open = $transfer->status !== 'completed' && ! ($transfer->status === 'failed' && $transfer->cutover_at === null && $transfer->current_step === 'reserved' && $transfer->recovery_evidence === null && $importedKeys === []);
            $entry = [
                'source_route_id' => $transfer->source_route_id,
                'destination_route_id' => $transfer->destination_route_id,
                'destination_domain' => $transfer->destination_domain,
                'source_router_node_id' => $transfer->source_router_node_id,
                'source_app_identity' => false,
                'imported_environment_keys' => $importedKeys,
            ];
            $attributes = [];
            if ($open && $transfer->cutover_at === null) {
                $instance = DB::table('instances')->where('id', $transfer->instance_id)->first();
                if ($instance !== null && DB::table('processes')->where('owner_type', 'instance')->where('owner_id', $instance->id)->where('runtime_config->preset', 'annotator')->exists()) {
                    $runtime = json_decode($instance->app_runtime ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($runtime)) {
                        throw new RuntimeException('The retained annotator runtime ownership is invalid.');
                    }
                    $webRuntime = $runtime['web'] ?? [];
                    if (! is_array($webRuntime)) {
                        throw new RuntimeException('The retained annotator runtime ownership is invalid.');
                    }
                    $entry['annotator'] = ['source_store' => '/var/lib/orbit/annotator/instance-'.$instance->id.(($webRuntime['annotator_store_identity'] ?? false) === true ? '-web' : '')];
                }
                $evidence = json_decode($transfer->recovery_evidence ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($evidence)) {
                    throw new RuntimeException('The retained transfer recovery ownership is invalid.');
                }
                $attributes = ['status' => 'failed', 'failed_step' => $transfer->current_step, 'recovery_evidence' => json_encode([...$evidence, 'rollback_pending' => true], JSON_THROW_ON_ERROR)];
            }
            DB::table('instance_transfers')->where('id', $transfer->id)->update([
                ...$attributes, 'app_journal' => json_encode(['web' => $entry], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('instance_transfers')->exists()) {
            throw new RuntimeException('Cannot discard per-app transfer ownership.');
        }
        Schema::table('instance_transfers', static function (Blueprint $table): void {
            $table->dropColumn('app_journal');
        });
    }
};
