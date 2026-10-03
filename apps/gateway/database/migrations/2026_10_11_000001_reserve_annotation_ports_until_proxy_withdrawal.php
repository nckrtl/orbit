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
        Schema::create('annotation_port_assignments', function (Blueprint $table): void {
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('port');
            $table->unique(['node_id', 'port']);
            $table->primary(['instance_id', 'node_id', 'kind']);
        });
        foreach (['agentation_port', 'annotator_port'] as $kind) {
            DB::table('instances')->whereNotNull($kind)->orderBy('id')->each(function (object $instance) use ($kind): void {
                DB::table('annotation_port_assignments')->insert([
                    'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                    'kind' => $kind, 'port' => $instance->{$kind},
                ]);
            });
        }
        Schema::table('processes', function (Blueprint $table): void {
            $table->timestamp('endpoint_withdrawal_started_at')->nullable();
            $table->timestamp('endpoint_withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('processes', fn (Blueprint $table) => $table->dropColumn(['endpoint_withdrawal_started_at', 'endpoint_withdrawn_at']));
        Schema::dropIfExists('annotation_port_assignments');
    }
};
