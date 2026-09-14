<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_connections', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('driver', 16);
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('host', 255)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('database', 64)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('username', 128)->nullable();
            $table->text('password')->nullable();
            $table->timestamps();
        });
    }
};
