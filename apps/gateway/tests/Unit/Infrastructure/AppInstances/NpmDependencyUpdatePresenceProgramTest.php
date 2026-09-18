<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\NpmDependencyUpdatePresenceProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function npm_update_presence_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-npm-update-presence-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

function npm_update_presence_home(?string $vpScript = null, string $layout = 'xdg'): string
{
    $home = npm_update_presence_directory('-home');
    if ($vpScript !== null) {
        $bin = match ($layout) {
            'vite-plus' => $home.'/.vite-plus/bin',
            'vite-plus-current' => $home.'/.vite-plus/current/bin',
            'xdg-current' => $home.'/.local/share/vite-plus/current/bin',
            default => $home.'/.local/share/vite-plus/bin',
        };
        mkdir($bin, 0o700, true);
        file_put_contents($bin.'/vp', $vpScript);
        chmod($bin.'/vp', 0o700);
    }

    return $home;
}

/** @return array<string, mixed> */
function npm_update_presence(string $path, ?string $home = null): array
{
    $env = $home !== null ? ['HOME' => $home] : [];
    $process = new Process(['/usr/bin/python3', '-I', '-', $path], env: $env, timeout: 30);
    $process->setInput(NpmDependencyUpdatePresenceProgram::render());
    $process->mustRun();
    expect($process->getErrorOutput())->toBeEmpty();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function npm_update_presence_vp(string $versionLine = 'vp v0.3.0'): string
{
    return "#!/usr/bin/env bash\nif [ \"\${1:-}\" = '--version' ]; then printf '%s\\n' '".$versionLine."'; exit 0; fi\nexit 0\n";
}

describe('npm update presence probe', function (): void {
    it('reports absent, incomplete and present npm roots without executing files', function (): void {
        $root = npm_update_presence_directory();
        $home = npm_update_presence_home(npm_update_presence_vp());

        try {
            expect(npm_update_presence($root, $home))->toBe(['status' => 'absent']);

            file_put_contents($root.'/package.json', '{"scripts":{"preinstall":"touch NEVER"}}');
            expect(npm_update_presence($root, $home))->toBe(['status' => 'absent']);

            file_put_contents($root.'/package.json', '{"packageManager":"npm@11.19.0","scripts":{"preinstall":"touch NEVER"}}');
            expect(npm_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/package.json', '{"devEngines":{"packageManager":{"name":"npm","onFail":"warn"}}}');
            expect(npm_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            $present = npm_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');
            expect($present['vp']['path'])->toBe($home.'/.local/share/vite-plus/bin/vp');
            expect(file_exists($root.'/NEVER'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('reports an npm lock without its manifest as incomplete', function (): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root))->toBe(['status' => 'incomplete']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('requires a Vite+ 0.3.0 npm signal in addition to an npm lock', function (): void {
        $root = npm_update_presence_directory();
        $home = npm_update_presence_home(npm_update_presence_vp());

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/npm-shrinkwrap.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/package.json', '{"packageManager":"npm@11.19.0"}');
            $present = npm_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');

            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root, $home)['status'])->toBe('present');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('fails conflicting manager declarations and extra family signals before mutation', function (string $extra, string $error): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $extra === 'packageManager' ? '{"packageManager":"pnpm@10.33.0"}' : '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            if ($extra !== 'packageManager') {
                file_put_contents($root.'/'.$extra, '# other');
            }
            expect(npm_update_presence($root))->toBe(['error' => $error]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        ['packageManager', 'dependencies.ambiguous_manager'],
        ['.pnpmfile.cjs', 'dependencies.ambiguous_manager'],
        ['bunfig.toml', 'dependencies.ambiguous_manager'],
    ]);

    it('rejects package.json workspaces before mutation', function (): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"workspaces":["packages/*"]}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.unsupported_layout']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('treats pnpm and Bun projects without an npm lock as absent for this adapter', function (): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"pnpm@10.33.0"}');
            file_put_contents($root.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
            expect(npm_update_presence($root))->toBe(['status' => 'absent']);

            unlink($root.'/pnpm-lock.yaml');
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lockb', 'bun');
            expect(npm_update_presence($root))->toBe(['status' => 'absent']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('fails Yarn signals before mutation', function (string $signal): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            file_put_contents($root.'/'.$signal, '# yarn');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.unsupported_format']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with(['yarn.lock', '.yarnrc.yml', 'yarn.config.cjs']);

    it('fails workspace layouts and conflicting JavaScript locks before mutation', function (string $name, string $error): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            file_put_contents($root.'/'.$name, '# other');
            expect(npm_update_presence($root))->toBe(['error' => $error]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        ['pnpm-workspace.yaml', 'dependencies.unsupported_layout'],
        ['pnpm-lock.yaml', 'dependencies.ambiguous_manager'],
        ['bun.lock', 'dependencies.ambiguous_manager'],
        ['bun.lockb', 'dependencies.ambiguous_manager'],
    ]);

    it('rejects symlink and non-regular root files', function (): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/target.json', '{}');
            symlink('target.json', $root.'/package.json');
            file_put_contents($root.'/package-lock.json', '{}');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);

            unlink($root.'/package.json');
            unlink($root.'/package-lock.json');
            mkdir($root.'/package-lock.json');
            file_put_contents($root.'/package.json', '{}');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects relative and symlinked roots', function (): void {
        expect(npm_update_presence('relative/root'))->toBe(['error' => 'dependencies.unsafe_source']);

        $root = npm_update_presence_directory();
        $link = npm_update_presence_directory().'-link';

        try {
            symlink($root, $link);
            expect(npm_update_presence($link))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            unlink($link);
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('verifies Vite+ from each Orbit-managed user installation layout', function (string $layout, string $suffix): void {
        $root = npm_update_presence_directory();
        $home = npm_update_presence_home(npm_update_presence_vp(), $layout);

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            $present = npm_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');
            expect($present['vp']['path'])->toBe($home.$suffix);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    })->with([
        'user .vite-plus home' => ['vite-plus', '/.vite-plus/bin/vp'],
        'user .vite-plus current' => ['vite-plus-current', '/.vite-plus/current/bin/vp'],
        'XDG vite-plus home' => ['xdg', '/.local/share/vite-plus/bin/vp'],
        'XDG vite-plus current' => ['xdg-current', '/.local/share/vite-plus/current/bin/vp'],
    ]);

    it('prefers the Node-prerequisite Vite+ home order', function (): void {
        $root = npm_update_presence_directory();
        $home = npm_update_presence_home(npm_update_presence_vp('vp v0.3.0'), 'vite-plus');
        $xdg = $home.'/.local/share/vite-plus/bin';
        mkdir($xdg, 0o700, true);
        file_put_contents($xdg.'/vp', npm_update_presence_vp('vp v0.9.9'));
        chmod($xdg.'/vp', 0o700);

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            $present = npm_update_presence($root, $home);
            expect($present['vp']['path'])->toBe($home.'/.vite-plus/bin/vp');
            expect($present['vp']['version'])->toBe('0.3.0');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('rejects duplicate package.json object keys before manager selection', function (string $manifest): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $manifest);
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.invalid_manifest']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","packageManager":"npm@11.19.0"}'],
        'escaped duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","package\u004danager":"npm@11.19.0"}'],
        'duplicate workspaces conceals a workspace' => ['{"workspaces":["packages/*"],"workspaces":[]}'],
        'escaped duplicate workspaces' => ['{"workspaces":["packages/*"],"workspace\u0073":[]}'],
        'nested duplicate packageManager' => ['{"devEngines":{"packageManager":{"name":"yarn"},"packageManager":{"name":"npm"}}}'],
    ]);

    it('rejects an unreadable manifest when checking npm declarations', function (): void {
        $root = npm_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{invalid');
            expect(npm_update_presence($root))->toBe(['error' => 'dependencies.invalid_manifest']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('reports a missing, unexecutable or unreadable Vite+ installation as no delegation', function (callable $home): void {
        $root = npm_update_presence_directory();
        $homePath = $home();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(npm_update_presence($root, $homePath))->toBe(['status' => 'present', 'vp' => null]);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($homePath);
        }
    })->with([
        'vp is absent' => fn (): string => npm_update_presence_home(),
        'vp is not executable' => function (): string {
            $home = npm_update_presence_home(npm_update_presence_vp());
            chmod($home.'/.local/share/vite-plus/bin/vp', 0o600);

            return $home;
        },
        'vp prints an unparseable version' => fn (): string => npm_update_presence_home(npm_update_presence_vp('vite-plus')),
        'vp fails its version query' => fn (): string => npm_update_presence_home("#!/usr/bin/env bash\nexit 1\n"),
    ]);
});
