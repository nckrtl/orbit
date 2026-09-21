<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskAgentDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_agent_sessions', static function (Blueprint $table): void {
            $table->string('model')->nullable()->after('role');
            $table->string('effort')->nullable()->after('model');
        });

        // Sessions spawned before the columns existed still know their agent:
        // the task group's model override or the role default, and the role's effort.
        foreach (DB::table('task_agent_sessions')->orderBy('id')->get() as $session) {
            $group = DB::table('task_groups')->where('id', $session->task_group_id)->first();
            $reviewer = $session->role === 'reviewer';
            $override = $reviewer ? $group?->reviewer_model : $group?->implementer_model;
            DB::table('task_agent_sessions')->where('id', $session->id)->update([
                'model' => is_string($override) && $override !== ''
                    ? $override
                    : ($reviewer ? TaskAgentDefaults::ReviewerModel : TaskAgentDefaults::ImplementerModel),
                'effort' => $reviewer ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('task_agent_sessions', static function (Blueprint $table): void {
            $table->dropColumn(['model', 'effort']);
        });
    }
};
