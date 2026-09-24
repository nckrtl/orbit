<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string ROUTE_IMMUTABLE = 'OR NEW.route_id IS NOT OLD.route_id';

    private const string RUNTIME_IMMUTABLE = "OR NEW.route_id IS NOT OLD.route_id\n"
        .'                OR NEW.runtime_published <> OLD.runtime_published';

    public function up(): void
    {
        Schema::table('app_instance_removal_members', function (Blueprint $table): void {
            $table->boolean('runtime_published')->default(true);
        });

        $this->replace('app_instance_removal_members_immutable', self::ROUTE_IMMUTABLE, self::RUNTIME_IMMUTABLE);
    }

    public function down(): void
    {
        $this->replace('app_instance_removal_members_immutable', self::RUNTIME_IMMUTABLE, self::ROUTE_IMMUTABLE);

        Schema::table('app_instance_removal_members', function (Blueprint $table): void {
            $table->dropColumn('runtime_published');
        });
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
