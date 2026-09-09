<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$gateway = '/home/orbit/orbit/apps/gateway';
require $gateway.'/vendor/autoload.php';
$application = require $gateway.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$specifications = [
    'placement-dev' => [
        'group' => 'placement',
        'node' => 'app-dev',
        'environment' => 'development',
        'path' => '/home/orbit/orb207-placement-dev',
        'user' => null,
        'hostname' => 'orb207-placement-dev.orbit',
        'values' => [
            'DEV_LITERAL' => 'dev-import',
            'EXPANDED' => 'dev-import-expanded',
        ],
    ],
    'placement-prod' => [
        'group' => 'placement',
        'node' => 'app-prod',
        'environment' => 'production',
        'path' => '/home/orbit-app-orb207-placement',
        'user' => 'orbit-app-orb207-placement',
        'hostname' => 'orb207-placement-prod.orbit',
        'values' => ['PROD_LITERAL' => 'prod-import'],
    ],
    'store-dev' => [
        'group' => 'store',
        'node' => 'app-dev',
        'environment' => 'development',
        'path' => '/home/orbit/orb207-store-dev',
        'user' => null,
        'hostname' => 'orb207-store-dev.orbit',
        'values' => [
            'DEV_FILE' => 'dev-store',
            'UPDATED' => 'dev-offline',
        ],
    ],
    'store-prod' => [
        'group' => 'store',
        'node' => 'app-prod',
        'environment' => 'production',
        'path' => '/home/orbit-app-orb207-store',
        'user' => 'orbit-app-orb207-store',
        'hostname' => 'orb207-store-prod.orbit',
        'values' => [
            'PROD_FILE' => 'prod-store',
            'UPDATED' => 'prod-offline',
        ],
    ],
];

$command = $argv[1] ?? '';
$failureStage = 'dispatch';

$labels = static function (string $group) use ($specifications): array {
    if ($group === 'all') {
        return array_keys($specifications);
    }

    return array_keys(array_filter(
        $specifications,
        static fn (array $specification): bool => $specification['group'] === $group,
    ));
};

$cleanup = static function (string $group) use ($labels, &$failureStage): void {
    $slugs = array_map(static fn (string $label): string => "orb207-{$label}", $labels($group));
    $appIds = App::query()->whereIn('slug', $slugs)->pluck('id');

    $failureStage = 'cleanup-status';
    AppInstance::query()->whereIn('app_id', $appIds)->update(['status' => 'reserved']);
    $failureStage = 'cleanup-routes';
    Route::query()->whereIn('app_id', $appIds)->delete();
    $failureStage = 'cleanup-instances';
    AppInstance::query()->whereIn('app_id', $appIds)->delete();
    $failureStage = 'cleanup-apps';
    App::query()->whereIn('id', $appIds)->delete();
};

$instance = static function (string $label) use ($specifications): AppInstance {
    if (! array_key_exists($label, $specifications)) {
        throw new RuntimeException("Unknown fixture label: {$label}");
    }

    return AppInstance::query()
        ->whereHas('app', static fn ($query) => $query->where('slug', "orb207-{$label}"))
        ->sole();
};

try {
    match ($command) {
    'setup' => (static function () use ($cleanup, $specifications, &$failureStage): void {
        DB::transaction(static function () use ($cleanup, $specifications, &$failureStage): void {
            $cleanup('all');

            foreach ($specifications as $label => $specification) {
                $failureStage = "seed-{$label}";
                $node = Node::query()->where('name', $specification['node'])->sole();
                $app = App::query()->create([
                    'name' => "ORB-207 {$label}",
                    'slug' => "orb207-{$label}",
                    'repository_url' => "https://example.test/orb207-{$label}.git",
                    'default_branch' => 'main',
                    'root' => 'public',
                ]);
                $appInstance = AppInstance::query()->create([
                    'app_id' => $app->id,
                    'node_id' => $node->id,
                    'name' => 'default',
                    'environment' => $specification['environment'],
                    'checkout_path' => $specification['path'],
                    'production_user' => $specification['user'],
                    'production_home' => $specification['environment'] === 'production'
                        ? $specification['path']
                        : null,
                    'source_is_laravel' => false,
                    'provisioning_step' => 'active',
                    'status' => 'active',
                ]);
                $route = Route::query()->create([
                    'app_id' => $app->id,
                    'node_id' => $node->id,
                    'hostname' => $specification['hostname'],
                    'provenance' => 'explicit',
                    'publication' => 'private',
                    'status' => 'pending',
                ]);
                $route->targets()->create([
                    'app_instance_id' => $appInstance->id,
                    'position' => 0,
                ]);
                $route->update(['status' => 'active']);
            }
        });

        echo "ORB-207 Gateway fixtures ready\n";
    })(),
    'id' => (static function () use ($instance, $argv): void {
        echo $instance($argv[2] ?? '')->id, "\n";
    })(),
    'node-ip' => (static function () use ($argv): void {
        echo Node::query()->where('name', $argv[2] ?? '')->sole()->wireguard_ip, "\n";
    })(),
    'set-path' => (static function () use ($instance, $argv): void {
        $owner = $instance($argv[2] ?? '');
        $path = $argv[3] ?? '';
        $attributes = ['checkout_path' => $path];

        if ($owner->environment === 'production') {
            $attributes['production_home'] = $path;
        }

        $owner->update($attributes);
    })(),
    'set-node-user' => (static function () use ($argv): void {
        Node::query()->where('name', $argv[2] ?? '')->sole()->update(['user' => $argv[3] ?? '']);
    })(),
    'set-node-status' => (static function () use ($argv): void {
        Node::query()->where('name', $argv[2] ?? '')->sole()->update(['status' => $argv[3] ?? '']);
    })(),
    'assert-values' => (static function () use ($instance, $specifications, $argv): void {
        $label = $argv[2] ?? '';
        $owner = $instance($label);
        $expected = $specifications[$label]['values'];
        $stored = $owner->environmentValues()->orderBy('env_key')->get()->keyBy('env_key');

        if ($stored->count() !== count($expected)) {
            throw new RuntimeException("Unexpected stored key count for {$label}");
        }

        foreach ($expected as $key => $value) {
            $row = $stored->get($key);

            if (! $row instanceof AppInstanceEnvironmentValue || $row->env_value !== $value) {
                throw new RuntimeException("Unexpected decrypted value for {$label}:{$key}");
            }

            $raw = DB::table('app_instance_environment_values')->where('id', $row->id)->value('env_value');

            if (! is_string($raw) || $raw === $value || str_contains($raw, $value)) {
                throw new RuntimeException("Value was not encrypted for {$label}:{$key}");
            }
        }

        echo "{$label}: ", count($expected), " encrypted values verified\n";
    })(),
    'cleanup' => (static function () use ($cleanup, $argv): void {
        $group = $argv[2] ?? '';
        $cleanup($group);
        echo "ORB-207 {$group} Gateway fixtures removed\n";
    })(),
    'assert-clean' => (static function () use ($labels, $argv): void {
        $slugs = array_map(
            static fn (string $label): string => "orb207-{$label}",
            $labels($argv[2] ?? ''),
        );

        if (App::query()->whereIn('slug', $slugs)->exists()) {
            throw new RuntimeException('ORB-207 Gateway fixtures remain');
        }

        echo "ORB-207 Gateway fixture cleanup verified\n";
    })(),
        default => throw new RuntimeException("Unknown ORB-207 fixture command: {$command}"),
    };
} catch (Throwable) {
    fwrite(STDERR, "ORB-207 fixture command failed: {$command}:{$failureStage}\n");
    exit(70);
}
