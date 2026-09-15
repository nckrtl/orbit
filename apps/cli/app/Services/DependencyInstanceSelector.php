<?php

declare(strict_types=1);

namespace App\Services;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AppInstances\ResolveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\ResolvedDirectoryInstanceResponse;
use SensitiveParameter;

final readonly class DependencyInstanceSelector
{
    public function select(GatewayConnector $connector, #[SensitiveParameter] ?string $app = null, bool $all = false): ResolvedAppInstanceResponse|ResolvedDirectoryInstanceResponse|null
    {
        if ($all && $app !== null) {
            throw new GatewayApiException('App and all-instance selectors cannot be combined.', errorCode: 'dependencies.target_conflict');
        }
        if ($all) {
            return null;
        }
        if ($app !== null) {
            return $this->resolveDomain($connector, $app);
        }

        return $this->resolveDirectory($connector);
    }

    public function resolveDirectory(GatewayConnector $connector): ResolvedDirectoryInstanceResponse
    {
        $current = getcwd();
        $directory = $current === false ? false : realpath($current);
        if ($directory === false || ! is_dir($directory) || ! is_readable($directory)) {
            throw new GatewayApiException('The current directory is unavailable.', errorCode: 'dependencies.directory_unavailable');
        }

        return $connector->send(new ResolveDirectoryInstanceRequest($directory))->dtoOrFail();
    }

    public function resolveDomain(GatewayConnector $connector, #[SensitiveParameter] string $domain): ResolvedAppInstanceResponse
    {
        return $connector->send(new ResolveAppInstanceRequest($domain))->dtoOrFail();
    }
}
