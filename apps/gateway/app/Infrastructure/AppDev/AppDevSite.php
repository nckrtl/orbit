<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class AppDevSite
{
    public function __construct(
        public int $nodeId,
        public string $nodeAddress,
        public string $scope,
        public string $checkoutPath,
        public string $documentRoot,
        public ?string $phpVersion,
        public string $domain,
        public ?string $upstreamAddress = null,
        /** @var list<string> */
        public array $upstreamAddresses = [],
        public bool $unavailable = false,
        public string $environment = 'development',
        public ?string $productionUser = null,
        public ?string $productionHome = null,
        public ?string $appSlug = null,
        public ?string $certificateScope = null,
        public ?string $productionPhpSocket = null,
        public bool $publicListener = false,
        public bool $preserveForwardedIdentity = false,
        public ?string $localUnixUpstream = null,
        public ?int $vitePort = null,
        public ?string $localHttpUpstream = null,
    ) {}

    public function poolName(): string
    {
        return "orbit-{$this->scope}";
    }

    public function socketPath(): string
    {
        return $this->productionPhpSocket ?? "/run/php/{$this->poolName()}.sock";
    }

    public function usesDedicatedPhpRuntime(): bool
    {
        return $this->productionPhpSocket !== null;
    }

    public function certificateDirectory(): string
    {
        $scope = $this->certificateScope ?? $this->scope;

        return "/etc/caddy/orbit-certificates/{$scope}/current";
    }

    public function isLocalHttpProxy(): bool
    {
        return $this->localHttpUpstream !== null && $this->localHttpUpstream !== '';
    }

    public function isProxy(): bool
    {
        return $this->upstreamAddress !== null
            || $this->upstreamAddresses !== []
            || $this->localUnixUpstream !== null;
    }

    /** @return list<string> */
    public function proxyAddresses(): array
    {
        $addresses = $this->upstreamAddresses !== []
            ? $this->upstreamAddresses
            : ($this->upstreamAddress === null ? [] : [$this->upstreamAddress]);

        if (is_string($this->localUnixUpstream) && $this->localUnixUpstream !== '') {
            array_unshift($addresses, $this->localUnixUpstream);
        }

        return $addresses;
    }

    public function executionUser(string $developmentUser): string
    {
        if ($this->environment !== 'production') {
            return $developmentUser;
        }

        return $this->productionUser ?? (is_string($this->appSlug) ? "orbit-{$this->appSlug}" : $developmentUser);
    }

    public function executionHome(string $developmentHome): string
    {
        if ($this->environment !== 'production') {
            return $developmentHome;
        }

        return $this->productionHome ?? (is_string($this->appSlug) ? "/var/www/{$this->appSlug}" : $developmentHome);
    }
}
