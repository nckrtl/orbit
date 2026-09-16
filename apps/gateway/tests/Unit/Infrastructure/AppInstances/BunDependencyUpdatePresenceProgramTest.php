<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\BunDependencyUpdatePresenceProgram;
use App\Infrastructure\AppInstances\NpmDependencyUpdatePresenceProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function bun_update_presence_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-bun-update-presence-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

function bun_update_presence_home(?string $vpScript = null, string $layout = 'xdg'): string
{
    $home = bun_update_presence_directory('-home');
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
function bun_update_presence(string $path, ?string $home = null): array
{
    $env = $home !== null ? ['HOME' => $home] : [];
    $process = new Process(['/usr/bin/python3', '-I', '-', $path], env: $env, timeout: 30);
    $process->setInput(BunDependencyUpdatePresenceProgram::render());
    $process->mustRun();
    expect($process->getErrorOutput())->toBeEmpty();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function bun_update_presence_vp(string $versionLine = 'vp v0.3.0'): string
{
    return "#!/usr/bin/env bash\nif [ \"\${1:-}\" = '--version' ]; then printf '%s\\n' '".$versionLine."'; exit 0; fi\nexit 0\n";
}

describe('bun update presence probe', function (): void {
    it('reports absent, incomplete and present bun roots without executing files', function (): void {
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp());

        try {
            expect(bun_update_presence($root, $home))->toBe(['status' => 'absent']);

            file_put_contents($root.'/package.json', '{"scripts":{"preinstall":"touch NEVER"}}');
            expect(bun_update_presence($root, $home))->toBe(['status' => 'absent']);

            file_put_contents($root.'/package.json', '{"packageManager":"bun@1.3.14","scripts":{"preinstall":"touch NEVER"}}');
            expect(bun_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/package.json', '{"devEngines":{"packageManager":{"name":"bun","onFail":"warn"}}}');
            expect(bun_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            $present = bun_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');
            expect($present['vp']['path'])->toBe($home.'/.local/share/vite-plus/bin/vp');
            expect(file_exists($root.'/NEVER'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('reports a bun lock without its manifest as incomplete', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            expect(bun_update_presence($root))->toBe(['status' => 'incomplete']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('requires a text bun.lock in addition to a bun declaration', function (): void {
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp());

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bunfig.toml', 'logLevel = "debug"\n');
            expect(bun_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/package.json', '{"packageManager":"bun@1.3.14"}');
            expect(bun_update_presence($root, $home))->toBe(['status' => 'incomplete']);

            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            $present = bun_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('reports bunfig.toml without a text lock as incomplete', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bunfig.toml', 'logLevel = "debug"\n');
            expect(bun_update_presence($root))->toBe(['status' => 'incomplete']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('fails conflicting manager declarations and extra family signals before mutation', function (string $extra, string $error): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $extra === 'packageManager' ? '{"packageManager":"npm@11.19.0"}' : '{}');
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            if ($extra !== 'packageManager') {
                file_put_contents($root.'/'.$extra, '# other');
            }
            expect(bun_update_presence($root))->toBe(['error' => $error]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        ['packageManager', 'dependencies.ambiguous_manager'],
        ['package-lock.json', 'dependencies.ambiguous_manager'],
        ['pnpm-lock.yaml', 'dependencies.ambiguous_manager'],
    ]);

    it('rejects package.json workspaces before mutation', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"workspaces":["packages/*"]}');
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.unsupported_layout']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('leaves the npm adapter absent for a present bun root', function (): void {
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp());

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"bun@1.3.14"}');
            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            expect(bun_update_presence($root, $home)['status'])->toBe('present');

            $process = new Process(['/usr/bin/python3', '-I', '-', $root], env: ['HOME' => $home], timeout: 30);
            $process->setInput(NpmDependencyUpdatePresenceProgram::render());
            $process->mustRun();
            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 'absent']);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('treats npm and pnpm projects as absent for this adapter', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"npm@11.19.0"}');
            file_put_contents($root.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
            expect(bun_update_presence($root))->toBe(['status' => 'absent']);

            unlink($root.'/package-lock.json');
            file_put_contents($root.'/package.json', '{"packageManager":"pnpm@10.33.0"}');
            file_put_contents($root.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
            expect(bun_update_presence($root))->toBe(['status' => 'absent']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('fails Yarn signals before mutation', function (string $signal): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            file_put_contents($root.'/'.$signal, '# yarn');
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.unsupported_format']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with(['yarn.lock', '.yarnrc.yml', 'yarn.config.cjs']);

    it('fails workspace layouts and conflicting JavaScript locks before mutation', function (string $name, string $error): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            file_put_contents($root.'/'.$name, '# other');
            expect(bun_update_presence($root))->toBe(['error' => $error]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        ['pnpm-workspace.yaml', 'dependencies.unsupported_layout'],
        ['package-lock.json', 'dependencies.ambiguous_manager'],
        ['pnpm-lock.yaml', 'dependencies.ambiguous_manager'],
    ]);

    it('fails binary-only bun.lockb before mutation', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"bun@1.3.14"}');
            file_put_contents($root.'/bun.lockb', 'bun');
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.unsupported_format']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('accepts a text bun.lock when a binary lock also exists', function (): void {
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp());

        try {
            file_put_contents($root.'/package.json', '{"packageManager":"bun@1.3.14"}');
            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            file_put_contents($root.'/bun.lockb', 'bun');
            $present = bun_update_presence($root, $home);
            expect($present['status'])->toBe('present');
            expect($present['vp']['version'])->toBe('0.3.0');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('rejects symlink and non-regular root files', function (): void {

        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/target.json', '{}');
            symlink('target.json', $root.'/package.json');
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);

            unlink($root.'/package.json');
            unlink($root.'/bun.lock');
            mkdir($root.'/bun.lock');
            file_put_contents($root.'/package.json', '{}');
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects relative and symlinked roots', function (): void {
        expect(bun_update_presence('relative/root'))->toBe(['error' => 'dependencies.unsafe_source']);

        $root = bun_update_presence_directory();
        $link = bun_update_presence_directory().'-link';

        try {
            symlink($root, $link);
            expect(bun_update_presence($link))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            unlink($link);
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('verifies Vite+ from each Orbit-managed user installation layout', function (string $layout, string $suffix): void {
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp(), $layout);

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            $present = bun_update_presence($root, $home);
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
        $root = bun_update_presence_directory();
        $home = bun_update_presence_home(bun_update_presence_vp('vp v0.3.0'), 'vite-plus');
        $xdg = $home.'/.local/share/vite-plus/bin';
        mkdir($xdg, 0o700, true);
        file_put_contents($xdg.'/vp', bun_update_presence_vp('vp v0.9.9'));
        chmod($xdg.'/vp', 0o700);

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            $present = bun_update_presence($root, $home);
            expect($present['vp']['path'])->toBe($home.'/.vite-plus/bin/vp');
            expect($present['vp']['version'])->toBe('0.3.0');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('rejects duplicate package.json object keys before manager selection', function (string $manifest): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', $manifest);
            file_put_contents($root.'/bun.lock', "{\"lockfileVersion\":1}\n");
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.invalid_manifest']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        'duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","packageManager":"bun@1.3.14"}'],
        'escaped duplicate packageManager conceals Yarn' => ['{"packageManager":"yarn@1.22.22","package\u004danager":"bun@1.3.14"}'],
        'duplicate workspaces conceals a workspace' => ['{"workspaces":["packages/*"],"workspaces":[]}'],
        'escaped duplicate workspaces' => ['{"workspaces":["packages/*"],"workspace\u0073":[]}'],
        'nested duplicate packageManager' => ['{"devEngines":{"packageManager":{"name":"yarn"},"packageManager":{"name":"bun"}}}'],
    ]);

    it('rejects an unreadable manifest when checking bun declarations', function (): void {
        $root = bun_update_presence_directory();

        try {
            file_put_contents($root.'/package.json', '{invalid');
            expect(bun_update_presence($root))->toBe(['error' => 'dependencies.invalid_manifest']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('reports a missing, unexecutable or unreadable Vite+ installation as no delegation', function (callable $home): void {
        $root = bun_update_presence_directory();
        $homePath = $home();

        try {
            file_put_contents($root.'/package.json', '{}');
            file_put_contents($root.'/bun.lock', "{\n  \"lockfileVersion\": 1,\n  \"workspaces\": {\"\": {}},\n  \"packages\": {}\n}\n");
            expect(bun_update_presence($root, $homePath))->toBe(['status' => 'present', 'vp' => null]);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($homePath);
        }
    })->with([
        'vp is absent' => fn (): string => bun_update_presence_home(),
        'vp is not executable' => function (): string {
            $home = bun_update_presence_home(bun_update_presence_vp());
            chmod($home.'/.local/share/vite-plus/bin/vp', 0o600);

            return $home;
        },
        'vp prints an unparseable version' => fn (): string => bun_update_presence_home(bun_update_presence_vp('vite-plus')),
        'vp fails its version query' => fn (): string => bun_update_presence_home("#!/usr/bin/env bash\nexit 1\n"),
    ]);
});
