<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sql = $this->routeUpdateTrigger();
        $sql = $this->replaceOnce(
            $sql,
            "'router-caddy', 'laravel-url', 'dns-published', 'database-cutover'",
            "'router-caddy', 'laravel-url', 'environment-synchronized', 'dns-published', 'database-cutover'",
        );
        $sql = $this->replaceOnce(
            $sql,
            "'rollback-certificates', 'rollback-laravel-url', 'rolled-back'",
            "'rollback-certificates', 'rollback-laravel-url', 'rollback-environment', 'rolled-back'",
        );
        $sql = $this->replaceOnce(
            $sql,
            "app_instances.environment = 'development'",
            <<<'SQL'
                (
                                (app_instances.environment = 'development'
                                    AND NEW.hostname_change_step NOT IN (
                                        'environment-synchronized', 'rollback-environment'
                                    ))
                                OR (app_instances.environment = 'production'
                                    AND NEW.hostname_change_step NOT IN (
                                        'laravel-url', 'rollback-laravel-url'
                                    ))
                            )
                SQL,
        );

        $this->replaceRouteUpdateTrigger($sql);
    }

    public function down(): void
    {
        $unfinished = DB::table('routes')
            ->join('route_targets', 'route_targets.route_id', '=', 'routes.id')
            ->join('app_instances', 'app_instances.id', '=', 'route_targets.app_instance_id')
            ->where('app_instances.environment', 'production')
            ->whereNotNull('routes.hostname_change_target')
            ->orderBy('routes.id')
            ->pluck('routes.id');

        if ($unfinished->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot remove production Route hostname change support while operations are unfinished: '
                .$unfinished->implode(', ')
                .'.',
            );
        }

        $sql = $this->routeUpdateTrigger();
        $sql = $this->replaceOnce(
            $sql,
            "'router-caddy', 'laravel-url', 'environment-synchronized', 'dns-published', 'database-cutover'",
            "'router-caddy', 'laravel-url', 'dns-published', 'database-cutover'",
        );
        $sql = $this->replaceOnce(
            $sql,
            "'rollback-certificates', 'rollback-laravel-url', 'rollback-environment', 'rolled-back'",
            "'rollback-certificates', 'rollback-laravel-url', 'rolled-back'",
        );
        $sql = $this->replaceOnce(
            $sql,
            <<<'SQL'
                (
                                (app_instances.environment = 'development'
                                    AND NEW.hostname_change_step NOT IN (
                                        'environment-synchronized', 'rollback-environment'
                                    ))
                                OR (app_instances.environment = 'production'
                                    AND NEW.hostname_change_step NOT IN (
                                        'laravel-url', 'rollback-laravel-url'
                                    ))
                            )
                SQL,
            "app_instances.environment = 'development'",
        );

        $this->replaceRouteUpdateTrigger($sql);
    }

    private function routeUpdateTrigger(): string
    {
        $trigger = DB::selectOne(<<<'SQL'
            SELECT sql
            FROM sqlite_master
            WHERE type = 'trigger' AND name = 'routes_contract_update'
            SQL);

        if (! is_object($trigger) || ! is_string($trigger->sql ?? null)) {
            throw new RuntimeException('The Route update persistence contract is unavailable.');
        }

        return $trigger->sql;
    }

    private function replaceOnce(string $sql, string $search, string $replacement): string
    {
        $updated = str_replace($search, $replacement, $sql, $count);

        if ($count !== 1) {
            throw new RuntimeException('The Route update persistence contract has an unexpected shape.');
        }

        return $updated;
    }

    private function replaceRouteUpdateTrigger(string $sql): void
    {
        DB::statement('DROP TRIGGER routes_contract_update');
        DB::statement($sql);
    }
};
