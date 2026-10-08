<?php

declare(strict_types=1);

/*
 * Pest TIA records the code a test runs through PCOV in the test's own process. Code that a test runs in a PHP
 * subprocess is not linked to the test, so a change to that code does not select it. CI runs the `subprocess`
 * group on every affected run, and this contract keeps every such test in that group.
 */

/** The projects whose Pest suites CI runs. */
const SUBPROCESS_GROUP_PROJECTS = ['apps/cli', 'apps/docs', 'apps/e2e', 'apps/gateway', 'packages/php-sdk'];

/**
 * A test starts PHP when it names the running PHP binary or starts a `php` or `composer` command. PHP's directory on
 * PATH starts nothing.
 */
const SUBPROCESS_GROUP_MARKER = '/(?<!dirname\()\bPHP_BINARY\b|\bPhpExecutableFinder\b|(?:new\s+Process|proc_open)\(\s*\[\s*[\'"](?:php|composer)[\'"]/';

/** A file-level group declaration that lists `subprocess`. */
const SUBPROCESS_GROUP_DECLARATION = '/^pest\(\)->group\([^)]*[\'"]subprocess[\'"][^)]*\);$/m';

/**
 * Returns the suite's test files that start PHP, relative to the project, with the marker or helper that shows it.
 *
 * @return array<string, string>
 */
function subprocess_group_tests(string $project): array
{
    $root = base_path('../../'.$project);
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests', FilesystemIterator::SKIP_DOTS)) as $file) {
        // This contract names the markers it looks for, so it does not check itself.
        if ($file->getExtension() === 'php' && $file->getRealPath() !== __FILE__) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    // A test helper that starts PHP makes every test that uses it start PHP too, so follow helpers by their name.
    $helpers = [];

    do {
        $found = count($helpers);

        foreach ($files as $path => $source) {
            $name = basename($path, '.php');

            if (str_ends_with($path, 'Test.php') || in_array($name, ['Pest', 'bootstrap'], true) || isset($helpers[$name])) {
                continue;
            }

            if (subprocess_group_reason($source, $helpers) !== null) {
                $helpers[$name] = $path;
            }
        }
    } while (count($helpers) > $found);

    $config = (string) file_get_contents(is_file($root.'/phpunit.xml') ? $root.'/phpunit.xml' : $root.'/phpunit.xml.dist');
    $suites = array_map(
        static fn (SimpleXMLElement $directory): string => trim(ltrim(trim((string) $directory), './'), '/').'/',
        new SimpleXMLElement($config)->xpath('//testsuite/directory') ?: [],
    );
    $tests = [];

    foreach ($files as $path => $source) {
        $suite = array_any($suites, static fn (string $directory): bool => str_starts_with($path, $directory));

        if ($suite && str_ends_with($path, 'Test.php') && ($reason = subprocess_group_reason($source, $helpers)) !== null) {
            $tests[$path] = $reason;
        }
    }

    ksort($tests);

    return $tests;
}

/** @param array<string, string> $helpers */
function subprocess_group_reason(string $source, array $helpers): ?string
{
    if (preg_match(SUBPROCESS_GROUP_MARKER, $source, $match) === 1) {
        return $match[0];
    }

    foreach ($helpers as $name => $path) {
        if (preg_match('/\b'.preg_quote($name, '/').'\b/', $source) === 1) {
            return $path;
        }
    }

    return null;
}

it('puts every test that starts PHP in the subprocess group', function (string $project): void {
    $tests = subprocess_group_tests($project);
    $untagged = [];

    foreach ($tests as $path => $reason) {
        $source = (string) file_get_contents(base_path("../../{$project}/{$path}"));

        if (preg_match(SUBPROCESS_GROUP_DECLARATION, $source) !== 1) {
            $untagged[] = "{$project}/{$path} ({$reason})";
        }
    }

    expect($untagged)->toBe([], 'Add `pest()->group(\'subprocess\');` to these tests, so CI runs them on every affected run.');
})->with(SUBPROCESS_GROUP_PROJECTS);

it('detects PHP subprocesses started directly and through helpers', function (): void {
    expect(subprocess_group_reason('new Process([PHP_BINARY, base_path(\'artisan\')]);', []))->toBe('PHP_BINARY')
        ->and(subprocess_group_reason("new Process(['php', 'vendor/bin/pest']);", []))->toBe("new Process(['php'")
        ->and(subprocess_group_reason("proc_open([\n    'php', '-r', 'exit;'], [], \$pipes);", []))->toBe("proc_open([\n    'php'")
        ->and(subprocess_group_reason("new Process(['composer', 'guidance:check'], \$project);", []))->toBe("new Process(['composer'")
        ->and(subprocess_group_reason('new PhpExecutableFinder()->find();', []))->toBe('PhpExecutableFinder')
        ->and(subprocess_group_reason('new Harness()->publish();', ['Harness' => 'tests/Support/Harness.php']))->toBe('tests/Support/Harness.php')
        // A command stored as data starts nothing.
        ->and(subprocess_group_reason("'command' => ['/usr/bin/php', 'artisan', 'queue:work'],", []))->toBeNull()
        ->and(subprocess_group_reason("'command' => 'php artisan schedule:run',", []))->toBeNull()
        ->and(subprocess_group_reason("'command' => ['composer', 'test:affected'],", []))->toBeNull()
        ->and(subprocess_group_reason('$php = dirname(PHP_BINARY);', []))->toBeNull()
        ->and(subprocess_group_reason('new HarnessFactory();', ['Harness' => 'tests/Support/Harness.php']))->toBeNull();

    expect(preg_match(SUBPROCESS_GROUP_DECLARATION, "pest()->group('subprocess');"))->toBe(1)
        ->and(preg_match(SUBPROCESS_GROUP_DECLARATION, "pest()->group('privileged', 'subprocess');"))->toBe(1)
        ->and(preg_match(SUBPROCESS_GROUP_DECLARATION, "pest()->group('privileged');"))->toBe(0)
        ->and(preg_match(SUBPROCESS_GROUP_DECLARATION, "    it('runs', fn () => true)->group('subprocess');"))->toBe(0);
});
