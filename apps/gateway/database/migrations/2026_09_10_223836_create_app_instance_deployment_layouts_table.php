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
        Schema::create('app_instance_deployment_layouts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('step', [
                'accepted',
                'source_moved',
                'persistent_state_placed',
                'runtime_published',
                'route_projected',
                'completed',
            ]);
            $table->text('source_path');
            $table->text('release_path');
            $table->text('sqlite_source_path')->nullable();
            $table->json('inventory');
            $table->string('failed_step', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('app_instance_deployment_layouts')) {
            $recorded = DB::table('app_instance_deployment_layouts')->exists();

            if ($recorded) {
                throw new RuntimeException('Cannot discard recorded AppInstance deployment-layout conversions.');
            }
        }

        Schema::dropIfExists('app_instance_deployment_layouts');
    }
};
