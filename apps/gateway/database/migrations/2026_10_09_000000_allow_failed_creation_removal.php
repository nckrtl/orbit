<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replace('instance_removals_insert', "status = 'active' OR", "status = 'active' OR ".$this->failedCreation('instances').' OR');
        $this->replace('instance_removal_members_insert', "instances.status = 'active'", "instances.status = 'active' OR (NEW.runtime_published = 0 AND ".$this->failedCreation('instances').')');
        $this->replace('instance_removal_members_insert', 'OR NEW.starting_commit IS NULL', $this->unresolvedStartingCommitGuard());
        $this->replace('instances_removal_status_update', "OLD.status NOT IN ('active', 'source_resolved')", "(OLD.status NOT IN ('active', 'source_resolved') AND NOT ".$this->failedCreation('OLD').')');
    }

    public function down(): void
    {
        if (DB::table('instance_removal_members')->where('environment', 'development')->whereNull('starting_commit')->exists()) {
            throw new RuntimeException('Cannot roll back unresolved Instance removal evidence.');
        }

        $this->replace('instances_removal_status_update', "(OLD.status NOT IN ('active', 'source_resolved') AND NOT ".$this->failedCreation('OLD').')', "OLD.status NOT IN ('active', 'source_resolved')");
        $this->replace('instance_removal_members_insert', $this->unresolvedStartingCommitGuard(), 'OR NEW.starting_commit IS NULL');
        $this->replace('instance_removal_members_insert', "instances.status = 'active' OR (NEW.runtime_published = 0 AND ".$this->failedCreation('instances').')', "instances.status = 'active'");
        $this->replace('instance_removals_insert', "status = 'active' OR ".$this->failedCreation('instances').' OR', "status = 'active' OR");
    }

    private function unresolvedStartingCommitGuard(): string
    {
        return 'OR (NEW.starting_commit IS NULL AND NOT (NEW.runtime_published = 0'
            .' AND EXISTS (SELECT 1 FROM instances WHERE instances.id = NEW.instance_id AND '
            .$this->failedCreation('instances').')))';
    }

    private function failedCreation(string $row): string
    {
        return "({$row}.status IN ('reserved', 'checkout_prepared', 'source_resolved')"
            ." AND {$row}.failed_step IS NOT NULL AND {$row}.error_code IS NOT NULL"
            ." AND EXISTS (SELECT 1 FROM node_roles WHERE node_roles.node_id = {$row}.node_id"
            ." AND node_roles.role = 'app-dev' AND node_roles.status IN ('active', 'removing')))";
    }

    private function replace(string $trigger, string $clause, string $replacement): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            throw new RuntimeException('The Instance removal trigger clause is invalid.');
        }

        $pattern = '/'.implode('\s+', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).'/';
        $sql = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger)->value('sql');

        if (! is_string($sql) || preg_match_all($pattern, $sql) !== 1) {
            throw new RuntimeException("The {$trigger} trigger is incompatible.");
        }

        $updated = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $sql);

        if (! is_string($updated)) {
            throw new RuntimeException("The {$trigger} trigger cannot be updated.");
        }

        DB::transaction(static function () use ($trigger, $updated): void {
            DB::statement("DROP TRIGGER {$trigger}");
            DB::statement($updated);
        });
    }
};
