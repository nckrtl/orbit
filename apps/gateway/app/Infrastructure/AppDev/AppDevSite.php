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
        public ?int $agentationPort = null,
        public ?string $analyticsUpstream = null,
        /** @var list<string> */
        public array $analyticsTrustedProxies = [],
        /**
         * The site serves a second placement or a second Router during a stored transition. The
         * current site for the same address wins, and private DNS answers with it only when a
         * Router selection names its Node.
         */
        public bool $secondary = false,
    ) {}

    public function asSecondary(): self
    {
        return clone ($this, ['secondary' => true]);
    }

    /** Caddy serves one site block for each domain and listener on a Node. */
    public function addressKey(): string
    {
        return $this->nodeId.'|'.$this->domain.'|'.($this->publicListener ? 'public' : 'private');
    }

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

    /** A public listener uses Caddy automatic HTTPS and loads no Orbit certificate file. */
    public function loadsCertificate(string $scope): bool
    {
        return ! $this->publicListener && ($this->certificateScope ?? $this->scope) === $scope;
    }

    public function isLocalHttpProxy(): bool
    {
        return $this->localHttpUpstream !== null && $this->localHttpUpstream !== '';
    }

    /** A tracking host: only Plausible's script and event paths reach the analytics role. */
    public function isAnalyticsTracking(): bool
    {
        return $this->analyticsUpstream !== null && $this->analyticsUpstream !== '';
    }

    public function isProxy(): bool
    {
        return $this->isAnalyticsTracking()
            || $this->upstreamAddress !== null
            || $this->upstreamAddresses !== []
            || $this->localUnixUpstream !== null
            || $this->isLocalHttpProxy();
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
