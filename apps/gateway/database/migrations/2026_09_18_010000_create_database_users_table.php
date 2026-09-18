<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_users', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('database_connection_id')->constrained()->cascadeOnDelete();
            $table->string('username', 128);
            $table->text('privileges');
            $table->string('created_by', 255)->nullable();
            $table->timestamps();

            $table->unique(['database_connection_id', 'username']);
        });
    }
};
