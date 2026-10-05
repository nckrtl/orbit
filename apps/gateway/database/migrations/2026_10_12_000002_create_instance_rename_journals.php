<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_renames', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app')->nullable();
            $table->string('domain')->nullable();
            $table->boolean('branch_supplied');
            $table->string('branch')->nullable();
            // The old Route can be deleted before the operation records completion.
            $table->unsignedBigInteger('source_route_id')->nullable();
            $table->string('phase')->default('requested');
            $table->timestamps();
        });
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX instance_renames_open_instance ON instance_renames(instance_id) WHERE phase <> 'complete';
            CREATE TRIGGER instance_renames_identity_update BEFORE UPDATE ON instance_renames
            WHEN NEW.id IS NOT OLD.id
                OR (NEW.instance_id IS NOT OLD.instance_id AND NOT (OLD.phase = 'complete' AND NEW.instance_id IS NULL))
                OR NEW.app IS NOT OLD.app OR NEW.domain IS NOT OLD.domain
                OR NEW.branch_supplied IS NOT OLD.branch_supplied OR NEW.branch IS NOT OLD.branch
                OR NEW.source_route_id IS NOT OLD.source_route_id
                OR NEW.phase NOT IN ('requested', 'domain_converged', 'complete')
                OR (OLD.phase = 'domain_converged' AND NEW.phase = 'requested')
                OR (OLD.phase = 'complete' AND NEW.phase <> 'complete')
            BEGIN SELECT RAISE(ABORT, 'Rename request identity is immutable.'); END;
            SQL);
    }

    public function down(): void
    {
        if (DB::table('instance_renames')->where('phase', '!=', 'complete')->exists()) {
            throw new RuntimeException('Finish Instance renames before removing their recovery journals.');
        }
        Schema::dropIfExists('instance_renames');
    }
};
