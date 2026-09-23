<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string REMOVAL_ACTIVE = "AND name = NEW.requested_name AND status = 'active'";

    private const string REMOVAL_REMOVABLE = "AND name = NEW.requested_name\n"
        ."                        AND (status = 'active' OR (status = 'source_resolved' AND NOT EXISTS (\n"
        ."                            SELECT 1 FROM route_targets WHERE route_targets.app_instance_id = app_instances.id\n"
        .'                        )))';

    private const string MEMBER_ACTIVE = "AND app_instances.status = 'active'";

    private const string MEMBER_REMOVABLE = "AND (app_instances.status = 'active'\n"
        ."                            OR (app_instances.status = 'source_resolved' AND NEW.route_id IS NULL))";

    private const string STATUS_ACTIVE = "OLD.status <> 'active'";

    private const string STATUS_REMOVABLE = "OLD.status NOT IN ('active', 'source_resolved')";

    public function up(): void
    {
        $this->replace('app_instance_removals_insert', self::REMOVAL_ACTIVE, self::REMOVAL_REMOVABLE);
        $this->replace('app_instance_removal_members_insert', self::MEMBER_ACTIVE, self::MEMBER_REMOVABLE);
        $this->replace('app_instances_removal_status_update', self::STATUS_ACTIVE, self::STATUS_REMOVABLE);
    }

    public function down(): void
    {
        $this->replace('app_instances_removal_status_update', self::STATUS_REMOVABLE, self::STATUS_ACTIVE);
        $this->replace('app_instance_removal_members_insert', self::MEMBER_REMOVABLE, self::MEMBER_ACTIVE);
        $this->replace('app_instance_removals_insert', self::REMOVAL_REMOVABLE, self::REMOVAL_ACTIVE);
    }

    /**
     * Matches the clause whatever its indentation, since earlier migrations re-emit these triggers.
     */
    private function pattern(string $clause): string
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            throw new RuntimeException('The AppInstance removal trigger clause is invalid.');
        }

        return '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
    }

    private function replace(string $trigger, string $clause, string $replacement): void
    {
        $pattern = $this->pattern($clause);
        $sql = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', $trigger)
            ->value('sql');

        if (! is_string($sql) || preg_match_all($pattern, $sql) !== 1) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }

        $updated = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $sql);

        if (! is_string($updated)) {
            throw new RuntimeException("The {$trigger} trigger cannot be updated.");
        }

        DB::transaction(static function () use ($trigger, $updated): void {
            DB::statement("DROP TRIGGER {$trigger}");
            DB::unprepared($updated);
        });
    }
};
