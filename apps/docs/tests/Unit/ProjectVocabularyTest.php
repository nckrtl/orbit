<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

function vocabularyFixture(): string
{
    $root = sys_get_temp_dir().'/project-vocabulary-'.bin2hex(random_bytes(6));
    mkdir($root, 0777, true);

    return $root;
}

function vocabularyWrite(string $root, string $relative, string $contents): void
{
    $path = $root.'/'.$relative;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function vocabularyCheck(string $root): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/project-vocabulary', '--root', $root]);
    $process->run();

    return $process;
}

function vocabularyRemove(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

it('fails when an App-domain name is present', function (string $relative, string $contents, string $name): void {
    $root = vocabularyFixture();
    try {
        vocabularyWrite($root, $relative, $contents);

        $process = vocabularyCheck($root);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain($name);
    } finally {
        vocabularyRemove($root);
    }
})->with([
    'App model class' => ['apps/gateway/app/Models/App.php', "<?php\nclass App {}\n", 'App model'],
    'App model reference' => ['apps/gateway/app/Example.php', "<?php\n\$class = 'App\\Models\\App';\n", 'App model'],
    'AppInstance' => ['apps/cli/app/Example.php', "<?php\nclass AppInstance {}\n", 'AppInstance'],
    'app_id' => ['packages/php-sdk/src/Example.php', "<?php\n\$column = 'app_id';\n", 'app_id'],
    'app_instance_id' => ['apps/web/src/example.ts', "export const column = 'app_instance_id';\n", 'app_instance'],
    'app_instances table' => ['docs/reference/example.md', "The `app_instances` table is gone.\n", 'app_instance'],
    'attached reason' => ['apps/gateway/app/Example.php', "<?php\n\$details = ['reason' => 'app_instances_attached'];\n", 'app_instance'],
    'apps table' => ['apps/gateway/database/migrations/2026_10_05_000000_add_widget.php', "<?php\nSchema::create('apps', function (): void {});\n", 'apps table'],
    'constrained apps table' => ['apps/gateway/database/migrations/2026_10_05_000000_add_widget.php', "<?php\n\$table->foreignId('project_id')->constrained('apps');\n", 'apps table'],
    'constrained apps table argument' => ['apps/gateway/database/migrations/2026_10_05_000000_add_widget.php', "<?php\n\$table->foreignId('project_id')->constrained(table: 'apps');\n", 'apps table'],
    'references apps table' => ['apps/gateway/database/migrations/2026_10_05_000000_add_widget.php', "<?php\n\$table->foreign('project_id')->references('id')->on('apps');\n", 'apps table'],
    'exists apps rule' => ['apps/gateway/app/Example.php', "<?php\n\$rules = ['project_id' => 'exists:apps,id'];\n", 'apps table'],
    'unique apps rule' => ['apps/gateway/app/Example.php', "<?php\n\$rules = ['slug' => 'unique:apps'];\n", 'apps table'],
    'rule exists apps' => ['apps/gateway/app/Example.php', "<?php\n\$rules = ['project_id' => Rule::exists('apps', 'id')];\n", 'apps table'],
    'rule unique apps' => ['apps/gateway/app/Example.php', "<?php\n\$rules = ['slug' => Rule::unique('apps')];\n", 'apps table'],
    'camelCase app instance' => ['apps/web/src/instance.ts', "export const appInstanceId = 4;\n", 'AppInstance'],
    'camelCase app id' => ['apps/web/src/project.ts', "export const appId = 4;\n", 'appId'],
    'covers glob' => ['docs/reference/example.md', "apps/gateway/app/Actions/AppInstances/CreateAppInstanceAction.php\n", 'AppInstance'],
]);

it('allows monorepo paths, the GitHub App, Reverb, node storage, and the rename migration', function (): void {
    $root = vocabularyFixture();
    try {
        vocabularyWrite($root, 'apps/gateway/app/Example.php', "<?php\n\$path = 'apps/gateway';\n\$settings = ['apps' => ['path' => '/srv/orbit/apps']];\n");
        vocabularyWrite($root, 'apps/gateway/app/Domain/GitHub/GitHubAppStore.php', "<?php\n\$id = \$app['app_id'];\npublic int \$appId;\n");
        vocabularyWrite($root, 'apps/gateway/config/broadcasting.php', "<?php\nreturn ['connections' => ['reverb' => ['app_id' => null]]];\n");
        vocabularyWrite($root, 'apps/gateway/config/app.php', "<?php\nreturn ['name' => 'Orbit'];\n");
        vocabularyWrite($root, 'apps/gateway/app/Infrastructure/AgentView/AgentViewSubscriber.php', "<?php\n\$fingerprint = \$credentials->appId;\n");
        vocabularyWrite(
            $root,
            'apps/gateway/database/migrations/2026_10_04_000000_rename_app_domain_to_project_and_instance.php',
            "<?php\nSchema::rename('apps', 'projects');\n\$column = 'app_instance_id';\n",
        );
        vocabularyWrite($root, 'docs/reference/example.md', "Monorepo folders such as `apps/cli` stay. The node storage setting is `apps`.\n");

        $process = vocabularyCheck($root);

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        vocabularyRemove($root);
    }
});

it('accepts this repository', function (): void {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 4).'/bin/project-vocabulary']);
    $process->setTimeout(60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
