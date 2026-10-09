<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Route can name a web root inside its Instance's checkout. Existing Routes keep a null web root, so
 * they serve the Instance's effective root exactly as before. A Route with a web root does not count
 * toward the Instance's one Route without a web root, and it has one target.
 */
return new class extends Migration
{
    private const string NewWebRoot = '(SELECT web_root FROM routes WHERE id = NEW.route_id)';

    private const string InstanceRoutes = 'SELECT 1 FROM route_targets JOIN routes AS counted ON counted.id = route_targets.route_id'
        .' WHERE route_targets.instance_id = NEW.instance_id AND counted.web_root IS NULL';

    /** The column and the triggers change in one transaction, so a trigger mismatch leaves the schema as it was. */
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('routes', static function (Blueprint $table): void {
                $table->string('web_root')->nullable();
            });

            foreach ($this->changes() as [$trigger, $before, $after]) {
                $this->replace($trigger, $before, $after);
            }
        });
    }

    /** A rollback would turn every Route with a web root into another Route for its Instance's effective root. */
    public function down(): void
    {
        if (DB::table('routes')->whereNotNull('web_root')->exists()) {
            throw new RuntimeException('Routes with a web root exist. Remove them, or clear their web root, before rolling back this migration.');
        }

        DB::transaction(function (): void {
            foreach (array_reverse($this->changes()) as [$trigger, $before, $after]) {
                $this->replace($trigger, $after, $before);
            }

            Schema::table('routes', static function (Blueprint $table): void {
                $table->dropColumn('web_root');
            });
        });
    }

    /** @return list<array{string, string, string}> */
    private function changes(): array
    {
        $newWebRoot = self::NewWebRoot;
        $instanceRoutes = self::InstanceRoutes;

        return [
            [
                'route_targets_contract_insert',
                'NEW.position < 0',
                "NEW.position < 0 OR ({$newWebRoot} IS NOT NULL AND EXISTS (SELECT 1 FROM route_targets WHERE route_id = NEW.route_id))",
            ],
            [
                'route_targets_contract_insert',
                'OR (SELECT COUNT(*) FROM route_targets WHERE instance_id = NEW.instance_id) >= 2',
                "OR ({$newWebRoot} IS NULL AND (SELECT COUNT(*) FROM ({$instanceRoutes})) >= 2)",
            ],
            [
                'route_targets_contract_insert',
                'EXISTS (SELECT 1 FROM route_targets WHERE instance_id = NEW.instance_id) AND NOT EXISTS (',
                "{$newWebRoot} IS NULL AND EXISTS ({$instanceRoutes}) AND NOT EXISTS (",
            ],
            [
                'route_targets_contract_update',
                'NEW.position < 0',
                "NEW.position < 0 OR ({$newWebRoot} IS NOT NULL AND EXISTS (SELECT 1 FROM route_targets AS other WHERE other.route_id = NEW.route_id AND other.id <> OLD.id))",
            ],
            [
                'route_targets_contract_update',
                'WHERE instance_id = NEW.instance_id AND id <> OLD.id AND NOT EXISTS (',
                "WHERE instance_id = NEW.instance_id AND id <> OLD.id AND {$newWebRoot} IS NULL AND (SELECT web_root FROM routes WHERE id = route_targets.route_id) IS NULL AND NOT EXISTS (",
            ],
            [
                'instances_active_route_update',
                "WHERE route_targets.instance_id = NEW.id AND routes.status IN ('active', 'activating')",
                "WHERE route_targets.instance_id = NEW.id AND routes.status IN ('active', 'activating') AND routes.web_root IS NULL",
            ],
        ];
    }

    private function replace(string $trigger, string $clause, string $replacement): void
    {
        $tokens = preg_split('/\s+/', trim($clause), flags: PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new RuntimeException('The Route web root clause is invalid.');
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
