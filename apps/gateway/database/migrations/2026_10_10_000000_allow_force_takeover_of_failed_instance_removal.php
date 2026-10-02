<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string ORIGINAL = 'OR NEW.force <> OLD.force';

    private const string TAKEOVER = "OR (NEW.force <> OLD.force AND NOT (OLD.force = 0 AND NEW.force = 1 AND OLD.status = 'failed' AND NEW.status = 'failed'))";

    public function up(): void
    {
        $this->replace(self::ORIGINAL, self::TAKEOVER);
    }

    public function down(): void
    {
        $this->replace(self::TAKEOVER, self::ORIGINAL);
    }

    private function replace(string $clause, string $replacement): void
    {
        $trigger = 'instance_removals_immutable';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');

        if (! is_string($sql) || substr_count($sql, $clause) !== 1) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }

        $updated = str_replace($clause, $replacement, $sql);
        DB::transaction(static function () use ($trigger, $updated): void {
            DB::statement("DROP TRIGGER {$trigger}");
            DB::statement($updated);
        });
    }
};
