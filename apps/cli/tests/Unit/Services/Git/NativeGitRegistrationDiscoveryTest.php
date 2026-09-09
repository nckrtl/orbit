<?php

declare(strict_types=1);

use App\Services\Git\NativeGitRegistrationDiscovery;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('refuses a credential-bearing origin before returning displayable facts', function (): void {
    $directory = sys_get_temp_dir().'/orbit-cli-git-'.Str::uuid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($directory);

    try {
        foreach ([
            ['git', 'init', '--initial-branch=main', $directory],
            ['git', '-C', $directory, 'config', 'user.email', 'orb105@example.test'],
            ['git', '-C', $directory, 'config', 'user.name', 'ORB-105'],
            ['git', '-C', $directory, 'commit', '--allow-empty', '-m', 'Initial'],
            [
                'git',
                '-C',
                $directory,
                'remote',
                'add',
                'origin',
                'https://orb105-user:orb105-token@example.test/acme.git',
            ],
        ] as $command) {
            $process = new Process($command);
            $process->mustRun();
        }

        expect(new NativeGitRegistrationDiscovery()->inspect($directory))->toBeNull();
    } finally {
        $files->deleteDirectory($directory);
    }
});
