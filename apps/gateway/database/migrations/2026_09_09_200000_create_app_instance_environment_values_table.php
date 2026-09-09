<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_instance_environment_values', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_instance_id')->constrained()->cascadeOnDelete();
            $table->string('env_key', 255);
            $table->text('env_value');
            $table->timestamps();

            $table->unique(['app_instance_id', 'env_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_instance_environment_values');
    }
};
