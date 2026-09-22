<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Support\EffectiveUser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

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
    public function resolve(string $name, string $target, string $kind): array
    {
        try {
            $this->assertSafeName($name);
            $configurationDirectory = $this->dnsmasqConfigurationDirectory();
            $mapping = $this->mapping($name, $target, $kind);
            $overrideIsCurrent = $this->overrideIsCurrent($name, $mapping, $configurationDirectory);

            if ($overrideIsCurrent && $this->servesTarget($name, $target, $kind)) {
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
            $mappingChanged = ! $this->mappingIsCurrent($name, $mapping);
            $masterChanged = $this->syncMasterConfiguration($name, $configurationDirectory);
            $resolverChanged = $this->syncSystemResolver($name);

            if ($mappingChanged) {
                $this->files->put($this->configurationPath($name), $mapping);
            }

            $changed = $masterChanged || $resolverChanged || $mappingChanged;

            if (! $changed && $this->servesTarget($name, $target, $kind)) {
                return ['status' => 'already_resolved', 'changed' => false];
            }
        } catch (Throwable) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        if (! $this->refreshDnsmasq() || ! $this->servesTarget($name, $target, $kind)) {
            return ['status' => 'refresh_failed', 'changed' => true];
        }

        $this->flushResolverCache();

        return ['status' => 'resolved', 'changed' => true];
    }

    /** @return array{status: string, changed: bool} */
    public function reset(string $name): array
    {
        try {
            $this->assertSafeName($name);
        } catch (Throwable) {
            return ['status' => 'write_failed', 'changed' => false];
        }

        $configurationPath = $this->configurationPath($name);
        $resolverPath = $this->resolverPath($name);
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

    private function mapping(string $name, string $target, string $kind): string
    {
        return match ($kind) {
            'tld' => "address=/{$name}/{$target}\n",
            'hostname' => "host-record={$name},{$target}\n",
            default => throw new RuntimeException('The resolver name kind is invalid.'),
        };
    }

    private function mappingIsCurrent(string $name, string $mapping): bool
    {
        $configurationPath = $this->configurationPath($name);

        if (! $this->files->exists($configurationPath)) {
            return false;
        }

        return trim($this->files->get($configurationPath)) === trim($mapping);
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

    private function overrideIsCurrent(string $tld, string $mapping, string $configurationDirectory): bool
    {
        if (! $this->mappingIsCurrent($tld, $mapping)) {
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

        $userId = EffectiveUser::id() ?? getmyuid();

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

    private function servesTarget(string $name, string $target, string $kind): bool
    {
        $query = [
            'dig',
            '@127.0.0.1',
            $kind === 'hostname' ? $name : "orbit-local-resolver-health.{$name}",
            '+short',
        ];

        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            array_splice($query, 3, 0, ['AAAA']);
        }

        try {
            $result = Process::timeout(10)->run($query);
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

    private function assertSafeName(string $name): void
    {
        if (
            strlen($name) > 253
            || preg_match(
                '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',
                $name,
            ) !== 1
        ) {
            throw new RuntimeException('The resolver name is invalid.');
        }
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
