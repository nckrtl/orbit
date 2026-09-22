<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceGuards(true);
    }

    public function down(): void
    {
        if (DB::table('app_instance_removal_members')->whereNull('route_id')->orWhere('route_outcome', 'absent')->exists()) {
            throw new RuntimeException('Cannot roll back accepted Route absence in Instance removal evidence.');
        }

        $this->replaceGuards(false);
    }

    private function replaceGuards(bool $allowAbsent): void
    {
        $requiredRoute = <<<'SQL'
            OR NOT EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE route_targets.route_id = NEW.route_id
                        AND route_targets.app_instance_id = NEW.app_instance_id
                )
            SQL;
        $optionalRoute = <<<'SQL'
            OR (NEW.route_id IS NULL AND (
                    EXISTS (SELECT 1 FROM apps WHERE id = NEW.app_id AND type = 'laravel-app')
                    OR EXISTS (SELECT 1 FROM route_targets WHERE app_instance_id = NEW.app_instance_id)
                ))
                OR (NEW.route_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM route_targets
                    WHERE route_targets.route_id = NEW.route_id
                        AND route_targets.app_instance_id = NEW.app_instance_id
                ))
            SQL;
        $requiredOutcome = "OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted'))";
        $optionalOutcome = <<<'SQL'
            OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted', 'absent'))
                OR (NEW.route_outcome IS NOT NULL AND ((NEW.route_id IS NULL) <> (NEW.route_outcome = 'absent')))
            SQL;

        DB::transaction(function () use ($allowAbsent, $requiredRoute, $optionalRoute, $requiredOutcome, $optionalOutcome): void {
            $this->replaceTrigger(
                'app_instance_removal_members_insert',
                $allowAbsent ? $requiredRoute : $optionalRoute,
                $allowAbsent ? $optionalRoute : $requiredRoute,
            );
            $this->replaceTrigger(
                'app_instance_removal_members_immutable',
                $allowAbsent ? $requiredOutcome : $optionalOutcome,
                $allowAbsent ? $optionalOutcome : $requiredOutcome,
            );
        });
    }

    private function replaceTrigger(string $name, string $before, string $after): void
    {
        $trigger = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql');

        if (! is_string($trigger) || substr_count($trigger, $before) !== 1) {
            throw new RuntimeException('The Instance removal Route evidence guard is incompatible.');
        }

        DB::statement("DROP TRIGGER {$name}");
        DB::unprepared(str_replace($before, $after, $trigger));
    }
};
