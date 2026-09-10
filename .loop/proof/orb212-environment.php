<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Models\Activity;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$gateway = '/home/orbit/orbit/apps/gateway';
require $gateway.'/vendor/autoload.php';
$application = require $gateway.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$specifications = [
    'development' => [
        'node' => 'app-dev',
        'environment' => 'development',
        'path' => '/home/orbit/orb212-environment-dev',
        'user' => 'orbit',
        'hostname' => 'orb212-development.orbit',
    ],
    'production' => [
        'node' => 'app-prod',
        'environment' => 'production',
        'path' => '/home/orbit-app-orb212',
        'user' => 'orbit-app-orb212',
        'hostname' => 'orb212-production.orbit',
    ],
];

$profiles = [
    'baseline' => [
        'APP_KEY' => 'baseline-key',
        'APP_URL' => 'https://{{app_instance.hostname}}',
    ],
    'complete' => [
        'APP_ENV' => '{{app_instance.environment}}',
        'APP_KEY' => 'complete-proof-sentinel',
        'APP_URL' => 'https://{{app_instance.hostname}}',
        'EMPTY' => '',
        'LITERAL' => 'dollar $ backslash \\ quote "',
        'MULTILINE' => "line one\nline two",
    ],
    'retry' => [
        'APP_KEY' => 'retry-proof-key',
        'KEY' => 'initial',
    ],
    'bootstrap' => [
        'APP_KEY' => 'bootstrap-proof-key',
        'APP_URL' => 'https://{{app_instance.hostname}}',
    ],
];

$command = $argv[1] ?? '';
$failureStage = 'dispatch';

$instance = static function (string $label) use ($specifications): AppInstance {
    if (! array_key_exists($label, $specifications)) {
        throw new RuntimeException('Unknown ORB-212 fixture label.');
    }

    return AppInstance::query()
        ->whereHas('app', static fn ($query) => $query->where('slug', "orb212-{$label}"))
        ->sole();
};

$resetValues = static function (string $label, string $profile) use ($instance, $profiles): void {
    if (! array_key_exists($profile, $profiles)) {
        throw new RuntimeException('Unknown ORB-212 value profile.');
    }

    $owner = $instance($label);
    $owner->environmentValues()->delete();

    foreach ($profiles[$profile] as $key => $value) {
        $owner->environmentValues()->create(['env_key' => $key, 'env_value' => $value]);
    }
};

try {
    match ($command) {
        'setup' => (static function () use ($specifications, $profiles, &$failureStage): void {
            DB::transaction(static function () use ($specifications, $profiles, &$failureStage): void {
                $failureStage = 'cleanup-existing';
                $appIds = App::query()->whereIn('slug', ['orb212-development', 'orb212-production'])->pluck('id');
                AppInstance::query()->whereIn('app_id', $appIds)->update(['status' => 'reserved']);
                Route::query()->whereIn('app_id', $appIds)->delete();
                AppInstance::query()->whereIn('app_id', $appIds)->delete();
                App::query()->whereIn('id', $appIds)->delete();

                foreach ($specifications as $label => $specification) {
                    $failureStage = "seed-{$label}";
                    $node = Node::query()->where('name', $specification['node'])->sole();
                    $app = App::query()->create([
                        'name' => "ORB-212 {$label}",
                        'slug' => "orb212-{$label}",
                        'repository_url' => "https://example.test/orb212-{$label}.git",
                        'default_branch' => 'main',
                        'root' => 'public',
                    ]);
                    $owner = AppInstance::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $node->id,
                        'name' => 'default',
                        'environment' => $specification['environment'],
                        'checkout_path' => $specification['path'],
                        'production_home' => $specification['environment'] === 'production' ? $specification['path'] : null,
                        'production_user' => $specification['environment'] === 'production' ? $specification['user'] : null,
                        'source_is_laravel' => false,
                        'provisioning_step' => 'active',
                        'status' => 'active',
                    ]);
                    foreach ($profiles['baseline'] as $key => $value) {
                        $owner->environmentValues()->create(['env_key' => $key, 'env_value' => $value]);
                    }
                    $route = Route::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $node->id,
                        'hostname' => $specification['hostname'],
                        'provenance' => 'explicit',
                        'publication' => 'private',
                        'status' => 'pending',
                    ]);
                    $route->targets()->create(['app_instance_id' => $owner->id, 'position' => 0]);
                    $route->update(['status' => 'active']);
                }
            });
        })(),
        'cleanup' => (static function () use (&$failureStage): void {
            DB::transaction(static function () use (&$failureStage): void {
                $appIds = App::query()->whereIn('slug', ['orb212-development', 'orb212-production'])->pluck('id');
                $failureStage = 'cleanup-status';
                AppInstance::query()->whereIn('app_id', $appIds)->update(['status' => 'reserved']);
                $failureStage = 'cleanup-routes';
                Route::query()->whereIn('app_id', $appIds)->delete();
                $failureStage = 'cleanup-instances';
                AppInstance::query()->whereIn('app_id', $appIds)->delete();
                $failureStage = 'cleanup-apps';
                App::query()->whereIn('id', $appIds)->delete();
            });
        })(),
        'assert-clean' => (static function (): void {
            if (App::query()->whereIn('slug', ['orb212-development', 'orb212-production'])->exists()) {
                throw new RuntimeException('ORB-212 Gateway fixtures remain.');
            }
        })(),
        'node-ip' => (static function () use ($argv): void {
            echo Node::query()->where('name', $argv[2] ?? '')->sole()->wireguard_ip, "\n";
        })(),
        'id' => (static function () use ($argv, $instance): void {
            echo $instance($argv[2] ?? '')->id, "\n";
        })(),
        'hostname' => (static function () use ($argv, $instance): void {
            echo $instance($argv[2] ?? '')->app->routes()->sole()->hostname, "\n";
        })(),
        'describe' => (static function () use ($application, $argv, $instance, $specifications): void {
            $label = $argv[2] ?? '';
            $owner = $instance($label);
            $context = $application->make(AppInstanceEnvironmentContextResolver::class)->resolve($owner, true);
            $expected = $specifications[$label] ?? null;
            if (! is_array($expected) || $owner->app->root !== 'public' || $context->path !== $expected['path'] || $context->executionUser !== $expected['user'] || $context->node->name !== $expected['node']) {
                throw new RuntimeException('Unexpected ORB-212 placement.');
            }
            echo "{$label}:{$context->node->name}:{$context->executionUser}:{$context->path}\n";
        })(),
        'reset-values' => $resetValues($argv[2] ?? '', $argv[3] ?? ''),
        'delete-values' => $instance($argv[2] ?? '')->environmentValues()->delete(),
        'corrupt-values' => (static function () use ($argv, $instance): void {
            $owner = $instance($argv[2] ?? '');
            $owner->environmentValues()->delete();
            DB::table('app_instance_environment_values')->insert([
                'app_instance_id' => $owner->id,
                'env_key' => 'BROKEN',
                'env_value' => 'not-ciphertext',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        })(),
        'storage-digest' => (static function () use ($argv, $instance): void {
            $rows = DB::table('app_instance_environment_values')
                ->where('app_instance_id', $instance($argv[2] ?? '')->id)
                ->orderBy('env_key')->get(['env_key', 'env_value'])->toJson();
            echo hash('sha256', $rows), "\n";
        })(),
        'assert-no-activity-secret' => (static function () use ($argv): void {
            $needle = $argv[2] ?? '';
            if ($needle === '' || str_contains(Activity::query()->get()->toJson(), $needle)) {
                throw new RuntimeException('ORB-212 activity exposed a protected value.');
            }
        })(),
        default => throw new RuntimeException('Unknown ORB-212 fixture command.'),
    };
} catch (Throwable) {
    fwrite(STDERR, "ORB-212 fixture command failed: {$command}:{$failureStage}\n");
    exit(71);
}
