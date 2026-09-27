<?php

declare(strict_types=1);

use App\Models\App as OrbitApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('removes inert development applicability from stored runtime definitions', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Definitions',
        'slug' => 'definitions',
        'repository_url' => 'https://example.test/definitions.git',
    ]);

    foreach (['process_definitions', 'schedule_definitions'] as $table) {
        DB::table($table)->insert([
            [
                'id' => (string) Str::uuid(),
                'app_id' => $app->id,
                'name' => 'development-only',
                'environments' => json_encode(['development'], JSON_THROW_ON_ERROR),
                'spec' => json_encode(['command' => 'run'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'app_id' => $app->id,
                'name' => 'mixed',
                'environments' => json_encode(['development', 'production'], JSON_THROW_ON_ERROR),
                'spec' => json_encode(['command' => 'run'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    (require database_path('migrations/2026_09_30_100100_remove_development_app_runtime_definitions.php'))->up();

    foreach (['process_definitions', 'schedule_definitions'] as $table) {
        expect(DB::table($table)->where('name', 'development-only')->exists())->toBeFalse()
            ->and(json_decode(DB::table($table)->where('name', 'mixed')->value('environments'), true, 512, JSON_THROW_ON_ERROR))
            ->toBe(['production']);
    }
});
