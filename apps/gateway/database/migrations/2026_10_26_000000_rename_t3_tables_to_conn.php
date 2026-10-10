<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The T3 Code layer is now the Conn layer, after the client that uses it. The tables and their
 * foreign keys and indexes move with it; the rows stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('t3_profiles', 'conn_profiles');
        Schema::rename('t3_profile_bindings', 'conn_profile_bindings');
        Schema::rename('t3_environments', 'conn_environments');
        Schema::rename('t3_pairings', 'conn_pairings');

        // Columns first, each in its own statement: SQLite rebuilds a renamed index from the
        // columns it reads when the blueprint compiles, so a rename in the same blueprint is stale.
        Schema::table('conn_profile_bindings', static function (Blueprint $table): void {
            $table->renameColumn('t3_profile_id', 'conn_profile_id');
        });
        Schema::table('conn_pairings', static function (Blueprint $table): void {
            $table->renameColumn('t3_environment_id', 'conn_environment_id');
        });

        Schema::table('conn_profiles', static function (Blueprint $table): void {
            $table->renameIndex('t3_profiles_name_unique', 'conn_profiles_name_unique');
        });
        Schema::table('conn_profile_bindings', static function (Blueprint $table): void {
            $table->renameIndex('t3_profile_bindings_node_id_unique', 'conn_profile_bindings_node_id_unique');
        });
        Schema::table('conn_environments', static function (Blueprint $table): void {
            $table->renameIndex('t3_environments_environment_id_unique', 'conn_environments_environment_id_unique');
        });
        Schema::table('conn_pairings', static function (Blueprint $table): void {
            $table->renameIndex('t3_pairings_t3_environment_id_node_id_index', 'conn_pairings_conn_environment_id_node_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('conn_pairings', static function (Blueprint $table): void {
            $table->renameIndex('conn_pairings_conn_environment_id_node_id_index', 't3_pairings_t3_environment_id_node_id_index');
        });
        Schema::table('conn_pairings', static function (Blueprint $table): void {
            $table->renameColumn('conn_environment_id', 't3_environment_id');
        });
        Schema::table('conn_environments', static function (Blueprint $table): void {
            $table->renameIndex('conn_environments_environment_id_unique', 't3_environments_environment_id_unique');
        });
        Schema::table('conn_profile_bindings', static function (Blueprint $table): void {
            $table->renameIndex('conn_profile_bindings_node_id_unique', 't3_profile_bindings_node_id_unique');
        });
        Schema::table('conn_profile_bindings', static function (Blueprint $table): void {
            $table->renameColumn('conn_profile_id', 't3_profile_id');
        });
        Schema::table('conn_profiles', static function (Blueprint $table): void {
            $table->renameIndex('conn_profiles_name_unique', 't3_profiles_name_unique');
        });

        Schema::rename('conn_pairings', 't3_pairings');
        Schema::rename('conn_environments', 't3_environments');
        Schema::rename('conn_profile_bindings', 't3_profile_bindings');
        Schema::rename('conn_profiles', 't3_profiles');
    }
};
