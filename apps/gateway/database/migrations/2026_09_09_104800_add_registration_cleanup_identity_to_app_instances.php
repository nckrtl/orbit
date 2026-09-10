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
        Schema::table('app_instances', static function (Blueprint $table): void {
            $table
                ->unsignedBigInteger('registration_source_device')
                ->nullable()
                ->after('registration_authoritative_path');
            $table
                ->unsignedBigInteger('registration_source_inode')
                ->nullable()
                ->after('registration_source_device');
        });
    }

    public function down(): void
    {
        $unfinished = DB::table('app_instances')
            ->whereIn('registration_relocation_state', ['destination_verified', 'original_cleanup'])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($unfinished !== []) {
            throw new RuntimeException(
                'Cannot roll back while AppInstance source cleanup is incomplete: '.implode(', ', $unfinished),
            );
        }

        Schema::table('app_instances', static function (Blueprint $table): void {
            $table->dropColumn([
                'registration_source_device',
                'registration_source_inode',
            ]);
        });
    }
};
