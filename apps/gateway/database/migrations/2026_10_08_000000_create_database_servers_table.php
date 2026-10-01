<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_servers', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tag', 128);
            $table->unsignedInteger('port');
            $table->text('root_password');
            $table->string('status', 32);
            $table->string('failed_step', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamps();
        });
    }
};
