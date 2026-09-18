<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\YarnDependencyUpdatePresenceProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function yarn_update_presence_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-yarn-update-presence-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

/** @return array<string, mixed> */
function yarn_update_presence(string $path, ?string $home = null): array
{
    $env = $home !== null ? ['HOME' => $home] : [];
    $process = new Process(['/usr/bin/python3', '-I', '-', $path], env: $env, timeout: 30);
    $process->setInput(YarnDependencyUpdatePresenceProgram::render());
    $process->mustRun();
    expect($process->getErrorOutput())->toBeEmpty();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

describe('Yarn update presence probe', function (): void {
    it('reports absent npm, pnpm and empty roots without executing files', function (): void {
        $root = yarn_update_presence_directory();
        $home = yarn_update_presence_directory('-home');
        mkdir($home.'/.local/share/vite-plus/bin', 0o700, true);
        file_put_contents($home.'/.local/share/vite-plus/bin/vp', "#!/usr/bin/env bash\ntouch \"$root/VP-RAN\"\nprintf 'vp v0.3.0\\n'\n");
        chmod($home.'/.local/share/vite-plus/bin/vp', 0o700);

        try {
            expect(yarn_update_presence($root, $home))->toBe(['status' => 'absent']);

            file_put_contents($root.'/package.json', '{"scripts":{"preinstall":"touch NEVER"},"packageManager":"npm@11.19.0"}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(yarn_update_presence($root, $home))->toBe(['status' => 'absent']);

            unlink($root.'/package-lock.json');
            file_put_contents($root.'/package.json', '{"packageManager":"pnpm@10.33.0"}');
            file_put_contents($root.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
            expect(yarn_update_presence($root, $home))->toBe(['status' => 'absent']);
            expect(file_exists($root.'/NEVER'))->toBeFalse();
            expect(file_exists($root.'/VP-RAN'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('classifies Classic yarn.lock and yarn 1 declarations as Classic Yarn', function (string $manifest, string $lock): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $manifest);
            file_put_contents($root.'/yarn.lock', $lock);
            expect(yarn_update_presence($root))->toBe(['status' => 'present', 'family' => 'classic']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'lockfile v1' => ['{"scripts":{"preinstall":"touch NEVER"}}', "# yarn lockfile v1\n\none@^1:\n  version \"1.0.0\"\n"],
        'yarn 1 packageManager' => ['{"packageManager":"yarn@1.22.22"}', "# yarn lockfile v1\n"],
    ]);

    it('classifies modern metadata locks and Yarn 2+ declarations as modern Yarn', function (string $manifest, array $files): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $manifest);
            foreach ($files as $name => $contents) {
                file_put_contents($root.'/'.$name, $contents);
            }
            expect(yarn_update_presence($root))->toBe(['status' => 'present', 'family' => 'modern']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'metadata lock' => ['{}', ['yarn.lock' => "__metadata:\n  version: 8\n"]],
        'yarn 4 packageManager' => ['{"packageManager":"yarn@4.9.2"}', ['yarn.lock' => "# yarn lockfile v1\n"]],
        'yarnrc.yml' => ['{}', ['.yarnrc.yml' => "nodeLinker: node-modules\n"]],
        'yarn.config.cjs' => ['{}', ['yarn.config.cjs' => 'module.exports = {};']],
        'devEngines yarn 3' => ['{"devEngines":{"packageManager":{"name":"yarn","version":"3.6.0"}}}', []],
    ]);

    it('prefers modern when Classic and modern signals both exist', function (): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"yarn@1.22.22"}');
            file_put_contents($root.'/yarn.lock', "# yarn lockfile v1\n");
            file_put_contents($root.'/.yarnrc.yml', "nodeLinker: node-modules\n");
            expect(yarn_update_presence($root))->toBe(['status' => 'present', 'family' => 'modern']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('treats a Yarn lock without package.json as present Yarn', function (): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/yarn.lock', "# yarn lockfile v1\n");
            expect(yarn_update_presence($root))->toBe(['status' => 'present', 'family' => 'classic']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('fails workspace layouts before family selection', function (string $name, string $contents): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $name === 'workspaces' ? '{"workspaces":["packages/*"]}' : '{}');
            if ($name !== 'workspaces') {
                file_put_contents($root.'/'.$name, $contents);
            }
            file_put_contents($root.'/yarn.lock', "# yarn lockfile v1\n");
            expect(yarn_update_presence($root))->toBe(['error' => 'dependencies.unsupported_layout']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'manifest workspaces' => ['workspaces', ''],
        'pnpm-workspace.yaml' => ['pnpm-workspace.yaml', "packages:\n  - packages/*\n"],
    ]);

    it('rejects duplicate package.json keys before family selection', function (string $manifest): void {
        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $manifest);
            file_put_contents($root.'/yarn.lock', "# yarn lockfile v1\n");
            expect(yarn_update_presence($root))->toBe(['error' => 'dependencies.invalid_manifest']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","packageManager":"npm@11.19.0"}'],
        'escaped duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","package\u004danager":"npm@11.19.0"}'],
    ]);

    it('rejects symlink root files and relative roots', function (): void {
        expect(yarn_update_presence('relative/root'))->toBe(['error' => 'dependencies.unsafe_source']);

        $root = yarn_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/target.lock', "# yarn lockfile v1\n");
            symlink('target.lock', $root.'/yarn.lock');
            expect(yarn_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});
