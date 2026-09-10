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
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->string('deployment_branch')->nullable()->after('branch');
            $table->json('deployment_steps')->nullable()->after('deployment_branch');
        });

        DB::table('app_instances')
            ->where('environment', 'production')
            ->whereNotNull('branch')
            ->update(['deployment_branch' => DB::raw('branch')]);
    }

    public function down(): void
    {
        if (DB::table('app_instances')->whereNotNull('deployment_branch')->orWhereNotNull('deployment_steps')->exists()) {
            throw new RuntimeException('Cannot discard configured AppInstance deployment state.');
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn(['deployment_branch', 'deployment_steps']);
        });
    }
};
