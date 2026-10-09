<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/**
 * Writes `APP_URL` into the stable `.env` of another application directory of a production Instance,
 * `<home>/env/<directory>/.env`, which each release links to. It reads `artisan` and `.env.example`
 * from the selected release, and does nothing before the first release or for a directory without
 * `artisan`. A new file gets its own key. A cached configuration in a release keeps its value until
 * the next deployment rebuilds it.
 */
final readonly class RemoteProductionRouteApplicationUrlWriter implements RouteApplicationUrlWriter
{
    public function __construct(
        private ProductionSshExecutor $ssh,
    ) {}

    public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
    {
        $instance->loadMissing(['node', 'project']);
        $user = $instance->production_user;
        $home = $instance->production_home;

        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== "/home/{$user}"
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            throw new ResourceOperationException('app-prod.web_root_invalid', 'The production Instance identity is incomplete.', 409);
        }

        $directory = $relativeDirectory === '' ? '' : RelativeWebRoot::validate($relativeDirectory);
        $storedName = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
        $storedName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{') ? $storedName : null;
        $settings = json_encode([
            'url' => $url,
            'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote(LaravelApplicationKey::generate()),
            'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($storedName ?? $instance->project->name),
            'app_name_stored' => $storedName !== null,
        ], JSON_THROW_ON_ERROR);

        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['sudo', '-u', $user, '-H', 'python3', '-c', <<<'PYTHON'
                    import json, os, pathlib, re, sys, tempfile

                    home = pathlib.Path(sys.argv[1])
                    directory = sys.argv[2]
                    settings = json.load(sys.stdin)
                    if home.resolve(strict=True) != home:
                        raise SystemExit(42)
                    current = home / 'current'
                    if not current.is_symlink():
                        raise SystemExit(0)
                    release = current.resolve(strict=True)
                    if release.parent != home / 'releases':
                        raise SystemExit(42)
                    application = release / directory if directory else release
                    if application.resolve(strict=True) != application:
                        raise SystemExit(42)
                    artisan = application / 'artisan'
                    if artisan.is_symlink() or not artisan.is_file():
                        raise SystemExit(0)

                    def safe_regular(path):
                        if path.is_symlink() or (path.exists() and not path.is_file()):
                            raise SystemExit(42)
                        if path.exists() and path.stat().st_uid != os.getuid():
                            raise SystemExit(42)

                    stable = home / 'env'
                    for part in [''] + (directory.split('/') if directory else []):
                        stable = stable / part if part else stable
                        if stable.is_symlink() or (stable.exists() and not stable.is_dir()):
                            raise SystemExit(42)
                        if not stable.exists():
                            stable.mkdir(mode=0o700)
                        if stable.stat().st_uid != os.getuid():
                            raise SystemExit(42)

                    def entries(text, key):
                        return list(re.finditer(rb'(?m)^' + key + rb'=([^\r\n]*)', text))

                    def plain(found):
                        value = found[0].group(1).strip() if found else b''
                        if len(value) >= 2 and value[:1] == value[-1:] and value[:1] in (b'"', b"'"): value = value[1:-1]
                        return value

                    def put(text, found, line):
                        if found: return text[:found[0].start()] + line + text[found[0].end():]
                        separator = b'' if text == b'' or text.endswith(b'\n') else b'\n'
                        return text + separator + line + b'\n'

                    env = stable / '.env'
                    safe_regular(env)
                    created = not env.exists()
                    if created:
                        template = application / '.env.example'
                        if template.is_symlink() or (template.exists() and not template.is_file()):
                            raise SystemExit(42)
                        original = template.read_bytes() if template.exists() else b''
                        mode = 0o600
                    else:
                        original = env.read_bytes()
                        mode = env.stat().st_mode & 0o770
                    found = entries(original, b'APP_URL')
                    if len(found) > 1: raise SystemExit(42)
                    updated = put(original, found, ('APP_URL=' + settings['url']).encode())
                    # Laravel cannot boot with an empty key. A new file keeps the template's key or takes a
                    # generated one; an existing file only fills an empty key.
                    found = entries(updated, b'APP_KEY')
                    if len(found) < 2 and ((created and plain(found) == b'') or (found and plain(found) == b'')):
                        updated = put(updated, found, settings['app_key'].encode())
                    found = entries(updated, b'APP_NAME')
                    if created and len(found) < 2 and (settings['app_name_stored'] or plain(found) in (b'', b'Laravel')):
                        updated = put(updated, found, settings['app_name'].encode())
                    if updated != original or created:
                        fd, candidate = tempfile.mkstemp(prefix='.orbit-url-', dir=stable)
                        try:
                            os.fchmod(fd, mode)
                            with os.fdopen(fd, 'wb') as stream: stream.write(updated)
                            os.replace(candidate, env)
                        finally:
                            if os.path.exists(candidate): os.unlink(candidate)
                    elif env.stat().st_mode & 0o007:
                        os.chmod(env, mode)
                    PYTHON, $home, $directory],
                protectedInput: ProtectedInput::fromString($settings),
            ),
            'app-prod-laravel-url',
            'app-prod.laravel_url_configuration_failed',
        );
    }
}
