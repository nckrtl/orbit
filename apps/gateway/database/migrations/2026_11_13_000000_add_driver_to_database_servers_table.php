<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Database server runs MySQL or Valkey (ADR 0221). Every existing server runs MySQL. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_servers', static function (Blueprint $table): void {
            $table->string('driver', 16)->default('mysql')->after('slug');
            $table->boolean('default_open')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('database_servers', static function (Blueprint $table): void {
            $table->dropColumn(['driver', 'default_open']);
        });
    }
};
