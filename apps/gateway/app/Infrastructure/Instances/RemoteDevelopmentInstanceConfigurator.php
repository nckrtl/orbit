<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;

final readonly class RemoteDevelopmentInstanceConfigurator implements DevelopmentInstanceConfigurator
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private ComposerSourceClassifier $classifier,
    ) {}

    public function inspect(Instance $instance, ?string $app = null): DevelopmentSourceProfile
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing(['project', 'node']);

        $configuration = $instance->appConfiguration($app);

        return $this->inspectConfiguration($instance, $configuration);
    }

    /** @param array{name: string, path: string, web_root: ?string, type: string} $configuration */
    public function inspectConfiguration(Instance $instance, array $configuration, ?string $checkoutPath = null): DevelopmentSourceProfile
    {
        $instance->loadMissing(['project', 'node']);
        $app = $configuration['name'];
        $type = ProjectType::from($configuration['type']);
        if ($type === ProjectType::Monorepo && ! $instance->routes()->where('routes.app', $app)->exists()) {
            return new DevelopmentSourceProfile(null, false);
        }

        $account = $this->accounts->resolve($instance->node);
        $checkoutPath ??= $instance->placedOnAppProd() && is_string($instance->production_home) ? $instance->production_home.'/current' : $instance->checkout_path;
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', ApplicationDirectory::resolvePath($checkoutPath, $configuration['path']), $account->user, $type->frameworkEntryPoint()],
                input: <<<'BASH'
                    checkout=$1
                    managed_user=$2
                    entry_point=$3
                    if [ -d "$checkout" ] && [ "$(realpath -e -- "$checkout")" != "$checkout" ]; then
                        printf 'UNSAFE\n'
                        exit 0
                    fi
                    composer="$checkout/composer.json"
                    artisan="$checkout/$entry_point"

                    if [ -L "$composer" ] || { [ -e "$composer" ] && [ ! -f "$composer" ]; }; then
                        printf 'UNSAFE\n'
                        exit 0
                    fi
                    if [ ! -e "$composer" ]; then
                        if [ -e "$artisan" ] || [ -L "$artisan" ]; then printf 'PARTIAL\n'; else printf 'NONE\n'; fi
                        exit 0
                    fi
                    test "$(stat -c %U -- "$composer")" = "$managed_user" || { printf 'UNSAFE\n'; exit 0; }
                    if [ -L "$artisan" ]; then artisan_kind=unsafe
                    elif [ -f "$artisan" ]; then artisan_kind=regular
                    elif [ -e "$artisan" ]; then artisan_kind=unsafe
                    else artisan_kind=absent
                    fi
                    printf 'COMPOSER\t%s\t' "$artisan_kind"
                    base64 --wrap=0 -- "$composer"
                    printf '\n'
                    BASH,
            ),
            step: 'app-instance-source-classify',
            errorCode: 'app-dev.source_classification_failed',
        );

        return $this->profile(trim($result->stdout), $type);
    }

    public function configureLaravelUrl(Instance $instance, string $url, ?string $app = null): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing(['node', 'project']);
        $account = $this->accounts->resolve($instance->node);
        $appName = $instance->appConfiguration($app)['name'];
        $storedKey = LaravelApplicationKey::stored($instance, $appName);
        $storedName = $instance->environmentValues()->where('app', $appName)->where('env_key', 'APP_NAME')->first()?->env_value;
        $storedName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{') ? $storedName : null;
        $settings = json_encode([
            'url' => $url,
            'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote($storedKey ?? LaravelApplicationKey::generate()),
            'app_key_stored' => $storedKey !== null,
            'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($storedName ?? $instance->project->name),
            'app_name_stored' => $storedName !== null,
        ], JSON_THROW_ON_ERROR);
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['python3', '-c', <<<'PYTHON'
                        import json, os, pathlib, re, sys, tempfile

                        root = pathlib.Path(sys.argv[1])
                        owner = sys.argv[2]
                        settings = json.load(sys.stdin)
                        url = settings['url']
                        if root.resolve(strict=True) != root:
                            raise SystemExit(42)

                        def safe_regular(path, required=False):
                            if path.is_symlink() or (path.exists() and not path.is_file()):
                                raise SystemExit(42)
                            if required and not path.is_file():
                                raise SystemExit(42)
                            if path.exists():
                                import pwd
                                if pwd.getpwuid(path.stat().st_uid).pw_name != owner:
                                    raise SystemExit(42)

                        def atomic(path, value, mode):
                            fd, candidate = tempfile.mkstemp(prefix='.orbit-url-', dir=path.parent)
                            try:
                                os.fchmod(fd, mode)
                                with os.fdopen(fd, 'wb') as stream: stream.write(value)
                                os.replace(candidate, path)
                            finally:
                                if os.path.exists(candidate): os.unlink(candidate)

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

                        env = root / '.env'
                        safe_regular(env)
                        template = root / '.env.example'
                        created = not env.exists()
                        if env.exists():
                            original = env.read_bytes()
                            mode = env.stat().st_mode & 0o777
                        else:
                            safe_regular(template)
                            original = template.read_bytes() if template.exists() else b''
                            mode = template.stat().st_mode & 0o777 if template.exists() else 0o600
                        found = entries(original, b'APP_URL')
                        if len(found) > 1: raise SystemExit(42)
                        updated = put(original, found, ('APP_URL=' + url).encode())
                        # Laravel cannot boot with an empty key. A new file takes the stored key, the
                        # template's own key, or a generated one; an existing file only fills an empty key.
                        # Duplicate key or name lines stay as they are.
                        found = entries(updated, b'APP_KEY')
                        empty = plain(found) == b''
                        if len(found) < 2 and ((created and (empty or settings['app_key_stored'])) or (found and empty)):
                            updated = put(updated, found, settings['app_key'].encode())
                        # A new file takes the stored name, or the Project name over the framework default.
                        found = entries(updated, b'APP_NAME')
                        if created and len(found) < 2 and (settings['app_name_stored'] or plain(found) in (b'', b'Laravel')):
                            updated = put(updated, found, settings['app_name'].encode())
                        # Other local users, the Node agent included, never read an Instance's environment.
                        mode &= 0o770
                        if updated != original or not env.exists(): atomic(env, updated, mode)
                        elif env.stat().st_mode & 0o007: os.chmod(env, mode)

                        replacement = ('APP_URL=' + url).encode()
                        testing = root / '.env.testing'
                        safe_regular(testing)
                        if testing.exists():
                            content = testing.read_bytes()
                            matches = list(re.finditer(rb'(?m)^APP_URL=.*$', content))
                            if len(matches) > 1: raise SystemExit(42)
                            if matches:
                                match = matches[0]
                                content = content[:match.start()] + replacement + content[match.end():]
                            else:
                                content += (b'' if content.endswith(b'\n') else b'\n') + replacement + b'\n'
                            atomic(testing, content, testing.stat().st_mode & 0o770)

                        cache = root / 'bootstrap' / 'cache' / 'config.php'
                        safe_regular(cache)
                        if cache.exists():
                            original = cache.read_bytes()
                            escaped = url.replace('\\', '\\\\').replace("'", "\\'").encode()
                            # Read literal-array structure without evaluating application PHP.
                            pattern = rb"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*.*?\*/|//[^\n]*|\#[^\n]*|=>|[()\[\],]|[A-Za-z_][A-Za-z0-9_]*|\S"
                            tokens = [token for token in re.finditer(pattern, original, re.S)
                                      if not token.group().startswith((b'/*', b'//', b'#'))]
                            stack = []
                            pending = None
                            matches = []
                            for index, token in enumerate(tokens):
                                value = token.group()
                                if value in (b'(', b'['):
                                    stack.append((stack[-1] if stack else []) + ([pending] if pending is not None else []))
                                    pending = None
                                elif value in (b')', b']'):
                                    if not stack: raise SystemExit(42)
                                    stack.pop()
                                    pending = None
                                elif value == b',':
                                    pending = None
                                elif value[:1] in (b"'", b'\"') and index + 1 < len(tokens) and tokens[index + 1].group() == b'=>':
                                    pending = value[1:-1]
                                    if len(stack) == 2 and stack[-1] == [b'app'] and pending == b'url':
                                        if index + 2 >= len(tokens): raise SystemExit(42)
                                        candidate = tokens[index + 2]
                                        if candidate.group()[:1] not in (b"'", b'\"'): raise SystemExit(42)
                                        matches.append(candidate)
                            if stack or len(matches) != 1: raise SystemExit(42)
                            match = matches[0]
                            updated = original[:match.start()] + b"'" + escaped + b"'" + original[match.end():]
                            if updated != original: atomic(cache, updated, cache.stat().st_mode & 0o777)
                        PYTHON, $instance->applicationDirectory($app), $account->user],
                protectedInput: ProtectedInput::fromString($settings),
            ),
            step: 'laravel-url',
            errorCode: 'app-dev.laravel_url_configuration_failed',
        );
        if ($instance->exists) {
            InstanceEnvironmentValue::query()->updateOrCreate(
                ['instance_id' => $instance->id, 'app' => $instance->appConfiguration($app)['name'], 'env_key' => 'APP_URL'],
                ['env_value' => $url],
            );
        }
    }

    private function profile(string $result, ProjectType $projectType): DevelopmentSourceProfile
    {
        if ($result === 'NONE') {
            return new DevelopmentSourceProfile(null, false);
        }

        if ($result === 'UNSAFE' || $result === 'PARTIAL' || ! str_starts_with($result, "COMPOSER\t")) {
            throw $this->invalid('app-dev.source_metadata_unsafe');
        }

        $parts = explode("\t", $result, 3);
        $json = isset($parts[2]) ? base64_decode($parts[2], true) : false;

        if (! is_string($json)) {
            throw $this->invalid('app-dev.php_version_unsupported');
        }

        return $this->classifier->classify($json, $projectType, $parts[1]);
    }

    private function invalid(string $errorCode, ?\Throwable $previous = null): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'source-classification',
            errorCode: $errorCode,
            message: 'The development source metadata is invalid or unsupported.',
            previous: $previous,
        );
    }
}
