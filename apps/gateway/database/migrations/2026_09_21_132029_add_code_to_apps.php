<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table): void {
            $table->string('code', 3)->nullable();
        });
        $used = [];
        foreach (DB::table('apps')->orderByRaw("CASE WHEN slug = 'orbit' THEN 0 ELSE 1 END")->orderBy('id')->get(['id', 'slug']) as $project) {
            $code = ProjectCode::suggest((string) $project->slug, $used);
            DB::table('apps')->where('id', $project->id)->update(['code' => $code]);
            $used[] = $code;
        }
        Schema::table('apps', function (Blueprint $table): void {
            $table->unique('code');
        });
        foreach (['insert', 'update'] as $event) {
            DB::unprepared("CREATE TRIGGER apps_code_{$event} BEFORE {$event} ON apps WHEN NEW.code IS NULL OR NEW.code NOT GLOB '[A-Z][A-Z][A-Z]' BEGIN SELECT RAISE(ABORT, 'Project code must contain three uppercase letters'); END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS apps_code_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS apps_code_update');
        Schema::table('apps', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
