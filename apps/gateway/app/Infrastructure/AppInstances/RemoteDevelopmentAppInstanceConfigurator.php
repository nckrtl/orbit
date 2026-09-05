<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteDevelopmentAppInstanceConfigurator implements DevelopmentAppInstanceConfigurator
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private ComposerSourceClassifier $classifier,
    ) {}

    public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
    {
        $appInstance->loadMissing(['app', 'node']);
        $account = $this->accounts->resolve($appInstance->node);
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $appInstance->checkout_path, $account->user],
                input: <<<'BASH'
                    checkout=$1
                    managed_user=$2
                    composer="$checkout/composer.json"
                    artisan="$checkout/artisan"

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

        return $this->profile(trim($result->stdout));
    }

    public function configureLaravelUrl(AppInstance $appInstance, string $url): void
    {
        $appInstance->loadMissing('node');
        $account = $this->accounts->resolve($appInstance->node);
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['python3', '-c', <<<'PYTHON'
                        import os, pathlib, re, sys, tempfile

                        root = pathlib.Path(sys.argv[1])
                        owner = sys.argv[2]
                        url = sys.stdin.read()

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
                        if env.exists():
                            original = env.read_bytes()
                            replacement = ('APP_URL=' + url).encode()
                            updated, count = re.subn(rb'(?m)^APP_URL=.*$', replacement, original, count=1)
                            if count == 0:
                                separator = b'' if original == b'' or original.endswith(b'\n') else b'\n'
                                updated = original + separator + replacement + b'\n'
                            if updated != original: atomic(env, updated, env.stat().st_mode & 0o777)

                        cache = root / 'bootstrap' / 'cache' / 'config.php'
                        safe_regular(cache)
                        if cache.exists():
                            original = cache.read_bytes()
                            escaped = url.replace('\\', '\\\\').replace("'", "\\'").encode()
                            pattern = rb"(?m)(['\"]url['\"]\s*=>\s*)['\"][^'\"\r\n]*['\"]"
                            matches = list(re.finditer(pattern, original))
                            if len(matches) != 1: raise SystemExit(42)
                            match = matches[0]
                            updated = original[:match.start()] + match.group(1) + b"'" + escaped + b"'" + original[match.end():]
                            if updated != original: atomic(cache, updated, cache.stat().st_mode & 0o777)
                        PYTHON, $appInstance->checkout_path, $account->user],
                protectedInput: ProtectedInput::fromString($url),
            ),
            step: 'laravel-url',
            errorCode: 'app-dev.laravel_url_configuration_failed',
        );
    }

    private function profile(string $result): DevelopmentSourceProfile
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

        return $this->classifier->classify($json, $parts[1] ?? 'absent');
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
