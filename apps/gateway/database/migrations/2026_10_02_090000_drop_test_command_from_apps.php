<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('apps', 'test_command')) {
            Schema::table('apps', static function ($table): void {
                $table->dropColumn('test_command');
            });
        }
    }

    public function down(): void
    {
        // The legacy field is intentionally not restored.
    }
};
