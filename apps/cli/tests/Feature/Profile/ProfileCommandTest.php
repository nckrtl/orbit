<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Commands\Profile\ProfileCommand;
use App\Services\Profile\ProfileRequestProfiler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Command\Command;

describe('profile', function (): void {
    it('is a local command and exposes only the local profile inputs', function (): void {
        $command = app(Kernel::class)->all()['profile'];
        $definition = $command->getDefinition();

        expect(is_subclass_of(ProfileCommand::class, GatewayCommand::class))
            ->toBeTrue()
            ->and(array_keys($definition->getArguments()))
            ->toBe(['url'])
            ->and($definition->getOptions())
            ->toHaveKeys(['as-first-user', 'user', 'json'])
            ->and($definition->hasOption('app'))
            ->toBeFalse()
            ->and($definition->hasOption('node'))
            ->toBeFalse()
            ->and($definition->hasOption('node-transport'))
            ->toBeFalse()
            ->and($definition->hasOption('uri'))
            ->toBeFalse();
    });

    it('profiles an explicit absolute URL exactly without gateway IO', function (): void {
        $url = 'https://docs.test/admin/users?filter=active#queries';
        $profiler = fakeLocalProfile(fakeProfileData(url: $url));
        $mock = MockClient::global();

        $exitCode = Artisan::call('profile', [
            'url' => $url,
            '--user' => '42',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::SUCCESS)
            ->and($mock->getLastPendingRequest())
            ->toBeNull()
            ->and($profiler->calls)
            ->toHaveCount(1)
            ->and($profiler->calls[0]['url'])
            ->toBe($url)
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-AUTH'])
            ->toBe('user')
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-USER'])
            ->toBe('42')
            ->and($profiler->calls[0]['headers']['X-REQUEST-ID'])
            ->toBe($decoded['request_id'])
            ->and($decoded)
            ->not->toHaveKey('target')
            ->and($decoded['origin'])
            ->toBe('caller')
            ->and($decoded['source'])
            ->toBe('baseline')
            ->and($decoded['instrumented'])
            ->toBeFalse()
            ->and($decoded['auth_mode'])
            ->toBe('user');

        MockClient::destroyGlobal();
    });

    it('uses APP_URL from the nearest ancestor env file without importing other values', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $previousImportedValue = getenv('ORBIT_PROFILE_MUST_NOT_IMPORT');

        file_put_contents(filename: $tree['root'].'/.env', data: "APP_URL=https://farther.test\n");
        file_put_contents(
            filename: $tree['app'].'/.env',
            data: "ORBIT_PROFILE_MUST_NOT_IMPORT=forbidden\nAPP_URL = \"https://nearest.test/path?filter=active\" # local app\n",
        );

        putenv("ORBIT_HOST_CWD={$tree['cwd']}");
        putenv('ORBIT_PROFILE_MUST_NOT_IMPORT');

        $profiler = fakeLocalProfile(fakeProfileData(url: 'https://nearest.test/path?filter=active'));
        $mock = MockClient::global();

        try {
            $exitCode = Artisan::call('profile', ['--json' => true]);

            expect($exitCode)
                ->toBe(Command::SUCCESS)
                ->and($mock->getLastPendingRequest())
                ->toBeNull()
                ->and($profiler->calls)
                ->toHaveCount(1)
                ->and($profiler->calls[0]['url'])
                ->toBe('https://nearest.test/path?filter=active')
                ->and(getenv('ORBIT_PROFILE_MUST_NOT_IMPORT'))
                ->toBeFalse();
        } finally {
            MockClient::destroyGlobal();
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            restore_profile_environment_variable('ORBIT_PROFILE_MUST_NOT_IMPORT', $previousImportedValue);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('falls back to getcwd when ORBIT_HOST_CWD is not set', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $previousCwd = getcwd();

        file_put_contents(filename: $tree['app'].'/.env', data: "APP_URL=https://cwd.test/dashboard\n");
        putenv('ORBIT_HOST_CWD');
        chdir($tree['cwd']);

        $profiler = fakeLocalProfile(fakeProfileData(url: 'https://cwd.test/dashboard'));

        try {
            $exitCode = Artisan::call('profile', ['--json' => true]);

            expect($exitCode)
                ->toBe(Command::SUCCESS)
                ->and($profiler->calls)
                ->toHaveCount(1)
                ->and($profiler->calls[0]['url'])
                ->toBe('https://cwd.test/dashboard');
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }

            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('prefers an explicit URL over the nearest env file', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $explicitUrl = 'http://127.0.0.1:8080/status?full=1';

        file_put_contents(filename: $tree['app'].'/.env', data: "APP_URL=https://env.test\n");
        putenv("ORBIT_HOST_CWD={$tree['cwd']}");

        $profiler = fakeLocalProfile(fakeProfileData(url: $explicitUrl));

        try {
            $exitCode = Artisan::call('profile', [
                'url' => $explicitUrl,
                '--json' => true,
            ]);

            expect($exitCode)
                ->toBe(Command::SUCCESS)
                ->and($profiler->calls[0]['url'])
                ->toBe($explicitUrl);
        } finally {
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('prompts locally for the URL when no explicit or env URL exists', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $url = 'https://prompted.test/profile';

        putenv("ORBIT_HOST_CWD={$tree['cwd']}");

        $profiler = fakeLocalProfile(fakeProfileData(url: $url));
        $mock = MockClient::global();

        try {
            $this
                ->artisan('profile')
                ->expectsQuestion('URL to profile', $url)
                ->assertSuccessful();

            expect($mock->getLastPendingRequest())
                ->toBeNull()
                ->and($profiler->calls)
                ->toHaveCount(1)
                ->and($profiler->calls[0]['url'])
                ->toBe($url);
        } finally {
            MockClient::destroyGlobal();
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('prompts locally when the nearest env APP_URL is invalid', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $url = 'https://prompted-after-invalid-env.test/profile';

        file_put_contents(filename: $tree['app'].'/.env', data: "APP_URL=/relative-only\n");
        putenv("ORBIT_HOST_CWD={$tree['cwd']}");

        $profiler = fakeLocalProfile(fakeProfileData(url: $url));
        $mock = MockClient::global();

        try {
            $this
                ->artisan('profile')
                ->expectsQuestion('URL to profile', $url)
                ->assertSuccessful();

            expect($mock->getLastPendingRequest())
                ->toBeNull()
                ->and($profiler->calls)
                ->toHaveCount(1)
                ->and($profiler->calls[0]['url'])
                ->toBe($url);
        } finally {
            MockClient::destroyGlobal();
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('returns missing_required_input in JSON mode when no URL can be resolved', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');

        putenv("ORBIT_HOST_CWD={$tree['cwd']}");
        $profiler = fakeLocalProfile(fakeProfileData());
        $mock = MockClient::global();

        try {
            $exitCode = Artisan::call('profile', ['--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(Command::FAILURE)
                ->and($mock->getLastPendingRequest())
                ->toBeNull()
                ->and($decoded)
                ->toBe([
                    'error' => [
                        'code' => 'profile.validation_failed',
                        'message' => 'URL to profile is required.',
                        'request_id' => null,
                    ],
                ])
                ->and($profiler->calls)
                ->toBeEmpty();
        } finally {
            MockClient::destroyGlobal();
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('returns invalid_url for an explicit non-http URL without making a request', function (): void {
        $profiler = fakeLocalProfile(fakeProfileData());
        $mock = MockClient::global();

        $exitCode = Artisan::call('profile', [
            'url' => 'ftp://docs.test/archive',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::FAILURE)
            ->and($mock->getLastPendingRequest())
            ->toBeNull()
            ->and($decoded['error']['code'])
            ->toBe('profile.validation_failed')
            ->and($decoded['error']['message'])
            ->toBe('URL to profile must be an absolute HTTP or HTTPS URL.')
            ->and($profiler->calls)
            ->toBeEmpty();

        MockClient::destroyGlobal();
    });

    it('returns invalid_url for an invalid APP_URL without making a request', function (): void {
        $tree = create_profile_env_tree();
        $previousHostCwd = getenv('ORBIT_HOST_CWD');

        file_put_contents(filename: $tree['app'].'/.env', data: "APP_URL=/relative-only\n");
        putenv("ORBIT_HOST_CWD={$tree['cwd']}");

        $profiler = fakeLocalProfile(fakeProfileData());
        $mock = MockClient::global();

        try {
            $exitCode = Artisan::call('profile', ['--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(Command::FAILURE)
                ->and($mock->getLastPendingRequest())
                ->toBeNull()
                ->and($decoded['error']['code'])
                ->toBe('profile.validation_failed')
                ->and($decoded['error']['message'])
                ->toBe('URL to profile must be an absolute HTTP or HTTPS URL.')
                ->and($profiler->calls)
                ->toBeEmpty();
        } finally {
            MockClient::destroyGlobal();
            restore_profile_environment_variable('ORBIT_HOST_CWD', $previousHostCwd);
            remove_profile_env_tree($tree['root']);
        }
    });

    it('fails before profiling when auth modes are combined', function (): void {
        $profiler = fakeLocalProfile(fakeProfileData());

        $exitCode = Artisan::call('profile', [
            'url' => 'https://docs.test',
            '--as-first-user' => true,
            '--user' => '42',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::FAILURE)
            ->and($decoded['error']['code'])
            ->toBe('profile.validation_failed')
            ->and($decoded['error']['message'])
            ->toBe('Use either --as-first-user or --user, not both.')
            ->and($profiler->calls)
            ->toBeEmpty();
    });

    it('enriches a completed profile with Toolbar response headers', function (): void {
        $toolbar = [
            'queries' => [
                'count' => 5,
                'slow_count' => 1,
                'duplicate_count' => 0,
            ],
        ];
        $url = 'https://docs.test/login';
        $profiler = fakeLocalProfile(fakeProfileData(
            url: $url,
            headers: [
                'content-type' => 'text/html',
                'x-toolbar-summary' => base64_encode(json_encode($toolbar, JSON_THROW_ON_ERROR)),
            ],
        ));

        $exitCode = Artisan::call('profile', [
            'url' => $url,
            '--as-first-user' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::SUCCESS)
            ->and($decoded['source'])
            ->toBe('baseline+toolbar')
            ->and($decoded['instrumented'])
            ->toBeTrue()
            ->and($decoded['toolbar'])
            ->toBe($toolbar)
            ->and($decoded['response_headers']['content-type'])
            ->toBe('text/html')
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-AUTH'])
            ->toBe('first-user')
            ->and($profiler->calls[0]['headers'])
            ->not->toHaveKey('X-TOOLBAR-USER');
    });

    it('treats a completed non-2xx response as success', function (): void {
        fakeLocalProfile(fakeProfileData(status: 503));

        $exitCode = Artisan::call('profile', [
            'url' => 'https://docs.test',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::SUCCESS)
            ->and($decoded['request']['status'])
            ->toBe(503)
            ->and($decoded['request']['completed'])
            ->toBeTrue();
    });

    it('preserves local request failure diagnostics', function (): void {
        $url = 'https://unreachable.test';
        fakeLocalProfile(fakeProfileData(
            url: $url,
            status: null,
            error: ['message' => 'Operation timed out'],
        ));

        $exitCode = Artisan::call('profile', [
            'url' => $url,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(Command::FAILURE)
            ->and($decoded['error']['code'])
            ->toBe('profile.request_failed')
            ->and($decoded['error']['message'])
            ->toBe('Failed to complete profile request.')
            ->and($decoded['error']['details'])
            ->toBe([
                'origin' => 'caller',
                'url' => $url,
                'error' => 'Operation timed out',
            ]);
    });

    it('renders human timing output for a completed request', function (): void {
        fakeLocalProfile(fakeProfileData(url: 'https://docs.test/admin'));

        $this
            ->artisan('profile', ['url' => 'https://docs.test/admin'])
            ->expectsOutput('GET https://docs.test/admin 200 in 115.42ms')
            ->expectsOutput('DNS ..................................... 2.15ms')
            ->expectsOutput('Total ................................. 115.42ms')
            ->assertSuccessful();
    });
});

/**
 * @param  array<string, string>  $headers
 * @param  array{message: string}|null  $error
 * @return array<string, mixed>
 */
function fakeProfileData(
    string $url = 'https://docs.test',
    ?int $status = 200,
    array $headers = [],
    ?array $error = null,
): array {
    $completed = $status !== null;

    return [
        'request' => [
            'method' => 'GET',
            'url' => $url,
            'uri' => profile_test_request_uri($url),
            'status' => $status,
            'bytes' => $completed ? 45120 : 0,
            'completed' => $completed,
        ],
        'timings' => [
            'dns_ms' => 2.15,
            'connect_ms' => 5.2,
            'tls_ms' => 8.1,
            'ttfb_ms' => 110.3,
            'download_ms' => 5.12,
            'total_ms' => 115.42,
        ],
        'response_headers' => $headers,
        'error' => $error,
    ];
}

/**
 * @param  array<string, mixed>  $profile
 */
function fakeLocalProfile(array $profile): ProfileCommandFakeProfiler
{
    $profiler = new ProfileCommandFakeProfiler($profile);

    app()->instance(ProfileRequestProfiler::class, $profiler);

    return $profiler;
}

final class ProfileCommandFakeProfiler implements ProfileRequestProfiler
{
    /**
     * @var list<array{url: string, headers: array<string, string>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        private readonly array $profile,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function profile(string $url, array $headers = [], ?string $caPath = null): array
    {
        $this->calls[] = compact('url', 'headers', 'caPath');

        return $this->profile;
    }
}

/**
 * @return array{root: string, app: string, cwd: string}
 */
function create_profile_env_tree(): array
{
    $root = sys_get_temp_dir().'/orbit-profile-env-'.bin2hex(random_bytes(6));
    $app = "{$root}/project";
    $cwd = "{$app}/storage/cache";

    if (! mkdir(directory: $cwd, permissions: 0o777, recursive: true) && ! is_dir($cwd)) {
        throw new RuntimeException("Unable to create profile env test tree: {$cwd}");
    }

    return compact('root', 'app', 'cwd');
}

function remove_profile_env_tree(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($root);
}

function restore_profile_environment_variable(string $name, string|false $value): void
{
    if ($value === false) {
        putenv($name);

        return;
    }

    putenv("{$name}={$value}");
}

function profile_test_request_uri(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    $path = is_string($path) && $path !== '' ? $path : '/';

    return is_string($query) && $query !== '' ? "{$path}?{$query}" : $path;
}
