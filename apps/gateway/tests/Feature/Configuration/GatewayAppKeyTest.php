<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function loadAppConfigWith(array $environment): array
{
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** @var array<string, mixed> $config */
    $config = require base_path('config/app.php');

    foreach ($previous as $name => $value) {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    return $config;
}

function temporaryOrbitHome(): string
{
    $home = storage_path('framework/testing/orbit-home-'.Str::uuid());
    File::ensureDirectoryExists($home);

    return $home;
}

it('reads the Gateway encryption key from ORBIT_HOME when APP_KEY is unset', function (): void {
    $home = temporaryOrbitHome();
    $storedKey = 'base64:'.base64_encode(random_bytes(32));
    File::put("{$home}/gateway.app-key", "{$storedKey}\n");

    $config = loadAppConfigWith(['APP_KEY' => null, 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBe($storedKey);

    File::deleteDirectory($home);
});

it('prefers an explicit APP_KEY over the stored Gateway key', function (): void {
    $home = temporaryOrbitHome();
    File::put("{$home}/gateway.app-key", "base64:stored\n");

    $config = loadAppConfigWith(['APP_KEY' => 'base64:explicit', 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBe('base64:explicit');

    File::deleteDirectory($home);
});

it('leaves the key unset without APP_KEY or a stored Gateway key', function (): void {
    $home = temporaryOrbitHome();

    $config = loadAppConfigWith(['APP_KEY' => null, 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBeNull();

    File::deleteDirectory($home);
});
