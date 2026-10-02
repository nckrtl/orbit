<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->changes() as [$trigger, $before, $after]) {
                $this->replace($trigger, $before, $after);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (array_reverse($this->changes()) as [$trigger, $before, $after]) {
                $this->replace($trigger, $after, $before);
            }
        });
    }

    /** @return list<array{string, string, string}> */
    private function changes(): array
    {
        // Failed-create eligibility and nullable starting commits are guarded by
        // allow_failed_creation_removal, which runs before this migration.
        return [
            ['instance_removal_members_insert', 'instances.branch IS NEW.branch', '(instances.starting_commit IS NULL OR instances.branch IS NEW.branch)'],
            ['instance_removal_members_source_commit_insert', 'length(NEW.source_commit) NOT IN (40, 64)', "(length(NEW.source_commit) NOT IN (40, 64) AND NOT (NEW.source_commit = '' AND NEW.starting_commit IS NULL AND NEW.environment = 'development' AND NEW.source_layout = 'checkout'))"],
        ];
    }

    private function replace(string $trigger, string $clause, string $replacement): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new RuntimeException('The removal clause is invalid.');
        }
        $pattern = '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');
        if (! is_string($sql) || preg_match_all($pattern, $sql) !== 1) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }
        $updated = preg_replace_callback($pattern, static fn (): string => $replacement, $sql);
        if (! is_string($updated)) {
            throw new RuntimeException("The {$trigger} trigger cannot be updated.");
        }
        DB::statement("DROP TRIGGER {$trigger}");
        DB::statement($updated);
    }
};
