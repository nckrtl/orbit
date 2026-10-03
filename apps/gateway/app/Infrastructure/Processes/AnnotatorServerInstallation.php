<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\RemoteCommand;

/** Publishes immutable server files so other Instances can keep serving during installation. */
final readonly class AnnotatorServerInstallation
{
    public function __construct(private ?string $packagePath = null, private ?string $assetDirectory = null) {}

    public static function sourceDigest(string $source): string
    {
        $paths = [$source.'/package.json', $source.'/vite.config.ts', $source.'/tsconfig.json', $source.'/bun.lock'];
        foreach (['src', 'bin'] as $directory) {
            if (! is_dir($source.'/'.$directory)) {
                throw new ResourceOperationException('process.annotator_source_missing', 'The Gateway annotation sources are incomplete.');
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths);
        $digest = hash_init('sha256');
        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new ResourceOperationException('process.annotator_source_missing', 'The Gateway annotation sources are incomplete.');
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new ResourceOperationException('process.annotator_source_missing', 'The Gateway annotation sources are incomplete.');
            }
            hash_update($digest, substr($path, strlen($source) + 1)."\0".$contents."\0");
        }

        return hash_final($digest);
    }

    public function command(): RemoteCommand
    {
        $source = $this->packagePath ?? base_path('../../packages/agent-annotation');
        $assetDirectory = $this->assetDirectory ?? resource_path('annotator');
        $asset = $assetDirectory.'/inject.js.gz';
        $manifest = $assetDirectory.'/manifest.json';
        if (! is_file($asset) || ! is_file($manifest)) {
            throw new ResourceOperationException('process.annotator_asset_missing', 'Build and distribute the Gateway annotator asset with bin/annotator-build before installation.');
        }
        try {
            $metadata = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ResourceOperationException('process.annotator_asset_stale', 'The Gateway annotator asset manifest is invalid. Rebuild with bin/annotator-build.');
        }
        if (! is_array($metadata) || ($metadata['source_sha256'] ?? null) !== self::sourceDigest($source) || ($metadata['asset_sha256'] ?? null) !== hash_file('sha256', $asset)) {
            throw new ResourceOperationException('process.annotator_asset_stale', 'Rebuild the Gateway annotator asset with bin/annotator-build before installation.');
        }
        $injection = gzdecode((string) file_get_contents($asset));
        if (! is_string($injection) || $injection === '') {
            throw new ResourceOperationException('process.annotator_asset_missing', 'The Gateway annotator asset is incomplete.');
        }
        $files = glob($source.'/bin/*') ?: [];
        if ($files === [] || ! is_file($source.'/bin/serve.mjs')) {
            throw new ResourceOperationException('process.annotator_source_missing', 'The Gateway annotation server files are missing.');
        }
        $payload = ['dist/inject.js' => $injection];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new ResourceOperationException('process.annotator_source_missing', 'The Gateway annotation server file could not be read.');
            }
            $relative = str_starts_with($file, $source.'/bin/') ? 'bin/' : 'dist/';
            $payload[$relative.basename($file)] = $contents;
        }

        return new RemoteCommand(arguments: ['sudo', 'python3', '-c', <<<'PY'
            import fcntl, hashlib, json, os, pathlib, shutil, sys, tempfile
            root = pathlib.Path('/opt/orbit/annotator')
            root.mkdir(mode=0o755, parents=True, exist_ok=True)
            raw = sys.stdin.buffer.read()
            files = json.loads(raw)
            release = root / 'releases' / hashlib.sha256(raw).hexdigest()
            with (root / '.install.lock').open('a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                release.parent.mkdir(mode=0o755, exist_ok=True)
                if not release.exists():
                    stage = pathlib.Path(tempfile.mkdtemp(prefix='.pending-', dir=release.parent))
                    try:
                        stage.chmod(0o755)
                        for name, content in files.items():
                            path = pathlib.PurePosixPath(name)
                            if len(path.parts) != 2 or path.parts[0] not in ('bin', 'dist') or '..' in path.parts:
                                raise ValueError('Invalid annotator installation path')
                            target = stage / path
                            target.parent.mkdir(mode=0o755, exist_ok=True)
                            target.write_text(content, encoding='utf-8')
                            target.chmod(0o644)
                        stage.rename(release)
                    finally:
                        if stage.exists():
                            shutil.rmtree(stage)
                pending = root / '.current.pending'
                pending.unlink(missing_ok=True)
                pending.symlink_to(release, target_is_directory=True)
                os.replace(pending, root / 'current')
            PY], input: json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
