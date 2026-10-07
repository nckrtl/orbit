<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A release record now exists from the moment a release is requested. A queued deploy may name a
 * short SHA, so the commit and the release id stay empty until prepare resolves them. `requested`
 * keeps the caller's input, `force` the rollback override, and `alert` the receipt of the one alert
 * a failed release raises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_releases', static function (Blueprint $table): void {
            $table->string('release_id', 12)->nullable()->change();
            $table->char('sha', 40)->nullable()->change();
            $table->string('requested', 40)->nullable();
            $table->boolean('force')->default(false);
            $table->json('alert')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_releases', static function (Blueprint $table): void {
            $table->dropColumn(['requested', 'force', 'alert']);
        });
    }
};
