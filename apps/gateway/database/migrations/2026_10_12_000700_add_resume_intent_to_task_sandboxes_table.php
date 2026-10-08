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
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->timestamp('resume_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('task_sandboxes')->whereNotNull('resume_requested_at')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Resolve pending sandbox resumes before rolling back their intent.');
        }
        Schema::table('task_sandboxes', function (Blueprint $table): void {
            $table->dropColumn('resume_requested_at');
        });
    }
};
