<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_connections', static function (Blueprint $table): void {
            $table->foreignId('database_server_id')->nullable()->after('node_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_instance_id')->nullable()->after('database_server_id')->constrained('instances')->nullOnDelete();
            $table->string('test_database', 64)->nullable()->after('database');
            $table->string('clone_step', 16)->nullable()->after('owner_instance_id');
        });
    }
};
