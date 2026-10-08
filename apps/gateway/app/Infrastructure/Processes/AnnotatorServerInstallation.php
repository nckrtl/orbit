<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\RemoteCommand;

/** Publishes immutable server files so other Instances can keep serving during installation. */
final readonly class AnnotatorServerInstallation
{
    /** The vendored files come from the @nckrtl/annotator release that bin/annotator-build copied. */
    public function __construct(private ?string $assetDirectory = null) {}

    public function command(): RemoteCommand
    {
        $assetDirectory = $this->assetDirectory ?? resource_path('annotator');
        $manifest = $assetDirectory.'/manifest.json';
        if (! is_file($manifest) || ! is_file($assetDirectory.'/inject.js.gz') || ! is_file($assetDirectory.'/bin/serve.mjs')) {
            throw new ResourceOperationException('process.annotator_asset_missing', 'Vendor the annotator release with bin/annotator-build before installation.');
        }
        try {
            $metadata = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ResourceOperationException('process.annotator_asset_stale', 'The vendored annotator manifest is invalid. Run bin/annotator-build.');
        }
        $digests = is_array($metadata) && is_array($metadata['files'] ?? null) ? $metadata['files'] : [];
        $vendored = array_map(
            fn (string $file): string => substr($file, strlen($assetDirectory) + 1),
            [...(glob($assetDirectory.'/bin/*') ?: []), $assetDirectory.'/inject.js.gz'],
        );
        sort($vendored);
        $listed = array_keys($digests);
        sort($listed);
        if ($vendored !== $listed) {
            throw new ResourceOperationException('process.annotator_asset_stale', 'The vendored annotator files do not match their manifest. Run bin/annotator-build.');
        }
        $payload = [];
        foreach ($digests as $name => $digest) {
            $contents = file_get_contents($assetDirectory.'/'.$name);
            if ($contents === false || hash('sha256', $contents) !== $digest) {
                throw new ResourceOperationException('process.annotator_asset_stale', 'A vendored annotator file changed after bin/annotator-build.');
            }
            if ($name === 'inject.js.gz') {
                $injection = gzdecode($contents);
                if (! is_string($injection) || $injection === '') {
                    throw new ResourceOperationException('process.annotator_asset_missing', 'The vendored annotator injection asset is incomplete.');
                }
                $payload['dist/inject.js'] = $injection;
            } else {
                $payload[$name] = $contents;
            }
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
