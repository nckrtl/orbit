<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class ProductionPhpRuntimeIdentity
{
    public string $runtimeDirectory;

    public string $generatedDirectory;

    public string $localTuningPath;

    public string $unitPath;

    public string $markerPath;

    public function __construct(
        public string $user,
        public string $home,
        public string $version,
        public string $service,
        public string $pool,
        public string $socket,
        public string $documentRoot,
    ) {
        $this->runtimeDirectory = "/etc/orbit/php-fpm/{$user}";
        $this->generatedDirectory = "{$this->runtimeDirectory}/generated";
        $this->localTuningPath = "{$this->runtimeDirectory}/local.conf";
        $this->unitPath = "/etc/systemd/system/{$service}";
        $this->markerPath = "{$this->runtimeDirectory}/orbit.identity";
    }

    public static function forProvisioning(AppInstance $appInstance, string $version): self
    {
        $user = $appInstance->production_user;
        $home = $appInstance->production_home;
        $documentRoot = $appInstance->effectiveRoot();

        if (
            $appInstance->environment !== 'production'
            || ! is_string($user)
            || ! preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user)
            || ! is_string($home)
            || $home !== "/home/{$user}"
            || ! preg_match('/\A[0-9]+\.[0-9]+\z/D', $version)
            || ! is_string($documentRoot)
            || ! str_starts_with($documentRoot, "{$home}/")
        ) {
            throw self::invalid();
        }

        return new self(
            user: $user,
            home: $home,
            version: $version,
            service: "orbit-{$user}-php{$version}-fpm.service",
            pool: "orbit-{$user}",
            socket: "/run/php/{$user}.sock",
            documentRoot: $documentRoot,
        );
    }

    public static function from(AppInstance $appInstance): self
    {
        $version = $appInstance->selected_php_version;

        if (! is_string($version)) {
            throw self::invalid();
        }

        $identity = self::forProvisioning($appInstance, $version);

        if (
            $appInstance->production_php_service !== $identity->service
            || $appInstance->production_php_pool !== $identity->pool
            || $appInstance->production_php_socket !== $identity->socket
        ) {
            throw self::invalid();
        }

        return $identity;
    }

    /** @return array{production_php_service: string, production_php_pool: string, production_php_socket: string} */
    public function attributes(): array
    {
        return [
            'production_php_service' => $this->service,
            'production_php_pool' => $this->pool,
            'production_php_socket' => $this->socket,
        ];
    }

    public function marker(): string
    {
        return implode("\n", [
            "user={$this->user}",
            "home={$this->home}",
            "version={$this->version}",
            "service={$this->service}",
            "pool={$this->pool}",
            "socket={$this->socket}",
            "document_root={$this->documentRoot}",
            '',
        ]);
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'app-prod.php_runtime_identity_invalid',
            message: 'The recorded production PHP runtime identity is invalid.',
            status: 409,
        );
    }
}
