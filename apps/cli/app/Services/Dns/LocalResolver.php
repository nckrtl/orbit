<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps local DNS mutation and recovery fail closed.
 * @mago-expect lint:kan-defect Local DNS convergence must account for partial privileged changes.
 * @mago-expect lint:too-many-methods Narrow methods keep each filesystem and process boundary explicit.
 */
final readonly class LocalResolver implements ResolvesLocalDns
{
    private Filesystem $files;

    public function __construct(
        private ?string $platform = null,
        private string $resolverDirectory = '/etc/resolver',
        private ?string $configurationDirectory = null,
        private ?string $masterConfigurationPath = null,
        private ?string $brewExecutable = null,
    ) {
        $this->files = new Filesystem;
    }

    public function platform(): string
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => 'unsupported',
        };
    }

    public function available(): bool
    {
        $brew = $this->brew();

        if ($brew === null) {
            return false;
        }

        try {
            return Process::timeout(10)->run([$brew, 'list', '--formula', 'dnsmasq'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{status: string, changed: bool} */
    public function resolve(string $tld, string $target): array
    {
        try {
            $configurationDirectory = $this->dnsmasqConfigurationDirectory();
            $overrideIsCurrent = $this->overrideIsCurrent($tld, $target, $configurationDirectory);

            if ($overrideIsCurrent && $this->servesTarget($tld, $target)) {
                return ['status' => 'already_resolved', 'changed' => false];
            }
        } catch (Throwable) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        if (! $this->authorizeSudo()) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        try {
            $this->files->ensureDirectoryExists($configurationDirectory);
            $existingTarget = $this->existingTarget($tld);
            $masterChanged = $this->syncMasterConfiguration($tld, $configurationDirectory);
            $resolverChanged = $this->syncSystemResolver($tld);
            $mappingChanged = $existingTarget !== $target;

            if ($mappingChanged) {
                $this->files->put($this->configurationPath($tld), "address=/{$tld}/{$target}\n");
            }

            $changed = $masterChanged || $resolverChanged || $mappingChanged;

            if (! $changed && $this->servesTarget($tld, $target)) {
                return ['status' => 'already_resolved', 'changed' => false];
            }
        } catch (Throwable) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        if (! $this->refreshDnsmasq() || ! $this->servesTarget($tld, $target)) {
            return ['status' => 'refresh_failed', 'changed' => true];
        }

        $this->flushResolverCache();

        return ['status' => 'resolved', 'changed' => true];
    }

    /** @return array{status: string, changed: bool} */
    public function reset(string $tld): array
    {
        $configurationPath = $this->configurationPath($tld);
        $resolverPath = $this->resolverPath($tld);
        $hasConfiguration = $this->files->exists($configurationPath);
        $hasResolver = $this->files->exists($resolverPath);

        if (! $hasConfiguration && ! $hasResolver) {
            return ['status' => 'already_absent', 'changed' => false];
        }

        if (! $this->authorizeSudo()) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        try {
            if ($hasConfiguration) {
                $this->files->delete($configurationPath);
            }

            if ($hasResolver) {
                $result = $this->privilegedProcess()->run(['sudo', '-n', 'rm', '--', $resolverPath]);

                if (! $result->successful()) {
                    return ['status' => 'write_failed', 'changed' => $hasConfiguration];
                }
            }
        } catch (Throwable) {
            return ['status' => 'write_failed', 'changed' => $hasConfiguration];
        }

        if (! $this->refreshDnsmasq()) {
            return ['status' => 'refresh_failed', 'changed' => true];
        }

        $this->flushResolverCache();

        return ['status' => 'reset', 'changed' => true];
    }

    private function existingTarget(string $tld): ?string
    {
        $configurationPath = $this->configurationPath($tld);

        if (! $this->files->exists($configurationPath)) {
            return null;
        }

        $matches = [];

        if (
            preg_match(
                '/^address=\/\.?'.preg_quote($tld, delimiter: '/').'\/([^\r\n#]+)\s*$/m',
                $this->files->get($configurationPath),
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return trim($matches[1]);
    }

    private function syncMasterConfiguration(string $tld, string $configurationDirectory): bool
    {
        $masterPath = $this->dnsmasqMasterConfigurationPath();
        $this->files->ensureDirectoryExists(dirname($masterPath));

        if (! $this->files->exists($masterPath)) {
            $this->files->put(
                $masterPath,
                $this->normalizedMasterConfiguration('', $tld, $configurationDirectory),
            );

            return true;
        }

        $contents = $this->files->get($masterPath);
        $nextContents = $this->normalizedMasterConfiguration($contents, $tld, $configurationDirectory);

        if ($nextContents === $contents) {
            return false;
        }

        $this->files->put($masterPath, $nextContents);

        return true;
    }

    private function normalizedMasterConfiguration(
        string $contents,
        string $tld,
        string $configurationDirectory,
    ): string {
        $include = "conf-dir={$configurationDirectory}/,*.conf";
        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            throw new RuntimeException('Could not parse the dnsmasq configuration.');
        }

        $nextLines = [];

        foreach ($lines as $line) {
            if ($this->isOrbitConfigurationDirectory($line) || $this->isAddressForTld($line, $tld)) {
                continue;
            }

            $nextLines[] = $line;
        }

        while ($nextLines !== [] && end($nextLines) === '') {
            array_pop($nextLines);
        }

        $nextLines[] = $include;

        return implode("\n", $nextLines)."\n";
    }

    private function overrideIsCurrent(string $tld, string $target, string $configurationDirectory): bool
    {
        if ($this->existingTarget($tld) !== $target) {
            return false;
        }

        $resolverPath = $this->resolverPath($tld);

        if (
            ! $this->files->exists($resolverPath)
            || $this->resolverNameservers($this->files->get($resolverPath)) !== ['127.0.0.1']
        ) {
            return false;
        }

        $masterPath = $this->dnsmasqMasterConfigurationPath();

        if (! $this->files->exists($masterPath)) {
            return false;
        }

        $contents = $this->files->get($masterPath);

        return $contents === $this->normalizedMasterConfiguration($contents, $tld, $configurationDirectory);
    }

    private function syncSystemResolver(string $tld): bool
    {
        $resolverPath = $this->resolverPath($tld);

        if (
            $this->files->exists($resolverPath)
            && $this->resolverNameservers($this->files->get($resolverPath)) === ['127.0.0.1']
        ) {
            return false;
        }

        if (! $this->files->isDirectory($this->resolverDirectory)) {
            $directoryResult = $this->privilegedProcess()->run([
                'sudo',
                '-n',
                'mkdir',
                '-p',
                '--',
                $this->resolverDirectory,
            ]);

            if (! $directoryResult->successful()) {
                throw new RuntimeException('Could not create the macOS resolver directory.');
            }
        }

        $stagedPath = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-resolver-');

        if ($stagedPath === false) {
            throw new RuntimeException('Could not stage the macOS resolver configuration.');
        }

        try {
            $this->files->put($stagedPath, "nameserver 127.0.0.1\n");
            $result = $this->privilegedProcess()->run([
                'sudo',
                '-n',
                'install',
                '-o',
                'root',
                '-g',
                'wheel',
                '-m',
                '0644',
                '--',
                $stagedPath,
                $resolverPath,
            ]);
        } finally {
            $this->files->delete($stagedPath);
        }

        if (! $result->successful()) {
            throw new RuntimeException('Could not install the macOS resolver configuration.');
        }

        return true;
    }

    private function refreshDnsmasq(): bool
    {
        $brew = $this->brew();

        if ($brew === null) {
            return false;
        }

        try {
            if (! $this->removeUserDnsmasqService()) {
                return false;
            }

            return $this
                ->privilegedProcess()
                ->run(['sudo', '-n', $brew, 'services', 'restart', 'dnsmasq'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function removeUserDnsmasqService(): bool
    {
        $launchAgentPath = $this->userLaunchAgentPath();

        if (! $this->files->exists($launchAgentPath)) {
            return true;
        }

        $userId = posix_geteuid();

        try {
            Process::timeout(30)->run([
                'launchctl',
                'bootout',
                "gui/{$userId}/homebrew.mxcl.dnsmasq",
            ]);

            return $this->files->delete($launchAgentPath);
        } catch (Throwable) {
            return false;
        }
    }

    private function servesTarget(string $tld, string $target): bool
    {
        try {
            $result = Process::timeout(10)->run([
                'dig',
                '@127.0.0.1',
                "orbit-local-resolver-health.{$tld}",
                '+short',
            ]);
        } catch (Throwable) {
            return false;
        }

        if (! $result->successful()) {
            return false;
        }

        $answers = preg_split('/\R+/', $result->output());

        return is_array($answers) && array_values(array_filter(array_map(trim(...), $answers))) === [$target];
    }

    private function flushResolverCache(): void
    {
        try {
            Process::timeout(10)->run(['dscacheutil', '-flushcache']);
            $this->privilegedProcess()->timeout(10)->run(['sudo', '-n', 'killall', '-HUP', 'mDNSResponder']);
        } catch (Throwable) {
            return;
        }
    }

    private function dnsmasqConfigurationDirectory(): string
    {
        if ($this->configurationDirectory !== null) {
            return rtrim($this->configurationDirectory, characters: '/');
        }

        $userHome = getenv('HOME');

        if (! is_string($userHome) || $userHome === '') {
            throw new RuntimeException('The user home directory is unavailable.');
        }

        return rtrim($userHome, characters: '/').'/.config/orbit/dnsmasq.d';
    }

    private function dnsmasqMasterConfigurationPath(): string
    {
        if ($this->masterConfigurationPath !== null) {
            return $this->masterConfigurationPath;
        }

        $brew = $this->brew();

        if ($brew === null) {
            throw new RuntimeException('Homebrew is unavailable.');
        }

        $result = Process::timeout(10)->run([$brew, '--prefix']);

        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException('Could not resolve the Homebrew prefix.');
        }

        return rtrim(trim($result->output()), characters: '/').'/etc/dnsmasq.conf';
    }

    private function userLaunchAgentPath(): string
    {
        $userHome = getenv('HOME');

        if (! is_string($userHome) || $userHome === '') {
            throw new RuntimeException('The user home directory is unavailable.');
        }

        return rtrim($userHome, characters: '/').'/Library/LaunchAgents/homebrew.mxcl.dnsmasq.plist';
    }

    private function brew(): ?string
    {
        if ($this->brewExecutable !== null) {
            return $this->brewExecutable;
        }

        try {
            $result = Process::timeout(10)->run(['which', 'brew']);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful() || trim($result->output()) === '') {
            return null;
        }

        return trim($result->output());
    }

    private function configurationPath(string $tld): string
    {
        return $this->dnsmasqConfigurationDirectory()."/{$tld}.conf";
    }

    private function resolverPath(string $tld): string
    {
        return rtrim($this->resolverDirectory, characters: '/')."/{$tld}";
    }

    private function isOrbitConfigurationDirectory(string $line): bool
    {
        return preg_match('#^conf-dir=.+/(?:\.config/orbit|orbit)/dnsmasq\.d/,\*\.conf$#', trim($line)) === 1;
    }

    private function isAddressForTld(string $line, string $tld): bool
    {
        return preg_match('/^address=\/\.?'.preg_quote($tld, delimiter: '/').'\/.+$/', trim($line)) === 1;
    }

    /** @return list<string> */
    private function resolverNameservers(string $contents): array
    {
        $nameservers = [];
        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            $matches = [];

            if (preg_match('/^\s*nameserver\s+(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $nameservers[] = trim($matches[1]);
        }

        return $nameservers;
    }

    private function privilegedProcess(): PendingProcess
    {
        return Process::timeout(120);
    }

    private function authorizeSudo(): bool
    {
        $process = Process::timeout(120);

        try {
            return $process
                ->tty(tty: $process->supportsTty())
                ->run(['sudo', '-v'])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
