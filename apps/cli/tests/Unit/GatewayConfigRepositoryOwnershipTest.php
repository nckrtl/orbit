<?php

declare(strict_types=1);

namespace App\Repositories {
    function fileowner(string $path): int|false
    {
        if (str_contains($path, 'foreign-owner')) {
            return posix_geteuid() + 1;
        }

        return \fileowner($path);
    }
}

namespace {
    use App\Exceptions\GatewayConfigException;
    use App\Repositories\GatewayConfigRepository;
    use Illuminate\Filesystem\Filesystem;
    use Illuminate\Support\Str;

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->configDirectory);
    });

    it('rejects a private gateway configuration owned by another user', function (): void {
        $this->configDirectory = sys_get_temp_dir().'/orbit-cli-foreign-owner-'.Str::uuid();
        $configPath = $this->configDirectory.'/config.json';
        mkdir(directory: $this->configDirectory, permissions: 0o700, recursive: true);
        file_put_contents(filename: $configPath, data: '{"active_gateway":null,"gateways":{}}');
        chmod(filename: $configPath, permissions: 0o600);

        expect(new GatewayConfigRepository($configPath)->active(...))
            ->toThrow(GatewayConfigException::class, 'Orbit gateway configuration is not private.');
    });
}
