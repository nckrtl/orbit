<?php

declare(strict_types=1);

// Read-only registry proof. Only a private temporary ORBIT_HOME is created.
// Never dispatches a product, framework, or extension command.
$options = getopt('', ['root:', 'expected:', 'candidate:', 'inspect']);
$temporary = null;
$previous = [];
$exit = 1;

function definitionShape(Symfony\Component\Console\Input\InputDefinition $definition): array
{
    $arguments = [];
    foreach ($definition->getArguments() as $argument) {
        $arguments[$argument->getName()] = [
            'required' => $argument->isRequired(),
            'array' => $argument->isArray(),
            'default' => $argument->getDefault(),
            'description' => $argument->getDescription(),
        ];
    }
    $options = [];
    foreach ($definition->getOptions() as $option) {
        $options[$option->getName()] = [
            'shortcut' => $option->getShortcut(),
            'accept_value' => $option->acceptValue(),
            'value_required' => $option->isValueRequired(),
            'value_optional' => $option->isValueOptional(),
            'array' => $option->isArray(),
            'negatable' => $option->isNegatable(),
            'default' => $option->getDefault(),
            'description' => $option->getDescription(),
        ];
    }
    ksort($options);

    return ['arguments' => $arguments, 'options' => $options];
}

function sourceHashes(string $root): array
{
    $paths = [
        'apps/cli/orbit', 'apps/cli/bootstrap/app.php', 'apps/cli/composer.lock',
        'packages/php-sdk/composer.lock',
    ];
    foreach (['apps/cli/app', 'apps/cli/config'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/'.$directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($paths);
    $hashes = [];
    foreach ($paths as $path) {
        $hash = hash_file('sha256', $root.'/'.$path);
        if (! is_string($hash)) {
            throw new RuntimeException('Cannot hash source: '.$path);
        }
        $hashes[$path] = $hash;
    }

    return $hashes;
}

function encode(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

try {
    $root = realpath((string) ($options['root'] ?? getcwd()));
    if (! is_string($root) || ! is_file($root.'/apps/cli/vendor/autoload.php')) {
        throw new RuntimeException('Use --root with the candidate repository and its CLI vendor tree.');
    }
    if (! isset($options['inspect']) && (! isset($options['expected']) || ! isset($options['candidate']))) {
        throw new RuntimeException('Use --expected=JSON --candidate=SHA, or --inspect for preparation.');
    }
    $candidate = $options['candidate'] ?? null;
    if ($candidate !== null && (! is_string($candidate) || preg_match('/\A[0-9a-f]{40}\z/D', $candidate) !== 1)) {
        throw new RuntimeException('Candidate must be a full 40-character commit SHA.');
    }
    $temporary = sys_get_temp_dir().'/orb-351-registry-'.bin2hex(random_bytes(12));
    if (! mkdir($temporary, 0700)) {
        throw new RuntimeException('Cannot create private registry home.');
    }
    file_put_contents($temporary.'/extensions.json', '{"enabled":["herdr"]}');
    chmod($temporary.'/extensions.json', 0600);
    foreach ([
        'ORBIT_HOME' => $temporary,
        'APP_CONFIG_CACHE' => $temporary.'/unused-config.php',
        'APP_SERVICES_CACHE' => $temporary.'/services.php',
        'APP_PACKAGES_CACHE' => $temporary.'/packages.php',
        'LOG_CHANNEL' => 'stderr',
    ] as $key => $value) {
        $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    require $root.'/apps/cli/vendor/autoload.php';
    $app = require $root.'/apps/cli/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if (rtrim((string) $app['config']->get('orbit.home'), '/') !== $temporary) {
        throw new RuntimeException('Bootstrap did not honor isolated ORBIT_HOME.');
    }
    if (! $app->make(App\Services\Extensions\LocalExtensionState::class)->enabled('herdr')) {
        throw new RuntimeException('Herdr extension is not enabled in the isolated home.');
    }
    $public = [];
    $framework = [];
    $registrationKeys = [];
    $globals = null;
    foreach ($kernel->all() as $registration => $command) {
        $name = $command->getName();
        $registrationKeys[$registration] = $name;
        $shape = [
            'name' => $name,
            'class' => $command::class,
            'hidden' => $command->isHidden(),
            'aliases' => $command->getAliases(),
            'description' => $command->getDescription(),
            'signature' => definitionShape($command->getNativeDefinition()),
        ];
        $application = $command->getApplication();
        if ($application !== null) {
            $observedGlobals = definitionShape($application->getDefinition());
            if ($globals !== null && $globals !== $observedGlobals) {
                throw new RuntimeException('Commands have inconsistent global definitions.');
            }
            $globals = $observedGlobals;
        }
        if (str_starts_with($command::class, 'App\\Commands\\')) {
            $source = (new ReflectionClass($command))->getFileName();
            if (! is_string($source) || ! str_starts_with($source, $root.'/')) {
                throw new RuntimeException('Product command loaded outside candidate: '.$name);
            }
            $shape['source'] = substr($source, strlen($root) + 1);
            $public[$name] = $shape;
        } else {
            $framework[$name] = $shape;
        }
    }
    ksort($public);
    ksort($framework);
    ksort($registrationKeys);
    $snapshot = [
        'schema' => 'orbit-cli-registry-proof-v1',
        'extension_configuration' => ['enabled' => ['herdr']],
        'public' => $public,
        'framework' => $framework,
        'registration_keys' => $registrationKeys,
        'global_signature' => $globals,
        'source_sha256' => sourceHashes($root),
    ];
    if (isset($options['inspect'])) {
        echo encode($snapshot);
        $exit = 0;
    } else {
        $expectedPath = realpath((string) $options['expected']);
        if (! is_string($expectedPath)) {
            throw new RuntimeException('Expected fixture does not exist.');
        }
        $expected = json_decode(file_get_contents($expectedPath), true, 512, JSON_THROW_ON_ERROR);
        $expectedSnapshot = $expected['registry'] ?? null;
        if (! is_array($expectedSnapshot) || ($expectedSnapshot['schema'] ?? null) !== $snapshot['schema']) {
            throw new RuntimeException('Expected fixture has the wrong registry schema.');
        }
        if (count($expectedSnapshot['public'] ?? []) !== 106 || count($expectedSnapshot['framework'] ?? []) !== 20) {
            throw new RuntimeException('Expected fixture must cover 106 public and 20 framework commands.');
        }
        $mismatches = [];
        foreach ($snapshot as $key => $value) {
            if (($expectedSnapshot[$key] ?? null) !== $value) {
                $mismatches[] = $key;
            }
        }
        if ($mismatches !== []) {
            throw new RuntimeException('Registry mismatch in: '.implode(', ', $mismatches));
        }
        echo encode([
            'state' => 'passed',
            'check' => 'ORB-351 exact registry and source reconciliation',
            'candidate' => $candidate,
            'candidate_binding' => 'Harness must bind the supplied commit to this mounted candidate; the fixture verifies recorded source hashes, not the commit label alone.',
            'root' => $root,
            'php_version' => PHP_VERSION,
            'public_commands' => count($public),
            'framework_commands' => count($framework),
            'registration_keys' => count($registrationKeys),
            'source_files' => count($snapshot['source_sha256']),
            'source_manifest_sha256' => hash('sha256', encode($snapshot['source_sha256'])),
            'expected_sha256' => hash_file('sha256', $expectedPath),
            'verifier_sha256' => hash_file('sha256', __FILE__),
            'extension_configuration' => $snapshot['extension_configuration'],
            'isolation' => 'Private temporary ORBIT_HOME; no command dispatched; no Gateway profile or caller home used.',
            'ux_adoption' => 'Unverified for every public command; this proves registry coverage only.',
        ]);
        $exit = 0;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, encode(['state' => 'failed', 'message' => $exception->getMessage()]));
} finally {
    foreach ($previous as $key => [$environment, $env, $server]) {
        $environment === false ? putenv($key) : putenv($key.'='.$environment);
        if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
        if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
    }
    if (is_string($temporary) && is_dir($temporary)) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temporary);
    }
}
exit($exit);
