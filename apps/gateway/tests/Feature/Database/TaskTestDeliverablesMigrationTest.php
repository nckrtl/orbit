<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('converts stored test deliverables in open groups to generic commands', function (): void {
    $default = DB::getDefaultConnection();
    $nestedProject = null;
    try {
        config()->set('database.connections.test_deliverables_migration', [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('test_deliverables_migration');
        $paths = glob(database_path('migrations/*.php')) ?: [];
        Artisan::call('migrate', ['--database' => 'test_deliverables_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $appId = DB::table('apps')->insertGetId([
            'name' => 'Migration fixture', 'slug' => 'migration-fixture', 'code' => 'MIG',
            'repository_url' => 'git@example.test:migration.git', 'repository_identity' => 'example.test/migration',
            'task_check' => 'cd apps/gateway && composer test',
            'test_command' => 'cd {project} && phpunit {project_file} --filter {name}',
        ]);
        $open = DB::table('task_groups')->insertGetId(['app_id' => $appId, 'title' => 'Open', 'brief' => 'Brief', 'status' => 'todo']);
        $closed = DB::table('task_groups')->insertGetId(['app_id' => $appId, 'title' => 'Closed', 'brief' => 'Brief', 'status' => 'completed']);
        $legacy = ['id' => 'repro', 'type' => 'test', 'description' => 'Reproduce it', 'project' => './apps//gateway/', 'file' => './tests//Feature/LayoutTest.php', 'name' => 'layout regression', 'fails_on_base' => true];
        $openTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $open, 'position' => 1, 'title' => 'Repro', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([$legacy], JSON_THROW_ON_ERROR),
        ]);
        $closedTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $closed, 'position' => 1, 'title' => 'Closed', 'brief' => 'Brief', 'status' => 'completed',
            'deliverables' => json_encode([$legacy], JSON_THROW_ON_ERROR),
        ]);

        $migration = require database_path('migrations/2026_09_29_130000_convert_test_deliverables_to_commands.php');
        $migration->up();

        expect(json_decode(DB::table('tasks')->where('id', $openTask)->value('deliverables'), true))->toBe([[
            'id' => 'repro',
            'type' => 'command',
            'description' => 'Reproduce it',
            'command' => "cd 'apps/gateway' && phpunit 'tests/Feature/LayoutTest.php' --filter 'layout regression'",
            'directory' => '.',
            'fails_on_base' => true,
            'paths' => ['apps/gateway/tests/Feature/LayoutTest.php'],
        ]])
            ->and(DB::table('apps')->where('id', $appId)->value('task_check'))->toBe('cd apps/gateway && composer test')
            ->and(json_decode(DB::table('tasks')->where('id', $closedTask)->value('deliverables'), true))->toBe([$legacy]);

        $composerCheckApp = DB::table('apps')->insertGetId([
            'name' => 'Composer check migration fixture', 'slug' => 'composer-check-migration-fixture', 'code' => 'CMF',
            'repository_url' => 'git@example.test:composer-check.git', 'repository_identity' => 'example.test/composer-check',
            'task_check' => 'composer check', 'test_command' => null,
        ]);
        $composerCheckGroup = DB::table('task_groups')->insertGetId([
            'app_id' => $composerCheckApp, 'title' => 'Composer check', 'brief' => 'Brief', 'status' => 'todo',
        ]);
        $composerCheckTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $composerCheckGroup, 'position' => 1, 'title' => 'Legacy Pest', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([$legacy], JSON_THROW_ON_ERROR),
        ]);
        $nestedApp = DB::table('apps')->insertGetId([
            'name' => 'Nested Pest migration fixture', 'slug' => 'nested-pest-migration-fixture', 'code' => 'NPM',
            'repository_url' => 'git@example.test:nested-pest-migration.git', 'repository_identity' => 'example.test/nested-pest-migration',
            'task_check' => 'composer check', 'test_command' => null,
        ]);
        $nestedGroup = DB::table('task_groups')->insertGetId([
            'app_id' => $nestedApp, 'title' => 'Nested Pest', 'brief' => 'Brief', 'status' => 'todo',
        ]);
        $nestedProjectName = 'orbit-legacy-pest-'.Str::random(12);
        $nestedProject = base_path('storage/framework/testing/'.$nestedProjectName);
        $nestedProjectRelative = 'apps/gateway/storage/framework/testing/'.$nestedProjectName;
        File::ensureDirectoryExists($nestedProject.'/tests');
        symlink(base_path('vendor'), $nestedProject.'/vendor');
        file_put_contents($nestedProject.'/phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="tests/bootstrap.php">
                <testsuites><testsuite name="Nested migration fixture"><file>tests/LegacyMappingTest.php</file></testsuite></testsuites>
                <php><env name="ORBIT_LEGACY_CONFIG" value="loaded" force="true"/></php>
            </phpunit>
            XML);
        file_put_contents($nestedProject.'/tests/bootstrap.php', "<?php\nputenv('ORBIT_LEGACY_BOOTSTRAP=loaded');\n");
        file_put_contents($nestedProject.'/tests/LegacyMappingTest.php', <<<'PHP'
            <?php

            it('computes sum (a+b)', function (): void {
                expect(getenv('ORBIT_LEGACY_CONFIG'))->toBe('loaded')
                    ->and(getenv('ORBIT_LEGACY_BOOTSTRAP'))->toBe('loaded');
            });

            it('computes sum ab', function (): never {
                throw new RuntimeException('Regex metacharacters were not escaped.');
            });

            it('Computes sum (a+b) case-decoy', function (): never {
                throw new RuntimeException('The legacy name filter was not case-sensitive.');
            });
            PHP);
        $nestedLegacy = [
            'id' => 'nested-pest-config',
            'type' => 'test',
            'description' => 'Nested Pest configuration is preserved',
            'project' => './'.$nestedProjectRelative,
            'file' => './tests//LegacyMappingTest.php',
            'name' => 'computes sum (a+b)',
            'fails_on_base' => true,
        ];
        $nestedTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $nestedGroup, 'position' => 1, 'title' => 'Nested Pest', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([$nestedLegacy], JSON_THROW_ON_ERROR),
        ]);
        $unsafe = [...$legacy, 'id' => 'unsafe', 'file' => '../LayoutTest.php'];
        $unsafeTask = DB::table('tasks')->insertGetId([
            'task_group_id' => $composerCheckGroup, 'position' => 2, 'title' => 'Unsafe', 'brief' => 'Brief', 'status' => 'todo',
            'deliverables' => json_encode([$unsafe], JSON_THROW_ON_ERROR),
        ]);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'unsafe project, file, or test name')
            ->and(json_decode(DB::table('tasks')->where('id', $composerCheckTask)->value('deliverables'), true))->toBe([[
                'id' => 'repro',
                'type' => 'command',
                'description' => 'Reproduce it',
                'command' => "cd 'apps/gateway' && vendor/bin/pest 'tests/Feature/LayoutTest.php' --filter='/layout regression/'",
                'directory' => '.',
                'fails_on_base' => true,
                'paths' => ['apps/gateway/tests/Feature/LayoutTest.php'],
            ]])
            ->and(DB::table('apps')->where('id', $composerCheckApp)->value('task_check'))->toBe('composer check')
            ->and(json_decode(DB::table('tasks')->where('id', $unsafeTask)->value('deliverables'), true))->toBe([$unsafe]);

        $nestedDeliverable = json_decode(DB::table('tasks')->where('id', $nestedTask)->value('deliverables'), true)[0];
        $pattern = '/'.preg_quote('computes sum (a+b)', '/').'/';
        expect($nestedDeliverable)->toMatchArray([
            'command' => 'cd '.escapeshellarg($nestedProjectRelative)
                .' && vendor/bin/pest '.escapeshellarg('tests/LegacyMappingTest.php')
                .' --filter='.escapeshellarg($pattern),
            'paths' => [$nestedProjectRelative.'/tests/LegacyMappingTest.php'],
        ]);

        $process = new Process(['bash', '-c', $nestedDeliverable['command']], dirname(base_path(), 2));
        $process->setTimeout(60);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())->toContain('1 passed');
    } finally {
        if (is_string($nestedProject)) {
            if (is_link($nestedProject.'/vendor')) {
                unlink($nestedProject.'/vendor');
            }
            File::deleteDirectory($nestedProject);
        }
        DB::setDefaultConnection($default);
        DB::purge('test_deliverables_migration');
    }
});
