<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setup and teardown steps run inside one API request, so a stored timeout above what that request
 * can give a step (540 seconds) could never be honored. Existing steps keep their order and command;
 * only the timeout is lowered to the limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_lifecycle_steps')
            ->where('timeout_seconds', '>', 540)
            ->update(['timeout_seconds' => 540]);
    }

    public function down(): void
    {
        // The previous timeouts are not recoverable, and the lowered ones stay valid.
    }
};
