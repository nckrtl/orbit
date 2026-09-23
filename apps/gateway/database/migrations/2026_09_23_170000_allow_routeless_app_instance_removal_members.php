<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string ROUTED_TARGET = <<<'SQL'
        OR NOT EXISTS (
                            SELECT 1 FROM route_targets
                            WHERE route_targets.route_id = NEW.route_id
                                AND route_targets.app_instance_id = NEW.app_instance_id
                        )
        SQL;

    private const string OPTIONAL_TARGET = <<<'SQL'
        OR (NEW.route_id IS NOT NULL AND NOT EXISTS (
                            SELECT 1 FROM route_targets
                            WHERE route_targets.route_id = NEW.route_id
                                AND route_targets.app_instance_id = NEW.app_instance_id
                        ))
                        OR (NEW.route_id IS NULL AND EXISTS (
                            SELECT 1 FROM route_targets
                            WHERE route_targets.app_instance_id = NEW.app_instance_id
                        ))
        SQL;

    private const string ROUTED_OUTCOMES = "OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted'))";

    private const string OPTIONAL_OUTCOMES = "OR (NEW.route_outcome IS NOT NULL AND NEW.route_outcome NOT IN ('retained', 'deleted', 'none'))\n"
        ."                OR (NEW.route_outcome IS NOT NULL AND (NEW.route_outcome = 'none') <> (NEW.route_id IS NULL))";

    public function up(): void
    {
        $this->replace(
            'app_instance_removal_members_insert',
            $this->pattern(self::ROUTED_TARGET),
            self::OPTIONAL_TARGET,
        );
        $this->replace(
            'app_instance_removal_members_immutable',
            $this->pattern(self::ROUTED_OUTCOMES),
            self::OPTIONAL_OUTCOMES,
        );
    }

    public function down(): void
    {
        if (DB::table('app_instance_removal_members')->whereNull('route_id')->exists()) {
            throw new RuntimeException('Cannot roll back while AppInstance removal evidence records an Instance without a Route.');
        }

        $this->replace(
            'app_instance_removal_members_immutable',
            $this->pattern(self::OPTIONAL_OUTCOMES),
            self::ROUTED_OUTCOMES,
        );
        $this->replace(
            'app_instance_removal_members_insert',
            $this->pattern(self::OPTIONAL_TARGET),
            self::ROUTED_TARGET,
        );
    }

    /**
     * Matches the clause whatever its indentation, since earlier migrations re-emit these triggers.
     */
    private function pattern(string $clause): string
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            throw new RuntimeException('The AppInstance removal member trigger clause is invalid.');
        }

        return '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
    }

    private function replace(string $trigger, string $pattern, string $replacement): void
    {
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
