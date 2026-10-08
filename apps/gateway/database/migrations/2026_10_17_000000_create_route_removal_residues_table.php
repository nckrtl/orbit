<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an offline Route removal left on a Node it could not reach. The Route record is gone, so each
 * row keeps the Route's identity and the removal steps the Node still needs. Doctor reports a row, and
 * the Node's footprint converge removes the projections and the row once the Node answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_removal_residues', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->unsignedBigInteger('route_id');
            $table->string('domain');
            $table->json('steps');
            $table->timestamps();
            $table->unique(['node_id', 'route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_removal_residues');
    }
};
