<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['process_definitions', 'schedule_definitions'] as $table) {
            foreach (DB::table($table)->select(['id', 'environments'])->get() as $definition) {
                $environments = json_decode($definition->environments, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($environments)) {
                    throw new RuntimeException("The {$table} definition has invalid environment data.");
                }

                $environments = array_values(array_filter(
                    $environments,
                    static fn (mixed $environment): bool => $environment === 'production',
                ));

                if ($environments === []) {
                    DB::table($table)->where('id', $definition->id)->delete();

                    continue;
                }

                DB::table($table)->where('id', $definition->id)->update([
                    'environments' => json_encode($environments, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    public function down(): void {}
};
