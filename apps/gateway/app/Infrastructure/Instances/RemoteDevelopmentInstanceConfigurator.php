<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\ProjectSandboxRuntimeGuard;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteDevelopmentInstanceConfigurator implements DevelopmentInstanceConfigurator
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private ComposerSourceClassifier $classifier,
    ) {}

    public function inspect(Instance $instance): DevelopmentSourceProfile
    {
        ProjectSandboxRuntimeGuard::assertRuntime($instance);
        $instance->loadMissing(['project', 'node']);

        // An unrouted monorepo is a source checkout, not a single PHP application.
        if ($instance->project->type === ProjectType::Monorepo && ! $instance->routes()->exists()) {
            return new DevelopmentSourceProfile(null, false);
        }

        $account = $this->accounts->resolve($instance->node);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->applicationDirectory(), $account->user, $instance->project->type->frameworkEntryPoint()],
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

        return $this->profile(trim($result->stdout), $instance->project->type);
    }

    public function configureLaravelUrl(Instance $instance, string $url): void
    {
        ProjectSandboxRuntimeGuard::assertRuntime($instance);
        $instance->loadMissing('node');
        $account = $this->accounts->resolve($instance->node);
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['python3', '-c', <<<'PYTHON'
                        import os, pathlib, re, sys, tempfile

                        root = pathlib.Path(sys.argv[1])
                        owner = sys.argv[2]
                        url = sys.stdin.read()
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

                        env = root / '.env'
                        safe_regular(env)
                        template = root / '.env.example'
                        if env.exists():
                            original = env.read_bytes()
                            mode = env.stat().st_mode & 0o777
                        else:
                            safe_regular(template)
                            original = template.read_bytes() if template.exists() else b''
                            mode = template.stat().st_mode & 0o777 if template.exists() else 0o600
                        replacement = ('APP_URL=' + url).encode()
                        matches = list(re.finditer(rb'(?m)^APP_URL=.*$', original))
                        if len(matches) > 1: raise SystemExit(42)
                        if len(matches) == 1:
                            match = matches[0]
                            updated = original[:match.start()] + replacement + original[match.end():]
                        else:
                            separator = b'' if original == b'' or original.endswith(b'\n') else b'\n'
                            updated = original + separator + replacement + b'\n'
                        # Other local users, the Node agent included, never read an Instance's environment.
                        mode &= 0o770
                        if updated != original or not env.exists(): atomic(env, updated, mode)
                        elif env.stat().st_mode & 0o007: os.chmod(env, mode)

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
                        PYTHON, $instance->applicationDirectory(), $account->user],
                protectedInput: ProtectedInput::fromString($url),
            ),
            step: 'laravel-url',
            errorCode: 'app-dev.laravel_url_configuration_failed',
        );
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
