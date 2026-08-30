<?php

declare(strict_types=1);

namespace Tests\Live\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;

/**
 * The public wrappers, state files, git, and read-only Incus queries every live
 * acceptance test drives. Each helper asserts its own success so a phase reads
 * as its evidence chain and nothing else.
 *
 * @mago-expect lint:too-many-methods One harness surface keeps every live test on the same public path.
 */
final class LiveHarness
{
    /** @param list<string> $names
     * @return array<string, string>
     */
    public static function inputs(array $names): array
    {
        $inputs = [];
        foreach ($names as $name) {
            $value = getenv($name);
            Assert::assertNotFalse($value, "Missing required live input: {$name}");
            Assert::assertNotSame('', $value, "Missing required live input: {$name}");
            $inputs[$name] = $value;
        }

        return $inputs;
    }

    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 5);
    }

    public static function wrapper(string $tool, string $action, string ...$arguments): ProcessResult
    {
        return Process::timeout(3_600)->run([
            self::repositoryRoot().'/bin/e2e-'.$tool,
            $action,
            ...$arguments,
            '--json',
        ]);
    }

    /** @return array<array-key, mixed> */
    public static function jsonWrapper(string $tool, string $action, string ...$arguments): array
    {
        $result = self::wrapper($tool, $action, ...$arguments);
        Assert::assertTrue($result->successful(), $result->errorOutput() ?: $result->output());

        return self::json($result->output());
    }

    /**
     * A wrapper call that must fail: returns the decoded failure payload.
     *
     * @return array<array-key, mixed>
     */
    public static function failedJsonWrapper(string $tool, string $action, string ...$arguments): array
    {
        $result = self::wrapper($tool, $action, ...$arguments);
        Assert::assertFalse($result->successful(), "{$tool} {$action} succeeded but must fail.");
        $payload = self::json($result->output());
        Assert::assertSame('failed', $payload['state'] ?? null);
        Assert::assertIsString($payload['error'] ?? null);

        return $payload;
    }

    /** @return array<array-key, mixed> */
    /** @mago-expect analysis:mixed-assignment Decoded JSON is asserted as an array immediately. */
    public static function json(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    public static function jsonFile(string $path): array
    {
        Assert::assertFileExists($path);

        return self::json((string) file_get_contents($path));
    }

    /** @return list<array<array-key, mixed>> */
    /** @mago-expect analysis:mixed-assignment Each journal line is asserted as an object record. */
    public static function journalEntries(string $stateRoot, string $operationId): array
    {
        $path = "{$stateRoot}/journals/{$operationId}.jsonl";
        Assert::assertFileExists($path);
        $entries = [];
        foreach (preg_split('/\R/', trim((string) file_get_contents($path))) ?: [] as $line) {
            $entry = self::json($line);
            Assert::assertSame($operationId, $entry['operation_id'] ?? null);
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param callable(): array<array-key, mixed> $action
     * @return array<array-key, mixed>
     */
    public static function jsonPhase(string $name, callable $action, ?PhaseTimings $timings = null): array
    {
        $started = microtime(true);
        try {
            return $action();
        } finally {
            self::notePhase($name, microtime(true) - $started, $timings);
        }
    }

    /** @param callable(): ProcessResult $action */
    public static function processPhase(string $name, callable $action, ?PhaseTimings $timings = null): ProcessResult
    {
        $started = microtime(true);
        try {
            return $action();
        } finally {
            self::notePhase($name, microtime(true) - $started, $timings);
        }
    }

    /** @param callable(): void $action */
    public static function voidPhase(string $name, callable $action, ?PhaseTimings $timings = null): void
    {
        $started = microtime(true);
        try {
            $action();
        } finally {
            self::notePhase($name, microtime(true) - $started, $timings);
        }
    }

    /** @mago-expect analysis:non-documented-method Pest exposes notes through its test proxy. */
    public static function note(string $message): void
    {
        test()->note($message);
    }

    /** @param list<string> $arguments */
    public static function git(string $worktree, array $arguments): string
    {
        $result = Process::path($worktree)->run(['git', ...$arguments]);
        Assert::assertTrue($result->successful(), $result->errorOutput() ?: $result->output());

        return strtolower(trim($result->output()));
    }

    /** @return list<string> */
    public static function gitStatus(string $worktree): array
    {
        $status = self::git($worktree, ['status', '--porcelain=v1', '--untracked-files=all']);

        return $status === '' ? [] : explode("\n", $status);
    }

    public static function checkout(string $worktree, string $sha): void
    {
        Assert::assertSame([], self::gitStatus($worktree));
        Assert::assertSame('', self::git($worktree, ['checkout', '--quiet', '--detach', $sha]));
        Assert::assertSame($sha, self::git($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
    }

    /**
     * Move the main checkout and its `main` branch together: the standby
     * refresher keys off HEAD while the acquirer fingerprints the `main` ref.
     */
    public static function checkoutMain(string $worktree, string $sha): void
    {
        Assert::assertSame([], self::gitStatus($worktree));
        Assert::assertSame('', self::git($worktree, ['checkout', '--quiet', '-B', 'main', $sha]));
        Assert::assertSame($sha, self::git($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']));
        Assert::assertSame($sha, self::git($worktree, ['rev-parse', '--verify', 'main^{commit}']));
    }

    /** @return array<array-key, mixed> */
    public static function incusResource(string $type, string $name): array
    {
        $matches = array_values(array_filter(
            $type === 'network' ? self::incusNetworks() : self::incusInstances($name),
            static fn (array $resource): bool => ($resource['name'] ?? null) === $name,
        ));
        Assert::assertCount(1, $matches, "Incus {$type} {$name} was not observed exactly once.");

        return $matches[0];
    }

    /**
     * Read-only: every instance of the harness project, or the exact name filter.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function incusInstances(?string $name = null): array
    {
        $remote = (string) config('e2e.incus.remote');

        return self::resourceList(self::incus([
            'list',
            $name === null ? "{$remote}:" : "{$remote}:{$name}",
            '--format=json',
        ]));
    }

    /** @return list<array<array-key, mixed>> */
    public static function incusNetworks(): array
    {
        $remote = (string) config('e2e.incus.remote');

        return self::resourceList(self::incus(['network', 'list', "{$remote}:", '--format=json']));
    }

    /** @return array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>} */
    public static function inventoryFingerprint(): array
    {
        return LiveInventory::fingerprint(self::incusInstances(), self::incusNetworks());
    }

    /**
     * Every exact name must be gone from the live inventory.
     *
     * @param list<string> $names
     */
    public static function assertIncusAbsent(array $names): void
    {
        Assert::assertSame(
            [],
            LiveInventory::observedNames(self::incusInstances(), self::incusNetworks(), $names),
            'Exact topology resources remain on the Incus host.',
        );
    }

    /** @param list<string> $arguments */
    public static function incus(array $arguments): ProcessResult
    {
        $result = self::incusProcess($arguments);
        Assert::assertTrue($result->successful(), $result->errorOutput() ?: $result->output());

        return $result;
    }

    /** @param list<string> $arguments */
    public static function incusProcess(array $arguments): ProcessResult
    {
        return Process::timeout(300)->run([
            'incus',
            '--project',
            (string) config('e2e.incus.project'),
            ...$arguments,
        ]);
    }

    /** @param list<string> $command */
    public static function incusExec(string $instance, array $command): ProcessResult
    {
        return self::incus([
            'exec',
            (string) config('e2e.incus.remote').':'.$instance,
            '--',
            ...$command,
        ]);
    }

    private static function notePhase(string $name, float $seconds, ?PhaseTimings $timings): void
    {
        $timings?->record($name, $seconds);
        self::note(sprintf('%s: %.3fs', $name, $seconds));
    }

    /** @return list<array<array-key, mixed>> */
    /** @mago-expect analysis:mixed-assignment Each listed resource is asserted as an object record. */
    private static function resourceList(ProcessResult $result): array
    {
        $resources = [];
        foreach (self::json($result->output()) as $resource) {
            Assert::assertIsArray($resource);
            $resources[] = $resource;
        }

        return $resources;
    }
}
