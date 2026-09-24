<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0141: a Route stores whether its sites are published and the second placement of a placement
 * change, so every Caddy build reads Route transitions from the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', static function (Blueprint $table): void {
            $table->boolean('sites_published')->default(false);
            $table->unsignedBigInteger('transition_node_id')->nullable();
            $table->unsignedBigInteger('transition_cluster_id')->nullable();
        });

        // Active, activating, and retiring Routes serve their sites today. The Route contract trigger
        // refuses any update of an authoritative Project Route whose last target an interrupted
        // Instance removal already cleared. That removal withdraws the Route on retry, so it keeps
        // no publication record.
        DB::table('routes')
            ->whereIn('status', ['active', 'activating', 'retiring'])
            ->whereNot(static fn ($query) => $query
                ->where('kind', 'app')
                ->whereIn('status', ['active', 'activating'])
                ->whereNotExists(static fn ($targets) => $targets
                    ->selectRaw('1')
                    ->from('route_targets')
                    ->whereColumn('route_targets.route_id', 'routes.id'))
                ->whereNot(static fn ($targetSet) => $targetSet
                    ->where('provenance', 'explicit')
                    ->whereNotNull('cluster_id')
                    ->whereNotNull('target_set_step')))
            ->update(['sites_published' => true]);
    }

    public function down(): void
    {
        $transitioning = DB::table('routes')
            ->where(static fn ($query) => $query
                ->whereNotNull('transition_node_id')
                ->orWhereNotNull('transition_cluster_id'))
            ->orderBy('id')
            ->pluck('id');

        if ($transitioning->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot remove Route placement transitions while they are unfinished: '
                .$transitioning->implode(', ')
                .'.',
            );
        }

        Schema::table('routes', static function (Blueprint $table): void {
            $table->dropColumn(['sites_published', 'transition_node_id', 'transition_cluster_id']);
        });
    }
};
